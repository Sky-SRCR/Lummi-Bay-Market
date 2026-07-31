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
    http_response_code(500);
    die("Database connection failed. Please contact your system administrator.");
}
?>
