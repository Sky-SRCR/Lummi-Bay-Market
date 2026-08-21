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
require_once __DIR__ . '/assets.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/upload_limits.php';

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

    /** How long a store name may be. `BrandStore`'s column width, for one reason: the
     *  venue name beside it on the same form is held to that, and two limits on one
     *  screen is a refusal somebody cannot predict. `SITE_NAME` is a `define()` with no
     *  column behind it, so this is a rule rather than a constraint being reported. */
    const SITE_NAME_MAX = BrandStore::NAME_MAX;

    /**
     * What a logo may be, and what it is stored as — a map rather than a list, and the
     * direction is the point: **the extension written to disk comes from the type the file
     * really is, not from the name the browser sent.** That is the shape `admin_panel.php`'s
     * branding upload already had, found by invariant 40 rather than remembered, and it is
     * strictly better than sanitising a filename because there is no filename left to
     * sanitise. SVG is excluded deliberately and for the same reason it is there: an SVG can
     * carry `<script>` and would be stored XSS served from this app's own origin.
     */
    const LOGO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png',
                        'image/gif'  => 'gif', 'image/webp' => 'webp'];

    /** The label the logo gets in the Asset Library, so it is findable later. */
    const LOGO_LABEL = 'Store logo';

    /**
     * The shipped navigation colour, for the form to show as its placeholder.
     *
     * A method rather than the page reading `BrandingConfig::DEFAULTS` for itself, and the
     * reason is the rule that caught this whole fieldset: `BRAND_NAV_BG` may be named in
     * this module and four others, and a page that spells it is a page that has started
     * having its own opinion about the store's colours (invariant 14). Asking keeps the
     * placeholder and the value that would be written the same thing by construction.
     */
    public static function navBgDefault()
    {
        return (string) BrandingConfig::DEFAULTS['BRAND_NAV_BG'];
    }

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
     *   * `UNKNOWN` — the shared file carries no stamp, so nothing on disk says whose it
     *     is. **Treated exactly like `BORROWED`** (§4bt): not adopted, not connected
     *     through, a database of this folder's own asked for. Every credentials file
     *     written before the stamp existed is in this state, including the live one, so
     *     this is the state the field reports come from — and the two answers that
     *     preceded this one both went wrong in the same direction. §4bp adopted it with a
     *     sentence, which reached nobody until the install was finished and the installer
     *     gone. §4br asked which database this folder used and offered to adopt or to
     *     repoint, the second gated on the password of the database in use — a question
     *     with a wrong answer on the menu, and a page asking for the credentials of a
     *     database it should not have been touching. What is left is the only reading that
     *     needs no judgement: a file that cannot be shown to be this folder's is not this
     *     folder's. The way in for the install that really does own it is the stamp, which
     *     is one line in a file they already have, and `sharingNote()` spells it.
     *
     * The stamp is a parameter rather than a `defined()` call for invariant 37's reason:
     * the interesting cases are all files this machine does not have, so a rule that could
     * only be asked about the one file sitting here could only ever give the one answer it
     * happens to give.
     *
     * On why anything but `OWN` may show a form at all, given that install.php is a public
     * URL: the form cannot act without working database credentials. `installerDoDatabase()`
     * connects before it writes anything, so a visitor who does not already hold a MySQL
     * user and password cannot repoint an install with it — and one who does needs no
     * form. That reasoning was written for `BORROWED` and it covers `UNKNOWN` unchanged;
     * what it does *not* cover is a folder that cannot own a file, where the form's only
     * possible target is somebody else's credentials — `canOwnCredentials()` is that gate.
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
     * without asking a second question.
     *
     * **It does not name the database that file points at, and that is the point** (§4bt).
     * It used to, because the sentence seemed only worth printing when it named the
     * database somebody did not expect — which was true while the installer went on to
     * *use* it. Nothing reaches that database now: the page names the file, not what is
     * behind it, because a name printed beside a form is a thing to try, and there is
     * nothing here to try it with.
     *
     * The path is spelled by `InstallPaths` rather than here. This module already refuses
     * to know what that file is called (`credentialsSource()` asks the same way) — the one
     * place the two could disagree is a directory outside the webroot that nothing in this
     * repo can see.
     */
    public static function sharingNote($ownership, $appDir, $credentialsFile)
    {
        if ($ownership === self::OWN || $ownership === self::NONE) { return ''; }

        $candidates = InstallPaths::credentialsCandidates($appDir);
        $mine       = $candidates[0];
        $folder     = InstallPaths::installName($appDir);
        $file       = basename((string) $credentialsFile);

        // The folder cannot be given a file of its own, so there is no form to offer: the
        // only path this page could write is the shared file itself, which is another
        // install's credentials. A rename first, then everything else works.
        if ($folder === '') {
            return 'The only credentials file this folder can read is ' . $file . ', which '
                 . 'belongs to whichever install wrote it, and this folder\'s name cannot be '
                 . 'used to give it one of its own. Nothing was read from that database and '
                 . 'nothing here will write to it. Rename this folder to letters, digits, '
                 . 'dots, dashes and underscores only, then reload this page.';
        }

        $shared = ($ownership === self::BORROWED)
            ? 'That file was written for an install in a folder called something else.'
            : 'That file does not say which install it belongs to, so it is not this '
              . 'folder\'s to use.';

        // Spelled out rather than described as "the commented line in that file": the files
        // this describes are the ones written *before* the stamp existed, so there is no
        // comment in them to point at. It is the way back for the install that really does
        // own that file — one line, in a file they already have — and it is the only way,
        // because this page will not decide it for them by connecting.
        $andIfItIsMine = ($ownership === self::BORROWED) ? '' :
            ' If that file is this install\'s own — if this folder is the app you have been '
            . 'using — add the line   define(\'' . self::STAMP . '\', \'' . $folder
            . '\');   to it, and reload. That is the only thing that makes this page use it.';

        return 'This install is in ' . $folder . ' and has no credentials file of its own. '
             . 'The nearest one is ' . $file . '. ' . $shared . ' Nothing was read from the '
             . 'database it names and nothing here will write to it. Give this install a '
             . 'database of its own below — that writes ' . $mine . ', which is looked for '
             . 'first, and nothing in the app folder changes.' . $andIfItIsMine;
    }

    /**
     * Can this folder be given a credentials file of its own?
     *
     * The whole of §4bt rests on this being asked before a form is offered. A folder whose
     * name `InstallPaths` refuses has exactly one candidate — the shared file — so
     * `credentialsTarget()` would answer with *that* path, and the database form would
     * overwrite another install's credentials with this one's. The refusal is a rename,
     * which the sentence names, and it is a real dead end until somebody does it: there is
     * no file this page could write that only this folder would read.
     *
     * Pure, and it takes the folder rather than a flag because the answer is a property of
     * the path and nothing else — `installName()` is the same rule that decides the
     * filename, asked once so the two cannot drift apart.
     */
    public static function canOwnCredentials($appDir)
    {
        return InstallPaths::installName($appDir) !== '';
    }

    /**
     * What this folder already has, when the only thing missing is the tables.
     *
     * A credentials file this install owns, a database that answered, and no tables in it
     * is not the state the database form is for — every value that form asks for is
     * already on disk and already known to work. Printing the form anyway asks a person to
     * retype four things a page in front of them could have said, and a typo in the second
     * of them repoints the folder at a database nobody meant: `installerDoDatabase()`
     * rewrites the credentials file from whatever was typed. This is §4br's lesson one
     * boundary further in — the page had the facts and did not say them — and it is the
     * state a person is in whenever the credentials were written by hand, which
     * `INSTALL.md` has recommended all along.
     *
     * Pure, and it takes both values rather than reading `DB_NAME`, for the reason
     * `sharingNote()` does: the sentence is only worth printing when it names the database
     * somebody is about to accept, and a suite has to be able to ask it about a file this
     * machine does not have.
     */
    public static function tablesNote($credentialsFile, $databaseName)
    {
        $file     = basename((string) $credentialsFile);
        $database = ((string) $databaseName === '')
            ? 'the database it names' : (string) $databaseName;

        return 'This folder already has its credentials: ' . $file . ', above the webroot, '
             . 'naming ' . $database . '. That database answered, and there are no tables in '
             . 'it yet — so the only thing left to do here is build them, and nothing needs '
             . 'typing again.';
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
    // Stage 4a — the store's own identity
    // ============================================================
    // Everything a customer eventually sees is set on four different pages of the signed-in
    // app, and none of them is reachable until an account exists. That is the right shape
    // for colours and typography, which want a preview beside them — and the wrong shape
    // for the three facts a person installing already has in their hand: what the place is
    // called, what address its mail comes from, and the logo file on their desktop.
    //
    // The mail-from address is the one that earns its place on this form. Left at the
    // shipped `noreply@yourdomain.com`, a password reset is sent from a domain this server
    // does not own, is dropped as spam, and **so is the alert that would have said so** —
    // which is the worst shape a default can have. It is still not *required* here: an
    // install held hostage for an address somebody has to go and decide is an install
    // abandoned half way, and the finished screen goes on saying what a default costs.
    //
    // Colours are offered and refused rather than corrected, which is the same stance
    // `BrandAdmin::checkFields()` takes: storing `#ffffff` for something somebody typed and
    // reporting success is #21. The one substitution made here is **stated on the form** —
    // the navigation's text colour follows the background it sits on, because a light
    // colour typed into a dark-themed bar is white text on white and nothing else in this
    // app would ever mention it.

    /**
     * What is wrong with the store details on the form, or '' if nothing is.
     *
     * Pure, and every field optional: this whole fieldset is skippable, so "absent" has to
     * mean "leave the shipped default alone" rather than "clear it". A value that is
     * *present and unusable* is a different answer and gets a sentence.
     *
     * @param array $store site_name, mail_from, nav_bg, bg_val — any subset
     */
    public static function storeProblem(array $store)
    {
        $name = trim((string) ($store['site_name'] ?? ''));
        if ($name !== '') {
            if (strlen($name) > self::SITE_NAME_MAX) {
                return 'The store name has to be ' . self::SITE_NAME_MAX
                     . ' characters or fewer.';
            }
            if (preg_match('/[\x00-\x1f\x7f]/', $name)) {
                return 'The store name cannot contain control characters.';
            }
        }

        $mail = trim((string) ($store['mail_from'] ?? ''));
        if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            return 'That does not look like an email address for mail to come from.';
        }

        // Two colours, each named by where a person will see it, because "invalid colour"
        // on a form with two of them is a sentence nobody can act on.
        $colours = ['nav_bg' => 'The colour across the top of the admin pages',
                    'bg_val' => 'The background a new sign starts with'];
        foreach ($colours as $key => $where) {
            $raw = (string) ($store[$key] ?? '');
            if ($raw === '') { continue; }
            if (Color::read($raw) === '') {
                return $where . ' is not a colour (' . Color::describe($raw)
                     . '). Use a six-digit hex colour such as #1a252f, or leave it empty.';
            }
        }

        return '';
    }

    /**
     * Black or white, whichever can be read on this background.
     *
     * The one value this form derives rather than asks for, and it is derived because the
     * alternative is invisible: `BRAND_TEXT` ships as white for a dark navigation bar, and
     * a person typing their brand's pale grey into the colour box would get white text on
     * pale grey with nothing on any screen to say why the menu had vanished. Stated on the
     * form, so it is a substitution somebody was told about rather than #21 again.
     *
     * Decided with `Color::contrastRatio()` rather than a luminance rule of its own. The
     * first draft had the sRGB coefficients and a midpoint written out here, which is a
     * second opinion about legibility in an app that already has one — and `Color` holds
     * the threshold this project chose (`READABLE_RATIO`, WCAG AA) as well as the
     * arithmetic. Two answers to "can this be read" is how one of them comes to disagree
     * with the warning an admin is shown.
     *
     * Pure, and white for anything it cannot read: that is the shipped default and it is
     * right for the shipped background. A tie goes to white for the same reason.
     *
     * **No warning goes with this, and that was checked rather than assumed.** The first
     * draft added one — "the colour you chose is hard to read against black *and* white" —
     * and then the arithmetic was run over 4096 backgrounds: not one of them is hard to
     * read under the better of the two. It cannot be: a background dark enough to fail
     * against white passes against black at the same threshold, and the two bands meet with
     * no gap. So the sentence would have been an `ok` line nobody could ever produce
     * (invariant 30), and it was deleted instead of shipped.
     */
    public static function readableTextOn($background)
    {
        $hex = Color::read($background);
        if ($hex === '') { return '#ffffff'; }
        $onBlack = Color::contrastRatio('#000000', $hex);
        $onWhite = Color::contrastRatio('#ffffff', $hex);
        return ($onBlack > $onWhite) ? '#000000' : '#ffffff';
    }

    /**
     * What is wrong with the uploaded logo, or '' if nothing is.
     *
     * Pure, and takes the cap and the detected type rather than reading either: `ini_get`
     * has one value on this machine and `mime_content_type()` needs a file that is really
     * there, so a rule that read them could only ever be asked in the one configuration
     * the tests happen to run in (invariant 37).
     *
     * The same four questions `crud.php` asks, in the same order, because they are the same
     * question — is this an image this app is willing to keep. The extension list is
     * `AssetLibrary`'s, so a type added there is accepted here without anybody remembering
     * to. What this does **not** do is trust the extension afterwards: the stored name is
     * built by `logoStoredName()` from the matched list entry, never from what arrived.
     *
     * @param array  $file     one entry of $_FILES
     * @param int    $maxBytes UploadLimit::logoBytes(), passed in
     * @param string $mime     mime_content_type() of the temporary file, passed in
     */
    public static function logoFileProblem(array $file, $maxBytes, $mime)
    {
        $error = isset($file['error']) ? intval($file['error']) : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return 'That logo is larger than this server accepts in one upload ('
                 . UploadLimit::describeBytes($maxBytes) . ').';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'That logo did not finish uploading. Nothing else was affected.';
        }
        if (intval($file['size'] ?? 0) > intval($maxBytes)) {
            return 'That logo is ' . UploadLimit::describeBytes(intval($file['size'] ?? 0))
                 . ' and this server accepts up to ' . UploadLimit::describeBytes($maxBytes)
                 . '.';
        }
        $ext = strtolower((string) pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, AssetLibrary::IMAGE_EXTENSIONS, true)) {
            return 'A logo has to be a ' . strtoupper(implode(', ', AssetLibrary::IMAGE_EXTENSIONS))
                 . ' image. Nothing else was affected.';
        }
        if (!isset(self::LOGO_TYPES[(string) $mime])) {
            return 'That file is named like an image and is not one (' . (string) $mime
                 . '). Nothing else was affected.';
        }
        return '';
    }

    /**
     * The name that file will be stored under, or '' if it may not be stored at all.
     *
     * **This is the line that stops a logo being a PHP file.** `uploads/` is the one folder
     * in the webroot with no `.htaccess` of its own — the three in this app are the root,
     * `lib/` and `tools/` — so a `.php` written there is executed by the web server, by
     * anybody, for ever.
     *
     * So **nothing the browser sent reaches the filename**. Not the basename, and not the
     * extension: the extension is looked up from the type the file actually is, and a type
     * that is not in `LOGO_TYPES` produces no filename rather than a carefully cleaned one.
     * `shell.php`, `shell.php.png` and `logo.png` with PHP inside it all end at the same
     * place — either a `.png` holding whatever the file held, which the server serves as an
     * image, or nothing.
     *
     * The random half is a parameter for the reason every other decision here is: a name
     * built from `random_bytes()` cannot be asserted, and what wants asserting is the shape
     * and the extension rather than the entropy.
     *
     * @param string $mime  mime_content_type() of the temporary file, passed in
     * @param string $token hex from the caller's own random_bytes()
     */
    public static function logoStoredName($mime, $token)
    {
        if (!isset(self::LOGO_TYPES[(string) $mime])) { return ''; }
        $token = preg_replace('/[^a-f0-9]/', '', strtolower((string) $token));
        if ($token === '') { return ''; }
        return 'install_' . $token . '.' . self::LOGO_TYPES[(string) $mime];
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
     * How many administrators this database holds who could actually sign in.
     *
     * Beside `accountCount()` and not folded into it, because they answer different
     * questions and the installer needs both: the count decides which *screen* comes next,
     * and this decides whether the screen that says the install is over is telling the
     * truth. A database can hold accounts that no longer open the app — closed, suspended,
     * or `basic` — and `accountCount()` counts every one of them.
     */
    public function openAdminCount()
    {
        $accounts = new AccountStore($this->pdo);
        return $accounts->openAdminCount();
    }

    /**
     * What the last screen says, given what this install actually did about the account.
     *
     * The heading, the sentence and whether it is a refusal, from one call — because the
     * three had to agree and the way they disagreed was the defect (§4bu). `install.php`
     * reaches its final screen two ways. One is a form somebody filled in: a username, an
     * email, a password typed twice, and this page created the account. The other is a
     * database that already held accounts when the page first loaded, which skips the
     * administrator step altogether — nothing is asked for and nothing is created. Both
     * printed **Installed** and offered a sign-in link, so the store owner's question
     * ("where did it get the username and password? I never set one") had no answer on the
     * screen that had just told them the install was finished.
     *
     * The third case is the one worth a refusal: a database holding accounts, none of which
     * is an administrator who can sign in. The installer will not create one there and this
     * is not timidity — the administrator form is public, drawn on a page with no account
     * behind it, and offering it over a database that already holds accounts is offering
     * anybody who finds the file an administrator on live data. So it says what is true and
     * what to do about it instead.
     *
     * Pure, and it takes the two facts rather than a `PDO`: the whole point is that the
     * sentence can be asked for on a machine where neither state exists.
     *
     * The two coercions below are why the comparisons under them are strict for nothing —
     * `!== ''` behaves identically to `!=` once the value is a string, and `=== 1` to `== 1`
     * once it is an int, so mutating either survives the suite. That is the coercion being
     * right rather than a check missing: what the cast prevents is a caller handing this
     * `null` and getting "Sign in as ." — a sentence naming no account — and that one is
     * checked. Written down rather than left as three quiet survivors (invariant 30).
     *
     * @param string $created    the username this page created, '' if it created none
     * @param int    $openAdmins administrators in that database who can sign in
     * @return array{heading: string, note: string, stop: bool}
     */
    public static function administratorOutcome($created, $openAdmins)
    {
        $created    = (string) $created;
        $openAdmins = intval($openAdmins);

        if ($created !== '') {
            return [
                'heading' => 'Installed',
                'note'    => 'Sign in as ' . $created . ', with the password you typed on the '
                           . 'last page. That is the only account in this database and it is '
                           . 'an administrator; every other account is made from inside the '
                           . 'app, in Admin Panel → Accounts.',
                'stop'    => false,
            ];
        }

        $signable = ($openAdmins === 1)
            ? 'one of them can sign in as an administrator'
            : $openAdmins . ' of them can sign in as an administrator';

        if ($openAdmins > 0) {
            return [
                'heading' => 'This database was already installed',
                'note'    => 'No account was created here and nothing was asked for: that '
                           . 'database already held accounts before this installer ran, and '
                           . $signable . '. So there is no username or password from this '
                           . 'install to remember — this folder runs on the accounts that '
                           . 'were already there. For an administrator of its own, point a '
                           . 'fresh copy of this installer at an empty database.',
                'stop'    => false,
            ];
        }

        return [
            'heading' => 'This database was already installed',
            'note'    => 'No account was created here, and none of the accounts that database '
                       . 'already held can sign in as an administrator — they are closed, '
                       . 'suspended, or not administrators. This installer will not add one to '
                       . 'a database that already holds accounts: this page has no account '
                       . 'behind it, so over live data that form is an administrator for '
                       . 'whoever finds the file. Give one of those accounts back its '
                       . 'administrator role and its active flag in the database, or point a '
                       . 'fresh copy of this installer at an empty database.',
            'stop'    => true,
        ];
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
    /**
     * Everything that would refuse this form, or null if nothing would.
     *
     * Extracted so it can be asked **before** a file is moved. `move_uploaded_file()` cannot
     * be rolled back, so a logo accepted and then followed by "the two passwords are not the
     * same" leaves a file in `uploads/` and a row in the library that nobody asked for — the
     * same shape `crud.php` puts its grant check above the file handling for. One writer of
     * the rules, two callers: this page asks first, and `createFirstAdmin()` asks again
     * rather than trusting that it did.
     *
     * The order is `setup.php`'s and is kept deliberately: a person filling in a form wants
     * the first thing that is wrong with it. The store details come last of the validations
     * because they are the optional half — being told about a colour before being told the
     * password is too short is the wrong first sentence.
     */
    public function refusalFor($username, $email, $password, $confirm, $brandName,
                               array $store = [])
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

        $storeProblem = self::storeProblem($store);
        if ($storeProblem !== '') {
            return InstallResult::failed($storeProblem);
        }

        $existing = (new AccountStore($this->pdo))->total();
        if ($existing < 0) {
            return InstallResult::failed('The users table is not there, so the schema has not '
                . 'been applied to this database yet.');
        }
        if ($existing > 0) {
            return InstallResult::failed('This database already holds accounts, so there is no '
                . 'first administrator to create. Sign in instead.');
        }
        return null;
    }

    public function createFirstAdmin($username, $email, $password, $confirm, $brandName,
                                     array $store = [], ?BrandingConfig $branding = null)
    {
        $refusal = $this->refusalFor($username, $email, $password, $confirm, $brandName, $store);
        if ($refusal !== null) { return $refusal; }

        $username  = trim((string) $username);
        $email     = trim((string) $email);
        $password  = (string) $password;
        $brandName = BrandStore::cleanName($brandName);
        $accounts  = new AccountStore($this->pdo);

        // The venue first. `schema.sql` seeds one generically-named Brand so that
        // `displays.brand_id` has something to point at from the first moment; naming a
        // venue is renaming that one. Creating a second would leave "Store Brand" on the
        // Display Branding tab, reading like an install that stopped half way.
        //
        // The logo and the background ride along in the same write rather than in one of
        // their own, because `BrandStore::updateDetails()` writes **every** column it knows
        // about — that is its documented contract, and the reason is that it cannot tell a
        // slot somebody cleared from one the form never carried. So a second call would be
        // the first call's values erased. The palette is carried through the same way and
        // for the same reason: this form does not offer palette slots, and passing none
        // would null the six the seeded Brand has.
        try {
            $brands = new BrandStore($this->pdo);
            $brandAdmin = new BrandAdmin($this->pdo, $brands, new BrandStyles($this->pdo),
                                         new DisplayStore($this->pdo));
            $rows   = $brands->all();
            $logoId = intval($store['logo_asset_id'] ?? 0);
            if (count($rows) === 1) {
                $was    = $rows[0];
                $bg     = trim((string) ($store['bg_val'] ?? ''));
                $fields = [
                    'name'          => $brandName,
                    'logo_asset_id' => ($logoId > 0) ? $logoId : $was->logoAssetId(),
                    'bg_type'       => ($bg === '') ? $was->backgroundType() : 'color',
                    'bg_val'        => ($bg === '') ? $was->backgroundValue() : $bg,
                ];
                foreach (BrandStore::paletteFields() as $index => $field) {
                    $fields[$field] = $was->paletteSlot($index);
                }
                $named = $brandAdmin->updateDetails($was, $fields);
            } else {
                $fields = ['name' => $brandName];
                if ($logoId > 0) { $fields['logo_asset_id'] = $logoId; }
                $bg = trim((string) ($store['bg_val'] ?? ''));
                if ($bg !== '') { $fields['bg_type'] = 'color'; $fields['bg_val'] = $bg; }
                $named = $brandAdmin->create($fields);
            }
            if (!$named->isOk()) {
                return InstallResult::failed('The venue "' . $brandName . '" could not be saved, '
                    . 'so no account was created either.', [$named->message()]);
            }
        } catch (Throwable $e) {
            return InstallResult::failed('The venue could not be saved, so no account was '
                . 'created either.', [$e->getMessage()]);
        }

        // Then the site's own identity, which is a *file* rather than a row — and the one
        // file in this app whose syntax every page depends on. `BrandingConfig` renders it,
        // parses what it rendered, writes a temporary file, reads it back and renames it
        // into place, so the failure it reports is always "the site is still running on
        // exactly what it had" (#36). Nothing here reimplements a byte of that.
        //
        // Before the account and after the venue, for the same reason the venue is where it
        // is: the account is what switches this installer off, so everything that could
        // still be retried happens above it. A refusal here leaves a named venue, no
        // account, and this page still working.
        if ($branding !== null) {
            $changes = self::brandingChanges($store);
            if ($changes) {
                $written = $branding->save($changes);
                if (!$written->isOk()) {
                    return InstallResult::failed('The venue "' . $brandName . '" was saved and '
                        . 'the store details were not, so no account was created either — this '
                        . 'page still works.', [$written->message()]);
                }
            }
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

    /**
     * The `branding_config.php` settings this form actually asked for.
     *
     * Pure, and it returns **only what was given** — an empty array when the fieldset was
     * skipped, so `save()` is not called at all rather than called with the file's own
     * values. Writing a file every page requires in order to change nothing in it is a risk
     * taken for no reason.
     *
     * `MAIL_FROM_NAME` follows the store name because it is the name a recipient sees beside
     * the address, and an install that named the store and left "Display System" on its mail
     * has two names for one place. `BRAND_TEXT` follows the background it sits on — the one
     * derived value on this form, and the form says so.
     */
    public static function brandingChanges(array $store)
    {
        $changes = [];

        $name = trim((string) ($store['site_name'] ?? ''));
        if ($name !== '') {
            $changes['SITE_NAME']      = $name;
            $changes['MAIL_FROM_NAME'] = $name;
        }

        $mail = trim((string) ($store['mail_from'] ?? ''));
        if ($mail !== '') { $changes['MAIL_FROM'] = $mail; }

        $logo = trim((string) ($store['logo_path'] ?? ''));
        if ($logo !== '') { $changes['BRAND_LOGO'] = $logo; }

        // Read, not passed through. `storeProblem()` has already refused anything that is
        // not a colour, so this cannot substitute for a value somebody typed — what it does
        // is normalise `#ABC` and `abcdef` into the six-digit form the file is read with.
        $nav = Color::read((string) ($store['nav_bg'] ?? ''));
        if ($nav !== '') {
            $changes['BRAND_NAV_BG'] = $nav;
            $changes['BRAND_TEXT']   = self::readableTextOn($nav);
        }

        return $changes;
    }
}
