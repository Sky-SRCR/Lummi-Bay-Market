<?php
// ============================================================
// SITE CONFIGURATION
// Non-database settings. You can override these by defining
// the constants in your ../private/db_credentials.php file
// before this file is loaded.
// ============================================================

// The eight branding settings — the logo, four nav colours, the site name and the
// two mail-from fields — with their defaults, in one call. `auth.php` requires this
// file at the top of every page, so by the time anything renders they are defined.
//
// This used to be seven lines here and seven more in each of login.php, builder.php
// and help.php, all spelling out the same defaults, and the guard on the require was
// BRAND_LOGO here and BRAND_NAV_BG in the other three. Four copies of one list is
// four things to change and three chances to forget. It also had a sharper edge:
// MAIL_FROM, MAIL_FROM_NAME and SITE_NAME were defined as a group behind a single
// `if (!defined('MAIL_FROM'))`, so a branding file that named one and not the others
// left SITE_NAME undefined — which is a fatal in PHP 8, on every page.
//
// Anything already defined is left as it is, so the override above still works.
require_once __DIR__ . '/lib/branding.php';
(new BrandingConfig(__DIR__))->apply();
