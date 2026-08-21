// ============================================================
// WHAT THE SERVER PUTS ON THE PAGE, SAID OUT LOUD
// ============================================================
//   require('./page_constants')  — used by every node suite, not run on its own
//
// `builder.php` hands its JavaScript twenty-one values through PHP interpolation, and
// `viewer.php` three. The node suites cannot run PHP, so they have always stripped
// `<?= … ?>` to the literal `0` and then written a handful of the constants back by
// hand. `0` is a valid expression in every position the page interpolates one, which
// is what made that work — and is also what made it silent. Every value a suite did
// not think to write back was **zero**, and nothing said so.
//
// What that cost, measured rather than guessed: setting all twenty-one to what a real
// page carries and re-running all eight suites changes not one check. So the zeroes
// were never *wrong* — unlike the seven checks §4bg found on the PHP side — but they
// were never *seen* either, and two of them were doing damage:
//
//   LOCK_LAPSE_SECONDS and LOCK_WARN_SECONDS were 0 in all eight. The idle warning is
//   drawn when `idle >= WARN && idle < LAPSE`, which with both at zero is false for
//   every idle there has ever been. The bar that tells somebody their edit lock is
//   about to lapse, and the countdown inside it, were **unreachable** — not untested,
//   which a person can notice, but impossible to reach, which nobody can.
//
//   CANVAS_W and CANVAS_H were 0 in `selftest_viewer.js`. `scaleToFit()` divides the
//   window by them, so the one piece of geometry on the page a customer looks at
//   produced Infinity and NaN margins on every run, threw nothing, and was asserted
//   by nobody.
//
// So this module exists to make the silence a declaration. It holds one value per
// constant — what the page really carries — and it refuses two things that used to
// pass unnoticed:
//
//   1. A constant the page interpolates that nothing here names. Add one to
//      `builder.php` and every suite fails until this file says what it is, rather
//      than the new value quietly being zero in all eight.
//   2. An override for a constant the page does not have. `selftest_builder_readonly.js`
//      already worried about this for one name — `check(/var CAN_PICK_BRAND = false;/…)`,
//      one line guarding one constant — and it is the same failure as the `lock-holder`
//      entry that sat in that suite's PRESENT list for months pointing at nothing.
//
// A suite still says what its own premise needs; the difference is that everything it
// does *not* say is now a value somebody wrote down on purpose.

const fs   = require('fs');
const path = require('path');

const REPO = path.join(__dirname, '..');

/**
 * The sixteen chrome roles, read from the module that owns them.
 *
 * `THEME_STORE` is a map of custom property to colour, and writing the sixteen names
 * out here would be a second copy of `SiteChrome::ROLES` that agrees with it until
 * somebody adds a role. The page builds the names the same way (`varName()`:
 * underscores become hyphens, prefixed `--`), so this reads the list rather than
 * repeating it. A seventeenth role appears here the day it appears there.
 */
function chromeRoleVars() {
    const src = fs.readFileSync(path.join(REPO, 'lib', 'site_chrome.php'), 'utf8');
    const block = src.slice(src.indexOf('const ROLES = ['));
    const names = [];
    const re = /^\s*'([a-z_]+)'\s*=>\s*\[/gm;
    let m;
    const body = block.slice(0, block.indexOf('];'));
    while ((m = re.exec(body))) { names.push('--' + m[1].replace(/_/g, '-')); }
    if (names.length !== 16) {
        throw new Error('page_constants: expected sixteen chrome roles in site_chrome.php, read '
                        + names.length + ' — the list moved and this reader did not');
    }
    const out = {};
    // One colour, not sixteen distinguishable ones: a suite that cares which role got
    // which value says so itself (`selftest_builder_theme.js` does). This is only what
    // the constant *is* on a page nobody has themed.
    names.forEach(function (n) { out[n] = '#1a252f'; });
    return out;
}

/** `LayoutRules::CORNER_RADIUS_MAX`, read from the module, never repeated here. */
function cornerRadiusMax() {
    const src = fs.readFileSync(path.join(REPO, 'lib', 'layout_rules.php'), 'utf8');
    const m = src.match(/const\s+CORNER_RADIUS_MAX\s*=\s*(\d+)\s*;/);
    if (!m) {
        throw new Error('page_constants: no CORNER_RADIUS_MAX in lib/layout_rules.php — the '
                        + 'constant moved and this reader did not');
    }
    return parseInt(m[1], 10);
}

/**
 * Every value the server interpolates, and what it is on a page somebody is looking at.
 *
 * Not "a value that will not crash" — the value a real page carries, so that a suite
 * which does not override one is still running the page rather than a degenerate
 * version of it. Where the honest answer is "empty", the empty value is of the right
 * *type*: `BRANDS` is `[]` rather than `0`, because the page iterates it.
 */
const PAGE_DEFAULTS = {
    // Who is looking, and at what. A suite whose premise is a read-only page or a basic
    // account overrides the first two; nothing else should have to.
    IS_ADMIN:           true,
    READ_ONLY:          false,
    SITE_NAME:          'Store Display System',
    CSRF_TOKEN:         'test-csrf-token',

    // The sign. 1920×1080 because that is what a Screen is and what every canvas in
    // this repo's fixtures is; a zero here made the Viewer's scale Infinity.
    DISPLAY_TAG:        'deli',
    DISPLAY_ID:         4,
    DISPLAY_TITLE:      'Deli Board',
    CANVAS_W:           1920,
    CANVAS_H:           1080,

    // The edit lock. These two are the reason this file exists: the real numbers are
    // `LockState::IDLE_LAPSE_SECONDS` and `WARN_AFTER_SECONDS`, and at zero the warning
    // window between them does not exist.
    LOCK_HOLDER:        '',
    LOCK_LAPSE_SECONDS: 900,
    LOCK_WARN_SECONDS:  780,

    // What the server will accept. 8 MB is what `UploadLimit` answers on a stock PHP.
    UPLOAD_MAX_BYTES:   8388608,
    UPLOAD_MAX_LABEL:   '8 MB',

    // The venue this sign wears (v2 steps 3 and 4). Empty and unpickable is what a
    // Display with no Brand assigned carries.
    BRANDS:             [],
    BRAND_ID:           0,
    CAN_PICK_BRAND:     false,

    // How far back Undo reaches (ADR-0010). Five is `BrandingConfig::DEFAULTS`.
    UNDO_LIMIT:         5,

    // The largest corner radius the publish path will accept (§4by). Read out of the
    // module that owns it rather than written here, for the reason the role names are:
    // a number copied into this file agrees with the app until somebody changes one.
    CORNER_RADIUS_MAX:  cornerRadiusMax,

    // The person's own workspace (v2 step 5). No themes exist until an admin makes one,
    // so nobody is wearing one and the store default is what the page was rendered in.
    THEMES:             [],
    THEME_ID:           0,
    THEME_STORE:        chromeRoleVars,
};

/** Values that are not interpolated but that a suite may still want to pin. */
const SETTABLE_BESIDES = new Set(['LAYOUT_STAMP']);

const PHP_ECHO = /<\?(php|=)[\s\S]*?\?>/g;
const SCRIPT   = /<script\b(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/gi;

/** Constants this page hands its JavaScript from PHP: `var NAME = <?= … ?>;`. */
function interpolatedOn(php) {
    const names = [];
    const re = /^[ \t]*var\s+([A-Z][A-Z0-9_]*)\s*=\s*<\?(php|=)[\s\S]*?\?>/gm;
    let m;
    while ((m = re.exec(php))) { names.push(m[1]); }
    return names;
}

/**
 * The page's own JavaScript, with every server-provided value set to something a person
 * wrote down.
 *
 * @param {string} phpPath   builder.php or viewer.php
 * @param {object} overrides what this suite's premise needs — anything not named here
 *                           comes from PAGE_DEFAULTS
 * @return {string} the script bodies, joined, ready to eval
 */
function buildPageJs(phpPath, overrides) {
    const php  = fs.readFileSync(phpPath, 'utf8');
    const page = path.basename(phpPath);
    overrides  = overrides || {};

    // 1. Nothing the page interpolates may be left to chance.
    const interpolated = interpolatedOn(php);
    const unnamed = interpolated.filter(n => !(n in PAGE_DEFAULTS) && !(n in overrides));
    if (unnamed.length) {
        throw new Error('page_constants: ' + page + ' interpolates ' + unnamed.join(', ')
            + ' and nothing says what it is. Give it a value in PAGE_DEFAULTS — the old '
            + 'behaviour was to leave it as the literal 0 in all eight suites, silently.');
    }

    // 2. An override for something this page does not carry is a line that reads as
    //    covering a case and covers nothing.
    const carried = new Set(interpolated.concat([...SETTABLE_BESIDES]));
    const dead = Object.keys(overrides).filter(n => !carried.has(n));
    if (dead.length) {
        throw new Error('page_constants: ' + page + ' has no ' + dead.join(', ')
            + ' to set. Either the name is wrong or the page dropped it.');
    }

    let js = php.replace(PHP_ECHO, '0')
                .match(SCRIPT)
                .map(b => b.replace(/^<script\b[^>]*>/i, '').replace(/<\/script>$/i, ''))
                .join('\n');

    const wanted = {};
    interpolated.forEach(n => { wanted[n] = PAGE_DEFAULTS[n]; });
    Object.keys(overrides).forEach(n => { wanted[n] = overrides[n]; });

    Object.keys(wanted).forEach(function (name) {
        let value = wanted[name];
        // A default may be a function so that reading a file — the sixteen role names —
        // happens when a page is built rather than when this module is required.
        if (typeof value === 'function') { value = value(); }
        // Always a real value rendered to a literal, never a string of JavaScript. A
        // suite handing over source would be a second way to get a syntax error into a
        // page whose only gate is that it parses.
        const literal = JSON.stringify(value);
        const line = new RegExp('^[ \\t]*var\\s+' + name + '\\s*=.*$', 'm');
        if (!line.test(js)) {
            throw new Error('page_constants: no `var ' + name + ' =` line in ' + page);
        }
        js = js.replace(line, 'var ' + name + ' = ' + literal + ';');
    });

    return js;
}

module.exports = { buildPageJs, PAGE_DEFAULTS, interpolatedOn, chromeRoleVars };
