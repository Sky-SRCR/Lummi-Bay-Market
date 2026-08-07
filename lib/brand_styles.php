<?php
// ============================================================
// BRAND STANDARDS — the shared typography of the branded blocks
// ============================================================
// One row per branded block type in `block_styles`, shared by every Display
// (ADR decision C). This module is the only place that writes that table.
//
// It exists because there were two writers — the Admin Panel form and the
// Builder's api.php endpoint — and they disagreed about the thing that matters
// most: what a POST missing half its fields means. The endpoint skipped a type
// it had no data for; the form wrote all six regardless, falling back to black
// Arial 16, so a truncated or replayed form reset the store's entire brand
// typography on every sign and reported success. Neither validated anything: the
// min/max on the size box and the weight dropdown existed only in HTML, so a
// crafted POST could set every price on every sign to size 0 — invisible — and
// it would reach the Screens on the next 30-second poll.
//
// Two properties this table needs and only an owning module can promise:
//
//   · Absent means untouched. A save writes the types it was actually given, and
//     says how many. Silence is never a value.
//   · Every stored value renders. These land in `element.style.fontSize` and
//     friends on a wall-mounted Screen with nobody watching, so a value that
//     cannot render is not stored in the first place.
//
// What this module deliberately does NOT own: the decision about whether a save
// is allowed at all. That is the edit lock's business, and it lives with the
// callers — see DisplayStore::editedByAnyoneElse.

require_once __DIR__ . '/color.php';

/** The six branded block types, in the order the admin form shows them. */
class BrandStyles
{
    private $pdo;

    /**
     * Every block type that carries brand typography. A `block_subtype` outside
     * this list is free text and styles itself from its own element row.
     */
    public static function types()
    {
        return ['section_header', 'item_title', 'item_title_2', 'price', 'price_2', 'description'];
    }

    /**
     * What a field is when the row does not say.
     *
     * The column defaults in `schema.sql`, which is where they were already written
     * down and where a row created outside this app picks them up. Not the seed
     * values — those differ per block type on purpose, and a fallback that guessed
     * `price` meant red would be inventing a brand rather than reporting one.
     */
    const DEFAULTS = [
        'font_family' => 'Arial',
        'font_size'   => 16,
        'font_color'  => '#000000',
        'font_weight' => 'normal',
        'font_style'  => 'normal',
        'line_height' => 1.4,
    ];

    /** Each field in the words the Brand Standards form puts above its column. */
    const FIELD_LABELS = [
        'font_family' => 'Font family',
        'font_size'   => 'Size',
        'font_color'  => 'Colour',
        'font_weight' => 'Weight',
        'font_style'  => 'Style',
        'line_height' => 'Line height',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Every stored style, keyed by block type — what a snapshot carries.
     *
     * **Raw, on purpose.** These rows are cleaned on the way *in*, which is only a
     * promise about values this module wrote; a row edited by hand, or written before
     * a rule existed, comes back exactly as it is. `readable()` below is what turns
     * one into what will actually render, and `ColorAudit` reads this method rather
     * than that one — an audit whose source had already been tidied would find
     * nothing and say so.
     */
    public function all()
    {
        $out = [];
        foreach ($this->pdo->query("SELECT * FROM block_styles")->fetchAll() as $row) {
            $out[$row['block_type']] = $row;
        }
        return $out;
    }

    /**
     * What one stored row will actually render as.
     *
     * The cleaners below were only ever reached on the way in, so a page that read a
     * row and put it straight into a `style` attribute was trusting a promise this
     * module never made about rows it did not write. `font-family` was the sharp
     * edge: escaping stops a value ending the *attribute*, and nothing stopped it
     * ending the *declaration* — `Arial; position: fixed; top: 0` is, after escaping,
     * exactly that inside a style attribute. Same shape as the brand colours in
     * §4ac, one boundary further in.
     *
     * Every field goes through the same function `save()` uses, so what a page draws
     * and what a later save would store cannot disagree. An absent field falls back
     * to DEFAULTS first, so a row that predates a column reads as the app's documented
     * value rather than as whatever `cleanSize(null)` clamps to.
     *
     * This changes nothing stored. `unrenderable()` is how a caller says so.
     */
    public static function readable(array $row)
    {
        return [
            'font_family' => self::cleanFamily(self::field($row, 'font_family')),
            'font_size'   => self::cleanSize(self::field($row, 'font_size')),
            // No fallback passed: cleanColor()'s own is what save() would store, and
            // the two answering differently is the whole failure this method exists to
            // stop. Absent is handled a line earlier, by field() — an absent colour and
            // an unreadable one are different questions with different answers (#21).
            'font_color'  => self::cleanColor(self::field($row, 'font_color')),
            'font_weight' => self::cleanWeight(self::field($row, 'font_weight')),
            'font_style'  => self::cleanStyle(self::field($row, 'font_style')),
            'line_height' => self::cleanLineHeight(self::field($row, 'line_height')),
        ];
    }

    /**
     * Every field of one row whose stored value is not the value that renders.
     *
     * The other half of not substituting silently, which is the whole of #21: a page
     * that quietly draws `#ffffff` where the row says `gold` looks deliberate, so
     * nobody investigates, and the next save stores the substitute for good. Each
     * entry names the field in the words the form uses, quotes what is stored, and
     * says what is being drawn instead.
     *
     * Two fields are compared as numbers rather than as text, because they are stored
     * in numeric columns and `1.4` and `1.40` are the same line height — MySQL returns
     * one and SQLite the other, and a difference of engine is not a fault to report.
     *
     * @return array list of ['field','label','value','instead']
     */
    public static function unrenderable(array $row)
    {
        $renders = self::readable($row);
        $bad     = [];
        foreach (self::FIELD_LABELS as $field => $label) {
            // Absent is not wrong. A column added after a row was written has no
            // value here, and DEFAULTS is the right answer with nothing to fix.
            if (!array_key_exists($field, $row) || $row[$field] === null) { continue; }
            $stored = $row[$field];
            $same   = in_array($field, ['font_size', 'line_height'], true)
                    ? (floatval($stored) === floatval($renders[$field]))
                    : (strcasecmp((string)$stored, (string)$renders[$field]) === 0);
            if ($same) { continue; }
            $bad[] = ['field' => $field, 'label' => $label,
                      'value' => $stored, 'instead' => (string)$renders[$field]];
        }
        return $bad;
    }

    /** One field of a stored row, or the app's documented value when it has none. */
    private static function field(array $row, $name)
    {
        return (array_key_exists($name, $row) && $row[$name] !== null)
             ? $row[$name] : self::DEFAULTS[$name];
    }

    /**
     * Write the types present in $styles, and only those.
     *
     * $styles is a map of block_type => ['font_family' => …, 'font_size' => …, …].
     * A type that is absent is left exactly as it is; a field that is absent
     * within a present type falls back to that field's safe default, because the
     * caller has told us it meant to save this type.
     *
     * Returns the number of types written, so a caller can tell "saved nothing"
     * from "saved" instead of reporting success either way. A row that does not
     * exist is not created here — see the seed in lib/schema.php, which is what
     * guarantees all six exist.
     */
    public function save(array $styles)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE block_styles
                SET font_family = ?, font_size = ?, font_color = ?,
                    font_weight = ?, font_style = ?, line_height = ?
              WHERE block_type = ?"
        );

        $saved = 0;
        foreach (self::types() as $type) {
            if (!isset($styles[$type]) || !is_array($styles[$type])) { continue; }
            $s = $styles[$type];
            $stmt->execute([
                self::cleanFamily($s['font_family'] ?? null),
                self::cleanSize($s['font_size'] ?? null),
                self::cleanColor($s['font_color'] ?? null),
                self::cleanWeight($s['font_weight'] ?? null),
                self::cleanStyle($s['font_style'] ?? null),
                self::formatLineHeight($s['line_height'] ?? null),
                $type,
            ]);
            $saved++;
        }
        return $saved;
    }

    // ---- Validation ---------------------------------------------------------
    // Every one of these is reachable from a hand-built POST, and the result of a
    // bad value is not an error message but a sign in the window rendering wrong,
    // on every Display at once, with no undo and no publish to roll back.

    /**
     * A font stack, as a plain name list. Anything that could close the CSS
     * declaration it lands in is dropped rather than escaped: the Viewer assigns
     * this through `style.fontFamily`, so a value with a `;` or a `}` in it is
     * discarded by the CSSOM anyway — silently, which is the worst outcome.
     */
    public static function cleanFamily($value)
    {
        $value = trim((string)$value);
        // Refused whole rather than stripped down. Editing "Arial;position:fixed"
        // into "Arialpositionfixed" would store a font nobody asked for and nothing
        // would say so — the sign would just quietly render in the fallback face.
        if ($value === '' || strlen($value) > 100) { return 'Arial'; }
        return preg_match('/^[A-Za-z0-9 ,\'\-]+$/', $value) ? $value : 'Arial';
    }

    /** Points, within what a canvas can actually show. 0 is invisible; huge is one letter. */
    public static function cleanSize($value)
    {
        $n = intval($value);
        if ($n < 8)   { return 8; }
        if ($n > 400) { return 400; }
        return $n;
    }

    /**
     * `#rrggbb`, lowercased, or the fallback.
     *
     * Still a clamp rather than a refusal, and deliberately: this module's contract
     * is that every stored value renders (see the header), because these land on a
     * wall-mounted Screen with nobody watching. What changed with #21 is only that
     * the *rule* is Color's now rather than a fourth private copy of the regex — the
     * four copies disagreeing about the substitute is what made "saved" mean four
     * different things.
     *
     * The caller that has an admin in front of it does not use this to decide
     * whether to accept the form; admin_panel.php asks Color directly and refuses.
     * By the time a value reaches here it has already been through that, so the
     * fallback covers the API path and a hand-built POST, not a mistyped swatch.
     */
    public static function cleanColor($value, $fallback = '#ffffff')
    {
        $color = Color::read($value);
        return $color !== '' ? $color : $fallback;
    }

    public static function cleanWeight($value)
    {
        return ((string)$value === 'bold') ? 'bold' : 'normal';
    }

    public static function cleanStyle($value)
    {
        return ((string)$value === 'italic') ? 'italic' : 'normal';
    }

    /**
     * A multiplier, clamped to what the column can hold. DECIMAL(4,2) means two
     * decimals and a maximum of 99.99 — and `number_format` with its default
     * thousands separator produced "1,000.00" for anything larger, which is not a
     * decimal literal at all and failed the whole write.
     */
    public static function cleanLineHeight($value)
    {
        $n = floatval($value);
        if (!is_finite($n) || $n < 0.5) { return 1.4; }
        if ($n > 5)                     { return 5.0; }
        return $n;
    }

    /** The stored form: two decimals, no separator, safe to bind to DECIMAL(4,2). */
    public static function formatLineHeight($value)
    {
        return number_format(self::cleanLineHeight($value), 2, '.', '');
    }
}
