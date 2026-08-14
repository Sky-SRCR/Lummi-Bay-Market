<?php
// ============================================================
// BRAND STANDARDS — the typography a Brand paints its blocks with
// ============================================================
// One row per branded block type **per Brand** in `block_styles`. This module is
// the only place that writes that table.
//
// It was one row per block type, shared by every Display (roadmap decision C), and
// ADR-0011 reversed that: the installation stopped being one store, and one set of
// colours across a restaurant, a bar and a casino floor is not a shared look but a
// defect that reaches every screen. The table is keyed on `(brand_id, block_type)`
// now, and every method here takes the Brand it is about.
//
// **Re-scoped and not replaced**, which is the whole of what changed. Both
// properties below are exactly what they were; they are now promises about one
// Brand's six rows rather than about the installation's six. Nothing about
// validation, rendering or the absent-versus-unreadable line moved.
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

    /**
     * Where each branded block type starts, for a Brand that has just been created.
     *
     * Starting points and not a copy of anybody's values — the store edits these in
     * Admin Panel → Display Branding and its own numbers win. They differ per type on
     * purpose, which is what makes a new Brand look like a sign rather than like six
     * identical lines, and it is also why DEFAULTS above is *not* this list: a
     * fallback that guessed `price` meant red would be inventing a brand rather than
     * reporting one.
     *
     * Owned here rather than in `lib/schema.php`, which is what re-keying on the
     * Brand made necessary: convergence seeds these for a database that predates
     * Brands, `BrandAdmin` seeds them for every Brand created afterwards, and
     * `schema.sql` writes them for a fresh install. Three writers of one list is
     * three chances for a new Brand to start somewhere the last one did not.
     */
    const STARTING_POINTS = [
        'section_header' => ['Arial', 36, '#ffffff', 'bold',   'normal', 1.30],
        'item_title'     => ['Arial', 24, '#ffffff', 'bold',   'normal', 1.30],
        'item_title_2'   => ['Arial', 24, '#27ae60', 'bold',   'normal', 1.30],
        'price'          => ['Arial', 30, '#e74c3c', 'bold',   'normal', 1.20],
        'price_2'        => ['Arial', 30, '#e74c3c', 'bold',   'normal', 1.20],
        'description'    => ['Arial', 16, '#bdc3c7', 'normal', 'normal', 1.40],
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
     * One Brand's stored styles, keyed by block type — what a snapshot carries.
     *
     * **Raw, on purpose.** These rows are cleaned on the way *in*, which is only a
     * promise about values this module wrote; a row edited by hand, or written before
     * a rule existed, comes back exactly as it is. `readable()` below is what turns
     * one into what will actually render, and `ColorAudit` reads this method rather
     * than that one — an audit whose source had already been tidied would find
     * nothing and say so.
     *
     * A Brand with no rows answers `[]`, and that is a meaningful answer rather than
     * an error: `paints()` reads it as "the Brand paints nothing here, so the block's
     * own values are load-bearing", which is what both renderers already do with an
     * empty `blockStyles[sub]`. Convergence seeds all six for every Brand, so it is a
     * half-seeded install rather than a design — but it is reachable, and stripping a
     * column on the strength of a row that is not there is how a price becomes 16px
     * Arial black on a wall.
     */
    public function all($brandId)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM block_styles WHERE brand_id = ?");
        $stmt->execute([intval($brandId)]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['block_type']] = $row;
        }
        return $out;
    }

    /**
     * Every Brand's styles at once, keyed by brand id and then by block type.
     *
     * For the callers whose question is about more than one Brand. Two are about the
     * *installation*: `ColorAudit`, which reports every stored colour this app cannot
     * read wherever it lives, and the tool that prints it. Reading these one Brand at a
     * time would be a query per Brand to answer a question about all of them, and an
     * audit that skipped a Brand because nothing pointed at it would miss exactly the
     * rows nobody is looking at.
     *
     * The third is `builder.php`, and it is about one page rather than the install: an
     * admin who picks a Brand there repaints the canvas in the browser and writes
     * nothing (v2 decision 6), so the standards of every Brand they *could* pick have to
     * be on the page before they pick one. A read per switch would put a request that
     * can fail in the middle of an edit, for data that changes about once a season.
     */
    public function allByBrand()
    {
        $out = [];
        foreach ($this->pdo->query("SELECT * FROM block_styles ORDER BY brand_id ASC")->fetchAll() as $row) {
            $out[intval($row['brand_id'])][$row['block_type']] = $row;
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
     * §4ai, one boundary further in.
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

    /**
     * Whether Brand Standards paints this block, rather than the block's own row.
     *
     * The question both renderers already ask before they choose a font —
     * `applyTextStyles()` in `builder.php`, and the same three lines inside
     * `viewer.php`'s render loop — written down once so the publish path can ask
     * exactly the same one. All three have to agree, and they disagree in opposite
     * directions: a publish that stripped typography a renderer was still going to
     * read blanks the block on the sign, and a publish that keeps typography the
     * Brand paints writes the fossil this method exists to stop (invariant 32).
     *
     * `$stored` is `all()`'s output, keyed by block type, which is what makes a
     * **missing** row mean "the Brand paints nothing here, so the block's own values
     * are load-bearing" — precisely what both renderers do with an empty
     * `blockStyles[sub]`. That is a half-seeded install rather than a design, and
     * `signageSchemaPlan()` seeds all six; but it is reachable — `rehearse_phase1.php`
     * looks for exactly this — and stripping a column on the strength of a row that
     * is not there is how a price becomes 16px Arial black on a wall.
     *
     * Only text blocks: `renderBlock()` calls `applyTextStyles()` under
     * `el.type === 'text'` and nothing else reads these columns. A carousel or a
     * table styles its *contents* from Brand Standards, but never from the element's
     * own six.
     *
     * The `is_string` guard is this module's own door and not a publish check.
     * `LayoutRules` refuses a payload whose `block_subtype` is not one of the seven
     * words before `LayoutStore` ever gets here, so the publish path cannot reach it;
     * what it covers is a later caller, and what it prevents is `isset($stored[$sub])`
     * throwing on an array offset instead of answering. Its mutant therefore dies as a
     * *fatal* rather than an assertion — the honest grade, and worth writing down
     * rather than contorting the line into something a check can grade better
     * (invariant 30).
     */
    public static function paints($blockType, $blockSubtype, array $stored)
    {
        if ($blockType !== 'text')       { return false; }
        if (!is_string($blockSubtype))   { return false; }
        return $blockSubtype !== '' && $blockSubtype !== 'free' && isset($stored[$blockSubtype]);
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
    public function save($brandId, array $styles)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE block_styles
                SET font_family = ?, font_size = ?, font_color = ?,
                    font_weight = ?, font_style = ?, line_height = ?
              WHERE brand_id = ? AND block_type = ?"
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
                intval($brandId),
                $type,
            ]);
            $saved++;
        }
        return $saved;
    }

    /**
     * Give a new Brand the six rows it needs to be editable at all.
     *
     * Called by `BrandAdmin` inside its transaction, and by nothing else — a Brand
     * without these is one whose Brand Standards form saves nothing and says nothing,
     * because that form is six `UPDATE`s and an `UPDATE` that matches no row is a
     * silent success. That is the same defect convergence seeds against for the
     * Brand it creates itself.
     *
     * Only the types that are actually missing are written, so this is safe to call
     * against a Brand that already has some — a half-finished create, or a Brand made
     * before a seventh branded type existed. It never overwrites: the store's own
     * numbers win.
     *
     * @return int rows written
     */
    public function seedFor($brandId)
    {
        $brandId = intval($brandId);
        $have    = $this->all($brandId);

        $stmt = $this->pdo->prepare(
            "INSERT INTO block_styles
             (brand_id, block_type, font_family, font_size, font_color, font_weight, font_style, line_height)
             VALUES (?,?,?,?,?,?,?,?)"
        );

        $written = 0;
        foreach (self::STARTING_POINTS as $type => $values) {
            if (isset($have[$type])) { continue; }
            $stmt->execute(array_merge([$brandId, $type], $values));
            $written++;
        }
        return $written;
    }

    /**
     * Drop one Brand's standards, for a Brand being destroyed.
     *
     * Explicit rather than left to `block_styles_ibfk_1`'s ON DELETE CASCADE, for
     * invariant 10's reason: that constraint is added by convergence and may never
     * have applied on a live database, and six rows keyed to a Brand that is gone are
     * rows nothing will ever read or clean up again.
     *
     * @return int rows removed
     */
    public function deleteFor($brandId)
    {
        $stmt = $this->pdo->prepare("DELETE FROM block_styles WHERE brand_id = ?");
        $stmt->execute([intval($brandId)]);
        return $stmt->rowCount();
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
