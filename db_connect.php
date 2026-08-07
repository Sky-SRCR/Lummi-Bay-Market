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

$credentialsFile = dirname(__DIR__, 2) . '/private/db_credentials.php';


if (file_exists($credentialsFile)) {
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
?>
