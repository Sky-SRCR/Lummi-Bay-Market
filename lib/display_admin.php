<?php
// ============================================================
// ADMINISTERING DISPLAYS
// ============================================================
// Adding, editing, retiring and destroying a Display — the Phase 3 admin screen's
// whole vocabulary, behind four methods.
//
//   create(fields)                → DisplayResult   blank, or a duplicate of another Display
//   updateDetails(Display, fields)→ DisplayResult   title, screen name tag, location
//   setActive(Display, bool)      → DisplayResult   retire without losing the layout
//   destroy(Display, typedTag)    → DisplayResult   the layout goes with it
//
// Why this exists rather than more methods on DisplayStore: administering a
// Display spans both tables. Creating one as a duplicate writes a `displays` row
// *and* a set of `canvas_elements` rows; destroying one removes both. Those two
// tables have one gatekeeper each and neither may reach into the other, so the
// composition — with the validation and the transaction that make it safe — needs
// somewhere of its own. Without this module all of it would sit in
// `admin_panel.php`, where Phase 4's grant screen would then need its own copy.
//
// This module writes no SQL. It holds a PDO only to open and close the
// transaction that makes "create as a duplicate" and "destroy" all-or-nothing;
// every statement is still DisplayStore's or LayoutStore's.
//
// Rules enforced here, so that every path to a Display agrees on them:
//   · A Display needs a title, a valid unused screen name tag, and dimensions
//     inside the bounds. A tag left blank is suggested from the title.
//   · Dimensions are set once and never again (ADR-0004) — there is no method
//     here that changes them.
//   · A layout may be duplicated only from a Display of identical dimensions
//     (ADR-0004), and only into an empty one.
//   · Destroying a Display requires its screen name tag typed back. There is no
//     undo anywhere in this app, and this is the most destructive button in it.

require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/layout_store.php';

/**
 * The outcome of an administrative change, as a value.
 *
 * Adapters branch on kind() and show message(); field() names the input to point
 * at when there is one. Never parse the message to work out what happened.
 */
class DisplayResult
{
    const OK       = 'ok';
    const INVALID  = 'invalid';    // the input cannot be used
    const CONFLICT = 'conflict';   // the input collides with an existing Display
    const FAILED   = 'failed';     // the database refused; nothing was changed

    private $kind;
    private $display;
    private $message;
    private $field;

    private function __construct($kind, $display, $message, $field)
    {
        $this->kind    = $kind;
        $this->display = $display;
        $this->message = $message;
        $this->field   = $field;
    }

    public static function ok(Display $display, $message)
    {
        return new self(self::OK, $display, $message, '');
    }

    /** A Display that no longer exists — destroy() has nothing to hand back. */
    public static function gone($message)
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

    /** The Display as stored after the change, or null after a destroy or a failure. */
    public function display() { return $this->display; }
}

class DisplayAdmin
{
    private $pdo;
    private $displays;
    private $layouts;

    /**
     * @param PDO $pdo transaction boundaries only — this module writes no SQL
     */
    public function __construct(PDO $pdo, DisplayStore $displays, LayoutStore $layouts)
    {
        $this->pdo      = $pdo;
        $this->displays = $displays;
        $this->layouts  = $layouts;
    }

    /**
     * Add a Display, blank or as a duplicate of one the same shape.
     *
     * Fields: title, tag (blank suggests from title), location, canvas_width,
     * canvas_height, bg_val, duplicate_from (a screen name tag, or '' for blank).
     *
     * Active from the moment it exists: a Display nobody has pointed a Screen at
     * yet is harmless, and one that silently starts retired is a support call.
     */
    public function create(array $fields)
    {
        $clean = [];
        $bad = $this->validateDetails($fields, 0, $clean);
        if ($bad) { return $bad; }

        $width  = isset($fields['canvas_width'])  ? $fields['canvas_width']  : '';
        $height = isset($fields['canvas_height']) ? $fields['canvas_height'] : '';
        if (!DisplayStore::isValidCanvasSize($width, $height)) {
            return DisplayResult::invalid('canvas_width',
                'Choose a canvas size between ' . DisplayStore::CANVAS_MIN . ' and '
                . DisplayStore::CANVAS_MAX . ' pixels on each side. '
                . 'It cannot be changed after the display is created.');
        }
        $clean['canvas_width']  = intval($width);
        $clean['canvas_height'] = intval($height);
        $clean['bg_type']       = 'color';
        $clean['bg_val']        = self::cleanColor(isset($fields['bg_val']) ? $fields['bg_val'] : '');
        $clean['is_active']     = 1;

        // "Duplicate of" is resolved before anything is written, so a stale form
        // naming a Display that has since been deleted or is the wrong shape
        // fails without leaving a half-made Display behind.
        $source = null;
        $sourceTag = isset($fields['duplicate_from']) ? trim((string)$fields['duplicate_from']) : '';
        if ($sourceTag !== '') {
            $source = $this->displays->forTag($sourceTag);
            if (!$source) {
                return DisplayResult::invalid('duplicate_from',
                    'The display you chose to duplicate no longer exists. Reload and try again.');
            }
            if ($source->canvasWidth() !== $clean['canvas_width']
                || $source->canvasHeight() !== $clean['canvas_height']) {
                return DisplayResult::invalid('duplicate_from',
                    'A layout can only be duplicated between displays of exactly the same size. "'
                    . $source->title() . '" is ' . $source->dimensionsLabel() . '. '
                    . 'Start from blank instead.');
            }
        }

        $this->pdo->beginTransaction();
        try {
            $display = $this->displays->insert($clean);
            if (!$display) {
                $this->pdo->rollBack();
                return DisplayResult::failed('The display could not be created. Nothing was changed.');
            }

            $copied = 0;
            if ($source) {
                $copied = $this->layouts->copyLayout($source, $display);
                if ($copied === false) {
                    // Shapes matched a moment ago; something changed underneath.
                    $this->pdo->rollBack();
                    return DisplayResult::failed(
                        'That layout could not be duplicated. The display was not created.');
                }
            }

            $this->pdo->commit();

            $what = $source
                ? ' with ' . $copied . ' element' . ($copied === 1 ? '' : 's') . ' copied from ' . $source->title()
                : ' as a blank canvas';
            return DisplayResult::ok($display,
                'Display "' . $display->title() . '" created' . $what . '. '
                . 'Point a screen at ' . $this->viewerPath($display) . '.');
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            // The one expected failure is a tag that was taken between the check
            // and the insert; anything else is reported the same way, because
            // either way nothing was written.
            return DisplayResult::failed('The display could not be created. Nothing was changed.');
        }
    }

    /**
     * Change the reference details, and possibly the screen name tag.
     *
     * Renaming the tag changes the Viewer URL: whatever is pointed at the old one
     * stops working. That is the admin's call (ADR-0003) — the panel confirms it
     * and shows the new URL — so this method carries the consequence in its
     * message rather than refusing.
     */
    public function updateDetails(Display $display, array $fields)
    {
        $clean = [];
        $bad = $this->validateDetails($fields, $display->id(), $clean);
        if ($bad) { return $bad; }

        $renamed = $clean['tag'] !== $display->tag();

        try {
            $updated = $this->displays->updateDetails($display, $clean);
        } catch (Exception $e) {
            return DisplayResult::failed('That display could not be updated. Nothing was changed.');
        }
        if (!$updated) {
            return DisplayResult::failed('That display no longer exists.');
        }

        $note = $renamed
            ? ' Its address changed — any screen showing it must now be pointed at '
              . $this->viewerPath($updated) . '.'
            : '';
        return DisplayResult::ok($updated, 'Display "' . $updated->title() . '" updated.' . $note);
    }

    /**
     * Retire a Display, or bring it back.
     *
     * Retiring keeps the layout and keeps it editable; the Screens show "This
     * display is turned off" within one poll (≤30s).
     */
    public function setActive(Display $display, $active)
    {
        try {
            $updated = $this->displays->setActive($display, $active);
        } catch (Exception $e) {
            return DisplayResult::failed('That display could not be changed. Nothing was changed.');
        }
        if (!$updated) {
            return DisplayResult::failed('That display no longer exists.');
        }

        return DisplayResult::ok($updated, $active
            ? 'Display "' . $updated->title() . '" is on. Screens showing it update within 30 seconds.'
            : 'Display "' . $updated->title() . '" is turned off. Its layout is kept and stays editable; '
              . 'any screen showing it now says so within 30 seconds.');
    }

    /**
     * Set a Display's background colour.
     *
     * Advances the layout stamp, because the background is part of what the Screen
     * shows: an admin with the Builder open still holding the old stamp would
     * otherwise publish their stale colour straight back over this one. They get
     * the refusal instead (ADR-0006).
     *
     * Only a colour. Background *images* are set from the Builder, where the
     * upload is already validated in one place and you can see the canvas you are
     * changing.
     */
    public function setBackgroundColor(Display $display, $hex)
    {
        try {
            $this->displays->applyBackground($display, Background::color(self::cleanColor($hex)));
            $this->displays->advanceLayoutRevision($display);
            $updated = $this->displays->forId($display->id());
        } catch (Exception $e) {
            return DisplayResult::failed('That background could not be changed. Nothing was changed.');
        }
        if (!$updated) {
            return DisplayResult::failed('That display no longer exists.');
        }

        return DisplayResult::ok($updated,
            'Background colour set for "' . $updated->title() . '". Screens showing it update within '
            . '30 seconds, and any builder tab opened before now must reload before it can publish.');
    }

    /**
     * Destroy a Display and its layout, behind the screen name tag typed back.
     *
     * There is no undo in this app and nothing is versioned, so this is the one
     * action that can lose work with no way back. The typed tag is the whole
     * safeguard — an admin who mistypes it gets a refusal, not a deletion.
     */
    public function destroy(Display $display, $typedTag)
    {
        if (DisplayStore::normalizeTag($typedTag) !== $display->tag()) {
            return DisplayResult::invalid('confirm_tag',
                'Type the screen name tag "' . $display->tag() . '" exactly to delete this display. '
                . 'Nothing was deleted.');
        }

        $this->pdo->beginTransaction();
        try {
            // Elements first, and explicitly: the ON DELETE CASCADE may never have
            // applied on a live database that is behind the repo, and a layout
            // orphaned by a deleted Display is invisible to every scoped query
            // while still occupying the table.
            $lost = $this->layouts->deleteAllElements($display);
            $this->displays->deleteRow($display);
            $this->pdo->commit();
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return DisplayResult::failed('That display could not be deleted. Nothing was changed.');
        }

        return DisplayResult::gone(
            'Display "' . $display->title() . '" (' . $display->tag() . ') and its '
            . $lost . ' element' . ($lost === 1 ? '' : 's') . ' were deleted. '
            . 'Any screen still pointed at it now shows "Display not found".');
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * The rules a Display's details must satisfy, checked once for create and
     * edit alike so the two cannot drift apart.
     *
     * @param array $clean out: title, tag and location, ready for the store
     * @return DisplayResult|null null when the input is usable
     */
    private function validateDetails(array $in, $exceptId, array &$clean)
    {
        $title = trim((string)(isset($in['title']) ? $in['title'] : ''));
        if ($title === '') {
            return DisplayResult::invalid('title',
                'Give the display a title — it is how everyone will refer to this screen.');
        }
        if (strlen($title) > DisplayStore::TITLE_MAX) {
            return DisplayResult::invalid('title',
                'That title is too long (limit ' . DisplayStore::TITLE_MAX . ' characters).');
        }

        $location = trim((string)(isset($in['location']) ? $in['location'] : ''));
        if (strlen($location) > DisplayStore::LOCATION_MAX) {
            return DisplayResult::invalid('location',
                'That location is too long (limit ' . DisplayStore::LOCATION_MAX . ' characters).');
        }

        // A blank tag is filled in from the title rather than refused: the tag is
        // a URL detail, and the title is the thing an admin actually has in mind.
        $tag = DisplayStore::normalizeTag(isset($in['tag']) ? $in['tag'] : '');
        if ($tag === '') { $tag = DisplayStore::suggestTag($title); }

        if (!DisplayStore::isValidTag($tag)) {
            return DisplayResult::invalid('tag',
                'The screen name tag must be ' . DisplayStore::TAG_MIN . '–' . DisplayStore::TAG_MAX
                . ' characters of lowercase letters, numbers and hyphens — it goes in the display\'s '
                . 'web address, like viewer.php?display=drive-thru.');
        }
        if ($this->displays->tagExists($tag, $exceptId)) {
            return DisplayResult::conflict('tag',
                'Another display already uses the screen name tag "' . $tag . '". Choose a different one.');
        }

        $clean = ['title' => $title, 'tag' => $tag, 'location' => $location];
        return null;
    }

    /** The Viewer address to put on a device, relative to the app root. */
    private function viewerPath(Display $display)
    {
        return 'viewer.php?display=' . $display->tag();
    }

    /** A `#rrggbb` colour, or the dark default the canvas has always had. */
    private static function cleanColor($value)
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string)$value) ? strtolower($value) : '#1a1a2e';
    }
}
