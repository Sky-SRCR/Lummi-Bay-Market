<?php
// ============================================================
// ADMINISTERING BRANDS
// ============================================================
// Creating, editing and destroying a Brand — Admin Panel → Display Branding's whole
// vocabulary, behind three methods.
//
//   create(fields)               → BrandResult   the Brand and its six standards
//   updateDetails(Brand, fields) → BrandResult   name, logo, background, palette
//   destroy(Brand, typedName)    → BrandResult   refused while any sign wears it
//
// Why this exists rather than more methods on BrandStore: administering a Brand
// spans two tables. Creating one writes a `brands` row *and* six `block_styles`
// rows, because a Brand without those is one whose standards form saves nothing and
// says nothing — an `UPDATE` matching no row is a silent success. Destroying one
// removes both, and has to ask a third table first. Each table has one gatekeeper
// and none may reach into another, so the composition needs somewhere of its own.
//
// This module writes no SQL. It holds a PDO only to open and close the transaction
// that makes create and destroy all-or-nothing; every statement is still
// BrandStore's or BrandStyles'. That is the same shape as `DisplayAdmin`,
// `AccountAdmin` and `PasswordResetCompletion`, and it is the same shape on purpose.
//
// Rules enforced here, so that every path to a Brand agrees on them:
//   · A Brand needs a name no other Brand has. Names are compared the way a person
//     would read them, so "Salmon House" and "salmon house" are the same name.
//   · A palette colour that cannot be read is refused, naming the slot — never
//     replaced (#21). The rule itself is lib/color.php's.
//   · A default background is the same question `Background` already answers for a
//     Display, asked of a different row, so it is asked through the same class.
//   · Destroying a Brand requires its name typed back, and is refused outright while
//     any Display still wears it, naming the signs (ADR-0011). Reassigning them to
//     some other Brand would repaint three signs in a restaurant on one click, which
//     is exactly the merge the standing rule refuses.
//   · A Brand's standards are seeded from `BrandStyles::STARTING_POINTS`, so a new
//     Brand looks like a sign rather than like six identical lines.

require_once __DIR__ . '/brands.php';
require_once __DIR__ . '/brand_styles.php';
require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/color.php';

/**
 * The outcome of an administrative change to a Brand, as a value.
 *
 * Same four kinds as `DisplayResult`, and the same contract: adapters branch on
 * kind() and show message(); field() names the input to point at when there is one.
 * Never parse the message to work out what happened.
 */
class BrandResult
{
    const OK       = 'ok';
    const INVALID  = 'invalid';    // the input cannot be used
    // The input is fine; the state of things refuses it. A name another Brand has,
    // or a Brand three signs are still wearing.
    const CONFLICT = 'conflict';
    const FAILED   = 'failed';     // the database refused; nothing was changed

    private $kind;
    private $brand;
    private $message;
    private $field;

    private function __construct($kind, $brand, $message, $field)
    {
        $this->kind    = $kind;
        $this->brand   = $brand;
        $this->message = $message;
        $this->field   = $field;
    }

    public static function ok(Brand $brand, $message)
    {
        return new self(self::OK, $brand, $message, '');
    }

    /** A change with no Brand left to be its subject — one that has just been destroyed. */
    public static function done($message)
    {
        return new self(self::OK, null, $message, '');
    }

    public static function invalid($field, $message)
    {
        return new self(self::INVALID, null, $message, $field);
    }

    public static function conflict($field, $message)
    {
        return new self(self::CONFLICT, null, $message, $field);
    }

    public static function failed($message)
    {
        return new self(self::FAILED, null, $message, '');
    }

    public function isOk()    { return $this->kind === self::OK; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }
    public function field()   { return $this->field; }

    /** The Brand as stored after the change, or null after a destroy or a failure. */
    public function brand()   { return $this->brand; }
}

class BrandAdmin
{
    private $pdo;
    private $brands;
    private $styles;
    private $displays;

    public function __construct(PDO $pdo, BrandStore $brands, BrandStyles $styles, DisplayStore $displays)
    {
        $this->pdo      = $pdo;
        $this->brands   = $brands;
        $this->styles   = $styles;
        $this->displays = $displays;
    }

    /**
     * Create a Brand, complete with the six sets of standards that make it editable.
     *
     * One transaction, because a `brands` row with no `block_styles` rows behind it is
     * a Brand whose whole typography form is a silent no-op — the fields revert on
     * reload and nothing says why. Half of this landing is worse than none of it.
     */
    public function create(array $fields)
    {
        $problem = $this->checkFields($fields, 0);
        if ($problem) { return $problem; }

        try {
            $this->pdo->beginTransaction();

            $brand = $this->brands->insert($fields);
            if (!$brand) {
                $this->abandon();
                return BrandResult::failed('That brand could not be created. Nothing was saved.');
            }
            $this->styles->seedFor($brand->id());

            $this->pdo->commit();
            return BrandResult::ok($brand,
                'Brand "' . $brand->name() . '" created. Set its typography and palette below; '
                . 'nothing wears it until a display is assigned to it.');
        } catch (Throwable $e) {
            $this->abandon();
            return BrandResult::failed('That brand could not be created. Nothing was saved.');
        }
    }

    /**
     * Change a Brand's name, logo, default background and palette.
     *
     * No transaction: one row, one statement. The standards are edited by their own
     * form and are not touched here — which is why this method cannot be the one that
     * quietly resets them, the way the Brand Standards form used to reset the whole
     * store's typography from a truncated POST.
     *
     * **Immediate across the venue, and irreversible.** Every Screen wearing this
     * Brand picks the change up on its next thirty-second poll with no publish at all
     * (ADR-0011). Nothing here makes that undoable, and the caller is expected to have
     * refused the edit while somebody holds a live lock on a Display using this Brand
     * — a narrower refusal than Brand Standards used to make, and the one place this
     * work makes the app less restrictive rather than more.
     */
    public function updateDetails(Brand $brand, array $fields)
    {
        $problem = $this->checkFields($fields, $brand->id());
        if ($problem) { return $problem; }

        try {
            $saved = $this->brands->updateDetails($brand, $fields);
            if (!$saved) {
                return BrandResult::failed('That brand could not be saved. Nothing was changed.');
            }
            return BrandResult::ok($saved,
                'Brand "' . $saved->name() . '" saved. Every screen wearing it picks this up '
                . 'within 30 seconds — no publishing needed.');
        } catch (Throwable $e) {
            return BrandResult::failed('That brand could not be saved. Nothing was changed.');
        }
    }

    /**
     * Destroy a Brand and its standards, with its name typed back.
     *
     * Refused while any Display wears it, naming them. That refusal is the feature:
     * the alternative — moving those signs onto some other Brand — would repaint a
     * restaurant's three boards within thirty seconds on one click, and there is no
     * undo anywhere in this app (ADR-0011).
     *
     * The last Brand cannot be destroyed either, and for a different reason:
     * `displays.brand_id` is NOT NULL, so an installation with no Brands is one where
     * no Display can be created. That refusal only ever fires on an install whose one
     * Brand nothing is using, which is a fresh one — where the answer is to rename it
     * rather than to remove it.
     */
    public function destroy(Brand $brand, $typedName)
    {
        if (BrandStore::cleanName($typedName) === ''
            || strcasecmp(BrandStore::cleanName($typedName), $brand->name()) !== 0) {
            return BrandResult::invalid('confirm_name',
                'Type the brand\'s name exactly to confirm. Nothing was deleted.');
        }

        $wearers = $this->displays->usingBrand($brand->id());
        if ($wearers) {
            return BrandResult::conflict('brand_id', $this->wornBySentence($brand, $wearers));
        }

        if ($this->brands->count() <= 1) {
            return BrandResult::conflict('brand_id',
                'This is the only brand, and every display has to wear one, so it cannot be '
                . 'deleted. Rename it instead — nothing is wearing it, so nothing will change '
                . 'on any screen.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->styles->deleteFor($brand->id());
            $this->brands->deleteRow($brand);
            $this->pdo->commit();
            return BrandResult::done('Brand "' . $brand->name() . '" and its typography were deleted.');
        } catch (Throwable $e) {
            $this->abandon();
            return BrandResult::failed('That brand could not be deleted. Nothing was changed.');
        }
    }

    /**
     * "Three displays still wear this brand: Salmon House Board, …" — a refusal that
     * names what is in the way, because "it is in use" is not something a person can
     * act on without going to look.
     */
    private function wornBySentence(Brand $brand, array $wearers)
    {
        $names = [];
        foreach ($wearers as $display) { $names[] = $display->title(); }

        $count = count($names);
        $shown = implode(', ', array_slice($names, 0, 6));
        if ($count > 6) { $shown .= ', and ' . ($count - 6) . ' more'; }

        return ($count === 1 ? 'One display still wears' : $count . ' displays still wear')
             . ' "' . $brand->name() . '": ' . $shown . '. '
             . 'Nothing was deleted. Move ' . ($count === 1 ? 'it' : 'them')
             . ' to another brand first — reassigning ' . ($count === 1 ? 'it' : 'them')
             . ' automatically would repaint ' . ($count === 1 ? 'that screen' : 'those screens')
             . ' within 30 seconds, and there is no undo.';
    }

    /**
     * The rules a Brand's own fields have to meet, for create and for edit alike.
     *
     * One method, so the two paths cannot end up with different ideas of a valid
     * Brand — which is what `DisplayAdmin` learned from having "create" and "create as
     * a duplicate" check different things.
     *
     * @return BrandResult|null the refusal, or null when there is nothing wrong
     */
    private function checkFields(array $fields, $exceptId)
    {
        $name = BrandStore::cleanName($fields['name'] ?? '');
        if ($name === '') {
            return BrandResult::invalid('name', 'A brand needs a name. Nothing was saved.');
        }
        if (!BrandStore::isValidName($name)) {
            return BrandResult::invalid('name',
                'That brand name cannot be used — it must be ' . BrandStore::NAME_MAX
                . ' characters or fewer and contain no control characters. Nothing was saved.');
        }
        if ($this->brands->nameExists($name, $exceptId)) {
            return BrandResult::conflict('name',
                'Another brand is already called "' . $name . '". Brand names have to be '
                . 'different so a person picking one can tell them apart. Nothing was saved.');
        }

        // Refused, never replaced. Storing `#ffffff` for a value somebody typed and
        // reporting success is #21, and a palette is offered as swatches — a swatch
        // drawn in a colour nobody chose is worse than one that was refused out loud.
        $slot = 0;
        foreach (BrandStore::paletteFields() as $field) {
            $slot++;
            $raw = $fields[$field] ?? null;
            if ($raw === null || $raw === '') { continue; }
            if (Color::read($raw) === '') {
                return BrandResult::invalid($field,
                    'Palette colour ' . $slot . ' is not a colour (' . Color::describe($raw)
                    . '). Use a six-digit hex colour such as #1a1a2e, or leave it empty. '
                    . 'Nothing was saved.');
            }
        }

        // The same class the publish path and the Display form ask, so a Brand's
        // default background cannot be something a Display could never be set to.
        $bg = ($fields['bg_type'] ?? 'color') === 'image'
            ? Background::image((string)($fields['bg_val'] ?? ''))
            : Background::color((string)($fields['bg_val'] ?? ''));
        $problem = $bg->problemWith($fields['bg_val'] ?? '');
        if ($problem !== null) {
            return BrandResult::invalid('bg_val', $problem);
        }

        return null;
    }

    /** Roll back if there is anything to roll back, and never throw doing it. */
    private function abandon()
    {
        try {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        } catch (Throwable $e) {
            // Nothing left to do: the write is not committed either way, and the
            // caller is already returning a refusal.
        }
    }
}
