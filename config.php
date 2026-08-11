<?php
// ============================================================
// SITE CONFIGURATION
// Non-database settings. You can override these by defining
// the constants in your ../private/db_credentials.php file
// before this file is loaded.
// ============================================================

// The ten generated settings — the logo, four nav colours, the site name, the two
// mail-from fields, the Builder's undo depth and the store's time zone — with their
// defaults, in one call. `auth.php` requires this file at the top of every page, so by
// the time anything renders they are defined.
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

// ── The clock a person reads ──────────────────────────────────
// Decision #44. Nothing ever set a timezone, so PHP fell back to whatever
// `date.timezone` held — nothing, on the live host, which means UTC — and the store
// is in Washington. Set here because this is the file every page loads through
// `auth.php` and the file that already turns the generated settings into things the
// app can act on; a page setting it for itself is nine pages that can disagree.
//
// This is not what makes the times on those pages right: `StoreClock::label()`
// converts explicitly, so it is correct in `viewer.php`, which loads neither this
// file nor `auth.php`, and in a self-test that moves the process zone about on
// purpose. What this line does is make the *cheap* call agree with the correct one,
// so a bare `date()` added to a page later is not seven hours from every other time
// beside it. See lib/store_clock.php for the three clocks that were involved.
require_once __DIR__ . '/lib/store_clock.php';
StoreClock::apply();

// ── Builder undo ──────────────────────────────────────────────
// UNDO_STEPS is how many steps back the Builder's Undo button can go, set on the
// admin Settings page (ADR-0010) and stored in `branding_config.php` with the other
// eight — which is why it is a `BrandingConfig::DEFAULTS` entry and not a `define()`
// of its own here. A second writer of that file would have its value dropped the
// next time somebody saved the Branding form, silently.
//
// The ceiling is a constant rather than a setting because it is not an opinion about
// this store: undo is a stack of canvas snapshots in one browser tab, so the cost of
// a larger number is that tab's memory and nothing else — but an unbounded stack on
// a sign with a hundred blocks is a tab that slows down over an afternoon and never
// says why.
define('UNDO_STEPS_MAX', 20);

/**
 * The undo depth as the Builder should believe it, whatever is in the config file.
 * Hand-edited to 500, to -1, or to the word "five", this still answers with
 * something the editor can act on.
 *
 * 0 switches Undo off completely: no button, no keyboard shortcut, no snapshots
 * taken. That is the setting to reach for if it ever misbehaves on the shop floor,
 * and it is why the number is a setting rather than a constant in the JS. It is also
 * where a hand-edit to nonsense lands, deliberately — `intval('five')` is 0, and a
 * Builder with no Undo is a Builder behaving exactly as it did before this existed,
 * where a Builder believing some other number would not be.
 *
 * The stored value is a **parameter** with the constant as its default, for §4o's
 * reason: a rule that reads a global can only ever be tested against whatever this
 * process happens to hold, and the shapes worth testing here — 500, -1, "five" — are
 * the ones a running installation will not be in. Both callers, the settings form
 * and the Builder, take the no-argument form, so they cannot disagree about what a
 * stored value means.
 */
function undoStepsSetting($stored = null): int {
    if ($stored === null) { $stored = defined('UNDO_STEPS') ? UNDO_STEPS : 0; }
    return max(0, min(UNDO_STEPS_MAX, intval($stored)));
}
