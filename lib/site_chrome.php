<?php
// ============================================================
// WHAT THE APPLICATION'S OWN COLOURS ARE
// ============================================================
// This class was called `Brand`, and the name was wrong by this project's own
// vocabulary. `CONTEXT.md` gives **Brand** one meaning — *what a customer sees on
// a TV* — and what this file holds is the opposite of that: the navigation bar,
// the accent and the text colour of the application an employee works in, which
// CONTEXT.md calls a **Workspace Theme**. Nothing here reaches a Screen.
//
// It went unnoticed while there was only one of either. ADR-0011 gives the
// sign-facing Brand a table, a module and a picker, so the word had to go back to
// the thing the vocabulary says owns it (`lib/brands.php`), and this had to be
// called what it is. `SiteChrome` rather than `WorkspaceTheme` deliberately: a
// Workspace Theme is per-person and chosen, and this is neither yet — it is one
// set of colours for the whole install, read out of a generated file. Step 5 of
// the v2 roadmap is what turns it into the other, and it keeps these four method
// names when it does, so every page's call site survives that change too.
//
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

class SiteChrome
{
    /**
     * What each colour is when nothing above it says otherwise: no theme worn, and a
     * config that does not say, or does not say a colour.
     *
     * The first four are the values `setup_branding.php` writes on a fresh install and
     * the ones the four page-level fallbacks all held, so a deployment that has never
     * opened the Branding page sees no change from this module existing.
     *
     * The other nine are **today's hardcoded values, moved here rather than chosen** —
     * each one was a literal in `builder.php`, `help.php` or `admin_panel.php`, and
     * they are written down here so that the install with no theme at all is painted
     * by construction exactly as it was before step 5. Every one of them is also a
     * column default on `workspace_themes`, and `selftest_layout` asserts the two
     * lists agree; a value changed in one place and not the other would be a theme
     * that starts somewhere the store default is not.
     */
    const DEFAULTS = [
        // Chrome: the surfaces and the things drawn on them.
        'nav_bg'       => '#1a252f',
        'nav_border'   => '#0d1b24',
        'nav_text'     => '#ffffff',
        'accent'       => '#3498db',
        'work_area'    => '#2c3e50',
        'panel'        => '#1a252f',
        'panel_border' => '#34495e',
        // Status: the five meanings behind every banner this app draws.
        'status_good'  => '#27ae60',
        'status_warn'  => '#7d6608',
        'status_bad'   => '#7b3f3f',
        'status_busy'  => '#4b3869',
        'status_note'  => '#7a4a12',
        // The one thing on this list that is drawn over the canvas rather than beside
        // it. See ROLES for why that is allowed and what is not.
        'selection'    => '#e74c3c',
    ];

    /**
     * Every role, the words a person picks it by, and which group of the theme form it
     * belongs to.
     *
     * Thirteen — the plan said twelve, with six chrome roles, and six is one short:
     * the **navigation border** is one of the four colours a shop can already set from
     * Site Branding, and a theme that could not hold it would repaint the live nav the
     * moment anybody chose one. Decision 9's "no sign moves" has an application-side
     * twin, and this is it.
     *
     * The last role is the reason decision 11 needs a *check* rather than a
     * convention. `#builder-canvas` and everything drawn on it belong to the Brand,
     * because what the canvas shows is what the sign shows — but the selection
     * outline and the resize handles are drawn *over* the canvas and never reach a
     * Screen, so they are chrome that happens to sit there. That distinction cannot
     * be seen by looking at a colour, only at where it is used, which is what
     * `tools/check_invariants.php` looks at.
     */
    const ROLES = [
        'nav_bg'       => ['Navigation background', 'chrome'],
        'nav_border'   => ['Navigation border',     'chrome'],
        'nav_text'     => ['Navigation text',       'chrome'],
        'accent'       => ['Accent',                'chrome'],
        'work_area'    => ['Work area',             'chrome'],
        'panel'        => ['Panel',                 'chrome'],
        'panel_border' => ['Panel border',          'chrome'],
        'status_good'  => ['Saved / done',          'status'],
        'status_warn'  => ['Warning',               'status'],
        'status_bad'   => ['Problem',               'status'],
        'status_busy'  => ['Somebody else is here', 'status'],
        'status_note'  => ['Advisory note',         'status'],
        'selection'    => ['Selection outline and handles', 'overlay'],
    ];

    /**
     * The constant each colour is stored in, and the label the Branding form uses.
     *
     * Four of the thirteen, and deliberately still four: these are the ones
     * `branding_config.php` has always held, which makes them the ones the **store
     * default** can differ from the documented defaults in. The other nine are a
     * theme's to change and nobody else's — adding them to the Branding form would
     * give the install two ways to set the same colour, which is the second opinion
     * invariant 16 exists about.
     */
    const FIELDS = [
        'nav_bg'     => ['BRAND_NAV_BG',     'Navigation background'],
        'nav_border' => ['BRAND_NAV_BORDER', 'Navigation border'],
        'accent'     => ['BRAND_ACCENT',     'Accent'],
        'nav_text'   => ['BRAND_TEXT',       'Navigation text'],
    ];

    /**
     * The theme this request is painted in, or null for the store default.
     *
     * Static, because the whole point of step 5 is that every page keeps calling
     * `SiteChrome::navBg()` and the twelve beside it — a resolution threaded through
     * every call site would have been a change to every stylesheet in the app instead
     * of one line per page. What it is *not* is a static that reaches for state: it is
     * set by `wear()`, from a value the page looked up and passed in, and this file
     * names neither `$_SESSION` nor a PDO.
     */
    private static $worn = null;

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

    /**
     * Paint this request in a Workspace Theme, or in the store default when passed
     * null.
     *
     * Called once, near the top of a signed-in page, with the theme the account chose
     * — `WorkspaceThemeStore::forAccount()` is what looks it up. **The account is
     * passed in and never read here**: a static that reached for `$_SESSION` would be
     * the hidden coupling this codebase has spent its history removing, and would make
     * every check below answerable only in a process that had a session.
     *
     * `login.php` and `reset_password.php` never call this, which is what makes
     * decision 12's "the sign-in page is unaffected" true by construction rather than
     * by a rule somebody has to remember. `viewer.php` is further out still: it loads
     * neither `config.php` nor `auth.php`, so this file is not even present on the one
     * page a customer sees.
     *
     * The parameter is typed without this file requiring `workspace_themes.php`, the
     * same way `DisplayStore::applyBrand()` names a `BrandChoice`: a type hint is
     * checked when the call happens, by which point the caller has loaded the class it
     * is passing, and requiring it here would be a cycle — that module reads `ROLES`
     * out of this one.
     *
     * `?WorkspaceTheme` and not `WorkspaceTheme … = null`: from PHP 8.4 the implicit form
     * is deprecated, and this method is called on every signed-in page load — so on a host
     * that has moved to 8.4 it writes a line into the error log on every request, into the
     * same log that alerts admins and rotates at a size cap. The explicit form is
     * understood back to 7.1, so it costs nothing below the floor. Invariant 36 is why
     * this is the only spelling in the tree, and why it is checked rather than remembered:
     * `php -l` is clean on both forms, and the deprecation fires when the file is
     * *compiled*, which is before any error handler the suite installs exists.
     *
     * @param WorkspaceTheme|null $theme
     */
    public static function wear(?WorkspaceTheme $theme = null)
    {
        self::$worn = $theme;
    }

    /** The theme being worn, or null for the store default. */
    public static function worn()
    {
        return self::$worn;
    }

    public static function navBg()      { return self::role('nav_bg'); }
    public static function navBorder()  { return self::role('nav_border'); }
    public static function accent()     { return self::role('accent'); }
    public static function text()       { return self::role('nav_text'); }
    public static function workArea()   { return self::role('work_area'); }
    public static function panel()      { return self::role('panel'); }
    public static function panelBorder(){ return self::role('panel_border'); }
    public static function statusGood() { return self::role('status_good'); }
    public static function statusWarn() { return self::role('status_warn'); }
    public static function statusBad()  { return self::role('status_bad'); }
    public static function statusBusy() { return self::role('status_busy'); }
    public static function statusNote() { return self::role('status_note'); }
    public static function selection()  { return self::role('selection'); }

    /**
     * The colour for one role, resolved.
     *
     * Named accessors above are the interface — `text()` keeps its name although its
     * role is now `nav_text`, because every page and every check says `SiteChrome::text()`
     * and the point of this step is that no call site changes. This is how the thirteen
     * agree, and it is public because the theme form and the check both need to walk
     * `ROLES` and ask about each one without a thirteen-way switch.
     */
    public static function role($key)
    {
        if (self::$worn !== null) {
            return self::themeColor($key, self::$worn->colorFor($key));
        }
        return self::configColor($key);
    }

    /**
     * What one role of one theme actually paints: the theme's stored value when this
     * app can read it, and otherwise **what the store default paints for that role**.
     *
     * The fallback is the layer underneath, not the documented default, and the two
     * differ for the four config-backed roles on any install that has branded its nav.
     * This file argued the other way until the store owner decided it: a theme with one
     * unusable value should leave the shop's own colour in that place rather than
     * revert it to the colour the app ships with, because "use the store default" means
     * `branding_config.php` in every other sentence of this step and a phrase cannot
     * mean two things one method apart. What made it worth deciding rather than leaving
     * is that no check in this repo can tell: the container has no branding file, so
     * the shop's colour and the documented one are the same string here, and the
     * mutation run that changed this line lived (§4bd).
     *
     * The nine roles with no `FIELDS` entry have no config layer at all — they were
     * literals in three stylesheets until step 5 and are a theme's to change or
     * nobody's — so for those `configColor()` *is* the documented default and this
     * answers exactly what it always did.
     *
     * Never a silent substitute: `WorkspaceTheme::unreadable()` names every value a
     * theme stores that could not be used, the panel's theme table prints that list
     * beside the swatches, and `ColorAudit` reports it. Substituting *and* saying so
     * is the whole of #21.
     *
     * Public because two places have to answer this question about a theme that is not
     * the one being worn — the payload the Builder's picker switches between, and the
     * swatch row on the panel — and a second copy of the layering is how the four
     * copies of the colour rule came to disagree in the first place.
     *
     * @param string $key    one of DEFAULTS' keys
     * @param mixed  $stored whatever the theme row holds for it, or null
     * @return string `#rrggbb`
     */
    public static function themeColor($key, $stored)
    {
        $read = Color::read($stored);
        return $read !== '' ? $read : self::configColor($key);
    }

    /**
     * Every role resolved: key => `#rrggbb`.
     *
     * What the Builder hands its own script so a person changing their theme sees it
     * happen without the page reloading — which is not a nicety. The picker lives in
     * the Builder's gear, and a Builder holding unpublished work that reloads has
     * thrown that work away; a setting about the colour of a menu bar must not be able
     * to do that.
     */
    public static function roleColors()
    {
        $out = [];
        foreach (array_keys(self::ROLES) as $key) { $out[$key] = self::role($key); }
        return $out;
    }

    /**
     * Every role as the **store default** has it: the config file's four, and the
     * documented default for the other nine. What "use the store default" means, in
     * colours.
     *
     * Needed as a value rather than as a state because the page that offers that choice
     * is already wearing a theme when it renders it — so the alternative was taking the
     * theme off, reading thirteen colours and putting it back, in a static, half way
     * down a page. A method that answers without moving anything is the same answer with
     * nothing left behind if it throws.
     */
    public static function storeColors()
    {
        $out = [];
        foreach (array_keys(self::ROLES) as $key) {
            $out[$key] = isset(self::FIELDS[$key]) ? self::configColor($key) : self::DEFAULTS[$key];
        }
        return $out;
    }

    /**
     * The CSS custom-property name a role is drawn through: `--nav-bg` for `nav_bg`.
     *
     * One function, because three things have to agree about this string — the
     * `:root` block below, the script that repaints without reloading, and the check
     * that refuses a role inside a canvas rule. Two of the three could have guessed
     * it; the third would then be enforcing a rule about a name nothing used.
     */
    public static function varName($key)
    {
        if (!isset(self::ROLES[$key])) {
            throw new InvalidArgumentException('No chrome role called ' . $key . '.');
        }
        return '--' . str_replace('_', '-', $key);
    }

    /**
     * The declarations for a `:root` block: every role as a custom property.
     *
     * **Why the pages draw colours through variables at all.** Before step 5 each page
     * interpolated `SiteChrome::navBg()` into every rule that needed it, and the nine
     * roles that were literals were interpolated nowhere — so a theme would have meant
     * a hundred-odd new echoes across three files, each one a place to forget that a
     * colour in a `<style>` block is validated and never escaped. Thirteen validated
     * echoes in one block, and `var(--nav-bg)` everywhere else, is the same rule
     * enforced in thirteen places instead of a hundred. It is also what makes the
     * canvas check possible: decision 11 is a statement about *where a role may be
     * used*, and a `var(--…)` in a stylesheet is something a check can find.
     *
     * Every value has been through `Color::read()` inside `pick()`, so this string
     * cannot carry a `}` that ends the block — which is the property escaping could
     * never have given it. Emitted as one echo, which is why
     * `tools/check_invariants.php` lists this method beside the thirteen accessors.
     */
    public static function styleVariables()
    {
        $out = [];
        foreach (self::roleColors() as $key => $hex) {
            $out[] = '    ' . self::varName($key) . ': ' . $hex . ';';
        }
        return implode("\n", $out);
    }

    /**
     * The logo's path, or '' when there is none.
     *
     * Not validated the way a colour is, and deliberately: it lands in
     * `src="{{ Markup::text(SiteChrome::logo()) }}"`, where escaping *is* the right tool
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
        foreach (array_keys(self::FIELDS) as $key) { $out[$key] = self::storedInConfig($key); }
        return $out;
    }

    /**
     * One of the four config-backed colours as the *store* has it, ignoring whatever
     * theme this request is wearing.
     *
     * What the Branding form offers as "what is there now", because that form edits the
     * store's own colours and not the reader's preference. See `storedInConfig()` for
     * what asking the layered accessor there would have saved.
     */
    public static function configColor($key)
    {
        return self::pick($key, self::storedInConfig($key));
    }


    /**
     * What the config file defined for one field, or null if it defined nothing — with
     * no theme layer over it.
     *
     * The distinction is not decoration, it is a defect that was one edit away. Three
     * things ask about the four config-backed colours *as configured* rather than *as
     * painted*: `all()`, `unreadable()`, and the Branding form, which fills its four
     * `type=color` inputs with them. Had those gone through the layered read, an admin
     * wearing a theme would have opened the Branding tab, been shown the theme's
     * colours as "what is there now", and saved them into `branding_config.php` — a
     * form quietly rewriting the store's own colours to one person's preference,
     * reported as success. Which is #21's shape exactly: the wrong value, saved, with
     * a green message.
     */
    private static function storedInConfig($key)
    {
        if (!isset(self::FIELDS[$key])) { return null; }
        $name = self::FIELDS[$key][0];
        return defined($name) ? constant($name) : null;
    }
}
