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
//   setElementHidden(Display, id, bool)   → bool: was it this Display's element?
//   deleteElement(Display, id)            → bool: was it this Display's element?
//   elementCount(Display)                 → int, for a confirm that says what is at stake
//   copyLayout(Display, Display)          → int copied: duplicating a Display
//   deleteAllElements(Display)            → int deleted: destroying a Display
//
// Callers pass a Display and get results back. They never see the transaction,
// the temp-id map, the staleness comparison, the asset auto-save, the plain-text
// stripping, or the admin/basic section rules.
//
// The `displays` table is not this module's to write: the stamp, the background
// and the publish record are reached through DisplayStore, which owns every
// statement against it. This store owns `canvas_elements`, and only that.
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

    public function elements()   { return $this->elements; }
    public function background() { return $this->background; }
    public function actorId()    { return $this->actorId; }
    public function isAdmin()    { return $this->isAdmin; }
    public function stamp()      { return $this->stamp; }
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

    public function __construct(PDO $pdo, DisplayStore $displays)
    {
        $this->pdo      = $pdo;
        $this->displays = $displays;
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

        $styles = [];
        foreach ($this->pdo->query("SELECT * FROM block_styles")->fetchAll() as $s) {
            $styles[$s['block_type']] = $s;
        }

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
    public function setElementHidden(Display $display, $elementId, $hidden)
    {
        if (!$this->ownsElement($display, $elementId)) { return false; }
        $this->pdo->prepare("UPDATE canvas_elements SET hidden = ? WHERE id = ? AND display_id = ?")
                  ->execute([$hidden ? 1 : 0, intval($elementId), $display->id()]);
        $this->displays->advanceLayoutRevision($display);
        return true;
    }

    /**
     * Delete one element. Children of a section go with it via the section_id
     * FK's ON DELETE CASCADE. Returns false when the element is not this
     * Display's.
     */
    public function deleteElement(Display $display, $elementId)
    {
        if (!$this->ownsElement($display, $elementId)) { return false; }
        $this->pdo->prepare("DELETE FROM canvas_elements WHERE id = ? AND display_id = ?")
                  ->execute([intval($elementId), $display->id()]);
        $this->displays->advanceLayoutRevision($display);
        return true;
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
        $this->pdo->beginTransaction();
        try {
            $current = $this->displays->lockLayoutRevision($display);
            if ($current === false) {
                $this->pdo->rollBack();
                return PublishResult::failed('That display no longer exists. Reload the page.');
            }

            // The Display as it stands under that row lock. Both refusals below are
            // decided from this one read, so they cannot disagree about the moment
            // they describe.
            $fresh = $this->displays->forId($display->id());
            $lock  = ($fresh ?: $display)->lockState();

            if ($lock->heldByOther($request->actorId())) {
                $this->pdo->rollBack();
                return PublishResult::locked($this->lockedMessage($lock));
            }

            if ((string)intval($current) !== $request->stamp()) {
                $this->pdo->rollBack();
                return PublishResult::stale($this->stalenessMessage($fresh ?: $display));
            }

            if ($request->isAdmin()) {
                $this->displays->applyBackground($display, $request->background());
                $this->replaceWholeLayout($display, $request->elements());
            } else {
                $this->replaceContentOnly($display, $request->elements());
            }

            $newStamp = $this->displays->recordPublish($display, $request->actorId());

            // Publishing is about as real as an interaction gets, so it keeps the
            // lock alive — and takes it back for a tab whose lock had lapsed while
            // nobody else claimed it (ADR-0007). Never a steal: the check above has
            // already established nobody else is holding this Display.
            $this->displays->claimLock($display, $request->actorId());

            $this->pdo->commit();
            return PublishResult::ok($newStamp);
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            return PublishResult::failed('Publish failed. Nothing was saved.');
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
                $tempMap[$el['temp_id']] = $this->pdo->lastInsertId();
            }
        }

        $this->insertContent($display, $elements, $tempMap);
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

            $parentTmp = $el['parent_temp_id'] ?? null;
            $sectionId = $parentTmp ? ($tempMap[$parentTmp] ?? null) : null;
            $assetId   = !empty($el['asset_id']) ? intval($el['asset_id']) : null;
            $manual    = $el['manual_content'] ?? '';

            // Text blocks are plain text (ADR-0002). Non-text types carry JSON
            // (carousel/table/marquee) or file paths and must NOT be stripped.
            if ($type === 'text') {
                $manual = toPlainText($manual);
            }

            if (!$assetId && $manual !== '' && $manual !== null && !empty($el['save_to_db_pool'])) {
                $assetId = $this->linkToAssetPool($type, $manual);
                $manual  = null;
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
     * Put new standalone content into the shared asset library and return its ID,
     * reusing an identical entry rather than accumulating duplicates. The library
     * is shared across Displays by design.
     */
    private function linkToAssetPool($type, $content)
    {
        $dup = $this->pdo->prepare("SELECT id FROM assets WHERE type = ? AND content = ? LIMIT 1");
        $dup->execute([$type, $content]);
        $existing = $dup->fetch();
        if ($existing) { return intval($existing['id']); }

        $this->pdo->prepare("INSERT INTO assets (type, content, label) VALUES (?,?,?)")
                  ->execute([$type, $content, 'Auto: ' . substr(strip_tags($content), 0, 20)]);
        return intval($this->pdo->lastInsertId());
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
