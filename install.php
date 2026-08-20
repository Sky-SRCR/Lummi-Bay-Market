<?php
// ============================================================
// THE INSTALLER — one file, one folder, one page
// ============================================================
// Upload this file on its own into an empty folder and open it in a browser. It unpacks
// the app it carries, creates the tables, writes the database credentials to the folder
// above the webroot, makes the first administrator and the venue's first Brand, and then
// deletes itself.
//
// It replaces `setup.php`, which did the last of those five things. There is one door
// now rather than two, and the reason that matters is not tidiness: a public
// "make yourself an administrator" form is the sharpest thing in this repo, and two of
// them is two windows to close, two files to remember, and two pages that have to agree
// about what "already installed" means.
//
// ── Why the app is inside this file ─────────────────────────────────────────────
//
// The alternative — 48 files uploaded by hand — is the route `INSTALL.md` used to lead
// with, and its worst failure has nothing to do with the app: **many FTP clients skip
// dotfiles by default**, so `lib/.htaccess` does not arrive, and `lib/` is then readable
// in a browser. A folder listing cannot see that; only a request can, which is why
// `docs/DEPLOY-SKIP.md` has had to ask that question twice, both ways, on a live server.
// One file cannot lose part of itself in transit.
//
// The payload is base64 rather than raw bytes appended after `__halt_compiler()`, which
// would be a third smaller. An FTP client set to ASCII mode rewrites line endings inside
// binary it thinks is text, and the damage is silent — a zip that fails a CRC check on
// somebody else's server, days later. Base64 survives that transfer, and 33% of 460 KB is
// not a number worth a class of failure nobody could diagnose.
//
// ── Why the unpacking is written out here rather than in lib/ ───────────────────
//
// Because of what it is for: at the moment this runs, `lib/` does not exist. Nothing can
// be required, so the reader is four functions at the top of this file — and the
// `INSTALLER_INSPECT` guard at the bottom is what lets the suite require this page,
// declare them, and test them without running an installer. That guard is not a
// back door: nothing else in the repo defines the constant, and a web request cannot.
//
// It is a zip reader rather than a call to `ZipArchive`, which is not present on every
// host, and rather than both, which would be two paths where one of them is the one that
// is never exercised. Local file headers are walked in order; the payload is built by
// `tools/build_installer.php` with sizes known, so nothing here has to understand a data
// descriptor — and an entry that has one is refused rather than guessed at.
//
// ── What it never does ─────────────────────────────────────────────────────────
//
// Report a write it has not read back, and continue over a database that refused a
// statement. Both are the same rule and it is the oldest one here: a failure that says
// nothing is worse than a failure, because the sentence somebody acts on is then about
// the wrong thing entirely (§4p, #9).

// The app this file carries, base64 of a zip. `tools/build_installer.php` replaces this
// one line; the copy that ships *beside* the files it would have carried leaves it
// empty, and the page then finds the app already unpacked and says so.
$APP_PAYLOAD = '';

// ============================================================
// The zip reader — four functions, no dependencies
// ============================================================

/**
 * Every entry in a zip, by walking its local file headers.
 *
 * @return array|null [['name' =>, 'data' =>, 'dir' => bool], …], or null with $error set
 */
function installerZipEntries($binary, &$error = null)
{
    $error  = '';
    $length = strlen($binary);
    $out    = [];
    $at     = 0;

    while ($at + 30 <= $length) {
        $signature = substr($binary, $at, 4);

        // "PK\x01\x02" is the central directory, which begins once the entries end. Any
        // other signature at an entry boundary means the file is not what it claims.
        if ($signature === "PK\x01\x02" || $signature === "PK\x05\x06") { break; }
        if ($signature !== "PK\x03\x04") {
            $error = 'the archive is damaged — an entry does not begin where the one before '
                   . 'it ended. If this file was uploaded in ASCII or text mode, upload it '
                   . 'again in binary mode.';
            return null;
        }

        $head = unpack('vflags/vmethod', substr($binary, $at + 6, 4));
        $size = unpack('Vcrc/Vcsize/Vusize', substr($binary, $at + 14, 12));
        $name = unpack('vnamelen/vextralen', substr($binary, $at + 26, 4));

        // Bit 3 says the sizes are in a descriptor *after* the data, so the data's length
        // is unknown until it has been found by scanning. The payload is built with sizes
        // known, so this cannot happen — and refusing is the only honest answer to an
        // archive somebody rebuilt with a tool that does it differently.
        if ($head['flags'] & 0x08) {
            $error = 'the archive uses streamed entries, which this installer does not read. '
                   . 'Use the app/ folder from the package instead.';
            return null;
        }

        $nameAt = $at + 30;
        $entry  = substr($binary, $nameAt, $name['namelen']);
        $dataAt = $nameAt + $name['namelen'] + $name['extralen'];
        if ($dataAt + $size['csize'] > $length) {
            $error = 'the archive ends in the middle of ' . $entry . ' — the upload did not '
                   . 'finish, or was cut short.';
            return null;
        }
        $raw = substr($binary, $dataAt, $size['csize']);

        if (substr($entry, -1) === '/') {
            $out[] = ['name' => $entry, 'data' => '', 'dir' => true];
            $at = $dataAt + $size['csize'];
            continue;
        }

        if ($head['method'] === 0) {
            $data = $raw;
        } elseif ($head['method'] === 8) {
            $data = @gzinflate($raw);
            if ($data === false) {
                $error = 'the archive could not be decompressed at ' . $entry . '.';
                return null;
            }
        } else {
            $error = 'the archive uses a compression method this installer does not read '
                   . '(' . intval($head['method']) . '). Use the app/ folder from the '
                   . 'package instead.';
            return null;
        }

        // The check that makes "it unpacked" mean something. A truncated or rewritten
        // upload usually still inflates; it does not still match its own checksum.
        if (crc32($data) !== $size['crc']) {
            $error = $entry . ' does not match the checksum it was packed with. If this file '
                   . 'was uploaded in ASCII or text mode, upload it again in binary mode.';
            return null;
        }

        $out[] = ['name' => $entry, 'data' => $data, 'dir' => false];
        $at = $dataAt + $size['csize'];
    }

    if (!$out) {
        $error = 'the archive holds no files.';
        return null;
    }
    return $out;
}

/**
 * Is this a name an archive may write to disk?
 *
 * The rule is that it names something *below* the folder we are unpacking into, and
 * nothing else. An entry called `../../private/db_credentials.php` is the whole reason
 * this function exists: an archive is data, and an unpacker that joins a path from data
 * onto a directory without looking at it will write wherever the data says. This payload
 * is built two lines away from here by a tool in the same repo — and the check is not
 * about this payload, it is about the next person to hand this file an archive.
 */
function installerSafeEntryName($name)
{
    $name = (string) $name;
    if ($name === '' || strlen($name) > 255) { return false; }
    if (strpos($name, "\0") !== false)        { return false; }
    if (strpos($name, '\\') !== false)        { return false; }
    if (substr($name, 0, 1) === '/')          { return false; }
    if (preg_match('#^[A-Za-z]:#', $name))    { return false; }
    foreach (explode('/', $name) as $part) {
        if ($part === '..') { return false; }
    }
    return true;
}

/**
 * Write every entry into a folder, and say how many files landed.
 *
 * Reads each file back before counting it, for the reason `Installer::writeFile()` does:
 * a quota, a full disk and an `open_basedir` restriction each fail in ways
 * `file_put_contents()` reports partially or not at all, and an installer that says
 * "48 files" over 46 of them has sent somebody to debug the wrong thing.
 */
function installerUnpack(array $entries, $into, &$error = null)
{
    $error   = '';
    $written = 0;

    foreach ($entries as $entry) {
        if (!installerSafeEntryName($entry['name'])) {
            $error = 'the archive holds an entry whose name points outside this folder, so '
                   . 'nothing was unpacked: ' . $entry['name'];
            return 0;
        }
    }

    foreach ($entries as $entry) {
        $target = rtrim($into, '/') . '/' . $entry['name'];
        $folder = $entry['dir'] ? $target : dirname($target);
        if (!is_dir($folder) && !@mkdir($folder, 0755, true)) {
            $error = 'could not create the folder ' . $folder;
            return $written;
        }
        if ($entry['dir']) { continue; }

        if (@file_put_contents($target, $entry['data']) === false) {
            $error = 'could not write ' . $entry['name'];
            return $written;
        }
        clearstatcache(true, $target);
        if (!is_file($target) || filesize($target) !== strlen($entry['data'])) {
            $error = $entry['name'] . ' is not on disk at the size it was written at — the '
                   . 'account may be out of space or over quota.';
            return $written;
        }
        $written++;
    }
    return $written;
}

/**
 * Delete this file, and answer whether it is really gone.
 *
 * `setup.php`'s function, moved here with its reasoning intact, because the reasoning is
 * what makes it worth having. "Delete the installer afterwards" is a job nobody is
 * assigned, so it does not get done — the old one sat on the live server for months. The
 * answer is read back from the filesystem rather than taken from `unlink()`, because "it
 * is still there" is the only outcome that needs acting on, and a write that fails while
 * reporting success is the defect this codebase has spent its whole history unpicking.
 *
 * Deleting the file the running request came from is safe: the script is compiled before
 * this line and the inode outlives the unlink, so the rest of the page still renders.
 */
function installerRemoveSelf()
{
    @unlink(__FILE__);
    clearstatcache(true, __FILE__);
    return !file_exists(__FILE__);
}

/**
 * A token only somebody who can read this server can produce.
 *
 * There is no session here and there should not be: sessions belong to the app, and the
 * app does not exist yet on the request that matters. What this protects is the one form
 * with a consequence — the administrator form, which is reachable in the window between
 * the credentials being written and the first account existing. Without a token, a page
 * on another site could post that form in somebody's browser and choose the password of
 * the administrator on their new install.
 *
 * The secret is the credentials file, which holds the database password and sits outside
 * the webroot. Anybody who can read it can already do worse than this, and nobody who
 * cannot can guess the hash. Before that file exists there is no token and no need for
 * one: the only form on offer is the database form, and posting it requires already
 * knowing the credentials it asks for.
 */
function installerToken($credentialsFile)
{
    if ($credentialsFile === '' || !is_file($credentialsFile)) { return ''; }
    return substr(hash('sha256', 'installer-form|' . $credentialsFile . '|'
                                 . (string) file_get_contents($credentialsFile)), 0, 40);
}

// ============================================================
// The page
// ============================================================

function installerMain()
{
    global $APP_PAYLOAD;

    $appDir = __DIR__;
    $bare   = '';

    // ---- Unpack, before anything is printed -----------------------------------
    // Ordered this way on purpose. Every screen below wants `Markup::text()` for the
    // values it prints, and until the app is on disk there is no `Markup::text()` — so
    // the choice is a page that escapes nothing or a page that unpacks first. Unpacking
    // is harmless and reversible by deleting a folder; printing an unescaped value is
    // not, and "it is only a version number" is how that rule gets learned twice.
    if (!is_file($appDir . '/lib/markup.php')) {
        if ($APP_PAYLOAD === '') {
            $bare = 'nopayload';
        } else {
            $binary = base64_decode($APP_PAYLOAD, true);
            if ($binary === false) {
                $bare = 'damaged';
            } else {
                $entries = installerZipEntries($binary, $why);
                if ($entries === null) {
                    $bare = 'damaged';
                } else {
                    installerUnpack($entries, $appDir, $why);
                    if (!is_file($appDir . '/lib/markup.php')) { $bare = 'unwritable'; }
                }
            }
        }
    }

    if ($bare !== '') { installerBarePage($bare); return; }

    require_once $appDir . '/lib/markup.php';
    require_once $appDir . '/lib/request_scheme.php';
    require_once $appDir . '/lib/install_paths.php';
    require_once $appDir . '/lib/installer.php';

    $credentialsFile = InstallPaths::credentialsFile($appDir);
    $token           = installerToken($credentialsFile);
    $posted          = ($_SERVER['REQUEST_METHOD'] === 'POST');
    $stage           = 'database';
    $errors          = [];
    $notes           = [];

    // ---- What state is this install in? Asked of the world, never remembered ----
    // No session and no hidden step counter: the credentials file either exists or does
    // not, the tables either exist or do not, and the account either exists or does not.
    // A wizard that remembers where it thinks it is disagrees with the server the moment
    // somebody reloads, presses back, or opens it in a second tab.
    $pdo = null;
    if ($credentialsFile !== '') {
        require_once $credentialsFile;
        $pdo = Installer::connectDatabase(DB_HOST, DB_NAME, DB_USER, DB_PASS, $why);
        if ($pdo === null) {
            $errors[] = 'The credentials file is there, but the database refused the '
                      . 'connection: ' . $why;
        } else {
            $installer = new Installer($pdo);
            $count     = $installer->accountCount();
            if     ($count > 0)  { $stage = 'finished'; }
            elseif ($count === 0) { $stage = 'admin'; }
            else                  { $stage = 'schema'; }
        }
    }

    // ---- Acting on a form -----------------------------------------------------
    if ($posted && ($_POST['step'] ?? '') === 'database') {
        $stage = installerDoDatabase($appDir, $errors, $notes);
        if ($stage === 'admin' || $stage === 'schema') {
            $credentialsFile = InstallPaths::credentialsFile($appDir);
            $token           = installerToken($credentialsFile);
        }
    } elseif ($posted && ($_POST['step'] ?? '') === 'admin') {
        if ($token === '' || !hash_equals($token, (string) ($_POST['token'] ?? ''))) {
            $errors[] = 'That form did not come from this page. Reload and try again.';
        } elseif ($pdo === null) {
            $errors[] = 'The database is not reachable, so no account was created.';
        } else {
            $result = (new Installer($pdo))->createFirstAdmin(
                $_POST['username'] ?? '', $_POST['email'] ?? '',
                $_POST['password'] ?? '', $_POST['confirm'] ?? '',
                $_POST['venue'] ?? ''
            );
            if ($result->isOk()) {
                $stage   = 'finished';
                $notes[] = $result->message();
            } else {
                $errors[] = $result->message();
                foreach ($result->detail() as $line) { $errors[] = $line; }
            }
        }
    }

    installerPage($appDir, $stage, $errors, $notes, $token, $pdo);
}

/**
 * The database step: reach it, build it, and write the credentials down.
 *
 * The order is the order the failures need to arrive in. Reach the server before naming
 * a database, because "wrong password" and "no such database" are the same refused
 * connection when both are asked at once and only the second one this page can offer to
 * fix. Write the credentials after the connection is proved and before the schema is
 * applied, so a schema that fails half way leaves an install that can be reloaded rather
 * than one that has forgotten what it just learned.
 */
function installerDoDatabase($appDir, array &$errors, array &$notes)
{
    $host = trim((string) ($_POST['host'] ?? ''));
    $name = trim((string) ($_POST['name'] ?? ''));
    $user = trim((string) ($_POST['user'] ?? ''));
    $pass = (string) ($_POST['pass'] ?? '');

    if ($host === '' || $name === '' || $user === '') {
        $errors[] = 'The host, database name and user are all needed.';
        return 'database';
    }

    $pdo = Installer::connectDatabase($host, $name, $user, $pass, $why);
    if ($pdo === null) {
        $server = Installer::connectServer($host, $user, $pass, $serverWhy);
        if ($server === null) {
            $errors[] = 'The server refused the connection: ' . $serverWhy;
            return 'database';
        }
        // The server is reachable and the database is not. Offering to create it is
        // worth doing and relying on it is not: on cPanel this fails, because cPanel
        // owns database creation and the user it issues has no privilege to do it.
        $notes[] = 'The server answered, but the database "' . $name . '" could not be '
                 . 'opened. Trying to create it.';
        if (!Installer::createDatabase($server, $name, $createWhy)) {
            $errors[] = 'The database "' . $name . '" does not exist and this user may not '
                      . 'create one: ' . $createWhy;
            $errors[] = 'On cPanel this is expected — it owns database creation. Go to '
                      . 'MySQL Databases, create the database, create a user, add the user '
                      . 'to the database with All Privileges, then come back. Type the full '
                      . 'name including the account prefix.';
            return 'database';
        }
        $pdo = Installer::connectDatabase($host, $name, $user, $pass, $why);
        if ($pdo === null) {
            $errors[] = 'The database was created and then could not be opened: ' . $why;
            return 'database';
        }
        $notes[] = 'The database "' . $name . '" was created.';
    }

    // A report, not a gate. Nothing branches on it: the engine answers this properly a
    // few lines below, by refusing statements. This is here so that somebody meeting
    // eleven "command denied" errors has already been told which box to tick.
    $missing = [];
    try {
        $grants  = $pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
        $missing = Installer::missingPrivileges(is_array($grants) ? $grants : []);
    } catch (Throwable $e) {
        $missing = [];
    }
    if ($missing) {
        $notes[] = 'This user does not appear to hold ' . implode(', ', $missing)
                 . '. If the tables below fail, that is why — the app needs '
                 . implode(', ', Installer::PRIVILEGES) . ' and nothing more.';
    }

    $target = Installer::credentialsTarget($appDir);
    $source = Installer::credentialsSource($appDir, ['host' => $host, 'name' => $name,
                                                     'user' => $user, 'pass' => $pass]);
    if (Installer::writeFile($target, $source, $writeWhy)) {
        $notes[] = 'The credentials are saved at ' . $target . ', outside the webroot.';
    } else {
        $errors[] = 'The credentials could not be saved: ' . $writeWhy;
        $errors[] = 'Create that file by hand with the contents shown below, then reload '
                  . 'this page. Do not put it beside the app instead.';
        $errors[] = $source;
        return 'database';
    }

    $script = @file_get_contents($appDir . '/schema.sql');
    if ($script === false) {
        $errors[] = 'schema.sql is not beside the app, so the tables cannot be created.';
        return 'schema';
    }
    if (!applySchemaScript($pdo, $script, $failures)) {
        $errors[] = 'The database refused ' . count($failures) . ' of the statements that '
                  . 'build the tables. Nothing further was attempted.';
        foreach ($failures as $failure) {
            $errors[] = $failure['error'];
        }
        return 'schema';
    }
    $notes[] = 'The tables are created.';

    // And then convergence, once, which is what finishes the job rather than leaving it
    // to whoever signs in first. `schema.sql` builds nine tables and seeds a Brand; it
    // does **not** seed a Display, and `seedLegacyDisplay()` in `signageSchemaPlan()` is
    // what does — so without this line a brand-new install has no sign until an admin has
    // signed in once, and this page could not print the address to point a television at.
    //
    // The standing rule is that this is called at the top of an entry point and never
    // deeper, because DDL commits an open transaction and says nothing about it. This is
    // an entry point and there is no transaction open: the credentials are written, the
    // tables are made, and the one transaction in this install — the venue and the
    // account — is not opened until the next request.
    try {
        ensureSignageSchema($pdo);
        $notes[] = 'The first display is set up.';
    } catch (Throwable $e) {
        $errors[] = 'The tables were created and the first display was not: '
                  . $e->getMessage();
        return 'schema';
    }

    $accounts = (new Installer($pdo))->accountCount();
    if ($accounts < 0) {
        $errors[] = 'The statements were accepted and the users table is still not there.';
        return 'schema';
    }
    return ($accounts > 0) ? 'finished' : 'admin';
}

/**
 * The page shown before the app is on disk, in literal sentences only.
 *
 * Nothing here interpolates a value, and that is the constraint rather than a style: at
 * this point `Markup::text()` does not exist, so there is no correct way to print one.
 * Four states, four fixed paragraphs, and each of them names the way out.
 */
function installerBarePage($which)
{
    installerHead('Installer');
    echo '<h1>The app is not unpacked</h1>';
    if ($which === 'nopayload') {
        echo '<p class="stop">This copy of the installer carries no app inside it, and the '
           . 'app is not in this folder either. This is the copy that ships <em>beside</em> '
           . 'the files — upload the contents of the package&rsquo;s <code>app/</code> '
           . 'folder here, then reload this page.</p>';
    } elseif ($which === 'damaged') {
        echo '<p class="stop">The app inside this file did not survive the upload. The usual '
           . 'cause is an FTP client set to ASCII or text mode, which rewrites line endings '
           . 'inside data it believes is text. Upload this file again in binary mode, or use '
           . 'cPanel&rsquo;s File Manager, which always does.</p>';
    } else {
        echo '<p class="stop">This folder could not be written to, so the app could not be '
           . 'unpacked into it. Set the folder to 755 and make sure it is owned by the '
           . 'account the web server runs as, then reload this page.</p>';
    }
    echo '<p>Neither the database nor any file outside this folder has been touched.</p>';
    installerFoot();
}

/** The one screen, in whichever of its four states this install is in. */
function installerPage($appDir, $stage, array $errors, array $notes, $token, ?PDO $pdo = null)
{
    installerHead('Install');

    echo '<h1>Install the display system</h1>';
    installerSteps($stage);

    foreach ($notes as $note) {
        echo '<p class="note">' . Markup::text($note) . '</p>';
    }
    foreach ($errors as $error) {
        echo '<p class="stop">' . Markup::text($error) . '</p>';
    }

    if ($stage === 'database' || $stage === 'schema') {
        installerPreflight($appDir);
    }

    if ($stage === 'database' || $stage === 'schema') {
        installerDatabaseForm();
    } elseif ($stage === 'admin') {
        installerAdminForm($token);
    } else {
        installerFinished($appDir, $pdo);
    }

    installerFoot();
}

/** What this machine can and cannot do, read here and decided in lib/installer.php. */
function installerPreflight($appDir)
{
    $private = dirname($appDir, 2);
    $checks  = Installer::preflight([
        'php'             => PHP_VERSION,
        'pdoMysql'        => extension_loaded('pdo_mysql'),
        'zlib'            => function_exists('gzinflate'),
        'appWritable'     => is_writable($appDir),
        'privateWritable' => is_dir($private) && is_writable($private),
        'https'           => RequestScheme::isSecure($_SERVER),
    ]);

    echo '<h2>This server</h2><dl class="checks">';
    foreach ($checks as $check) {
        echo '<dt class="' . Markup::text($check->verdict()) . '">'
           . Markup::text($check->name()) . '</dt><dd>'
           . Markup::text($check->sentence()) . '</dd>';
    }
    echo '</dl>';

    if (Installer::blocked($checks)) {
        echo '<p class="stop">Fix what is marked above before going on. The form below is '
           . 'still here because a host can be wrong about one of these, but an install over '
           . 'a refusal is one that half works.</p>';
    }
}

function installerDatabaseForm()
{
    echo '<h2>The database</h2>'
       . '<p>Create the database and its user in your host&rsquo;s control panel first, then '
       . 'type them here. On cPanel that is <strong>MySQL Databases</strong>, and the names '
       . 'carry your account prefix — type the full name.</p>'
       . '<form method="post"><input type="hidden" name="step" value="database">'
       . '<label>Host<input name="host" value="localhost" required></label>'
       . '<label>Database name<input name="name" required autofocus></label>'
       . '<label>Database user<input name="user" required></label>'
       . '<label>Database password<input name="pass" type="password"></label>'
       . '<button type="submit">Connect and create the tables</button>'
       . '</form>';
}

function installerAdminForm($token)
{
    echo '<h2>Your account, and the venue</h2>'
       . '<p>Every display wears a <strong>Brand</strong> — its typography, palette and '
       . 'logo. Naming the venue names the first one. You can add more later, one per '
       . 'venue.</p>'
       . '<form method="post"><input type="hidden" name="step" value="admin">'
       . '<input type="hidden" name="token" value="' . Markup::text($token) . '">'
       . '<label>Username<input name="username" required autofocus></label>'
       . '<label>Email address<input name="email" type="email" required></label>'
       . '<label>Password<input name="password" type="password" required '
       . 'minlength="' . intval(Installer::PASSWORD_MIN) . '"></label>'
       . '<label>Password again<input name="confirm" type="password" required></label>'
       . '<label>Venue name<input name="venue" placeholder="Salmon House" required '
       . 'maxlength="' . intval(BrandStore::NAME_MAX) . '"></label>'
       . '<button type="submit">Create the administrator</button>'
       . '</form>';
}

/** Done — so this file goes, and then says what is left to check. */
function installerFinished($appDir, ?PDO $pdo = null)
{
    $gone = installerRemoveSelf();

    echo '<h2>Installed</h2>';
    echo $gone
        ? '<p class="note">This installer has deleted itself. Nothing to remember.</p>'
        : '<p class="stop">This installer could not delete itself — the host did not allow '
        . 'the write. <strong>Delete install.php from the server by hand, now.</strong> '
        . 'While it is still being served, pointing the app at an empty database turns it '
        . 'back into a public form for creating an administrator.</p>';

    echo '<p><a href="login.php">Sign in &rarr;</a></p>';

    // The sign's own address, from the Display the schema seeded. Named rather than
    // described, because ADR-0003 is that every viewer URL carries its Display and a
    // person about to configure a television needs the whole string.
    // Through `DisplayStore`, never a query of its own. Invariant 2 is that one module
    // writes SQL against that table, and this page asking it directly would have been the
    // second reader — which is how a page comes to disagree with the app about which sign
    // is which.
    $tag = '';
    if ($pdo !== null) {
        try {
            $signs = (new DisplayStore($pdo))->all();
            if ($signs) { $tag = $signs[0]->tag(); }
        } catch (Throwable $e) {
            $tag = '';
        }
    }

    echo '<h2>Four things to check, then you are done</h2><ol class="todo">';
    echo '<li><strong>Ask for install.php again.</strong> You want a <code>404</code> — not '
       . 'this page, and not a form.</li>';
    echo '<li><strong>Ask for <code>schema.sql</code>.</strong> You want a <code>403</code>: '
       . 'that proves the .htaccess at the top of this folder is in place and this host '
       . 'honours it.</li>';
    echo '<li><strong>Ask for <code>lib/schema.php</code>.</strong> You want a '
       . '<code>403</code> too. A <code>404</code> means the dotfile in <code>lib/</code> '
       . 'never arrived; a <code>200</code> means it arrived and Apache is not reading it, '
       . 'and every module in that folder is then readable in a browser.</li>';
    echo '<li><strong>Open Admin Panel &rarr; Site Branding</strong> and set the site name '
       . 'and the address mail is sent from. Left at its default, password-reset codes are '
       . 'sent from a domain this server does not own and are dropped as spam — and so is '
       . 'the alert that would have told you.</li>';
    echo '</ol>';

    if ($tag !== '') {
        echo '<h2>The screen</h2><p>Your first display is called '
           . Markup::text($tag) . '. Rename it in Admin Panel &rarr; Displays — the name it '
           . 'starts with is not a setting — then point the television at:</p>'
           . '<p><code>viewer.php?display=' . Markup::text($tag) . '</code></p>'
           . '<p>No login. It re-reads the layout every 30 seconds.</p>';
    }
}

// ============================================================
// Chrome
// ============================================================
// Deliberately its own small stylesheet rather than the app's. `SiteChrome` reads the
// store's colours out of `branding_config.php`, and on this request there is no store yet
// — the whole page is about arranging for there to be one.

function installerHead($title)
{
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . Markup::text($title) . '</title><style>'
       . '*{box-sizing:border-box;margin:0;padding:0}'
       . 'body{background:#1a252f;color:#20303c;font:16px/1.6 system-ui,sans-serif;padding:32px 16px}'
       . '.card{background:#fff;max-width:640px;margin:0 auto;padding:32px;border-radius:10px;'
       . 'box-shadow:0 8px 32px rgba(0,0,0,.3)}'
       . 'h1{font-size:22px;margin-bottom:6px}h2{font-size:15px;text-transform:uppercase;'
       . 'letter-spacing:.08em;color:#3d5566;margin:28px 0 12px;padding-bottom:6px;'
       . 'border-bottom:1px solid #dfe5ea}'
       . 'p{margin-bottom:12px}code{font:13px ui-monospace,monospace;background:#eef2f5;'
       . 'padding:1px 5px;border-radius:3px;word-break:break-all}'
       . 'label{display:block;font-weight:600;font-size:13px;color:#4a5b68;margin-bottom:14px}'
       . 'input{display:block;width:100%;margin-top:4px;padding:10px 12px;border:1px solid #ccd4da;'
       . 'border-radius:5px;font-size:15px;font-weight:400;color:#20303c}'
       . 'button{width:100%;padding:12px;background:#27ae60;color:#fff;border:0;border-radius:5px;'
       . 'font-size:15px;font-weight:700;cursor:pointer}button:hover{background:#219a52}'
       . '.note{background:#e8f5ec;border:1px solid #b8dfc6;border-radius:5px;padding:9px 12px;'
       . 'font-size:14px}'
       . '.stop{background:#fdecea;border:1px solid #e9a79f;border-radius:5px;padding:9px 12px;'
       . 'font-size:14px;color:#8c2f22}'
       . '.steps{display:flex;gap:6px;flex-wrap:wrap;font-size:12px;margin-bottom:18px;'
       . 'color:#7c8b95}.steps span{padding:2px 8px;border:1px solid #dfe5ea;border-radius:99px}'
       . '.steps .at{background:#20303c;border-color:#20303c;color:#fff}'
       . '.checks{font-size:14px}.checks dt{font-weight:700;margin-top:10px}'
       . '.checks dd{color:#4a5b68}.checks dt.ok::before{content:"\\2713 ";color:#1c6b46}'
       . '.checks dt.warn::before{content:"\\26A0 ";color:#8a5406}'
       . '.checks dt.stop::before{content:"\\2715 ";color:#8c2f22}'
       . '.todo{margin:0 0 12px 20px;font-size:15px}.todo li{margin-bottom:8px}'
       . 'a{color:#23648f}'
       . '</style></head><body><div class="card">';
}

function installerSteps($stage)
{
    $order = ['database' => 'Database', 'schema' => 'Tables',
              'admin' => 'Administrator', 'finished' => 'Done'];
    echo '<div class="steps">';
    foreach ($order as $key => $label) {
        echo '<span class="' . ($key === $stage ? 'at' : '') . '">' . Markup::text($label)
           . '</span>';
    }
    echo '</div>';
}

function installerFoot()
{
    echo '</div></body></html>';
}

// The suite requires this file to test the four functions above without running an
// install. Nothing else in the repo defines the constant and a web request cannot.
if (!defined('INSTALLER_INSPECT')) { installerMain(); }
