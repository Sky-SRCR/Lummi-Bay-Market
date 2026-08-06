<?php
// ============================================================
// LAYOUT STORE
// ============================================================
// Every read and write of `canvas_elements` in the application goes through
// this file. That is the point: multi-display support adds one invariant that
// has to hold in every single statement — *this row belongs to that Display* —
// and publishing works by deleting a layout and re-inserting it. A scoping miss
// in a copy of that statement somewhere else does not degrade one sign, it
// empties all of them. So there is exactly one copy.
//
// Interface, in full:
//   snapshot(Display)                     → the payload a Viewer or Builder renders
//   publish(Display, PublishRequest)      → PublishResult
//   elementIndex(Display)                 → the admin Work Area list
//   setElementHidden(Display, id, bool, actorId) → ElementResult
//   deleteElement(Display, id, actorId)          → ElementResult
//   elementCount(Display)                 → int, for a confirm that says what is at stake
//   copyLayout(Display, Display)          → int copied: duplicating a Display
//   deleteAllElements(Display)            → int deleted: destroying a Display
//   assetUsage(assetId)                   → which Displays draw on one library row
//   referencedAssetIds()                  → every library row anything points at
//
// Callers pass a Display and get results back. They never see the transaction,
// the temp-id map, the staleness comparison, the asset auto-save, the plain-text
// stripping, or the admin/basic section rules.
//
// The `displays` table is not this module's to write: the stamp, the background
// and the publish record are reached through DisplayStore, which owns every
// statement against it. `assets` belongs to AssetLibrary the same way — this
// store asks it to pool a block's content and to drop the rows a publish
// strands, and writes no statement against that table itself. The one exception
// is snapshot()'s LEFT JOIN, which is read-only and on the path a Screen polls
// every thirty seconds; see lib/assets.php. This store owns `canvas_elements`.
//
// The last two methods above exist because the sweep is a question neither module
// can answer alone: which rows are referenced is this table's business, which of
// the rest were made by a publish rather than by a person is AssetLibrary's.
//
// Invariants enforced here, not by callers:
//   · No statement touches canvas_elements without a display_id predicate.
//   · A publish whose stamp no longer matches the Display is refused (ADR-0006),
//     and so is one whose edit lock has moved to somebody else (ADR-0007).
//   · A basic account's publish preserves that Display's sections (ADR-0005),
//     and cannot parent content into another Display's section.
//   · type='text' content is stripped to plain text (ADR-0002); carousel/table/
//     marquee JSON and media paths are not.
//   · Anything that changes the published layout advances the stamp.

require_once __DIR__ . '/plain_text.php';
require_once __DIR__ . '/displays.php';
require_once __DIR__ . '/brand_styles.php';
require_once __DIR__ . '/assets.php';

/** One publish attempt: the layout, the background intent, who is publishing, and the stamp they hold. */
class PublishRequest
{
    private $elements;
    private $background;
    private $actorId;
    private $isAdmin;
    private $stamp;

    public function __construct(array $elements, Background $background, $actorId, $isAdmin, $stamp)
    {
        $this->elements   = $elements;
        $this->background = $background;
        $this->actorId    = intval($actorId);
        $this->isAdmin    = (bool)$isAdmin;
        $this->stamp      = (string)$stamp;
    }

    /**
     * Build one from a posted JSON body, or return null if that body is not a
     * layout at all.
     *
     * This exists because the obvious adapter line — `json_decode($raw, true) ?: []`
     * — reads "an unreadable request is an empty layout", and publishing an empty
     * layout deletes every element on the Display. A truncated POST, a body cut
     * mid-multibyte-character, or JSON.stringify emitting an unpaired surrogate for
     * text truncated mid-emoji all decode to null, and all used to blank the sign
     * and report success. There is no undo.
     *
     * An empty array is still a layout: that is somebody who deleted every block
     * and meant it. Only "this did not decode" is refused.
     */
    public static function fromPostedJson($raw, Background $background, $actorId, $isAdmin, $stamp)
    {
        $elements = json_decode((string)$raw, true);
        if (!is_array($elements)) { return null; }
        return new self($elements, $background, $actorId, $isAdmin, $stamp);
    }

    public function elements()   { return $this->elements; }
    public function background() { return $this->background; }
    public function actorId()    { return $this->actorId; }
    public function isAdmin()    { return $this->isAdmin; }
    public function stamp()      { return $this->stamp; }
}

/**
 * The outcome of one element write (hide, show, delete), as a value.
 *
 * A bool could only say "that element is not on this display", which is why the
 * lock refusal had nowhere to live before: an adapter cannot tell the difference
 * between "wrong Display" and "somebody else is editing this one" from `false`.
 */
class ElementResult
{
    private $kind;      // 'ok' | 'not_found' | 'locked'
    private $message;

    private function __construct($kind, $message)
    {
        $this->kind    = $kind;
        $this->message = $message;
    }

    public static function ok()           { return new self('ok', ''); }
    public static function notOnDisplay() { return new self('not_found', 'That element is not on this display.'); }
    public static function locked($message) { return new self('locked', $message); }

    public function isOk()    { return $this->kind === 'ok'; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }
}

/**
 * The outcome of a publish, as a value. Adapters branch on kind() and pass
 * message() through to the user — never parse the message to work out what
 * happened.
 */
class PublishResult
{
    private $kind;      // 'ok' | 'stale' | 'locked' | 'failed'
    private $stamp;
    private $message;

    private function __construct($kind, $stamp, $message)
    {
        $this->kind    = $kind;
        $this->stamp   = $stamp;
        $this->message = $message;
    }

    public static function ok($stamp)       { return new self('ok', (string)$stamp, ''); }
    public static function stale($message)  { return new self('stale', '', $message); }
    /** Somebody else holds the edit lock (ADR-0007) — a different refusal from a stale stamp. */
    public static function locked($message) { return new self('locked', '', $message); }
    public static function failed($message) { return new self('failed', '', $message); }

    public function isOk()    { return $this->kind === 'ok'; }
    public function kind()    { return $this->kind; }
    public function stamp()   { return $this->stamp; }
    public function message() { return $this->message; }
}

class LayoutStore
{
    private $pdo;
    private $displays;

    /** Built on first use by assets(); see the note there. */
    private $assets = null;

    public function __construct(PDO $pdo, DisplayStore $displays)
    {
        $this->pdo      = $pdo;
        $this->displays = $displays;
    }

    /** Brand Standards, which owns `block_styles`. Built on demand: only reads need it. */
    private function brandStyles()
    {
        return new BrandStyles($this->pdo);
    }

    /**
     * The Asset Library, which owns `assets`. Built on demand, and not a
     * constructor argument: every caller of this store would otherwise have to
     * know about a table it never mentions, and the two writes involved (pooling a
     * block's content, dropping the rows a publish orphans) are both internal.
     *
     * Kept, unlike BrandStyles: it caches whether the marker column exists, and
     * pooling is called once per text block, so a fresh instance each time would
     * re-probe the table for every line on the sign — inside the publish
     * transaction.
     */
    private function assets()
    {
        if ($this->assets === null) { $this->assets = new AssetLibrary($this->pdo); }
        return $this->assets;
    }

    // ---- Read ---------------------------------------------------------------

    /**
     * Everything needed to render this Display: its own facts, its elements with
     * any linked asset content joined in, the shared Brand Standards typography,
     * and the current layout stamp.
     *
     * Sections come first so a client can build the DOM tree in one pass.
     */
    public function snapshot(Display $display)
    {
        $stmt = $this->pdo->prepare(
            "SELECT ce.*, a.content AS db_content
               FROM canvas_elements ce
               LEFT JOIN assets a ON ce.asset_id = a.id
              WHERE ce.display_id = ?
              ORDER BY CASE WHEN ce.type='section' THEN 0 ELSE 1 END, ce.sort_order ASC, ce.id ASC"
        );
        $stmt->execute([$display->id()]);
        $elements = $stmt->fetchAll();

        // Brand Standards belongs to BrandStyles, which is the only writer of that
        // table; reading it through the same module keeps one definition of what a
        // stored style looks like.
        $styles = $this->brandStyles()->all();

        $display_ = $display->toClientArray();

        return [
            // The Display carries its own background and canvas size. Phase 2 moved
            // the Viewer and Builder onto this key and removed the transitional
            // `settings` alias that stood in for the retired canvas_settings row.
            'display'      => $display_,
            'elements'     => $elements,
            'block_styles' => $styles,
            'layout_stamp' => $display->layoutStamp(),
        ];
    }

    /** The admin Work Area list for one Display: enough to identify and act on each element. */
    public function elementIndex(Display $display)
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, section_id, type, block_subtype, manual_content, hidden, z_index, width, height, sort_order
               FROM canvas_elements
              WHERE display_id = ?
              ORDER BY CASE WHEN type='section' THEN 0 ELSE 1 END, sort_order ASC, id ASC"
        );
        $stmt->execute([$display->id()]);
        return $stmt->fetchAll();
    }

    /** How many elements this Display carries — what a delete confirm needs to state. */
    public function elementCount(Display $display)
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM canvas_elements WHERE display_id = ?");
        $stmt->execute([$display->id()]);
        return intval($stmt->fetchColumn());
    }

    // ---- Whole-layout writes, for administering Displays --------------------
    // Called by DisplayAdmin inside its transaction. They are here rather than
    // there for the reason the whole module exists: `canvas_elements` has exactly
    // one gatekeeper, so the Display-scoping predicate cannot be forgotten in a
    // second copy of these statements.

    /**
     * Copy one Display's layout onto another, for "create as a duplicate of".
     *
     * Refuses, without writing, when:
     *   · the shapes differ — positions are absolute pixels and there is no undo,
     *     so a rescaled layout is not something to guess at (ADR-0004); or
     *   · the target already has elements — this is a fill, not a merge, and
     *     overwriting a layout is the one thing this app never does silently.
     *
     * Section parents are remapped to the new rows, so the copy is a tree in its
     * own right. Asset IDs are reused rather than duplicated: the library is
     * shared across Displays by design.
     *
     * @return int|false elements copied, or false if refused
     */
    public function copyLayout(Display $source, Display $target)
    {
        if (!$source->sameShapeAs($target)) { return false; }
        if ($source->id() === $target->id())  { return false; }
        if ($this->elementCount($target) > 0) { return false; }

        $stmt = $this->pdo->prepare(
            "SELECT * FROM canvas_elements
              WHERE display_id = ?
              ORDER BY CASE WHEN type='section' THEN 0 ELSE 1 END, sort_order ASC, id ASC"
        );
        $stmt->execute([$source->id()]);
        $rows = $stmt->fetchAll();

        $insert = $this->pdo->prepare(
            "INSERT INTO canvas_elements
             (display_id, section_id, type, block_subtype, x_pos, y_pos, width, height,
              manual_content, asset_id, section_bg,
              font_family, font_size, font_color, font_weight, font_style, line_height,
              text_align, locked, sort_order, z_index, hidden)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        // Sections come first in the result set, so a child's parent is always
        // already in the map by the time the child is inserted.
        $idMap  = [];
        $copied = 0;
        foreach ($rows as $row) {
            $oldSection = $row['section_id'] !== null ? intval($row['section_id']) : null;
            $newSection = $oldSection !== null && isset($idMap[$oldSection]) ? $idMap[$oldSection] : null;

            $insert->execute([
                $target->id(),
                $newSection,
                $row['type'],
                $row['block_subtype'],
                intval($row['x_pos']),
                intval($row['y_pos']),
                intval($row['width']),
                intval($row['height']),
                $row['manual_content'],
                $row['asset_id'] !== null ? intval($row['asset_id']) : null,
                $row['section_bg'],
                $row['font_family'],
                intval($row['font_size']),
                $row['font_color'],
                $row['font_weight'],
                $row['font_style'],
                number_format(floatval($row['line_height']), 2),
                $row['text_align'],
                intval($row['locked']) ? 1 : 0,
                intval($row['sort_order']),
                max(1, intval($row['z_index'])),
                intval($row['hidden']) ? 1 : 0,
            ]);

            if ($row['type'] === 'section') {
                $idMap[intval($row['id'])] = intval($this->pdo->lastInsertId());
            }
            $copied++;
        }

        return $copied;
    }

    /**
     * Remove every element of one Display, for destroying it.
     *
     * Children first, then the rest, for the same reason the publish delete does
     * it in that order: the section_id self-FK cascades, and this ordering keeps
     * that cascade from firing against rows the second statement removes anyway.
     * Both statements are Display-scoped.
     *
     * @return int elements there were to delete
     */
    public function deleteAllElements(Display $display)
    {
        $before = $this->elementCount($display);
        $this->pdo->prepare("DELETE FROM canvas_elements WHERE display_id = ? AND section_id IS NOT NULL")
                  ->execute([$display->id()]);
        $this->pdo->prepare("DELETE FROM canvas_elements WHERE display_id = ?")
                  ->execute([$display->id()]);
        return $before;
    }

    // ---- Element-level writes ----------------------------------------------

    /**
     * Hide or show one element. Returns false when the element is not this
     * Display's — the caller reports "not found" rather than reaching across
     * Displays. Advances the stamp, because a Builder tab holding the old layout
     * would otherwise republish the element it just saw hidden.
     */
    public function setElementHidden(Display $display, $elementId, $hidden, $actorId = 0)
    {
        $refusal = $this->refuseIfHeldByOther($display, $actorId);
        if ($refusal) { return $refusal; }
        if (!$this->ownsElement($display, $elementId)) { return ElementResult::notOnDisplay(); }

        $this->pdo->prepare("UPDATE canvas_elements SET hidden = ? WHERE id = ? AND display_id = ?")
                  ->execute([$hidden ? 1 : 0, intval($elementId), $display->id()]);
        $this->displays->advanceLayoutRevision($display);
        return ElementResult::ok();
    }

    /**
     * Delete one element, and its children if it is a section.
     *
     * Refused while somebody else holds the edit lock, like every other write to
     * this Display's elements.
     */
    public function deleteElement(Display $display, $elementId, $actorId = 0)
    {
        $refusal = $this->refuseIfHeldByOther($display, $actorId);
        if ($refusal) { return $refusal; }
        if (!$this->ownsElement($display, $elementId)) { return ElementResult::notOnDisplay(); }

        // Children are deleted explicitly rather than through the section_id FK's
        // ON DELETE CASCADE. That constraint is the one this build never converges
        // — lib/schema.php adds canvas_elements_ibfk_3 and nothing for ibfk_2 — so
        // on a live database where it was never applied, a section's children would
        // survive as rows pointing at a parent that is gone: invisible in both the
        // Builder and the Viewer, and erased for good by the next publish. Every
        // other destructive path in this build already deletes explicitly for the
        // same reason (invariant 10: assume the live schema is behind the repo).
        $this->pdo->prepare("DELETE FROM canvas_elements WHERE section_id = ? AND display_id = ?")
                  ->execute([intval($elementId), $display->id()]);
        $this->pdo->prepare("DELETE FROM canvas_elements WHERE id = ? AND display_id = ?")
                  ->execute([intval($elementId), $display->id()]);
        $this->displays->advanceLayoutRevision($display);
        return ElementResult::ok();
    }

    /**
     * The edit lock covers a Display's elements, not just its publishes.
     *
     * The Work Area's hide and delete used to check the grant and nothing else, so
     * an admin could remove a block from under somebody who was mid-edit. The stamp
     * bump meant that person's next publish was refused as stale — which protects
     * the layout but not their twenty minutes of work, and being told "reload and
     * redo it" is the exact outcome ADR-0007 exists to prevent on top of ADR-0006.
     *
     * Read fresh, like publish does: a lock can be taken or lapse while a panel
     * sits open. A lapsed lock is free and does not refuse anything.
     */
    private function refuseIfHeldByOther(Display $display, $actorId)
    {
        $fresh = $this->displays->forId($display->id());
        $lock  = ($fresh ?: $display)->lockState();
        if ($lock->heldByOther(intval($actorId))) {
            return ElementResult::locked($this->lockedMessage($lock));
        }
        return null;
    }

    /**
     * Which Displays have an element pointing at this library asset, and how many
     * elements in total.
     *
     * The Asset Library is shared, and publishing moves a text block's content into
     * it, with `manual_content` set to NULL on the element. Deleting that row
     * therefore blanks the line that used it (`asset_id` is ON DELETE SET NULL, and
     * the element has no copy of its own left), and editing it rewrites the line
     * with no publish. Neither the Library page nor its confirm could say so,
     * because counting the elements involved means asking `canvas_elements`, and
     * that is this module's table.
     *
     * Still plural, and it has to stay plural: publishing no longer creates a
     * shared row (see lib/assets.php), but every row it created before that change
     * is still out there, and a database that has run this app for a year has
     * several that two signs both draw on.
     *
     * Returns ['elements' => int, 'displays' => [display_id, …]].
     */
    public function assetUsage($assetId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT display_id, COUNT(*) AS n
               FROM canvas_elements
              WHERE asset_id = ?
              GROUP BY display_id"
        );
        $stmt->execute([intval($assetId)]);

        $elements = 0;
        $displays = [];
        foreach ($stmt->fetchAll() as $row) {
            $elements  += intval($row['n']);
            $displays[] = intval($row['display_id']);
        }
        return ['elements' => $elements, 'displays' => $displays];
    }

    // ---- Publish ------------------------------------------------------------

    /**
     * Replace this Display's layout with the submitted one, in one transaction.
     *
     * Refuses (without writing anything) in two cases, and there is no merge and no
     * undo behind either — the refusal is the whole safety net:
     *
     *   · somebody else holds the edit lock (ADR-0007). The lock says no at the
     *     door, but a lock can move while a tab sits open, so it is checked again
     *     here: hold the lock, go idle, lose it, and this is what stops the
     *     colleague who took over being overwritten.
     *   · the submitted stamp is not the Display's current stamp (ADR-0006) —
     *     someone published, or an element was hidden or deleted, since this
     *     Builder loaded.
     *
     * The lock is checked first. When both are true the lock is the more useful
     * thing to say: "reload and re-apply" is bad advice while somebody else is
     * mid-edit, because re-applying would only be refused again.
     *
     * An admin publishes the whole canvas including sections and background. A
     * basic account's publish keeps every section exactly as it is and replaces
     * only the content inside them (ADR-0005).
     */
    public function publish(Display $display, PublishRequest $request)
    {
        try {
            // Inside the try: beginTransaction can throw too (a connection that
            // died between the request starting and this call), and a publish that
            // fails must be a returned value, never an escaping exception.
            $this->pdo->beginTransaction();

            $current = $this->displays->lockLayoutRevision($display);
            if ($current === false) {
                $this->abandon();
                return PublishResult::failed('That display no longer exists. Reload the page.');
            }

            // The Display as it stands under that row lock. Both refusals below are
            // decided from this one read, so they cannot disagree about the moment
            // they describe.
            $fresh = $this->displays->forId($display->id());
            $lock  = ($fresh ?: $display)->lockState();

            if ($lock->heldByOther($request->actorId())) {
                $this->abandon();
                return PublishResult::locked($this->lockedMessage($lock));
            }

            if ((string)intval($current) !== $request->stamp()) {
                $this->abandon();
                return PublishResult::stale($this->stalenessMessage($fresh ?: $display));
            }

            // Read before the layout is deleted: these are the library rows this
            // Display points at right now, and a publish that replaces the layout
            // is what strands them.
            $wasReferenced = $this->assetIdsOn($display);

            if ($request->isAdmin()) {
                $this->displays->applyBackground($display, $request->background());
                $this->replaceWholeLayout($display, $request->elements());
            } else {
                $this->replaceContentOnly($display, $request->elements());
            }

            // Publishing a text block copies its text into the library and points
            // the block at the copy, so the third time somebody fixes a typo the
            // first two rows are pointed at by nothing. Drop those, and only those:
            // scoped to the ids this Display's own previous layout held, so a
            // publish to another sign shares no locks with this one, and filtered
            // through AssetLibrary, which refuses to delete a row a person made.
            $stranded = array_values(array_diff(
                $wasReferenced,
                $this->referencedAmong($wasReferenced)
            ));
            if ($stranded) { $this->assets()->discardPooled($stranded); }

            $newStamp = $this->displays->recordPublish($display, $request->actorId());

            // Publishing is about as real as an interaction gets, so it keeps the
            // lock alive — and takes it back for a tab whose lock had lapsed while
            // nobody else claimed it (ADR-0007). Never a steal: the check above has
            // already established nobody else is holding this Display.
            $this->displays->claimLock($display, $request->actorId());

            $this->pdo->commit();
            return PublishResult::ok($newStamp);
        } catch (Throwable $e) {
            // Throwable, not Exception. A TypeError from a hostile or malformed
            // payload is an Error, so `catch (Exception)` let it escape *after*
            // the DELETEs had run: no rollback of our own, no result object, and
            // the Builder reported "Network error." for a rejected publish. Only
            // PDO's teardown was undoing the delete.
            $this->abandon();
            return PublishResult::failed('Publish failed. Nothing was saved.');
        }
    }

    /**
     * Roll back if there is anything to roll back, and never throw doing it.
     *
     * inTransaction() is PDO's own bookkeeping, so it still reports true after
     * "MySQL server has gone away" — and rollBack() then throws from inside the
     * catch that was meant to contain the failure, past a Content-Type header
     * that has already promised JSON.
     */
    private function abandon()
    {
        try {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        } catch (Throwable $e) {
            // Nothing left to do: the write is not committed either way, and the
            // caller is already returning a refusal.
        }
    }

    // ---- Publish internals -------------------------------------------------

    /** Admin publish: this Display's canvas is replaced wholesale. */
    private function replaceWholeLayout(Display $display, array $elements)
    {
        // Children first, then the rest — the section_id self-FK cascades, and
        // deleting in this order keeps that cascade from firing against rows the
        // second statement is about to remove anyway. Both statements are scoped
        // to one Display; this pair is what used to be an unscoped
        // `DELETE FROM canvas_elements`.
        $this->pdo->prepare("DELETE FROM canvas_elements WHERE display_id = ? AND section_id IS NOT NULL")
                  ->execute([$display->id()]);
        $this->pdo->prepare("DELETE FROM canvas_elements WHERE display_id = ?")
                  ->execute([$display->id()]);

        // Sections are inserted first so their real IDs can parent the content.
        $tempMap = [];
        foreach ($elements as $el) {
            if (($el['type'] ?? '') !== 'section') { continue; }
            $this->insertSection($display, $el);
            if (!empty($el['temp_id'])) {
                self::requireUsableTempId($el['temp_id']);
                $tempMap[$el['temp_id']] = $this->pdo->lastInsertId();
            }
        }

        $this->insertContent($display, $elements, $tempMap);
    }

    /**
     * Refuse a temp id that cannot be an array key, rather than letting PHP decide.
     *
     * The refusal was already happening on the server this runs on — PHP 8 throws a
     * TypeError on an array subscript, publish() catches Throwable, and the result
     * is `failed`, which is correct. What was missing was anywhere saying so. The
     * rule lived in the language rather than in the module, and the check covering
     * it passed for a reason no reader of this file could see.
     *
     * Below PHP 8 the same code emits a warning and carries on: the section is
     * inserted but never mapped, and on the read side the same subscript yields
     * null, so the section's content is written at root level — the silent
     * reparenting #31 is about. That is not reachable on 8.2 and this is not a
     * portability shim for it. It is the refusal written down where the other
     * publish refusals are, which is the only place a later change would look.
     */
    private static function requireUsableTempId($value)
    {
        if (!is_string($value) && !is_int($value)) {
            throw new InvalidArgumentException(
                'a temp_id must be a string or an integer, not ' . gettype($value));
        }
    }

    /**
     * Basic-account publish: sections stay untouched, their content is replaced.
     *
     * The temp-id map is built from the section IDs the Builder reports (`db_id`),
     * checked against the sections this Display actually has. Without that check
     * a forged `db_id` would parent content into another Display's section.
     */
    private function replaceContentOnly(Display $display, array $elements)
    {
        $ownSections = [];
        $stmt = $this->pdo->prepare("SELECT id FROM canvas_elements WHERE display_id = ? AND type = 'section'");
        $stmt->execute([$display->id()]);
        foreach ($stmt->fetchAll() as $row) { $ownSections[intval($row['id'])] = true; }

        $this->pdo->prepare("DELETE FROM canvas_elements WHERE display_id = ? AND type != 'section'")
                  ->execute([$display->id()]);

        $tempMap = [];
        foreach ($elements as $el) {
            if (($el['type'] ?? '') !== 'section') { continue; }
            if (empty($el['temp_id']) || empty($el['db_id'])) { continue; }
            self::requireUsableTempId($el['temp_id']);
            $dbId = intval($el['db_id']);
            if (isset($ownSections[$dbId])) {
                $tempMap[$el['temp_id']] = $dbId;
            }
        }

        $this->insertContent($display, $elements, $tempMap);
    }

    private function insertSection(Display $display, array $el)
    {
        $this->pdo->prepare(
            "INSERT INTO canvas_elements
             (display_id, type, x_pos, y_pos, width, height, section_bg, locked, sort_order, z_index, hidden)
             VALUES (?, 'section', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $display->id(),
            intval($el['x_pos']  ?? 0),
            intval($el['y_pos']  ?? 0),
            intval($el['width']  ?? 400),
            intval($el['height'] ?? 300),
            $el['section_bg'] ?? null,
            intval($el['locked'] ?? 0),
            intval($el['sort_order'] ?? 0),
            max(1, intval($el['z_index'] ?? 1)),
            intval($el['hidden'] ?? 0) ? 1 : 0,
        ]);
    }

    /**
     * Insert every non-section element, in submission order.
     *
     * A block whose parent temp-id does not resolve lands at root level, which is
     * the behaviour a single-Display publish always had. It cannot leak across
     * Displays: the map only ever holds this Display's section IDs.
     */
    private function insertContent(Display $display, array $elements, array $tempMap)
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO canvas_elements
             (display_id, section_id, type, block_subtype, x_pos, y_pos, width, height,
              manual_content, asset_id,
              font_family, font_size, font_color, font_weight, font_style, line_height,
              text_align, locked, sort_order, z_index, hidden)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $order = 0;
        foreach ($elements as $el) {
            $type = $el['type'] ?? 'text';
            if ($type === 'section') { continue; }

            // The read side of the same subscript, and the more dangerous half: on
            // 7.1 an array here yields null through the `??`, which parents the
            // block at root level instead of inside its section. Refused rather
            // than resolved, for the reasons on requireUsableTempId().
            $parentTmp = $el['parent_temp_id'] ?? null;
            if ($parentTmp) { self::requireUsableTempId($parentTmp); }
            $sectionId = $parentTmp ? ($tempMap[$parentTmp] ?? null) : null;
            $assetId   = !empty($el['asset_id']) ? intval($el['asset_id']) : null;
            $manual    = $el['manual_content'] ?? '';

            // Text blocks are plain text (ADR-0002). Non-text types carry JSON
            // (carousel/table/marquee) or file paths and must NOT be stripped.
            if ($type === 'text') {
                $manual = toPlainText($manual);
            }

            if (!$assetId && $manual !== '' && $manual !== null && !empty($el['save_to_db_pool'])) {
                $pooled = $this->assets()->pool($type, $manual);
                if ($pooled > 0) {
                    $assetId = $pooled;
                    $manual  = null;
                }
                // A pool row that could not be written leaves the content on the
                // element, where it renders. The old code moved it either way and
                // linked to id 0.
            }

            // `$manual ?: null` — the old form — turned a text block reading
            // exactly "0" into an empty block, since "0" is falsy in PHP.
            $manualToStore = ($manual === null || $manual === '') ? null : $manual;

            $insert->execute([
                $display->id(),
                $sectionId,
                $type,
                $el['block_subtype'] ?? 'free',
                intval($el['x_pos']  ?? 0),
                intval($el['y_pos']  ?? 0),
                intval($el['width']  ?? 200),
                intval($el['height'] ?? 100),
                $manualToStore,
                $assetId,
                $el['font_family'] ?? 'Arial',
                intval($el['font_size'] ?? 16),
                $el['font_color']  ?? '#000000',
                $el['font_weight'] ?? 'normal',
                $el['font_style']  ?? 'normal',
                number_format(floatval($el['line_height'] ?? 1.4), 2),
                $el['text_align']  ?? '',
                intval($el['locked'] ?? 0),
                $order++,
                max(1, intval($el['z_index'] ?? 1)),
                intval($el['hidden'] ?? 0) ? 1 : 0,
            ]);
        }
    }

    /**
     * The asset ids this Display's layout currently points at.
     *
     * Read before a publish deletes the layout, so the rows that publish is about
     * to orphan can be found again afterwards. Distinct ids, not one per element:
     * two blocks can share a row from before pooling stopped sharing them.
     */
    private function assetIdsOn(Display $display)
    {
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT asset_id FROM canvas_elements
              WHERE display_id = ? AND asset_id IS NOT NULL"
        );
        $stmt->execute([$display->id()]);

        $out = [];
        foreach ($stmt->fetchAll() as $row) { $out[] = intval($row['asset_id']); }
        return $out;
    }

    /**
     * Which of these asset ids anything at all still points at — any Display, not
     * just the one being published.
     *
     * "Anything at all" is the only safe question. A row shared by two signs from
     * before pooling stopped sharing them is still live for the other sign after
     * this one stops using it, and `asset_id` is ON DELETE SET NULL: deleting it
     * would blank that line over there with nothing to say so.
     */
    private function referencedAmong(array $assetIds)
    {
        $ids = [];
        foreach ($assetIds as $id) {
            $id = intval($id);
            if ($id > 0) { $ids[] = $id; }
        }
        if (!$ids) { return []; }

        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT asset_id FROM canvas_elements
              WHERE asset_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $row) { $out[] = intval($row['asset_id']); }
        return $out;
    }

    /**
     * Every asset id any Display points at. For the Library page's tidy-up, which
     * has to ask both modules: this one knows the references, AssetLibrary knows
     * which of the remainder were created by a publish rather than by a person.
     */
    public function referencedAssetIds()
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT DISTINCT asset_id FROM canvas_elements WHERE asset_id IS NOT NULL"
            );
            $out = [];
            foreach ($stmt->fetchAll() as $row) { $out[] = intval($row['asset_id']); }
            return $out;
        } catch (Throwable $e) {
            // An empty list would read as "nothing is referenced", and the caller
            // would sweep the entire pool. Say so instead.
            return null;
        }
    }

    private function ownsElement(Display $display, $elementId)
    {
        $stmt = $this->pdo->prepare("SELECT id FROM canvas_elements WHERE id = ? AND display_id = ? LIMIT 1");
        $stmt->execute([intval($elementId), $display->id()]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Why a publish was refused when somebody else has the Display.
     *
     * Says the work is still on screen, because it is: nothing was deleted, and the
     * one thing that would lose it now is closing the tab. Reloading is deliberately
     * *not* the advice here — that is the staleness message's advice, and following
     * it while a colleague is mid-edit would throw away work that may well be
     * publishable in ten minutes.
     */
    private function lockedMessage(LockState $lock)
    {
        return 'Someone else is editing this display now (' . $lock->holderDescription() . '). '
             . 'Nothing was saved. Your changes are still on screen — publish again once they '
             . 'are finished, or ask them to close the builder.';
    }

    /**
     * Why a publish was refused, in terms the person can act on. Names the last
     * publish when there was one — a stamp can also have moved because an element
     * was hidden or deleted in the admin panel, so the wording says "changed",
     * not "published".
     */
    private function stalenessMessage(Display $display)
    {
        $who = $display->lastPublishDescription();
        $blame = $who !== '' ? ' (last published by ' . $who . ')' : '';
        return 'This display changed since you opened it' . $blame . '. '
             . 'Nothing was saved — reload the page and re-apply your change, '
             . 'so you do not overwrite someone else\'s work.';
    }
}
