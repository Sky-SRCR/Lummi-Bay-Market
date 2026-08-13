<?php
// ============================================================
// BRANDS
// ============================================================
// A Brand is a named, reusable visual identity that a Display wears (ADR-0011):
// the six branded block-type standards, a palette of colours offered as swatches,
// a logo asset, and a default canvas background. Several Displays share one — a
// venue with three boards has one red, edited once.
//
// Two things live here and nowhere else:
//   Brand      — one Brand's facts, in the app's vocabulary rather than the
//                database's.
//   BrandStore — every statement that touches the `brands` table, and the name
//                and palette rules.
//
// No caller outside this file may write SQL against `brands`.
//
// The *use case* of administering Brands — creating one complete with its six sets
// of standards, destroying one and refusing to when a sign still wears it — is one
// level up, in `brand_admin.php`. That module composes this one with BrandStyles,
// because a Brand's standards are not this file's table to touch.
//
// ---- Why this is not the class that used to be called Brand ------------------
// `lib/site_chrome.php` holds the application's own navigation colours. It was
// called `Brand` until this landed, which was the wrong word by CONTEXT.md's own
// vocabulary: a Brand is what a customer sees on a TV, and that is this file.
//
// ---- What a Brand does *not* carry -------------------------------------------
// Content, layout, canvas size and the screen name tag stay per-Display. Two signs
// wearing one Brand are two different signs that look like they belong together;
// they are not two copies of one sign. And the logo is a *named asset the Builder
// can place*, never something the Viewer draws by itself — a fixed corner and size
// cannot be right for both a landscape menu board and a portrait specials board
// (ADR-0004, and decision 5 of the v2 roadmap).

require_once __DIR__ . '/color.php';
// For `Background`, which already decides what a canvas background may be — a
// six-digit colour or a path inside this server's own uploads — and refuses rather
// than substituting. A Brand's default background is the same question asked about
// a different row, and a second opinion about it is how #23 and #24 happened.
require_once __DIR__ . '/displays.php';

/**
 * One Brand, as the app talks about it.
 *
 * A value object built from a `brands` row, never stored. The palette accessors are
 * the interesting part: like `BrandStyles::readable()`, they answer with what will
 * actually *render* rather than with what is in the row, and `unreadablePalette()`
 * is how a caller says which stored value it could not use. A substitute nobody is
 * told about is #21 again — and these land in `<style>`-adjacent places where
 * escaping is the wrong tool entirely, so validation is the only thing that helps.
 */
class Brand
{
    private $row;

    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function id()   { return intval($this->row['id']); }
    public function name() { return (string)$this->row['name']; }

    /** The library row holding this venue's logo, or 0 when it has none. */
    public function logoAssetId()
    {
        return isset($this->row['logo_asset_id']) && $this->row['logo_asset_id']
             ? intval($this->row['logo_asset_id']) : 0;
    }

    public function backgroundType()
    {
        return (isset($this->row['bg_type']) && $this->row['bg_type'] === 'image') ? 'image' : 'color';
    }

    public function backgroundValue()
    {
        return isset($this->row['bg_val']) ? (string)$this->row['bg_val'] : Background::DEFAULT_COLOR;
    }

    /**
     * The palette as swatches a person can actually be offered: `#rrggbb`, in slot
     * order, with the empty slots and the unreadable ones left out.
     *
     * An empty palette is a legitimate state — a Brand that has only had its
     * typography set — and it renders as no swatch row at all rather than as six
     * grey boxes. Never enforced anywhere: decision 4 of the v2 roadmap, and
     * ADR-0011's fourth rejected option. A block with its own colour keeps it.
     */
    public function palette()
    {
        $out = [];
        foreach (BrandStore::paletteFields() as $field) {
            $raw = isset($this->row[$field]) ? $this->row[$field] : null;
            if ($raw === null || $raw === '') { continue; }
            $read = Color::read($raw);
            if ($read !== '') { $out[] = $read; }
        }
        return $out;
    }

    /**
     * Every palette slot whose stored value is not a colour, for whoever reports it.
     *
     * The other half of not substituting silently. Same shape as `Brand`'s findings
     * in `ColorAudit` and as `BrandStyles::unrenderable()`: the slot in the words the
     * form uses, and the value exactly as stored.
     *
     * Absent is not unreadable — an unset slot is a slot nobody has filled in, and
     * there is nothing there for anyone to go and fix (#21's absent-versus-unreadable
     * line).
     *
     * @return array list of ['field','label','value']
     */
    public function unreadablePalette()
    {
        $bad  = [];
        $slot = 0;
        foreach (BrandStore::paletteFields() as $field) {
            $slot++;
            $raw = isset($this->row[$field]) ? $this->row[$field] : null;
            if ($raw === null || $raw === '') { continue; }
            if (Color::isColor($raw)) { continue; }
            $bad[] = ['field' => $field, 'label' => 'Palette colour ' . $slot, 'value' => $raw];
        }
        return $bad;
    }

    /** The raw stored value of one palette slot, or '' — for a form to redraw. */
    public function paletteSlot($index)
    {
        $fields = BrandStore::paletteFields();
        if (!isset($fields[$index])) { return ''; }
        $raw = isset($this->row[$fields[$index]]) ? $this->row[$fields[$index]] : null;
        return $raw === null ? '' : (string)$raw;
    }

    /**
     * The Brand as a client consumes it, under the `brand` key of a layout snapshot.
     *
     * The palette is sent *read* rather than raw, because the Builder puts these
     * straight into swatch buttons and a value the CSSOM discards is a swatch that
     * silently does nothing — the shape `selftest_builder_colors.js` exists for.
     */
    public function toClientArray()
    {
        return [
            'id'            => $this->id(),
            'name'          => $this->name(),
            'logo_asset_id' => $this->logoAssetId(),
            'bg_type'       => $this->backgroundType(),
            'bg_val'        => $this->backgroundValue(),
            'palette'       => $this->palette(),
        ];
    }
}

class BrandStore
{
    /** Column width, so a too-long name is refused rather than truncated by MySQL. */
    const NAME_MAX = 80;

    /**
     * How many colours a Brand may offer.
     *
     * Six because that is a palette a person can hold in their head and pick from a
     * row of swatches without reading labels — and because the slots are columns
     * rather than a table, which is what keeps `brands` a single-row read on the path
     * every Builder load takes. They are an ordered list and deliberately not *roles*:
     * decision 4 makes the palette an offer, and a role ("this is the heading colour")
     * is an instruction, which is the enforced palette ADR-0011 rejected.
     */
    const PALETTE_SLOTS = 6;

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** The palette columns, in slot order. One list, so no caller counts to six. */
    public static function paletteFields()
    {
        $out = [];
        for ($i = 1; $i <= self::PALETTE_SLOTS; $i++) { $out[] = 'palette_' . $i; }
        return $out;
    }

    // ---- Reads --------------------------------------------------------------

    /** Every Brand, oldest first — which puts the seeded one at the top. */
    public function all()
    {
        $out = [];
        foreach ($this->rows("ORDER BY id ASC", []) as $row) { $out[] = new Brand($row); }
        return $out;
    }

    public function forId($id)
    {
        // Not `intval` alone, for the reason `DisplayStore::forId()` gives at length:
        // this is reached straight from `$_POST`, and `intval("7abc")` is 7 — so a
        // mangled id would not fail, it would silently name a *different Brand*, which
        // the delete form would then act on.
        if (!DisplayStore::isIdLike($id)) { return null; }
        $id = intval($id);
        if ($id <= 0) { return null; }
        $rows = $this->rows("WHERE id = ? LIMIT 1", [$id]);
        return $rows ? new Brand($rows[0]) : null;
    }

    /** How many Brands exist. Setup and the Builder both ask before offering a picker. */
    public function count()
    {
        return count($this->all());
    }

    /**
     * Is this name taken? `$exceptId` lets a Brand keep its own name while renaming.
     *
     * Compared case-insensitively in PHP rather than left to the database, because
     * the two engines disagree: MySQL's default collation makes "Salmon House" and
     * "salmon house" the same name and refuses the second with a unique-key error,
     * and SQLite's does not. A rule that answers differently per engine is a rule the
     * self-test cannot state.
     */
    public function nameExists($name, $exceptId = 0)
    {
        $name = self::cleanName($name);
        if ($name === '') { return false; }
        foreach ($this->all() as $brand) {
            if ($brand->id() === intval($exceptId)) { continue; }
            if (strcasecmp($brand->name(), $name) === 0) { return true; }
        }
        return false;
    }

    // ---- Name rules ---------------------------------------------------------

    /**
     * Fold input toward a usable name without inventing one: trim, collapse runs of
     * whitespace.
     *
     * Anything that is not a string is not a name badly written — it is not a name
     * (#27). `(string)$array` yields the word "Array", which is a perfectly valid
     * Brand name, so the caller would go on to create one nobody asked for.
     */
    public static function cleanName($name)
    {
        if (!is_string($name)) { return ''; }
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * A name a person can tell apart from another one on a picker.
     *
     * Deliberately permissive about *which* characters — these are venue names, and
     * "Tavern & Grill" and "Café 12" are the ordinary case. What is refused is the
     * empty name, one longer than the column, and control characters, which are
     * invisible and would make two names that look identical be different rows.
     *
     * Length in bytes, matching the column's own limit as MySQL applies it to
     * utf8mb4 — the check is `strlen` and the column is 80 *characters*, so this is
     * stricter than the column for a name with accents in it and never looser. Being
     * refused at 80 bytes is a sentence; being truncated at 80 characters is a name
     * nobody chose.
     */
    public static function isValidName($name)
    {
        if (!is_string($name) || $name === '')       { return false; }
        if (strlen($name) > self::NAME_MAX)          { return false; }
        return preg_match('/[\x00-\x1f\x7f]/', $name) !== 1;
    }

    /**
     * A palette slot as it should be stored: `#rrggbb`, or null for an empty slot.
     *
     * Null and not '' — an empty slot is *absent*, and the column is nullable so it
     * can say so. A value that is not a colour also answers null rather than a
     * substitute, and the caller is expected to have refused the save already: the
     * admin form asks `Color::read()` itself and names the field, exactly as the
     * Branding form does since #21. This is the door for the paths with nobody in
     * front of them.
     */
    public static function cleanPaletteColor($value)
    {
        if ($value === null || $value === '') { return null; }
        $read = Color::read($value);
        return $read !== '' ? $read : null;
    }

    // ---- Writes -------------------------------------------------------------
    // Called by BrandAdmin, which validates first and holds the transaction. A Brand
    // is never created here alone: it needs its six sets of standards to be editable
    // at all, and those are BrandStyles' rows.

    /**
     * Insert a Brand and return it as stored.
     *
     * Takes an already-validated set of fields — a caller reaching this with a
     * duplicate name gets the unique-key exception, which is the database enforcing
     * what BrandAdmin already checked, not an expected path.
     */
    public function insert(array $fields)
    {
        $columns = ['name', 'logo_asset_id', 'bg_type', 'bg_val'];
        $values  = [
            self::cleanName($fields['name'] ?? ''),
            !empty($fields['logo_asset_id']) ? intval($fields['logo_asset_id']) : null,
            ($fields['bg_type'] ?? 'color') === 'image' ? 'image' : 'color',
            (string)($fields['bg_val'] ?? Background::DEFAULT_COLOR),
        ];
        foreach (self::paletteFields() as $field) {
            $columns[] = $field;
            $values[]  = self::cleanPaletteColor($fields[$field] ?? null);
        }

        $this->pdo->prepare(
            "INSERT INTO brands (" . implode(',', $columns) . ")
             VALUES (" . implode(',', array_fill(0, count($columns), '?')) . ")"
        )->execute($values);

        return $this->forId($this->pdo->lastInsertId());
    }

    /**
     * Change a Brand's name, logo, default background and palette.
     *
     * Every field is written, not only the ones that differ: this is reached from one
     * form that carries all of them, and "absent means untouched" is a promise about a
     * *partial* payload that this method is not in a position to keep — it cannot tell
     * a slot somebody cleared from one the form never carried. `BrandAdmin` is what
     * refuses a payload that is not whole, which is the same shape as the grant
     * matrix declaring both its axes.
     */
    public function updateDetails(Brand $brand, array $fields)
    {
        $sets   = ['name = ?', 'logo_asset_id = ?', 'bg_type = ?', 'bg_val = ?'];
        $values = [
            self::cleanName($fields['name'] ?? ''),
            !empty($fields['logo_asset_id']) ? intval($fields['logo_asset_id']) : null,
            ($fields['bg_type'] ?? 'color') === 'image' ? 'image' : 'color',
            (string)($fields['bg_val'] ?? Background::DEFAULT_COLOR),
        ];
        foreach (self::paletteFields() as $field) {
            $sets[]   = $field . ' = ?';
            $values[] = self::cleanPaletteColor($fields[$field] ?? null);
        }
        $values[] = $brand->id();

        $this->pdo->prepare("UPDATE brands SET " . implode(', ', $sets) . " WHERE id = ?")
                  ->execute($values);

        return $this->forId($brand->id());
    }

    /**
     * Remove the Brand row itself.
     *
     * Its standards are removed first, by BrandAdmin through BrandStyles, rather than
     * relying on `block_styles_ibfk_1`'s ON DELETE CASCADE — that constraint is added
     * by convergence and may never have applied (invariant 10). Rows left behind would
     * be six standards keyed to a Brand that is gone, which the next Brand to be
     * created cannot collide with only because ids are never reused.
     *
     * Whether a Display still wears it is checked one level up, before this is called.
     * The `displays_ibfk_3` foreign key refuses it again from underneath, and both are
     * meant: the check produces a sentence naming the signs, the constraint produces
     * an exception, and the constraint is what covers a database this app is not the
     * only thing writing to.
     */
    public function deleteRow(Brand $brand)
    {
        $this->pdo->prepare("DELETE FROM brands WHERE id = ?")->execute([$brand->id()]);
    }

    // ---- Internals ----------------------------------------------------------

    private function rows($where, array $params)
    {
        $stmt = $this->pdo->prepare("SELECT * FROM brands " . $where);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
