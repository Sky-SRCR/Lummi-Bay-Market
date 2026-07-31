<?php
// ============================================================
// SITE CONFIGURATION
// Non-database settings. You can override these by defining
// the constants in your ../private/db_credentials.php file
// before this file is loaded.
// ============================================================

// Load branding config first so it can override MAIL_FROM, SITE_NAME, etc.
$_bcFile = __DIR__ . '/branding_config.php';
if (file_exists($_bcFile) && !defined('BRAND_LOGO')) { require_once $_bcFile; }
unset($_bcFile);

if (!defined('MAIL_FROM')) {
    define('MAIL_FROM',      'noreply@yourdomain.com');   // ← set via Branding page or edit here
    define('MAIL_FROM_NAME', 'Display System');
    define('SITE_NAME',      'Store Display System');
}
?>
