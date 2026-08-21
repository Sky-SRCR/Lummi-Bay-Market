<?php
// ============================================================
// THE INSTALL, AS A USE CASE
// ============================================================
// `install.php` is the page. This is what it decides, and the split is the same one
// `DisplayAdmin`, `AccountAdmin`, `BrandAdmin` and `PasswordResetCompletion` are built
// on (invariant 22): the module owns the transaction, writes as little SQL as it can get
// away with, and hands back a result the page turns into a sentence.
//
// Three properties are worth stating because they are what make an installer testable at
// all, and an untested installer is one that has been run once, by its author, on the one
// machine it worked on:
//
//   1. **Every decision here is pure or takes its facts as parameters.** The page reads
//      the machine — `PHP_VERSION`, `extension_loaded()`, `is_writable()` — and hands the
//      answers in. That is invariant 37's seam, and it is the only reason the suite can
//      ask what this says on PHP 8.0 with no zlib and an unwritable webroot, which is a
//      machine nobody here has.
//   2. **It spells no filename.** Where the credentials go and what they are called is
//      `InstallPaths`' answer, asked rather than restated, because the file it names
//      lives outside the webroot where nothing in this repo could ever see the two
//      disagree.
//   3. **It never decides that something worked.** A write is read back, a privilege is
//      confirmed by the statement that needed it, and a refusal carries the engine's own
//      message. The one thing an installer must not do is report success it did not
//      check — the whole of `docs/BUILD-REFERENCE.md` §4p and #9 is what that costs.

require_once __DIR__ . '/install_paths.php';
require_once __DIR__ . '/server_report.php';
require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/brands.php';
require_once __DIR__ . '/brand_styles.php';
require_once __DIR__ . '/brand_admin.php';
require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/schema.php';

/**
 * One thing the installer looked at, and what it makes of it.
 *
 * Three verdicts rather than two. `stop` is "this cannot work"; `warn` is "this will
 * work and you will meet the consequence later", and the difference matters because the
 * warnings here are the expensive half — a host that cannot write above the webroot
 * installs perfectly and leaves a database password inside a directory Apache serves.
 */
class InstallCheck
{
    const OK   = 'ok';
    const WARN = 'warn';
    const STOP = 'stop';

    private $name;
    private $verdict;
    private $sentence;

    private function __construct($name, $verdict, $sentence)
    {
        $this->name     = $name;
        $this->verdict  = $verdict;
        $this->sentence = $sentence;
    }

    public static function ok($name, $sentence)      { return new self($name, self::OK,   $sentence); }
    public static function warned($name, $sentence)  { return new self($name, self::WARN, $sentence); }
    public static function stopped($name, $sentence) { return new self($name, self::STOP, $sentence); }

    public function name()     { return $this->name; }
    public function verdict()  { return $this->verdict; }
    public function sentence() { return $this->sentence; }
    public function isStop()   { return $this->verdict === self::STOP; }
}

/** Whether a step worked, and the sentence a person reads either way. */
class InstallResult
{
    private $ok;
    private $message;
    private $detail;

    private function __construct($ok, $message, array $detail)
    {
        $this->ok      = $ok;
        $this->message = $message;
        $this->detail  = $detail;
    }

    public static function ok($message, array $detail = [])
    {
        return new self(true, $message, $detail);
    }

    public static function failed($message, array $detail = [])
    {
        return new self(false, $message, $detail);
    }

    public function isOk()    { return $this->ok; }
    public function message() { return $this->message; }

    /** Lines to print under the message — a refused statement, a path to create by hand. */
    public function detail()  { return $this->detail; }
}

class Installer
{
    /** Matches `setup.php`'s rule, which was the only rule about this before now. */
    const PASSWORD_MIN = 8;

    /**
     * The privileges convergence and `schema.sql` between them need.
     *
     * From the deploy note in `HANDOFF.md` §5, which is where this was learned the
     * expensive way: an install presented as *"Base table or view not found: displays"*
     * when the real fault was a `CREATE` the user could not issue, and a user with
     * `CREATE` but not `ALTER` produces a Builder that **loads** — the two tables appear,
     * `canvas_elements.display_id` does not, and the app is in a state a crash would have
     * been kinder than.
     *
     * Deliberately not `ALL PRIVILEGES`. `DROP`, `TRUNCATE`, `LOCK TABLES` and
     * `CREATE TEMPORARY TABLES` appear nowhere in any statement this app issues, and in
     * an app with no undo a privilege it never uses is only risk.
     */
    const PRIVILEGES = ['SELECT', 'INSERT', 'UPDATE', 'DELETE',
                        'CREATE', 'ALTER', 'INDEX', 'REFERENCES'];

    /**
     * The constant a credentials file carries to say which install folder it was written
     * for. Read by `credentialsOwnership()` and by nothing else in the app — `db_connect.php`
     * does not know it exists, and a file without it still works exactly as it always did.
     */
    const STAMP = 'DB_INSTALL_FOLDER';

    /** This install has a credentials file of its own, or is about to write one. */
    const OWN = 'own';
    /** The file found belongs to a *different* install folder, and says so. */
    const BORROWED = 'borrowed';
    /** A file was found and does not say whose it is. Could be either. */
    const UNKNOWN = 'unknown';
    /** No credentials file exists yet. */
    const NONE = 'none';

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ============================================================
    // Stage 1 — can this machine run the app at all?
    // ============================================================

    /**
     * What the page read off the machine, turned into verdicts.
     *
     * Pure. The keys are the facts, and every one of them is something only the page can
     * answer: `php` a version string, `pdoMysql` / `zlib` whether the extension is
     * loaded, `appWritable` whether the folder the app is in can be written to,
     * `privateWritable` whether the folder above the webroot can, `https` whether this
     * request arrived over TLS.
     *
     * Absent keys are treated as false rather than as absent, which is the right way
     * round for a preflight: a fact nobody supplied is not a fact in this install's
     * favour.
     *
     * @return array InstallCheck[]
     */
    public static function preflight(array $facts)
    {
        $php = isset($facts['php']) ? (string) $facts['php'] : '';
        $out = [];

        if ($php === '') {
            $out[] = InstallCheck::stopped('PHP version',
                'The PHP version could not be read, so nothing here can say whether this '
                . 'host will run the app.');
        } elseif (version_compare($php, ServerReport::ASSUMED_PHP, '>=')) {
            $out[] = InstallCheck::ok('PHP version',
                'PHP ' . $php . ' — at or above the ' . ServerReport::ASSUMED_PHP
                . ' this app is written for.');
        } else {
            $out[] = InstallCheck::stopped('PHP version',
                'PHP ' . $php . ' is below ' . ServerReport::ASSUMED_PHP
                . ', which is the version this app is written for. On cPanel this is '
                . 'MultiPHP Manager — set this domain explicitly rather than leaving it on '
                . '"inherit", so the version cannot move under you later.');
        }

        $out[] = !empty($facts['pdoMysql'])
            ? InstallCheck::ok('MySQL support',
                'The pdo_mysql extension is loaded.')
            : InstallCheck::stopped('MySQL support',
                'The pdo_mysql extension is not loaded, so nothing here can reach a '
                . 'database. On cPanel it is under Select PHP Version → Extensions.');

        $out[] = !empty($facts['zlib'])
            ? InstallCheck::ok('Unpacking',
                'zlib is available, so this file can unpack the app it carries.')
            : InstallCheck::stopped('Unpacking',
                'zlib is not available, so this file cannot unpack the app it carries. '
                . 'Upload the app/ folder from the package by hand instead — INSTALL.md '
                . 'has that route, and it needs nothing from this page.');

        $out[] = !empty($facts['appWritable'])
            ? InstallCheck::ok('This folder',
                'Writable, so the app can be unpacked here and the Branding page can '
                . 'save the store\'s colours later.')
            : InstallCheck::stopped('This folder',
                'Not writable by the web user, so the app cannot be unpacked here — and '
                . 'the Branding page could not save either, because it writes a temporary '
                . 'copy beside its file and renames over it. 755 on the folder is enough '
                . 'on almost every host.');

        // A warning and not a stop, and this is the one the warn verdict exists for. The
        // app runs perfectly with its credentials inside the webroot: PHP executes the
        // file and emits nothing, so it reads as safe right up until a configuration
        // change stops PHP running and Apache hands the file over as text.
        $out[] = !empty($facts['privateWritable'])
            ? InstallCheck::ok('Above the webroot',
                'The folder above the webroot can be written to, so the database password '
                . 'goes somewhere no browser request can reach.')
            : InstallCheck::warned('Above the webroot',
                'The folder above the webroot cannot be written to from here. The install '
                . 'can continue — this page will print the file for you to place by hand — '
                . 'but do not solve it by putting the credentials beside the app. PHP '
                . 'normally executes such a file and emits nothing, which reads as safe '
                . 'until a configuration change stops PHP running and Apache serves it as '
                . 'text.');

        $out[] = !empty($facts['https'])
            ? InstallCheck::ok('HTTPS',
                'This page arrived over HTTPS, so the password you are about to type is '
                . 'encrypted on the way.')
            : InstallCheck::warned('HTTPS',
                'This page arrived over plain HTTP. The app\'s own .htaccess redirects to '
                . 'HTTPS once it is in place, but everything you type before that — '
                . 'including the database password — crosses the network in the clear. '
                . 'Install the certificate first if you can.');

        return $out;
    }

    /** Does anything in a preflight refuse to continue? */
    public static function blocked(array $checks)
    {
        foreach ($checks as $check) {
            if ($check->isStop()) { return true; }
        }
        return false;
    }

    // ============================================================
    // Stage 2 — the database
    // ============================================================

    /**
     * A connection to the server without naming a database, or null.
     *
     * Separate from the one below because the two questions have different answers and a
     * person needs to know which they got. "Wrong password" and "that database does not
     * exist" arrive as the same failed connection if you only ever ask for both at once,
     * and the second one this page can offer to fix.
     */
    public static function connectServer($host, $user, $pass, &$error = null)
    {
        return self::open('mysql:host=' . $host . ';charset=utf8mb4', $user, $pass, $error);
    }

    /** A connection to one database, or null. */
    public static function connectDatabase($host, $name, $user, $pass, &$error = null)
    {
        return self::open('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
                          $user, $pass, $error);
    }

    private static function open($dsn, $user, $pass, &$error)
    {
        $error = '';
        try {
            return new PDO($dsn, (string) $user, (string) $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return null;
        }
    }

    /**
     * Create the database, if this user is allowed to — and say plainly when it is not.
     *
     * **On cPanel this normally fails, and that is not a fault to work around.** cPanel
     * owns database creation: names carry the account prefix, the account↔database
     * mapping lives in cPanel's own datastore rather than in MySQL, and the user it
     * issues has privileges on mapped databases and no `CREATE DATABASE` anywhere. A
     * database made behind cPanel's back is one cPanel does not know about, will not
     * back up on the account's schedule, and cannot re-map after a restore.
     *
     * So this is offered rather than relied on: it succeeds on a server somebody
     * administers themselves, and on a shared host it returns the sentence naming the
     * three clicks that do work. The name is quoted as an identifier and checked against
     * the character class MySQL allows in an unquoted one first — a database name is not
     * a bound parameter, and the one thing an installer must never do is build DDL out
     * of a string it has not looked at.
     */
    public static function createDatabase(PDO $server, $name, &$error = null)
    {
        $error = '';
        $name  = (string) $name;
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            $error = 'A database name may hold letters, numbers and underscores, up to 64 '
                   . 'characters. Create it in cPanel and type the full name — including '
                   . 'the account prefix — into the form.';
            return false;
        }
        try {
            $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name
                          . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            return true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            return false;
        }
    }

    /**
     * The privileges this user appears not to hold, read from `SHOW GRANTS`.
     *
     * Pure, and a **report rather than a verdict** — which is why nothing branches on it.
     * `SHOW GRANTS` can be wrong in both directions for an installer's purposes: a role,
     * a wildcard database pattern or a per-table grant all read differently from the
     * plain `GRANT … ON \`db\`.*` this understands, and a host can hand out privileges
     * this cannot see. The authoritative answer arrives seconds later and from the engine
     * itself — `applySchemaScript()` reports every statement that was refused, with the
     * database's own message. This exists so that a person meeting eleven "command denied"
     * errors has already been told which privilege to go and tick.
     *
     * `ALL PRIVILEGES` answers nothing missing. `USAGE` is MySQL's word for no privileges
     * at all and is ignored rather than read as one.
     *
     * @param array $grantLines the rows of SHOW GRANTS, as strings
     * @return array the names from PRIVILEGES that no line mentioned
     */
    public static function missingPrivileges(array $grantLines)
    {
        $held = [];
        foreach ($grantLines as $line) {
            $line = strtoupper((string) $line);
            $on   = strpos($line, ' ON ');
            if ($on === false) { continue; }
            $granted = substr($line, 0, $on);
            if (strpos($granted, 'ALL PRIVILEGES') !== false) { return []; }
            foreach (self::PRIVILEGES as $privilege) {
                if (preg_match('/\b' . $privilege . '\b/', $granted)) { $held[$privilege] = true; }
            }
        }
        $missing = [];
        foreach (self::PRIVILEGES as $privilege) {
            if (!isset($held[$privilege])) { $missing[] = $privilege; }
        }
        return $missing;
    }

    // ============================================================
    // Stage 3 — the credentials, outside the webroot
    // ============================================================

    /**
     * Where this install's credentials file should be written.
     *
     * `InstallPaths` answers what the app *looks for*; this decides which of those
     * answers to write, and the rule is the one that keeps a second install off the
     * first one's signs. A shared file already being there means another copy of this app
     * is using it — so this install gets the file named after its own folder rather than
     * overwriting somebody else's database name with its own. Nothing else in this app
     * would notice that mistake: an unmodified second copy connects to the live database
     * and then behaves perfectly.
     *
     * @return string absolute path
     */
    public static function credentialsTarget($appDir)
    {
        $candidates = InstallPaths::credentialsCandidates($appDir);
        $shared     = $candidates[count($candidates) - 1];
        if (count($candidates) > 1 && is_file($shared)) { return $candidates[0]; }
        return $shared;
    }

    /**
     * Whose database is the file this install just read?
     *
     * `credentialsTarget()` is the write side of the second-install rule and it has been
     * right since the day it landed. This is the **read** side, which was missing, and the
     * gap between them is the whole of §4bp: a second install resolves the *shared*
     * credentials file, connects to the first install's database, finds an administrator
     * already in it, prints "Installed" and deletes itself. Every line of that is working
     * as written. The person now has a second folder signed in to the first one's signs,
     * and the only place that says so is a card in the admin panel they have no reason to
     * open. `INSTALL.md` even promised the opposite — *"the installer handles this by
     * itself"* — which was true of the form and not of the road to it.
     *
     * Four answers, because they need four different sentences:
     *
     *   * `NONE` — nothing to read. A first install, on its way to writing one.
     *   * `OWN` — the file is the one named after *this* folder, or it is the shared file
     *     and its stamp names this folder. Trust it; this is the install it describes.
     *   * `BORROWED` — the shared file's stamp names a **different** folder. Decided, not
     *     guessed: do not adopt it, do not connect through it, ask for a database.
     *   * `UNKNOWN` — the shared file carries no stamp, so it is either this install's or
     *     somebody else's and nothing on disk can tell. Every credentials file written
     *     before this change is in this state, including the live one, so the answer has
     *     to be the old behaviour *plus a sentence*. Adopting it silently is the defect;
     *     refusing it would put a database form on a working install.
     *
     * The stamp is a parameter rather than a `defined()` call for invariant 37's reason:
     * the interesting cases are all files this machine does not have, so a rule that could
     * only be asked about the one file sitting here could only ever give the one answer it
     * happens to give.
     *
     * On why `BORROWED` may show a form at all, given that install.php is a public URL:
     * the form cannot act without working database credentials. `installerDoDatabase()`
     * connects before it writes anything, so a visitor who does not already hold a MySQL
     * user and password cannot repoint an install with it — and one who does needs no
     * form.
     *
     * @param string      $appDir          the folder the app is installed in
     * @param string      $credentialsFile the path `InstallPaths::credentialsFile()` gave
     * @param string|null $stampedFolder   the file's own STAMP value, or null if it has none
     */
    public static function credentialsOwnership($appDir, $credentialsFile, $stampedFolder = null)
    {
        $credentialsFile = (string) $credentialsFile;
        if ($credentialsFile === '') { return self::NONE; }

        $candidates = InstallPaths::credentialsCandidates($appDir);
        if (count($candidates) > 1 && $credentialsFile === $candidates[0]) { return self::OWN; }

        // A path that is neither candidate cannot have come from
        // `InstallPaths::credentialsFile()`, so nothing is known about it — and answering
        // from the stamp would be answering about a file this install does not read.
        if ($credentialsFile !== $candidates[count($candidates) - 1]) { return self::UNKNOWN; }

        // The shared file, then. A folder whose name `InstallPaths` refused cannot have a
        // file of its own, so it can never be told apart from the install that wrote the
        // shared one.
        $folder = InstallPaths::installName($appDir);
        $stamp  = ($stampedFolder === null) ? '' : trim((string) $stampedFolder);
        if ($folder === '' || $stamp === '') { return self::UNKNOWN; }

        return ($stamp === $folder) ? self::OWN : self::BORROWED;
    }

    /**
     * What to say about a credentials file that is not demonstrably this install's.
     *
     * '' for the two states with nothing to report, so a caller prints it or does not
     * without asking a second question. Pure, and it takes the database name rather than
     * reading `DB_NAME`, because the sentence is only worth anything when it names the
     * database somebody did not expect.
     *
     * The path is spelled by `InstallPaths` rather than here. This module already refuses
     * to know what that file is called (`credentialsSource()` asks the same way) — the one
     * place the two could disagree is a directory outside the webroot that nothing in this
     * repo can see.
     */
    public static function sharingNote($ownership, $appDir, $credentialsFile, $databaseName)
    {
        if ($ownership === self::OWN || $ownership === self::NONE) { return ''; }

        $candidates = InstallPaths::credentialsCandidates($appDir);
        $mine       = $candidates[0];
        $folder     = InstallPaths::installName($appDir);
        $database   = ((string) $databaseName === '') ? 'the database it names' : (string) $databaseName;

        if ($folder === '') {
            return 'This install reads ' . basename((string) $credentialsFile) . ' and reached '
                 . $database . '. Its folder name cannot be used to give it a credentials '
                 . 'file of its own, so if another copy of this app is on this account, both '
                 . 'are using that one database. Rename the folder to letters, digits, dots, '
                 . 'dashes and underscores only.';
        }

        // The `unknown` arm carries a second remedy, and it is the one that matters on a
        // host that predates the stamp: a person who can see that this *is* the right
        // database can say so in one line, in a file they already have, and every later
        // install in a second folder is then decided rather than adopted. Without it the
        // only door out of `unknown` is the installer rewriting that file, which is the one
        // thing nobody should do to a working install.
        $shared = ($ownership === self::BORROWED)
            ? 'That file was written for an install in a folder called something else.'
            : 'That file does not say which install it belongs to, so it may be another '
              . 'copy of this app\'s.';

        // Spelled out rather than described as "the commented line in that file": the files
        // this describes are the ones written *before* the stamp existed, so there is no
        // comment in them to point at.
        $andIfItIsMine = ($ownership === self::BORROWED) ? '' :
            ' If that database is this install\'s, add the line   define(\''
            . self::STAMP . '\', \'' . $folder . '\');   to that file, and this page will '
            . 'know next time instead of printing this.';

        return 'This install is in ' . $folder . ' and has no credentials file of its own. '
             . 'It read ' . basename((string) $credentialsFile) . ' and reached ' . $database
             . '. ' . $shared . ' If this install was meant to have its own database, create '
             . $mine . ' with that database\'s details — it is looked for first, and nothing '
             . 'in the app folder changes.' . $andIfItIsMine;
    }

    /**
     * The contents of that file — the real values, or the blanks to fill in.
     *
     * One writer of this shape, used twice: the installer writes it with values, and
     * `tools/build_installer.php` puts the blank form in the package for anyone taking
     * the manual route. Two copies of a file this important is two things to change and
     * one chance to forget, and the forgotten one would be the copy a person edits by
     * hand at three in the afternoon.
     *
     * Every name in the prose comes from `InstallPaths`. The values are written with
     * `var_export()`, which is the only correct way to put an arbitrary password into PHP
     * source: a quote or a backslash in it would otherwise end the string, and the
     * install would fail with a parse error in a file outside the webroot that nobody
     * thinks to look at.
     *
     * @param array|null $values host/name/user/pass, or null for the blank form
     */
    public static function credentialsSource($appDir, ?array $values = null)
    {
        $candidates = InstallPaths::credentialsCandidates($appDir . '/EXAMPLE-FOLDER');
        $shared     = $candidates[count($candidates) - 1];
        $private    = basename(dirname($shared));
        $specific   = basename($candidates[0]);

        $filled = ($values !== null);
        $get    = function ($key, $blank) use ($values, $filled) {
            $value = ($filled && isset($values[$key])) ? (string) $values[$key] : $blank;
            return var_export($value, true);
        };

        // Which install wrote this. The installer stamps its own folder so that a *later*
        // install in a second folder can tell that this file is not its own and ask for a
        // database instead of adopting this one (`credentialsOwnership()`, §4bp).
        //
        // The blank form leaves the line **commented out**, and that is the careful half.
        // A stamp naming a folder that does not exist reads as "this file belongs to
        // somebody else" — so a placeholder somebody left alone would make their own
        // working install look borrowed, which is a database form offered over a live sign.
        // Absent, the answer is `UNKNOWN`: the old behaviour, and a sentence.
        $folder = InstallPaths::installName($appDir);
        $stamp  = ($filled && $folder !== '')
            ? "define('" . self::STAMP . "', " . var_export($folder, true) . ");\n"
            : "// define('" . self::STAMP . "', 'the-folder-this-app-is-in');\n";

        $head = $filled
            ? "// Written by the installer. Keep it — the app reads it on every request.\n"
            : "// Fill in all four. Leaving a placeholder is not a half-working install:\n"
              . "// the app refuses to connect and says so.\n";

        return "<?php\n"
             . "// ============================================================\n"
             . "// DATABASE CREDENTIALS — this file belongs OUTSIDE the webroot\n"
             . "// ============================================================\n"
             . "// Its place is:\n"
             . "//\n"
             . "//   /home/YOUR_ACCOUNT/" . $private . "/" . basename($shared) . "\n"
             . "//\n"
             . "// one level above public_html, where no browser request can reach it. The\n"
             . "// app walks up two folders from its own directory to find it.\n"
             . "//\n"
             . $head
             . "// ============================================================\n"
             . "\n"
             . "define('DB_HOST', " . $get('host', 'localhost') . ");\n"
             . "define('DB_NAME', " . $get('name', 'your_database_name') . ");\n"
             . "define('DB_USER', " . $get('user', 'your_database_user') . ");\n"
             . "define('DB_PASS', " . $get('pass', 'your_database_password') . ");\n"
             . "\n"
             . "// Which install folder these credentials were written for. Nothing in the\n"
             . "// app reads this; the installer does, to tell a second copy of the app that\n"
             . "// this file is not its own. Wrong is worse than absent, so it is left\n"
             . "// commented out unless the installer filled it in itself.\n"
             . $stamp
             . "\n"
             . "// -- A second copy of the app -------------------------------------------\n"
             . "//\n"
             . "// Two installs on one account walk up to this same file, so an unmodified\n"
             . "// copy in a second folder connects to the FIRST one's database — and then\n"
             . "// behaves perfectly. Signing in converges schema on the live tables;\n"
             . "// pressing Publish overwrites a real sign. Nothing warns you.\n"
             . "//\n"
             . "// So a second install gets a file of its own, named after its folder:\n"
             . "//\n"
             . "//   /home/YOUR_ACCOUNT/" . $private . "/" . $specific . "\n"
             . "//\n"
             . "// for an install in a folder called EXAMPLE-FOLDER. The name-specific file\n"
             . "// is looked for first; absent one, this shared file is used. The installer\n"
             . "// writes the specific one by itself when it finds this file already here.\n"
             . "//\n"
             . "// Then check it, before signing in a second time: Admin Panel -> Settings\n"
             . "// -> This Server names the install folder and the database it reached.\n";
    }

    /**
     * Write a file, and read it back before saying so.
     *
     * The read-back is not belt-and-braces. A quota, an immutable flag, a full disk and a
     * `open_basedir` restriction all fail in ways `file_put_contents()` reports partially
     * or not at all, and this is the one file the app cannot start without — an installer
     * that says "saved" over a file that is not there sends somebody to look for a
     * database fault that does not exist.
     */
    public static function writeFile($path, $contents, &$error = null)
    {
        $error = '';
        $dir   = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            $error = 'The folder ' . $dir . ' does not exist and could not be created.';
            return false;
        }
        if (@file_put_contents($path, $contents) === false) {
            $error = 'Writing ' . $path . ' was refused.';
            return false;
        }
        clearstatcache(true, $path);
        if (!is_file($path) || file_get_contents($path) !== $contents) {
            $error = $path . ' does not hold what was just written to it.';
            return false;
        }
        return true;
    }

    // ============================================================
    // Stage 4 — the first administrator, and the venue Brand
    // ============================================================

    /**
     * How many accounts this database holds: 0, more, or -1 for "no users table".
     *
     * The installer's whole idea of where it is up to. Three answers rather than two,
     * because "no accounts yet" sends it to the form and "no table" sends it back to the
     * schema — and a page that read the second as the first would offer to create an
     * administrator into nothing.
     */
    public function accountCount()
    {
        $accounts = new AccountStore($this->pdo);
        return $accounts->total();
    }

    /**
     * Name the venue, then create the administrator.
     *
     * **In that order, and with no transaction of its own** — which is the opposite of
     * what this module's siblings do, so it is worth saying why rather than leaving it to
     * look like an omission. `BrandAdmin` already owns a transaction over its two tables,
     * and MySQL has no nested transactions: a `beginTransaction()` around a call that
     * opens its own throws, so wrapping these two writes is not a stricter version of
     * this, it is a broken one.
     *
     * What replaces the transaction is the ordering, and it is chosen so that either
     * failure leaves something harmless:
     *
     *   * **Venue fails** → no account is created, and the form is offered again. Nothing
     *     has happened.
     *   * **Venue named, account fails** → a renamed Brand and still no accounts, so this
     *     page still works and renaming is idempotent. `setup.php` had this the other way
     *     round, and its own comment admitted the cost: an account created and a Brand
     *     that was not, reported as a success with a sentence about the Branding tab. The
     *     account is what disables the installer, so it goes last.
     *
     * The validation order is `setup.php`'s, kept deliberately: the fields, then the
     * email, then the venue name, then the password length, then the confirmation. A
     * person filling in a form wants the first thing that is wrong with it, and moving
     * that order about changes which sentence they get for no reason anybody can name.
     */
    public function createFirstAdmin($username, $email, $password, $confirm, $brandName)
    {
        $username  = trim((string) $username);
        $email     = trim((string) $email);
        $password  = (string) $password;
        $brandName = BrandStore::cleanName($brandName);

        if ($username === '' || $email === '' || $password === '' || $brandName === '') {
            return InstallResult::failed('Every field is needed.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return InstallResult::failed('That does not look like an email address.');
        }
        if (!BrandStore::isValidName($brandName)) {
            return InstallResult::failed('That venue name cannot be used — '
                . BrandStore::NAME_MAX . ' characters or fewer, and no control characters.');
        }
        if (strlen($password) < self::PASSWORD_MIN) {
            return InstallResult::failed('The password needs at least '
                . self::PASSWORD_MIN . ' characters.');
        }
        if ($password !== (string) $confirm) {
            return InstallResult::failed('The two passwords are not the same.');
        }

        $accounts = new AccountStore($this->pdo);
        $existing = $accounts->total();
        if ($existing < 0) {
            return InstallResult::failed('The users table is not there, so the schema has not '
                . 'been applied to this database yet.');
        }
        if ($existing > 0) {
            return InstallResult::failed('This database already holds accounts, so there is no '
                . 'first administrator to create. Sign in instead.');
        }

        // The venue first. `schema.sql` seeds one generically-named Brand so that
        // `displays.brand_id` has something to point at from the first moment; naming a
        // venue is renaming that one. Creating a second would leave "Store Brand" on the
        // Display Branding tab, reading like an install that stopped half way.
        try {
            $brands = new BrandStore($this->pdo);
            $brandAdmin = new BrandAdmin($this->pdo, $brands, new BrandStyles($this->pdo),
                                         new DisplayStore($this->pdo));
            $rows   = $brands->all();
            $named  = (count($rows) === 1)
                ? $brandAdmin->updateDetails($rows[0], [
                      'name'    => $brandName,
                      'bg_type' => $rows[0]->backgroundType(),
                      'bg_val'  => $rows[0]->backgroundValue(),
                  ])
                : $brandAdmin->create(['name' => $brandName]);
            if (!$named->isOk()) {
                return InstallResult::failed('The venue "' . $brandName . '" could not be saved, '
                    . 'so no account was created either.', [$named->message()]);
            }
        } catch (Throwable $e) {
            return InstallResult::failed('The venue could not be saved, so no account was '
                . 'created either.', [$e->getMessage()]);
        }

        try {
            $accounts->createAdmin($username, $email,
                                   password_hash($password, PASSWORD_DEFAULT));
        } catch (Throwable $e) {
            return InstallResult::failed('The venue "' . $brandName . '" was saved, but the '
                . 'administrator was not — so this page still works. Try again.',
                [$e->getMessage()]);
        }

        return InstallResult::ok('The administrator and the venue "' . $brandName
            . '" are created.');
    }
}
