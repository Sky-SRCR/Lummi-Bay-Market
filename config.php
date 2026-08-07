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

// ── Builder undo ──────────────────────────────────────────────
// How many steps back the Builder's Undo button can go, set on the admin
// Settings page (ADR-0008). It is a browser-side stack of canvas snapshots in
// one tab, so the cost of a larger number is that tab's memory and nothing else
// — but it is still bounded here, because an unbounded stack on a sign with a
// hundred blocks is a tab that slows down over an afternoon and never says why.
//
// 0 switches Undo off completely: no button, no keyboard shortcut, no snapshots
// taken. That is the setting to reach for if it ever misbehaves on the shop
// floor, and it is why the number is a setting rather than a constant in the JS.
if (!defined('UNDO_STEPS')) { define('UNDO_STEPS', 5); }
define('UNDO_STEPS_MAX', 20);

/**
 * The undo depth as the Builder should believe it, whatever is in the config
 * file. Hand-edited to 500, to -1, or to the word "five", this still answers
 * with something the editor can act on.
 */
function undoStepsSetting(): int {
    return max(0, min(UNDO_STEPS_MAX, intval(UNDO_STEPS)));
}
?>
