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
// standing anywhere. It is the audit half of §4w and it changes nothing: there is
// no undo in this app, and substituting a colour of our own is the exact defect
// #21 and #41 are about. Reporting is the whole job. A person picks the colour.
//
// **One predicate, three consequences.** `Color::isColor()` decides in all three
// places, but what an unreadable value *does* differs, and an audit that lumped
// them together would send somebody to the wrong screen:
//
//   canvas_elements.font_color  →  that Display cannot publish at all, until the
//                                  named block is given a colour. Loud, blocking,
//                                  and the only one anybody would ever notice.
//   displays.bg_val             →  the Screen quietly shows the stylesheet's
//                                  #1a1a2e instead of the stored colour. The CSSOM
//                                  discards what it cannot parse and says nothing.
//   block_styles.font_color     →  the worst of the three, and the one with no
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
require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/layout_store.php';
require_once __DIR__ . '/brand_styles.php';

class ColorAudit
{
    /** This Display refuses every publish until the block is given a colour. */
    const BLOCKS_PUBLISH = 'blocks-publish';
    /** The sign renders, in a colour nobody chose. */
    const WRONG_ON_SIGN  = 'wrong-on-sign';

    private $displays;
    private $layouts;
    private $styles;

    public function __construct(DisplayStore $displays, LayoutStore $layouts, BrandStyles $styles)
    {
        $this->displays = $displays;
        $this->layouts  = $layouts;
        $this->styles   = $styles;
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

        // Brand Standards are shared, so one bad row is every sign at once. Last in
        // the list and first in consequence — which is why the sentence says so
        // rather than leaving it to be inferred from the empty scope.
        foreach ($this->styles->all() as $type => $row) {
            $stored = isset($row['font_color']) ? $row['font_color'] : '';
            if ($stored === '' || Color::isColor($stored)) { continue; }
            $cosmetic[] = [
                'kind'  => self::WRONG_ON_SIGN,
                'scope' => '',
                'what'  => 'the Brand Standards colour for ' . $type,
                'value' => $stored,
                'consequence' => 'Every ' . $type . ' block on every sign renders in whatever '
                               . 'the browser inherits — black text on a dark canvas — because '
                               . 'this value is cleaned on the way in and not on the way out.',
                'fix'   => 'Settings → Brand Standards → ' . $type . ' → colour.',
            ];
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
