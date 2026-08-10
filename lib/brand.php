<?php
// ============================================================
// WHAT THE STORE'S OWN COLOURS ARE
// ============================================================
// `branding_config.php` is not a table. It is a PHP file the Admin Panel writes
// and a person edits — its own header says "edit this file directly to update
// branding after initial setup" — and what it holds is then interpolated straight
// into a `<style>` block on the Builder, the Help page and the sign-in page.
//
// That is the one place in this app where escaping is the wrong tool. Inside a
// `<style>` the HTML parser is looking for `</style` and nothing else, so
// `Markup::text()` leaves the value untouched in every respect that matters and
// still reads, at the call site, like the question has been dealt with. What is
// actually needed there is not "make these characters inert" but "this is a
// colour" — because a `BRAND_ACCENT` of
//
//     #fff; } body { background: url(https://example.invalid/x)
//
// is, after escaping, exactly those characters inside a stylesheet: a closed rule
// and a new one. Escaping cannot help. Only refusing the value can, and there is
// a module that already knows how to refuse one (`lib/color.php`).
//
// **Not currently reachable through the Branding form.** #21 made that form read
// every colour through `Color::read()` and refuse the save, naming the field, when
// one fails — so nothing an admin can type gets here. This exists for the other
// door: the file is generated, it is documented as hand-editable, it predates the
// rule that validates the form, and a deployment upgraded from before #21 may
// already hold whatever the old silent substitution wrote. That is the same shape
// as the rows `tools/audit_colors.php` exists to find (§4ac): the write path is
// closed and the values that were already there are not.
//
// **A default here is not the #21 defect.** `DisplayAdmin` substituting a colour
// for one an admin had just typed was a lie about a save. This substitutes nothing
// and saves nothing: it reads a file that already holds what it holds, and answers
// with the documented default when what it holds is not a colour — the same
// default a deployment with no `branding_config.php` at all gets, which is the only
// other honest answer for a page that has to render a stylesheet now. And it is
// said out loud rather than inferred: `unreadable()` is what `ColorAudit` reports
// through, so the value is named on a screen instead of quietly disappearing.
//
// The defaults were written out four times — once in each of `login.php`,
// `help.php`, `builder.php` and `admin_panel.php` — and the four agreed only
// because nobody had yet had a reason to change one. They are here once now.

require_once __DIR__ . '/color.php';

class Brand
{
    /**
     * What each colour is when the config does not say, or does not say a colour.
     *
     * These are the values `setup_branding.php` writes on a fresh install and the
     * ones the four page-level fallbacks all held, so a deployment that has never
     * opened the Branding page sees no change from this module existing.
     */
    const DEFAULTS = [
        'nav_bg'     => '#1a252f',
        'nav_border' => '#0d1b24',
        'accent'     => '#3498db',
        'text'       => '#ffffff',
    ];

    /** The constant each colour is stored in, and the label the Branding form uses. */
    const FIELDS = [
        'nav_bg'     => ['BRAND_NAV_BG',     'Navigation background'],
        'nav_border' => ['BRAND_NAV_BORDER', 'Navigation border'],
        'accent'     => ['BRAND_ACCENT',     'Accent'],
        'text'       => ['BRAND_TEXT',       'Navigation text'],
    ];

    /**
     * Make sure the generated branding file has been read, for a caller with no app
     * around it.
     *
     * It does not do the reading. `BrandingConfig` owns that file — it is the only
     * thing in the app that names it, renders it, and swaps it in (§4y) — and this
     * asks it rather than opening the file a second way. Two readers is how the two
     * of them come to disagree about what a missing value means, and the grep in §5
     * that would have caught it only works while there is one.
     *
     * Every page already has this via `config.php`, which calls `apply()`. What needs
     * it is `tools/audit_colors.php`: a CLI run with no `config.php` in it, reporting
     * on the colours this checkout holds. Idempotent — `BrandingConfig::load()`
     * guards on a constant and `require_once` on the path.
     */
    public static function load()
    {
        require_once __DIR__ . '/branding.php';
        (new BrandingConfig(dirname(__DIR__)))->load();
    }

    /**
     * The colour for one field, or the documented default when it is not one.
     *
     * Pure — the stored value is passed in rather than read — for the reason
     * `layout_rules.php` and `schema.php`'s decision half are pure (BUILD-REFERENCE
     * §4o): a `define()` cannot be undone, so a rule that could only be exercised
     * through the constants could only ever be tested with the one value this
     * machine happens to hold. Here the self-test can put every shape through it.
     *
     * An unknown key is a programming error and says so rather than answering a
     * colour. Four named accessors below are the interface; this is how they agree.
     *
     * @param string $key one of DEFAULTS' keys
     * @param mixed  $stored whatever the config file defined, or null for absent
     * @return string `#rrggbb`
     */
    public static function pick($key, $stored)
    {
        if (!isset(self::DEFAULTS[$key])) {
            throw new InvalidArgumentException('No brand colour called ' . $key . '.');
        }
        $read = Color::read($stored);
        return $read !== '' ? $read : self::DEFAULTS[$key];
    }

    public static function navBg()     { return self::pick('nav_bg',     self::stored('nav_bg')); }
    public static function navBorder() { return self::pick('nav_border', self::stored('nav_border')); }
    public static function accent()    { return self::pick('accent',     self::stored('accent')); }
    public static function text()      { return self::pick('text',       self::stored('text')); }

    /**
     * The logo's path, or '' when there is none.
     *
     * Not validated the way a colour is, and deliberately: it lands in
     * `src="{{ Markup::text(Brand::logo()) }}"`, where escaping *is* the right tool
     * and does the whole job — an attribute value cannot end early once both quote
     * characters are entities. The reason the colours need more is that a `<style>`
     * block has no such boundary, not that config values are untrusted in general.
     * Here for the default, so no page carries its own copy of it.
     */
    public static function logo()
    {
        return defined('BRAND_LOGO') && is_string(BRAND_LOGO) ? BRAND_LOGO : '';
    }

    /**
     * Every stored brand colour this app cannot read, for whoever reports it.
     *
     * Same shape as the findings in `ColorAudit`, which is where it is turned into
     * a sentence: key, the label the Branding form puts on that field, and the
     * value exactly as stored. Pure for the same reason `pick()` is; the caller
     * with no argument gets the real config.
     *
     * @param array|null $stored key => raw value, or null to read the constants
     * @return array list of ['key','label','value']
     */
    public static function unreadable($stored = null)
    {
        if ($stored === null) { $stored = self::all(); }
        $bad = [];
        foreach (self::FIELDS as $key => $field) {
            $raw = array_key_exists($key, $stored) ? $stored[$key] : null;
            // Absent is not unreadable. A config written before a colour existed
            // simply does not define it, and the default is the right answer with
            // nothing for anybody to go and fix.
            if ($raw === null) { continue; }
            if (Color::isColor($raw)) { continue; }
            $bad[] = ['key' => $key, 'label' => $field[1], 'value' => $raw];
        }
        return $bad;
    }

    /** Every brand colour as the config file left it: key => raw value, or null. */
    public static function all()
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) { $out[$key] = self::stored($key); }
        return $out;
    }

    /** What the config file defined for one field, or null if it defined nothing. */
    private static function stored($key)
    {
        $name = self::FIELDS[$key][0];
        return defined($name) ? constant($name) : null;
    }
}
