<?php
// ============================================================
// DATABASE CONNECTION
// ============================================================
// Credentials are loaded from a file OUTSIDE the public webroot
// so they cannot be reached via a browser request.
//
// Create this file on your server:
//   /home/YOUR_ACCOUNT/private/db_credentials.php
//
// That file should contain:
//   <?php
//   define('DB_HOST', 'localhost');
//   define('DB_NAME', 'your_database_name');
//   define('DB_USER', 'your_database_user');
//   define('DB_PASS', 'your_database_password');
//
// dirname(__DIR__) points one level above this file's parent
// folder (i.e. above public_html / www).
// ============================================================

// Every entry point includes this file, which makes it the one place that can put
// the error policy in front of all of them. An entry point that has already
// declared what kind of page it is — viewer.php, api.php — keeps its declaration;
// everything else gets the default. See lib/error_policy.php.
require_once __DIR__ . '/lib/error_policy.php';
ErrorPolicy::installDefault();

// And for the same reason, one line further: every page that renders a stored value
// has to escape it the same way, and `lib/markup.php` is where that rule lives (#15).
// Requiring it here rather than eight times is not tidiness — a page that forgot the
// include would be a fatal error on a live sign, discovered by whoever was looking at
// it. This file is the one include they all already have.
require_once __DIR__ . '/lib/markup.php';

// The other half of that rule, for the values escaping cannot help with. The store's
// own colours go into a `<style>` block, where there is no delimiter to escape and a
// value that is not a colour is CSS. `lib/brand.php` reads them, so no page carries
// its own copy of the defaults.
//
// It does *not* load `branding_config.php`. It did on the branch this came from, which
// predates §4y: since that write-up `lib/branding.php` is the only file in the app
// that spells the name, `config.php` brings the eight constants into being through it,
// and a consistency grep in BUILD-REFERENCE §5 holds it to that. Two readers is one
// more than the invariant allows, and the second one is redundant anyway — `Brand::`
// reads constants, and by the time any page renders a stylesheet `config.php` has
// defined them. Absent constants still answer the documented default, which is what
// `tools/audit_colors.php` relies on when it runs with no app around it.
require_once __DIR__ . '/lib/brand.php';

// Which credentials this install uses, and why it is not simply one shared path:
// two copies of this app in two folders on one account walk up to the same place, so
// an unmodified rehearsal copy used to connect to the *live* database in silence.
// `lib/install_paths.php` has the whole reasoning. The order is folder-specific
// first, shared second, so the live install's behaviour is exactly what it was.
require_once __DIR__ . '/lib/install_paths.php';
$credentialsFile = InstallPaths::credentialsFile(__DIR__);

if ($credentialsFile !== '') {
    require_once $credentialsFile;
} else {
    // ---- Fallback placeholders – fill these in if you are not
    //      using the private file method above. ----
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'your_database_name');
    define('DB_USER', 'your_database_user');
    define('DB_PASS', 'your_database_password');
}

// The alert channel is armed before the connection is attempted, because the
// connection failing is the first thing worth alerting about. It needs no database
// of its own: its recipients were cached to disk the last time an admin opened the
// admin panel, which is the only way an alert can go out when the database is the
// thing that is down.
ErrorPolicy::useAlerts(new AlertMailer(
    ErrorPolicy::stateDir(),
    defined('SITE_NAME') ? SITE_NAME : ''
));

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // This used to print "Database connection failed. Please contact your system
    // administrator." — as black text on a white page, on a TV, in the shop. The
    // policy decides what each kind of caller is told and, for a Screen, puts a
    // notice up that re-checks every 30 seconds so the sign comes back on its own.
    ErrorPolicy::fail(
        'database-unreachable',
        'Could not connect to the database: ' . $e->getMessage(),
        'Database unreachable'
    );
}

// ── The database's own clock ──────────────────────────────────
// Every moment PHP writes is UTC (§4t, §4v). Every moment *MySQL* writes was in
// MySQL's session zone, which defaults to the host's system zone and which nothing
// here had ever set — a third clock beside PHP's process zone and the store's own,
// and the only one no screen could show (#44). It reaches two values: the
// `created_at`/`updated_at` TIMESTAMP defaults, which PHP cannot write and so cannot
// convert, and `displays.last_published_at`, which used to be `CURRENT_TIMESTAMP` and
// is now a bound `gmdate()`. Setting the session to UTC makes "everything stored in
// this database is UTC" a whole sentence rather than nearly one, so
// `StoreClock::epochOf()` is right about every stamp it is handed instead of most of
// them.
//
// A numeric offset, not `'UTC'`: the named zones need MySQL's `mysql.time_zone`
// tables loaded, which a shared host may not have, and `+00:00` is always understood.
//
// TIMESTAMP columns already written are unaffected — MySQL stores them as an instant
// and converts on read, so old rows start reading correctly rather than stop. The one
// migration is `last_published_at`, which is a DATETIME and therefore stored as the
// wall clock it was written in; §4ap and `recordPublish()` say what that costs, which
// is one sentence per Display until its next publish.
//
// Suppressed rather than fatal, and reported instead: a protection that cannot apply
// is reported, not applied. `ServerReport` prints the session zone the connection
// actually ended up with, so a host that refused this says so on Settings → This
// Server rather than being silently back to three clocks.
//
// Unconditional, including on the path `api.php` serves to every Screen every 30
// seconds — which is the one place in this app where an extra statement per request
// deserves a sentence. It is a session variable: no metadata lock, no I/O, and nothing
// like the DDL invariant 7 keeps off that path. The alternative is worse than the cost:
// a connection whose time frame depends on which page opened it is the third clock all
// over again, in a form nothing could report, and the public poll is the one caller that
// would never be looked at.
try {
    $pdo->exec("SET time_zone = '+00:00'");
} catch (Throwable $e) {
    // Deliberately nothing. ErrorPolicy::report() here would fire on every request of
    // every page for as long as the host refused it (invariant 20), and the honest
    // channel for a standing configuration fact is the report that reads it back.
}
?>
