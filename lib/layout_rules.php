<?php
// ============================================================
// LAYOUT RULES
// ============================================================
// What a publishable layout looks like — as a pure function over the decoded
// payload, with no database, no Display and no transaction anywhere near it.
//
// It exists because of the shape the publish path had before it. Every field
// arrived through `intval($el['x_pos'] ?? 0)` or `$el['font_family'] ?? 'Arial'`,
// which is not a rule, it is a coercion: "abc" became 0, an array became 1, a
// width of 999999999 became a column overflow, and an unknown `type` became a row
// the ENUM stored as ''. None of those reported anything. The layout published,
// the sign changed, and there is no undo (#29, #30, #31, #32).
//
// So the rule is the one this app applies everywhere else: **refuse the write**.
// A publish is all-or-nothing already — it deletes the layout and re-inserts it —
// so a payload that cannot be stored faithfully must be turned away at the door,
// before the DELETE, with a sentence saying which block and what about it.
//
// Pure on purpose. The one thing the self-test could always cover exhaustively is
// a function with no I/O (see schema.php's decision half, BUILD-REFERENCE §4o),
// and "is this layout publishable?" is exactly that question. LayoutStore decides
// *when* to ask; this file is the whole answer.
//
// It is also where the element vocabulary lives. SCHEMA_ELEMENT_TYPE_ENUM and
// SCHEMA_BLOCK_SUBTYPE_ENUM in lib/schema.php are built from these two arrays
// rather than written out again, so the list the publish accepts and the list the
// column stores cannot drift apart — which is the drift that let an unknown type
// through in the first place.

require_once __DIR__ . '/color.php';

/**
 * The vocabulary and the bounds, and the check that applies them.
 *
 * Every constant here is either a column definition from schema.sql or a
 * judgement about what a sign can plausibly hold. The column ones are not
 * negotiable: exceeding them is a silent truncation on a MySQL that is not in
 * strict mode and a failed publish on one that is, and neither tells the person
 * what happened.
 */
class LayoutRules
{
    /** The `canvas_elements.type` vocabulary. */
    const ELEMENT_TYPES = ['section', 'text', 'image', 'video', 'carousel', 'marquee', 'table'];

    /** The `canvas_elements.block_subtype` vocabulary — the six branded types plus 'free'. */
    const BLOCK_SUBTYPES = ['free', 'section_header', 'item_title', 'item_title_2', 'price',
                            'price_2', 'description'];

    /**
     * Position and size bounds.
     *
     * DisplayStore::CANVAS_MAX is 10000, and a block may legitimately sit partly
     * off the canvas — dragged half out of frame, or parked to one side while
     * somebody works. Twice the largest canvas is room for that and still refuses
     * a coordinate that can only be a wrong-shaped value or a hostile one.
     */
    const POS_MIN  = -20000;
    const POS_MAX  =  20000;
    const SIZE_MIN =      1;
    const SIZE_MAX =  20000;

    const FONT_SIZE_MIN = 1;
    const FONT_SIZE_MAX = 2000;

    /** z_index is stored as written but floored at 1 by the insert; sort_order is re-numbered. */
    const Z_INDEX_MIN  = 1;
    const Z_INDEX_MAX  = 100000;
    const SORT_ORDER_MIN = 0;
    const SORT_ORDER_MAX = 100000;

    /**
     * Line height, clamped rather than refused — the owner's decision on #32.
     *
     * `line_height` is DECIMAL(4,2), so anything from 100 upwards cannot be stored
     * at all, and the value arrived through `number_format(floatval(…), 2)`, whose
     * *default thousands separator* turned 2000 into the string "2,000.00". Below
     * 0.5 the lines overlap into an unreadable smear and above 5 a price is alone
     * on the sign; neither is a layout anybody meant.
     */
    const LINE_HEIGHT_MIN = 0.5;
    const LINE_HEIGHT_MAX = 5.0;
    const LINE_HEIGHT_DEFAULT = 1.4;

    /**
     * Column widths from schema.sql, checked in bytes.
     *
     * MySQL counts VARCHAR in characters, so a byte count is the stricter of the
     * two: it refuses a handful of multibyte strings that would in fact have fit.
     * That is the safe direction, and it costs nothing real — these are font
     * names, CSS keywords and upload paths, none of which are multibyte in this
     * app. It also avoids depending on mbstring, which nothing else here needs.
     */
    const FONT_FAMILY_MAX = 100;
    const FONT_COLOR_MAX  = 50;
    const FONT_WEIGHT_MAX = 20;
    const FONT_STYLE_MAX  = 20;
    const TEXT_ALIGN_MAX  = 16;
    const SECTION_BG_MAX  = 255;

    /** `manual_content` is TEXT, which is 65535 *bytes*. */
    const MANUAL_CONTENT_MAX = 65535;

    /** A temp id is an array key and a payload-local handle, never stored. */
    const TEMP_ID_MAX = 190;

    /**
     * Is this layout publishable?
     *
     * @param array $elements the decoded payload, exactly as PublishRequest holds it
     * @return LayoutCheck ok, or the first problem with a count of the rest
     */
    public static function check(array $elements)
    {
        $problems = [];
        $seenTempIds = [];

        foreach ($elements as $index => $el) {
            $where = 'Block ' . (self::ordinalOf($elements, $index));

            if (!is_array($el)) {
                $problems[] = $where . ' is not a block at all (' . self::describe($el) . ').';
                continue;
            }

            $type = self::valueOf($el, 'type', 'text');
            if (!self::isTextLike($type) || !in_array((string)$type, self::ELEMENT_TYPES, true)) {
                $problems[] = $where . ' has an unknown type (' . self::describe($type) . '). '
                            . 'Blocks may be: ' . implode(', ', self::ELEMENT_TYPES) . '.';
                // Everything below reads fields whose meaning depends on the type,
                // so there is nothing useful left to say about this block.
                continue;
            }
            $type  = (string)$type;
            $where = 'Block ' . self::ordinalOf($elements, $index) . ' (' . $type . ')';

            if ($type !== 'section') {
                $subtype = self::valueOf($el, 'block_subtype', 'free');
                if (!self::isTextLike($subtype) || !in_array((string)$subtype, self::BLOCK_SUBTYPES, true)) {
                    $problems[] = $where . ' has an unknown block style ('
                                . self::describe($subtype) . ').';
                }
            }

            // ---- Temp ids ---------------------------------------------------
            // A temp id is the handle a section is parented by. The store skips an
            // empty one (`!empty`), so only a non-empty one is tracked here — and
            // PHP's `empty` counts the string "0", which is why '0' is deliberately
            // not a usable handle rather than quietly meaning "no handle".
            $tempId = self::valueOf($el, 'temp_id', null);
            if ($tempId !== null && !self::isUsableTempId($tempId)) {
                $problems[] = $where . ' has a temporary id that cannot be used as one ('
                            . self::describe($tempId) . ').';
            } elseif ($type === 'section' && $tempId !== null && !empty($tempId)) {
                $key = (string)$tempId;
                if (isset($seenTempIds[$key])) {
                    // #31. Two sections sharing a handle used to overwrite one
                    // another in the temp-id map, so every block belonging to the
                    // first one was inserted into the second — a whole column of
                    // prices moved to the other side of the sign, silently, with
                    // the publish reporting success.
                    $problems[] = $where . ' shares its temporary id ("' . $key . '") with block '
                                . $seenTempIds[$key] . '. Two sections cannot have the same one — '
                                . 'the blocks inside them would be moved into whichever came last.';
                } else {
                    $seenTempIds[$key] = self::ordinalOf($elements, $index);
                }
            }

            $parent = self::valueOf($el, 'parent_temp_id', null);
            if ($parent !== null && !empty($parent) && !self::isUsableTempId($parent)) {
                $problems[] = $where . ' names a parent section that cannot be a temporary id ('
                            . self::describe($parent) . ').';
            }

            $dbId = self::valueOf($el, 'db_id', null);
            if ($dbId !== null && !empty($dbId) && !self::isIntLike($dbId)) {
                $problems[] = $where . ' has a section id that is not a number ('
                            . self::describe($dbId) . ').';
            }

            // ---- Geometry ----------------------------------------------------
            self::checkInt($problems, $where, $el, 'x_pos',  'horizontal position', self::POS_MIN,  self::POS_MAX);
            self::checkInt($problems, $where, $el, 'y_pos',  'vertical position',   self::POS_MIN,  self::POS_MAX);
            self::checkInt($problems, $where, $el, 'width',  'width',               self::SIZE_MIN, self::SIZE_MAX);
            self::checkInt($problems, $where, $el, 'height', 'height',              self::SIZE_MIN, self::SIZE_MAX);
            self::checkInt($problems, $where, $el, 'z_index', 'layer',              self::Z_INDEX_MIN, self::Z_INDEX_MAX);
            self::checkInt($problems, $where, $el, 'sort_order', 'order',           self::SORT_ORDER_MIN, self::SORT_ORDER_MAX);

            if ($type !== 'section') {
                self::checkInt($problems, $where, $el, 'font_size', 'text size',
                               self::FONT_SIZE_MIN, self::FONT_SIZE_MAX);
            }

            // ---- The asset link ----------------------------------------------
            // `intval` on an array is 1, with no warning at all, so a wrong-shaped
            // asset_id used to point the block at library row 1 — whatever that
            // happens to be on this installation.
            $assetId = self::valueOf($el, 'asset_id', null);
            if ($assetId !== null && !empty($assetId) && !self::isIntLike($assetId)) {
                $problems[] = $where . ' points at a library item that is not a number ('
                            . self::describe($assetId) . ').';
            }

            // ---- Line height (#32) ---------------------------------------------
            // Clamped, not refused — but only once it is a number. A line height of
            // "tall" is a wrong-shaped value like any other.
            $lineHeight = self::valueOf($el, 'line_height', null);
            if ($lineHeight !== null && !self::isNumberLike($lineHeight)) {
                $problems[] = $where . ' has a line height that is not a number ('
                            . self::describe($lineHeight) . ').';
            }

            // ---- Flags ---------------------------------------------------------
            foreach (['locked' => 'locked flag', 'hidden' => 'hidden flag'] as $key => $label) {
                $flag = self::valueOf($el, $key, null);
                if ($flag !== null && !self::isFlagLike($flag)) {
                    $problems[] = $where . ' has a ' . $label . ' that is not a yes or a no ('
                                . self::describe($flag) . ').';
                }
            }

            // ---- Stored strings --------------------------------------------------
            $strings = [
                'section_bg'  => ['background image',  self::SECTION_BG_MAX],
            ];
            if ($type !== 'section') {
                $strings['font_family'] = ['font',             self::FONT_FAMILY_MAX];
                $strings['font_color']  = ['text colour',      self::FONT_COLOR_MAX];
                $strings['font_weight'] = ['font weight',      self::FONT_WEIGHT_MAX];
                $strings['font_style']  = ['font style',       self::FONT_STYLE_MAX];
                $strings['text_align']  = ['text alignment',   self::TEXT_ALIGN_MAX];
            }
            foreach ($strings as $key => $spec) {
                self::checkString($problems, $where, $el, $key, $spec[0], $spec[1]);
            }

            // ---- Colour semantics (#41) ----------------------------------------
            // `font_color` is the one stored string whose *meaning* has to be checked
            // and not just its shape, because of what reads it back. The Builder loads
            // a layout by assigning each colour to `block.style.color`, and the CSSOM
            // discards a value it cannot parse without saying so — leaving the property
            // empty, which the publish payload then rendered as `#000000`. So an
            // unreadable stored colour did not stay unreadable: opening the Display and
            // pressing Publish, changing nothing, rewrote that block black. On a canvas
            // whose default is #1a1a2e the block did not change colour so much as
            // vanish, and there is no undo to notice it with.
            //
            // Refused rather than corrected, for §4v's reason: a publish overwrites, so
            // a colour nobody can read is declined at the door with the block named,
            // and an admin fixes it deliberately. Blank stays legal — it means "no
            // colour of its own", which is what every branded block carries.
            //
            // Only when the length check above passed, so one wrong value is one
            // problem rather than two.
            if ($type !== 'section') {
                $fontColor = self::valueOf($el, 'font_color', null);
                if (is_string($fontColor) && $fontColor !== ''
                    && strlen($fontColor) <= self::FONT_COLOR_MAX
                    && !Color::isColor($fontColor)) {
                    $problems[] = $where . ' has a text colour that is not a colour ('
                                . self::describe($fontColor) . '). Colours are written as '
                                . 'six hexadecimal digits after a hash, like #1a1a2e.';
                }
            }

            // manual_content is the block's own content — a price, a JSON carousel,
            // an upload path. Only its shape and its size are this file's business;
            // what it *means* is the type's, and text is stripped to plain text by
            // the store on the way in (ADR-0002).
            self::checkString($problems, $where, $el, 'manual_content', 'content',
                              self::MANUAL_CONTENT_MAX);
        }

        return $problems ? LayoutCheck::refused($problems) : LayoutCheck::ok();
    }

    /**
     * A line height as it should be stored: inside the bounds, and written plain.
     *
     * `number_format($v, 2)` — the old call — uses a comma for thousands by
     * default, so a line height of 2000 was handed to a DECIMAL(4,2) column as the
     * string "2,000.00". The explicit empty separator is the fix for that half;
     * the clamp is the fix for the value being 2000 in the first place.
     *
     * Returns a string rather than a float so the caller cannot reintroduce a
     * locale-formatted one on the way to the placeholder.
     */
    public static function lineHeight($value)
    {
        if (!self::isNumberLike($value)) { $value = self::LINE_HEIGHT_DEFAULT; }
        $n = (float)$value;
        if (!is_finite($n)) { $n = self::LINE_HEIGHT_DEFAULT; }
        if ($n < self::LINE_HEIGHT_MIN) { $n = self::LINE_HEIGHT_MIN; }
        if ($n > self::LINE_HEIGHT_MAX) { $n = self::LINE_HEIGHT_MAX; }
        return number_format($n, 2, '.', '');
    }

    /**
     * These two arrays as MySQL writes an ENUM definition.
     *
     * lib/schema.php compares this against `information_schema`, which renders a
     * column type as `enum('a','b')` — lowercase keyword, single quotes, no spaces.
     * Generating it is what keeps the accepted vocabulary and the stored vocabulary
     * the same list.
     */
    public static function enumSql(array $values)
    {
        $quoted = [];
        foreach ($values as $value) { $quoted[] = "'" . $value . "'"; }
        return 'enum(' . implode(',', $quoted) . ')';
    }

    /**
     * Can this be an array key, and mean the same thing on both sides of the map?
     *
     * Strings and integers only. An array subscripted here is a TypeError on PHP 8
     * — which publish() catches, so the outcome was already a refusal, but one
     * reported as "Publish failed" with nothing said about why.
     */
    public static function isUsableTempId($value)
    {
        if (is_int($value)) { return true; }
        if (!is_string($value)) { return false; }
        return strlen($value) <= self::TEMP_ID_MAX;
    }

    // ---- Field checks --------------------------------------------------------

    private static function checkInt(array &$problems, $where, array $el, $key, $label, $min, $max)
    {
        $value = self::valueOf($el, $key, null);
        if ($value === null) { return; }          // absent: the insert's default stands

        if (!self::isIntLike($value)) {
            $problems[] = $where . ' has a ' . $label . ' that is not a whole number ('
                        . self::describe($value) . ').';
            return;
        }
        $n = intval($value);
        if ($n < $min || $n > $max) {
            $problems[] = $where . ' has a ' . $label . ' of ' . $n
                        . ', which is outside ' . $min . ' to ' . $max . '.';
        }
    }

    private static function checkString(array &$problems, $where, array $el, $key, $label, $max)
    {
        $value = self::valueOf($el, $key, null);
        if ($value === null) { return; }

        if (!self::isTextLike($value)) {
            $problems[] = $where . ' has a ' . $label . ' that is not text ('
                        . self::describe($value) . ').';
            return;
        }
        $length = strlen((string)$value);
        if ($length > $max) {
            $problems[] = $where . ' has a ' . $label . ' of ' . $length
                        . ' characters, and the most that can be stored is ' . $max . '.';
        }
    }

    // ---- Shapes --------------------------------------------------------------
    // Each of these mirrors what the insert would do with the value, which is the
    // only way the check and the write can agree about what "absent" means.

    /**
     * A field as the insert reads it: `$el['x'] ?? $default` treats a null and a
     * missing key identically, so this has to as well.
     */
    private static function valueOf(array $el, $key, $default)
    {
        return isset($el[$key]) ? $el[$key] : $default;
    }

    /** An integer, or something that is unambiguously one. Booleans are not. */
    private static function isIntLike($value)
    {
        if (is_bool($value)) { return false; }
        if (is_int($value))  { return true; }
        if (is_float($value)) { return is_finite($value) && floor($value) == $value; }
        if (is_string($value)) { return preg_match('/^\s*-?\d+\s*$/', $value) === 1; }
        return false;
    }

    /** A number of any kind — line height is the only field that may be fractional. */
    private static function isNumberLike($value)
    {
        if (is_bool($value)) { return false; }
        if (is_int($value))  { return true; }
        if (is_float($value)) { return is_finite($value); }
        if (is_string($value)) { return is_numeric(trim($value)); }
        return false;
    }

    /** Something that can be stored in a text column without PHP inventing a rendering. */
    private static function isTextLike($value)
    {
        return is_string($value) || is_int($value) || is_float($value);
    }

    /** A yes or a no, however the client chose to spell it. */
    private static function isFlagLike($value)
    {
        return is_bool($value) || self::isIntLike($value);
    }

    // ---- Reporting -----------------------------------------------------------

    /**
     * Which block this is, counting from 1.
     *
     * From the *key* when the payload is a list, because that is what a person
     * counting down the canvas would arrive at. A JSON object with named keys
     * decodes to a non-list array, and there "block 3" would be a lie — so the
     * key itself is named instead.
     */
    private static function ordinalOf(array $elements, $index)
    {
        return is_int($index) ? ($index + 1) : ('"' . $index . '"');
    }

    /** What arrived, in a few words a person can match against their canvas. */
    private static function describe($value)
    {
        if (is_array($value))  { return 'a list of ' . count($value) . ' things'; }
        if (is_bool($value))   { return $value ? 'true' : 'false'; }
        if (is_null($value))   { return 'nothing'; }
        if (is_object($value)) { return 'an object'; }
        $text = (string)$value;
        if ($text === '') { return 'an empty value'; }
        if (strlen($text) > 40) { $text = substr($text, 0, 37) . '…'; }
        return '"' . $text . '"';
    }
}

/**
 * The outcome of checking a layout, as a value.
 *
 * Carries every problem it found, and prints the first one. Finding them all and
 * showing one is deliberate: somebody looking at a refused publish needs the next
 * thing to fix, not an audit, and a list of forty is read as "this is broken"
 * rather than as forty fixable things. The count is there so a payload that is
 * wrong all over does not look like a single typo.
 */
class LayoutCheck
{
    private $problems;

    private function __construct(array $problems)
    {
        $this->problems = $problems;
    }

    public static function ok() { return new self([]); }
    public static function refused(array $problems) { return new self($problems); }

    public function isOk() { return !$this->problems; }

    /** Every problem found, in payload order. For the self-test and for a log line. */
    public function problems() { return $this->problems; }

    /** What to tell the person who pressed Publish. Empty when there is nothing wrong. */
    public function message()
    {
        if (!$this->problems) { return ''; }

        $rest = count($this->problems) - 1;
        $more = $rest > 0
            ? ' There ' . ($rest === 1 ? 'is 1 other problem' : 'are ' . $rest . ' other problems')
              . ' with this layout.'
            : '';

        return 'This layout could not be published, so nothing was saved and nothing on the '
             . 'screen changed. ' . $this->problems[0] . $more
             . ' Your work is still on screen.';
    }
}
