<?php
// ============================================================
// REHEARSAL — installing from nothing, against a real MySQL
// ============================================================
//   php tools/rehearse_install.php --host=127.0.0.1 --db=NAME \
//        --user=USER --pass=PASS --confirm-copy
//
//   --port=3307 too, if the server is not on the default port.
//
// The installer's own gate (`tools/build_installer.php`) is about a file list, and the
// suite's checks are about the decisions in `lib/installer.php` — every one of them pure,
// on SQLite, because that is what a container has. Neither of them has ever built a
// database. `schema.sql` is MySQL-only, so the one thing an installer is *for* was the one
// thing nothing here could ask.
//
// This is that question. It starts with an empty database and ends with an administrator,
// a named venue and a Display with a tag, on the engine a shop runs — and it asks after
// every step rather than at the end, because "the install worked" is exactly the kind of
// summary that hides which half of it did.
//
// **It is not the whole answer and does not pretend to be.** It drives `Installer` and
// `applySchemaScript()` directly, so what it proves is that the steps work on real MySQL.
// What it cannot see is a browser, an FTP client, or a person reading a sentence — the
// self-extraction, the form, and whether `INSTALL.md` is followable are still an owed walk
// on a host with nothing on it (§4bo). What it removes is the possibility that the
// database half has never run at all.
//
// CLI only, and never reachable from the browser (tools/.htaccess).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/lib/installer.php';

$opts = getopt('', ['host:', 'port:', 'db:', 'user:', 'pass:', 'confirm-copy']);

// The same guard as `rehearse_phase1.php`, for a sharper reason: that one writes to a
// database, and this one *creates and empties* one. A flag is a poor lock and it is the
// right one here — it makes the destructive thing a sentence somebody typed rather than
// the default behaviour of a command whose name sounds like a test.
if (!isset($opts['confirm-copy'])) {
    fwrite(STDERR, "Refusing to run without --confirm-copy.\n\n"
        . "This builds a database from nothing and will empty the one it is pointed at.\n"
        . "Point it at a throwaway name, never a database with a sign behind it, then\n"
        . "pass --confirm-copy.\n");
    exit(2);
}
foreach (['host', 'db', 'user'] as $needed) {
    if (!isset($opts[$needed]) || $opts[$needed] === '') {
        fwrite(STDERR, "--$needed is required.\n");
        exit(2);
    }
}

$host = $opts['host'] . (isset($opts['port']) ? ';port=' . $opts['port'] : '');
$db   = (string) $opts['db'];
$user = (string) $opts['user'];
$pass = isset($opts['pass']) ? (string) $opts['pass'] : '';

$checked  = 0;
$failures = [];

function step($title)
{
    echo "\n── " . $title . " ──\n";
}

function ok($line)
{
    echo "  ok   $line\n";
}

function bad($line, $summary)
{
    global $failures;
    echo "  FAIL $line\n";
    $failures[] = $summary;
}

function is_it($condition, $line, $summary = null)
{
    global $checked;
    $checked++;
    if ($condition) { ok($line); } else { bad($line, $summary === null ? $line : $summary); }
}

echo "Rehearsing an install from nothing\n";

// ---- The server, before any database is named ----------------------------------
step('The server');
$server = Installer::connectServer($host, $user, $pass, $why);
is_it($server !== null, 'the server answers' . ($server === null ? ' — ' . $why : ''));
if ($server === null) { echo "\nNothing further can be asked.\n"; exit(1); }

// The privilege report, on a user whose privileges are known. In CI this is root, so the
// interesting assertion is the shape of the answer rather than its content: a report that
// named something missing on a user holding everything would be a report nobody could
// read on a user holding almost everything.
$grants  = $server->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
$missing = Installer::missingPrivileges(is_array($grants) ? $grants : []);
is_it($missing === [], 'the privilege report finds nothing missing for this user'
      . ($missing ? ' — it names ' . implode(', ', $missing) : ''));

// ---- An empty database ---------------------------------------------------------
step('An empty database');

// The refusal that keeps this off anything real. A database holding a layout is a
// database with a sign behind it, and no flag makes emptying that acceptable.
$existing = Installer::connectDatabase($host, $db, $user, $pass, $why);
if ($existing !== null) {
    $rows = -1;
    try {
        $rows = intval($existing->query('SELECT COUNT(*) FROM canvas_elements')->fetchColumn());
    } catch (Throwable $e) {
        $rows = -1;
    }
    if ($rows > 0) {
        fwrite(STDERR, "\nRefusing to continue: " . $db . " holds " . $rows
            . " canvas elements, which is a sign's layout.\nPoint this at a throwaway name.\n");
        exit(2);
    }
    $existing = null;
}
$server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
is_it(Installer::createDatabase($server, $db, $createWhy),
      'a database is created' . ($createWhy !== '' ? ' — ' . $createWhy : ''));

$pdo = Installer::connectDatabase($host, $db, $user, $pass, $why);
is_it($pdo !== null, 'and can be opened' . ($pdo === null ? ' — ' . $why : ''));
if ($pdo === null) { echo "\nNothing further can be asked.\n"; exit(1); }

$installer = new Installer($pdo);
is_it($installer->accountCount() === -1,
      'an empty database reports -1 accounts rather than 0 — "no table" and "no accounts" '
      . 'are the two states the installer branches on');

// ---- The tables -----------------------------------------------------------------
step('The tables, from schema.sql');
$script = (string) file_get_contents($root . '/schema.sql');
$applied = applySchemaScript($pdo, $script, $schemaFailures);
is_it($applied, 'every statement in schema.sql is accepted');
foreach ($schemaFailures as $failure) {
    echo '       ' . $failure['error'] . "\n";
}
if (!$applied) { echo "\nNothing further can be asked.\n"; exit(1); }

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
foreach (['users', 'canvas_elements', 'assets', 'brands', 'block_styles',
          'workspace_themes', 'displays', 'display_permissions', 'password_resets'] as $table) {
    is_it(in_array($table, $tables, true), 'the ' . $table . ' table is there');
}
// Through the modules that own these tables, not with queries of this file's own.
// `rehearse_phase1.php` is on the exemption list for `displays` and `brands` because it
// checks the *structure* those modules cannot describe; this file only ever asks what is
// in them, and every one of those questions already has a method.
is_it(count((new BrandStore($pdo))->all()) === 1,
      'and one Brand is seeded, because displays.brand_id is NOT NULL and a sign has to '
      . 'have something to wear (ADR-0011)');
is_it((new DisplayStore($pdo))->count() === 0,
      'and no Display yet — schema.sql does not seed one, which is why the installer '
      . 'converges before it says it is finished');
is_it($installer->accountCount() === 0,
      'the users table is there and empty, which is the state the administrator form '
      . 'is offered in');

// ---- Convergence, which is what finishes the install ---------------------------
step('Convergence');
$converged = true;
try {
    ensureSignageSchema($pdo);
} catch (Throwable $e) {
    $converged = false;
    echo '       ' . $e->getMessage() . "\n";
}
is_it($converged, 'convergence runs against a database schema.sql just built');
$signs = (new DisplayStore($pdo))->all();
is_it(count($signs) === 1, 'and seeds exactly one Display, so a fresh install has a sign');
is_it(count($signs) === 1 && $signs[0]->tag() !== '',
      'with a tag, which is the address a television is pointed at (ADR-0003)');
is_it(count($signs) === 1 && intval($signs[0]->brandId()) > 0,
      'and wearing a Brand rather than nothing');

// Twice, because the second run is the one that says convergence is gated rather than
// merely idempotent — and because the per-request latch has to be cleared for the
// question to be asked at all.
SchemaLatch::forget();
$again = true;
try {
    ensureSignageSchema($pdo);
} catch (Throwable $e) {
    $again = false;
    echo '       ' . $e->getMessage() . "\n";
}
is_it($again, 'and runs a second time without complaint');
is_it(count((new DisplayStore($pdo))->all()) === 1,
      'without seeding a second Display, which is the check that would catch a gate '
      . 'reading a row count backwards');

// ---- The credentials file ------------------------------------------------------
step('The credentials, outside the webroot');
$fakeApp = sys_get_temp_dir() . '/lbm-rehearse-' . getmypid() . '/public_html/signs';
@mkdir($fakeApp, 0755, true);
$target = Installer::credentialsTarget($fakeApp);
$source = Installer::credentialsSource($fakeApp, ['host' => $opts['host'], 'name' => $db,
                                                  'user' => $user, 'pass' => $pass]);
is_it(Installer::writeFile($target, $source, $writeWhy),
      'the credentials file is written and read back'
      . ($writeWhy !== '' ? ' — ' . $writeWhy : ''));
is_it(InstallPaths::credentialsFile($fakeApp) === $target,
      'and it is the file the app would find from its own folder — which is the whole '
      . 'point of writing it two directories up rather than beside the app');
$lint = [];
$lintStatus = 0;
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($target) . ' 2>&1',
     $lint, $lintStatus);
is_it($lintStatus === 0,
      'and PHP can parse it, with this password in it — a parse error here is a file '
      . 'outside the webroot that nobody thinks to look at');

// And then the question the file was stamped to answer, asked in a *separate process* off
// the file that is really on disk. The unit checks in `selftest_layout.php` hand
// `credentialsOwnership()` its three facts; this is the only place the middle one comes
// from a file an installer wrote, read by an interpreter that was not there when it was
// written — which is the half that would break if the stamp were spelled two different
// ways, or written as a comment, or lost to the quoting.
$probe = function ($appDir) use ($target) {
    $code = 'require ' . var_export($target, true) . ';'
          . 'require ' . var_export(dirname(__DIR__) . '/lib/installer.php', true) . ';'
          . 'echo Installer::credentialsOwnership(' . var_export($appDir, true) . ', '
          . var_export($target, true) . ', defined(Installer::STAMP) '
          . '? constant(Installer::STAMP) : null);';
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out);
    return trim(implode('', $out));
};
// The same probe, with the file as a parameter — the block at the end of this rehearsal
// asks about a *different* file, and a closure that had captured one would have answered
// about the wrong install while looking like it worked.
$probe2 = function ($appDir, $file) {
    $code = 'require ' . var_export($file, true) . ';'
          . 'require ' . var_export(dirname(__DIR__) . '/lib/installer.php', true) . ';'
          . 'echo Installer::credentialsOwnership(' . var_export($appDir, true) . ', '
          . var_export($file, true) . ', defined(Installer::STAMP) '
          . '? constant(Installer::STAMP) : null);';
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1', $out);
    return trim(implode('', $out));
};

is_it($probe($fakeApp) === Installer::OWN,
      'a fresh process reads that file and agrees it belongs to the install that wrote it');
is_it($probe(dirname($fakeApp) . '/signs-test') === Installer::BORROWED,
      'and that it does not belong to the folder beside it — which is the install that used '
      . 'to connect to this database and report itself finished');

@unlink($target);
@rmdir(dirname($target));
@rmdir($fakeApp);
@rmdir(dirname($fakeApp));
@rmdir(dirname(dirname($fakeApp)));

// ---- The first administrator, the venue, and the store's own identity -----------
step('The first administrator');

// The logo goes in first, the way the page does it: `install.php` moves the file and asks
// `AssetLibrary` for the row, then hands the id down. Nothing is uploaded here — what this
// leg is for is the *chain* on a real engine, where `brands.logo_asset_id` is a foreign key
// into `assets` and SQLite's version of that check is a suggestion.
$logoId = (new AssetLibrary($pdo))->create('image', 'uploads/install_rehearsal.png',
                                           Installer::LOGO_LABEL);
is_it($logoId > 0, 'the logo row is created through the module that owns the table');

$brandingDir = sys_get_temp_dir() . '/lbm-rehearse-branding-' . getmypid();
@mkdir($brandingDir, 0700, true);
$config = new BrandingConfig($brandingDir);

$made = $installer->createFirstAdmin('rehearsal', 'rehearsal@example.com',
                                     'a-long-enough-password', 'a-long-enough-password',
                                     'Rehearsal Venue',
                                     ['site_name' => 'Rehearsal Store',
                                      'mail_from' => 'signs@example.com',
                                      'nav_bg'    => '#2E5C3A',
                                      'bg_val'    => '#101820',
                                      'logo_asset_id' => $logoId,
                                      'logo_path'     => 'uploads/install_rehearsal.png'],
                                     $config);
is_it($made->isOk(), 'the administrator, the venue and the store details are created'
      . ($made->isOk() ? '' : ' — ' . $made->message()));
foreach ($made->detail() as $line) { echo '       ' . $line . "\n"; }

$accounts = new AccountStore($pdo);
is_it($accounts->total() === 1, 'exactly one account exists');
$row = $accounts->findByNameOrEmail('rehearsal', '');
is_it($row !== null && $row['role'] === 'admin', 'and it is an administrator');
$brands = (new BrandStore($pdo))->all();
is_it(count($brands) === 1 && $brands[0]->name() === 'Rehearsal Venue',
      'the seeded Brand is renamed to the venue rather than a second one being made — '
      . '"Store Brand" beside a real venue reads like an install that stopped half way');
is_it(count($brands) === 1 && $brands[0]->logoAssetId() === $logoId,
      'wearing the logo, through the foreign key MySQL actually enforces');
is_it(count($brands) === 1 && $brands[0]->backgroundValue() === '#101820',
      'and the background a sign wearing it starts with');

$brandingSource = @file_get_contents($config->path());
is_it(is_string($brandingSource) && strpos((string) $brandingSource, "'Rehearsal Store'") !== false,
      'branding_config.php was written with the store name in it');
is_it(is_string($brandingSource) && strpos((string) $brandingSource, "'#ffffff'") !== false,
      'and with the text colour derived from the navigation colour rather than asked for');
@unlink($config->path());
@rmdir($brandingDir);

// The refusal that makes the installer safe to leave on a server for the minutes before
// it deletes itself: once an account exists there is no first administrator to create.
$twice = $installer->createFirstAdmin('second', 'second@example.com',
                                      'a-long-enough-password', 'a-long-enough-password',
                                      'Another Venue');
is_it(!$twice->isOk(), 'a second first-administrator is refused');
is_it(strpos($twice->message(), 'Sign in') !== false,
      'and the refusal says what to do instead');
is_it($accounts->total() === 1, 'and nothing was created by the attempt');

// ---- The state that reached the store, on a database that really has an admin ----
// §4br. The unit checks hand `mustAskWhose()` a number; this asks it about a database
// whose administrator was created two steps above, through an *unstamped* shared file of
// the shape every credentials file written before the stamp existed has — including the
// live one. Both halves have to be real for this to mean anything: an unstamped file on
// disk read by a process that did not write it, and an account count from MySQL.
step('A shared file over a database that already has an administrator');

$sharedApp = sys_get_temp_dir() . '/lbm-rehearse-shared-' . getmypid() . '/public_html/first';
@mkdir($sharedApp, 0755, true);
$candidates = InstallPaths::credentialsCandidates($sharedApp);
$sharedFile = $candidates[count($candidates) - 1];
@mkdir(dirname($sharedFile), 0700, true);

// A pre-stamp file, made the way one really is: the installer's own output with the stamp
// line taken back out. Written from the source rather than typed here, so a change to the
// file's shape cannot leave this fixture describing a file no installer ever wrote.
$stamped   = Installer::credentialsSource($sharedApp, ['host' => $opts['host'], 'name' => $db,
                                                       'user' => $user, 'pass' => $pass]);
$unstamped = preg_replace('/^define\(\'' . Installer::STAMP . '\'.*$/m', '', $stamped);
is_it($unstamped !== $stamped && strpos($unstamped, "\ndefine('" . Installer::STAMP) === false,
      'a pre-stamp credentials file is built by removing the line no such file has');
is_it(Installer::writeFile($sharedFile, $unstamped, $sharedWhy),
      'and written where two installs on one account both walk up to it'
      . ($sharedWhy !== '' ? ' — ' . $sharedWhy : ''));

$secondFolder = dirname($sharedApp) . '/second';
is_it($probe2($secondFolder, $sharedFile) === Installer::UNKNOWN,
      'a second folder reads it in a fresh process and cannot tell whose it is — which is '
      . 'the answer, not a failure to get one');
is_it(Installer::mustAskWhose(Installer::UNKNOWN, $accounts->total()),
      'and over this database — which has the administrator created above — that is asked '
      . 'about rather than adopted, because adopting it is what reported a second copy of '
      . 'the app "Installed" against the first one\'s signs');
is_it(!Installer::mustAskWhose(Installer::OWN, $accounts->total()),
      'while the same database behind a file that names this folder is just an install '
      . 'being opened again');

@unlink($sharedFile);
@rmdir(dirname($sharedFile));
@rmdir($sharedApp);
@rmdir(dirname($sharedApp));
@rmdir(dirname(dirname($sharedApp)));

step('The tables, from credentials that are already on disk');

// The other way in, and the one `INSTALL.md` has recommended all along: the credentials
// file was written by hand or by a previous attempt, it works, and the database behind it
// is empty. Everything above drives `Installer` directly; this drives the page's own
// `installerBuildTables()`, which is what that state now reaches — because until §4bs it
// reached the four-field form instead and was told nothing, and filling that form in
// rewrites the credentials file from whatever was typed.
define('INSTALLER_INSPECT', true);
require_once $root . '/install.php';

$server->exec('DROP DATABASE IF EXISTS `' . $db . '`');
$server->exec('CREATE DATABASE `' . $db . '`');
$onDisk = Installer::connectDatabase($host, $db, $user, $pass, $freshWhy);
is_it($onDisk !== null,
      'the database is emptied again' . ($onDisk === null ? ' — ' . $freshWhy : ''));

$handApp  = sys_get_temp_dir() . '/lbm-rehearse-hand-' . getmypid() . '/public_html/newsign';
@mkdir($handApp, 0755, true);
// A folder shaped like a real install rather than the repo: `schema.sql` inside it, and a
// `private/` two levels up. `installerBuildTables()` is handed *this* as its `$appDir`, so
// a line added to it that wrote the credentials file would write the file the check below
// reads. Handed the repo root instead, that check asserted a property of a function with no
// code for it — which is invariant 30's "a check that cannot fail" (§4bs).
@copy($root . '/schema.sql', $handApp . '/schema.sql');
$handFile = Installer::credentialsTarget($handApp);
@mkdir(dirname($handFile), 0700, true);
Installer::writeFile($handFile, Installer::credentialsSource(
    $handApp, ['host' => $opts['host'], 'name' => $db, 'user' => $user, 'pass' => $pass]
), $handWhy);
is_it(InstallPaths::credentialsFile($handApp) === $handFile
      && Installer::credentialsOwnership($handApp, $handFile, InstallPaths::installName($handApp))
         === Installer::OWN,
      'a folder with a credentials file of its own is not asked whose database it is'
      . ($handWhy !== '' ? ' — ' . $handWhy : ''));

// The one thing this harness has to do that a server does not: clear the per-request
// latch. Convergence refuses a second time on one request by design, and this process
// converged twice at the step above — so without this, `installerBuildTables()` reports
// "The first display is set up" over a `displays` table with nothing in it, and the
// difference is the harness rather than the page. Which is worth having found: the check
// below is what noticed, and it is the same check that would notice the page skipping the
// seed for a reason that *was* the page's.
SchemaLatch::forget();

$before   = (string) file_get_contents($handFile);
$handNotes = [];
$handErrs  = [];
$reached   = installerBuildTables($onDisk, $handApp, $handErrs, $handNotes);
is_it($reached === 'admin',
      'and pressing the one button on that screen builds the tables and lands on the '
      . 'administrator form' . ($handErrs ? ' — ' . implode(' / ', $handErrs) : ''));
is_it(in_array('The tables are created.', $handNotes, true)
      && in_array('The first display is set up.', $handNotes, true),
      'reporting both halves — the tables, and the Display that `schema.sql` does not seed '
      . 'and convergence does');
is_it((string) file_get_contents($handFile) === $before,
      'and the credentials file is byte-for-byte what it was: this route writes nothing '
      . 'above the webroot, which is the whole reason it does not ask for the four values '
      . 'that are already in that file');
// Through the seam, not `SELECT COUNT(*) FROM displays` — which is invariant 35 and which
// `check_invariants.php` caught here, in a tool, exactly as it is meant to.
$built = -1;
try {
    $built = count((new DisplayStore($onDisk))->all());
} catch (Throwable $e) {
    $built = -1;
}
is_it($built === 1, 'with one Display in the database, the way the typed route leaves it');

@unlink($handFile);
@rmdir(dirname($handFile));
@unlink($handApp . '/schema.sql');
@rmdir($handApp);
@rmdir(dirname($handApp));
@rmdir(dirname(dirname($handApp)));

// ---- And that every one of them ran --------------------------------------------
// The anchor `rehearse_phase1.php` did not have until §4bk, for the reason that write-up
// is about: this printed "clean" whether it ran every check or stopped after four.
//
// **A sum of declared terms, not a number**, and it is written this way because the flat
// number was wrong the day it landed and stayed wrong through a second edit (§4bq). There
// are 39 `is_it` call sites (this sentence deliberately writes the name without its
// bracket, so a naive count of the file does not include the comment about the count);
// one of them is the nine-table loop, which is a multiplier a
// reader cannot see from a total; and this check counts itself. `33` was written where 35
// was true, and **nothing local can run this file** — it needs a MySQL server — so the only
// place that disagreement could show up was the CI leg it was added to guard.
$expected = 43     // call sites that run once
          + 9      // the nine tables schema.sql builds, one call site
          + 1;     // this check, counting itself
$checked++;
if ($checked === $expected) {
    ok("this rehearsal ran every check it is supposed to ($checked)");
} else {
    bad('this rehearsal did not run every check it is supposed to — expected ' . $expected
        . ', ran ' . $checked, 'the rehearsal ran every check it is supposed to');
}

echo "\n$checked checks, " . count($failures) . " failed\n";
if ($failures) {
    foreach ($failures as $f) { echo "  FAILED: $f\n"; }
    exit(1);
}
echo "An install built from nothing, on this engine, end to end.\n";
exit(0);
