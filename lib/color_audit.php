<?php
// ============================================================
// COLOUR AUDIT — which stored colours nobody can read
// ============================================================
// #41 closed the way a colour nobody could read got written and re-written. It
// could not close the ones that were already there. A `font_color` holding `puce`
// — hand-edited, or stored before the rule existed — is now refused at the publish
// door with the block named, which is right, and which means the way to find out
// is for somebody to press Publish and be told no. Usually mid-change, in front of
// the sign they came to fix.
//
// This module asks that question of the whole database instead, before anybody is
// standing anywhere. It is the audit half of §4ac and it changes nothing: there is
// no undo in this app, and substituting a colour of our own is the exact defect
// #21 and #41 are about. Reporting is the whole job. A person picks the colour.
//
// **One predicate, four consequences.** `Color::isColor()` decides in all four
// places, but what an unreadable value *does* differs, and an audit that lumped
// them together would send somebody to the wrong screen:
//
//   canvas_elements.font_color  →  that Display cannot publish at all, until the
//                                  named block is given a colour. Loud, blocking,
//                                  and the only one anybody would ever notice.
//   displays.bg_val             →  the Screen quietly shows the stylesheet's
//                                  #1a1a2e instead of the stored colour. The CSSOM
//                                  discards what it cannot parse and says nothing.
//   branding_config.php         →  not a table and not a sign: the store's own colours,
//                                  interpolated into the `<style>` block on the Builder,
//                                  the Help page and the sign-in page. `SiteChrome::` answers
//                                  the documented default for one it cannot read, so
//                                  nothing looks broken and nothing says why. Worth
//                                  reporting because that file is generated, is
//                                  documented as hand-editable, and predates the rule
//                                  that made the Branding form refuse a bad colour.
//   block_styles.font_color     →  the worst of the four, and the one with no
//                                  refusal anywhere. BrandStyles cleans on the way
//                                  *in*, not on the way out, so a row edited by
//                                  hand is handed to every Screen as it stands.
//                                  Every branded block of that type — every price
//                                  on every sign — then renders in whatever the
//                                  browser inherits, which on a dark canvas is
//                                  black text nobody can read.
//
// A use-case module in the §2 sense: it owns the sweep, writes no SQL of its own,
// and returns findings that a caller turns into sentences. No transaction, because
// it takes no writes — the one of these three that reads and never writes.

require_once __DIR__ . '/color.php';
require_once __DIR__ . '/site_chrome.php';
require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/layout_store.php';
require_once __DIR__ . '/brand_styles.php';
require_once __DIR__ . '/brands.php';
// The other noun's table, for the one finding here that no customer can ever see.
require_once __DIR__ . '/workspace_themes.php';

class ColorAudit
{
    /** This Display refuses every publish until the block is given a colour. */
    const BLOCKS_PUBLISH = 'blocks-publish';
    /** The sign renders, in a colour nobody chose. */
    const WRONG_ON_SIGN  = 'wrong-on-sign';
    /** No sign is affected; the staff pages are drawing a default instead. */
    const WRONG_IN_APP   = 'wrong-in-app';

    private $displays;
    private $layouts;
    private $styles;
    private $brands;
    private $brand;
    private $themes;

    /**
     * @param array|null $brand key => raw brand colour, or null to read the real
     *                          `branding_config.php`. Passed in for the same reason
     *                          `SiteChrome::pick()` is pure: a `define()` cannot be undone,
     *                          so a test that could only reach the constants could only
     *                          ever exercise the values this machine happens to hold.
     */
    public function __construct(DisplayStore $displays, LayoutStore $layouts, BrandStyles $styles,
                                BrandStore $brands, ?array $brand = null,
                                ?WorkspaceThemeStore $themes = null)
    {
        $this->displays = $displays;
        $this->layouts  = $layouts;
        $this->styles   = $styles;
        $this->brands   = $brands;
        $this->brand    = $brand;
        $this->themes   = $themes;
    }

    /**
     * Every stored colour this app cannot read.
     *
     * Ordered so the blocking ones come first, then by Display, because a list that
     * opens with a cosmetic finding reads like a tidy-up rather than a sign that
     * cannot be published.
     *
     * Each finding is an array:
     *   kind    — BLOCKS_PUBLISH or WRONG_ON_SIGN
     *   scope   — the screen name tag, or '' for something shared by every sign
     *   what    — where to look, in the words the admin's own screens use
     *   value   — the stored string, exactly as it is stored
     *   consequence — what it is doing right now, as one sentence
     *   fix     — where a person goes to change it
     */
    public function findings()
    {
        $blocking = [];
        $cosmetic = [];

        foreach ($this->displays->all() as $display) {
            foreach ($this->layouts->unreadableFontColors($display) as $row) {
                $blocking[] = [
                    'kind'  => self::BLOCKS_PUBLISH,
                    'scope' => $display->tag(),
                    'what'  => self::blockLabel($row),
                    'value' => $row['font_color'],
                    'consequence' => 'This Display refuses every publish until this block '
                                   . 'is given a colour.',
                    'fix'   => 'Open ' . $display->tag() . ' in the Builder, select the block, '
                             . 'and pick a text colour.',
                ];
            }

            // Only when the Display is actually showing a colour. A Display on an
            // image background carries whatever `bg_val` held before the switch, and
            // reporting a value nothing reads would send somebody to fix a sign that
            // is not wrong.
            if ($display->backgroundType() === 'color'
                && !Color::isColor($display->backgroundValue())) {
                $cosmetic[] = [
                    'kind'  => self::WRONG_ON_SIGN,
                    'scope' => $display->tag(),
                    'what'  => 'the canvas background',
                    'value' => $display->backgroundValue(),
                    'consequence' => 'The Screen shows the default #1a1a2e instead. The browser '
                                   . 'discards a colour it cannot parse without saying so.',
                    'fix'   => 'Settings → Displays → ' . $display->tag() . ' → background colour.',
                ];
            }
        }

        // A Brand's standards are shared by every sign wearing it, so one bad row is a
        // whole venue at once. Read through allByBrand() rather than a query per Brand
        // — and rather than per *Display*, which would skip the standards of a Brand
        // nothing is currently assigned to. Those are exactly the rows nobody is
        // looking at, and the next Display assigned to that Brand inherits them.
        $names = [];
        foreach ($this->brands->all() as $brand) { $names[$brand->id()] = $brand->name(); }

        foreach ($this->styles->allByBrand() as $brandId => $rows) {
            // A Brand id with no Brand behind it — standards orphaned by a hand-deleted
            // row. Named by number so the finding still points somewhere.
            $brandName = isset($names[$brandId]) ? $names[$brandId] : 'brand #' . $brandId;

            foreach ($rows as $type => $row) {
                $stored = isset($row['font_color']) ? $row['font_color'] : '';
                if ($stored === '' || Color::isColor($stored)) { continue; }
                $cosmetic[] = [
                    'kind'  => self::WRONG_ON_SIGN,
                    'scope' => '',
                    'what'  => 'the ' . $brandName . ' brand standard colour for ' . $type,
                    'value' => $stored,
                    'consequence' => 'Every ' . $type . ' block on every sign wearing '
                                   . $brandName . ' renders in whatever the browser inherits — '
                                   . 'black text on a dark canvas — because this value is '
                                   . 'cleaned on the way in and not on the way out.',
                    'fix'   => 'Settings → Display Branding → ' . $brandName . ' → ' . $type . ' → colour.',
                ];
            }
        }

        // The palette is offered as swatches and never enforced, so an unreadable slot
        // paints nothing — it simply is not offered. Reported anyway, and this is the
        // #21 line: a swatch that quietly vanishes looks like a palette somebody chose
        // to leave short, so nobody investigates and the value stays wrong.
        foreach ($this->brands->all() as $brand) {
            foreach ($brand->unreadablePalette() as $bad) {
                $cosmetic[] = [
                    'kind'  => self::WRONG_ON_SIGN,
                    'scope' => '',
                    'what'  => strtolower($bad['label']) . ' on the ' . $brand->name() . ' brand',
                    'value' => $bad['value'],
                    'consequence' => 'That swatch is not offered when somebody picks a colour for '
                                   . 'a sign wearing ' . $brand->name() . '. Nothing on a screen is '
                                   . 'wrong; the palette is one colour short and does not say why.',
                    'fix'   => 'Settings → Display Branding → ' . $brand->name() . ' → palette.',
                ];
            }
        }

        // Last, because it is the only one no customer ever sees. Still reported: the
        // pages look deliberate, so nobody investigates, and the value stays wrong
        // until someone opens the Branding tab for an unrelated reason.
        foreach (SiteChrome::unreadable($this->brand) as $bad) {
            $cosmetic[] = [
                'kind'  => self::WRONG_IN_APP,
                'scope' => '',
                'what'  => 'the ' . $bad['label'] . ' colour in branding_config.php',
                'value' => $bad['value'],
                'consequence' => 'The Builder, the Help page and the sign-in page draw the '
                               . 'default ' . SiteChrome::DEFAULTS[$bad['key']] . ' instead. No sign '
                               . 'uses this colour, so nothing on the shop floor is wrong.',
                'fix'   => 'Settings → Branding → ' . $bad['label'] . '. Saving that form '
                         . 'rewrites the file.',
            ];
        }

        // And the same question one table further out (v2 step 5). A Workspace Theme's
        // columns are NOT NULL with defaults, so nothing the form can do produces one of
        // these — this is for a row somebody has been in with a database client, which is
        // the same door `branding_config.php` above is reported through and the same one
        // `tools/audit_colors.php` exists for. The store is optional so a caller with no
        // themes table to reach cannot be stopped from auditing everything else; the
        // self-test asserts that `audit_colors.php` does pass one, because an audit that
        // quietly skips a table is worse than one that cannot run.
        if ($this->themes !== null) {
            foreach ($this->themes->all() as $theme) {
                foreach ($theme->unreadable() as $bad) {
                    $cosmetic[] = [
                        'kind'  => self::WRONG_IN_APP,
                        'scope' => '',
                        'what'  => 'the ' . $bad['label'] . ' colour of the ' . $theme->name()
                                 . ' workspace theme',
                        'value' => $bad['value'],
                        'consequence' => 'Anybody wearing that theme sees the default '
                                       . SiteChrome::DEFAULTS[$bad['key']] . ' instead. No sign '
                                       . 'uses this colour, so nothing on the shop floor is wrong.',
                        'fix'   => 'Settings → Site Branding → Workspace Themes → ' . $theme->name()
                                 . ' → ' . $bad['label'] . '.',
                    ];
                }
            }
        }

        return array_merge($blocking, $cosmetic);
    }

    /**
     * Where to look for one block, in the words the admin's own screens use.
     *
     * Not "Block 3". The publish refusal counts blocks by their position in the
     * payload the Builder posts, and this reads rows out of a table — the two
     * numbers would agree often enough to be trusted and not always, which is worse
     * than not offering one. Type, style and position are what a person actually
     * looks for on a canvas.
     */
    private static function blockLabel(array $row)
    {
        $label = $row['type'];
        if (!empty($row['block_subtype']) && $row['block_subtype'] !== 'free') {
            $label .= '/' . $row['block_subtype'];
        }
        $label = 'the ' . $label . ' block at '
               . intval($row['x_pos']) . ',' . intval($row['y_pos']);
        // Worth saying. A hidden block is not on the sign and is still in the
        // payload, so it refuses the publish from somewhere nobody is looking.
        if (!empty($row['hidden'])) { $label .= ' (hidden)'; }
        return $label;
    }
}
