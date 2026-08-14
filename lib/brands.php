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
// For the name rules, which a Workspace Theme needs in exactly the same shape — see
// that file's header for why they left this one.
require_once __DIR__ . '/picker_name.php';
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
     * The Brand as a client consumes it: under the `brand` key of the **editor's**
     * layout reply (`api.php?action=get_editor_layout`), and once per switchable Brand
     * in the Builder's own `BRANDS`, which adds two keys of its own — the six standards,
     * and the file behind the logo, neither of which is this table's to answer.
     *
     * Deliberately not part of the snapshot both clients share. A Screen's read is
     * polled every thirty seconds by every TV in the building and has no use for any
     * of this — the Viewer never draws a logo (decision 5) and its typography arrives
     * under `block_styles` already — so a Brand read on that path would be a query
     * per poll per sign for a key nothing opens.
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

/**
 * What a publish should do about the Brand this sign wears.
 *
 * The same shape as `Background`, one column over, and for the same reason: picking a
 * Brand in the Builder writes nothing at the moment it is picked — it repaints the
 * canvas in the browser and rides out with the next Publish, on the path
 * `applyBackground()` already takes (decision 6). So the publish endpoint receives an
 * *intent*, and an intent that cannot be carried out has to be refusable before
 * anything is written rather than coerced into one that can.
 *
 * Two things can be wrong with it and they are found in different places:
 *
 *   not an id       `brand_id=7abc` or `brand_id=[]` arriving from a form nobody is
 *                   in front of. Decided here, with no database at all, which is what
 *                   lets `LayoutStore::publish()` refuse it in its pre-transaction
 *                   pass beside the layout rules.
 *   no such Brand   a real-looking id naming a row that is gone — a colleague deleted
 *                   the Brand while this tab sat open. Only the database knows, so the
 *                   *caller* looks it up under the publish's row lock and hands what
 *                   it found to `problemWith()`. The Brand is passed in rather than
 *                   fetched here for the reason every value object in this app is
 *                   built that way: a class that reaches for a PDO of its own is a
 *                   class the self-test cannot ask a question without a database.
 *
 * `displays.brand_id` is `NOT NULL` (decision 8), so there is no "clear it" intent to
 * express — the absence of one is `unchanged`, and a basic account's publish is always
 * that: the control is not on their page and the endpoint does not read the field for
 * them either.
 */
class BrandChoice
{
    /** A choice that names no Brand. Nothing writes one; publish refuses it. */
    const INVALID = 'invalid';

    private $kind;
    private $value;

    private function __construct($kind, $value)
    {
        $this->kind  = $kind;
        $this->value = $value;
    }

    /** Leave the Brand exactly as it is — a basic account, and an admin who did not pick. */
    public static function unchanged() { return new self('unchanged', null); }

    /**
     * Wear this Brand — or, when that is not an id, nothing at all. The offending
     * text is kept on the intent so the refusal can quote it.
     *
     * `isIdLike()` and not `intval()`, for the reason `BrandStore::forId()` gives:
     * `intval('7abc')` is 7, so a mangled value would not fail, it would silently
     * publish a *different venue's* Brand onto the sign.
     */
    public static function brand($id)
    {
        if (!DisplayStore::isIdLike($id) || intval($id) <= 0) {
            return new self(self::INVALID, $id);
        }
        return new self('brand', intval($id));
    }

    /** Can this intent be carried out at all? False only for something that is not an id. */
    public function isUsable() { return $this->kind !== self::INVALID; }

    public function kind() { return $this->kind; }

    /** The Brand id this names, or 0 for the two kinds that name none. */
    public function id() { return $this->kind === 'brand' ? intval($this->value) : 0; }

    /**
     * Is this choice applicable, given the Brand the caller found for `id()`?
     *
     * @param Brand|null $found what `BrandStore::forId($choice->id())` answered
     * @return string|null the problem, or null if there is none
     */
    public function problemWith($found)
    {
        switch ($this->kind) {
            // Both spellings share one body, exactly as `Background::problemWith()`
            // answers INVALID and 'color' together, and for the same two reasons. The
            // kind is what the factory produces *today*, and the test below is what
            // keeps this honest if the factory ever stops filtering — a reader that knew
            // only one of them would answer "no problem" for the other, which is a
            // publish nothing refuses.
            //
            // Sharing the body rather than answering INVALID early is deliberate and it
            // is what makes the test live rather than decorative: every INVALID reaches
            // it and fails it, so removing it changes an answer somebody is asserting.
            // A separate early `return` for INVALID would leave this line unreachable
            // through the factory, which is a guard no check can hold (§4bb's
            // `logoAssetId()`, and one more copy of the same sentence to keep in step).
            case self::INVALID:
            case 'brand':
                if (!DisplayStore::isIdLike($this->value) || intval($this->value) <= 0) {
                    return 'That publish named a brand this app cannot read ("' . self::snippet($this->value)
                         . '"), so nothing was saved. Reload the display and choose a brand again.';
                }
                // Refused rather than falling back to the Brand already on the sign:
                // falling back is a merge, and the person would be told the publish
                // succeeded while the one change they made was thrown away (invariant
                // 5). `displays.brand_id` is NOT NULL and `displays_ibfk_3` would
                // refuse the write from underneath anyway — this is the half that
                // produces a sentence rather than an exception.
                if (!($found instanceof Brand)) {
                    return 'The brand this display was set to no longer exists — somebody deleted it while '
                         . 'this page was open. Nothing was saved and your work is still on screen. Reload '
                         . 'the display and choose a brand again.';
                }
                return null;
        }
        return null;
    }

    /** Enough of a rejected value to recognise it, without pasting a payload into a page. */
    private static function snippet($value)
    {
        if (!is_string($value) && !is_int($value)) { return gettype($value); }
        $value = (string)$value;
        return strlen($value) > 40 ? substr($value, 0, 37) . '…' : $value;
    }
}

class BrandStore
{
    /** Column width, so a too-long name is refused rather than truncated by MySQL. */
    const NAME_MAX = PickerName::MAX;

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
     * The *other* Brand already using this name, or null. `$exceptId` lets a Brand
     * keep its own name while renaming.
     *
     * The comparison itself — case-insensitive, in PHP rather than in the database,
     * answering the clashing row rather than a yes/no — is `PickerName::clashIn()`,
     * with the reasoning for both of those choices. This method stays because the
     * *list* is the part only a store can supply, and because its name is what
     * `BrandAdmin` and every check already say.
     */
    public function otherBrandNamed($name, $exceptId = 0)
    {
        return PickerName::clashIn($this->all(), $name, $exceptId);
    }

    // ---- Name rules ---------------------------------------------------------

    // Both of these are `PickerName`'s rules, and the reasoning is written down there
    // — including why a non-string is not a name badly written but not a name at all.
    // They keep their names here because `BrandAdmin`, the panel and several checks
    // say `BrandStore::cleanName()`, and because a Brand is where the rule was first
    // needed. A Workspace Theme is the second thing to need it, which is what moved
    // it out of this file rather than copying it into that one.

    public static function cleanName($name)
    {
        return PickerName::clean($name);
    }

    public static function isValidName($name)
    {
        return PickerName::isValid($name);
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
