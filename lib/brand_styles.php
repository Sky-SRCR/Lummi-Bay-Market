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

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Every stored style, keyed by block type — what a snapshot carries. */
    public function all()
    {
        $out = [];
        foreach ($this->pdo->query("SELECT * FROM block_styles")->fetchAll() as $row) {
            $out[$row['block_type']] = $row;
        }
        return $out;
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

    /** `#rrggbb`, lowercased. The same rule the branding colours have always used. */
    public static function cleanColor($value, $fallback = '#ffffff')
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$value) ? strtolower($value) : $fallback;
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
