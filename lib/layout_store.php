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
//   · A publish whose stamp no longer matches the Display is refused (ADR-0006).
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
    private $kind;      // 'ok' | 'stale' | 'failed'
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
            'display'      => $display_,
            // PHASE-1 TRANSITIONAL — `settings` is the key the Viewer and Builder
            // already read for bg_type/bg_val, from the retired single-row
            // canvas_settings. Same array under both names so Phase 1 needs no
            // client change; Phase 2 moves both clients to `display` and this
            // alias goes.
            'settings'     => $display_,
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
     * Refuses (without writing anything) when the submitted stamp is not the
     * Display's current stamp — someone else published, or an element was hidden
     * or deleted, since this Builder loaded. There is no merge and no undo: the
     * refusal is the safety net (ADR-0006).
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

            if ((string)intval($current) !== $request->stamp()) {
                // Re-read before rolling back, so the refusal names whoever
                // published most recently rather than whoever had when this
                // request started.
                $fresh = $this->displays->forId($display->id());
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
