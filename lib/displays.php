<?php
// ============================================================
// DISPLAYS
// ============================================================
// A Display is one configured sign: its screen name tag, canvas size, title,
// location, background, active state, publish stamp and edit lock (CONTEXT.md).
//
// Two things live here and nowhere else:
//   Display      — one Display's facts, in the app's vocabulary rather than the
//                  database's.
//   DisplayStore — every statement that touches the `displays` table, the tag
//                  and canvas-size rules, and the recovery when the table is not
//                  there yet.
//
// No caller outside this file may write SQL against `displays`. Phase 5 adds
// methods here (lock take/heartbeat/release) rather than SQL anywhere else.
//
// The *use case* of administering Displays — what a complete new Display needs,
// creating one as a duplicate, destroying one with its layout — is one level up,
// in `display_admin.php`. That module composes this one with LayoutStore, because
// a Display's elements are not this file's to touch.

require_once __DIR__ . '/schema.php';

/**
 * What a publish should do with the Display's background — an admin sending
 * "image" with no new file means "keep the current image, just switch back to
 * it", which is not the same request as "set this image".
 */
class Background
{
    private $kind;
    private $value;

    private function __construct($kind, $value)
    {
        $this->kind  = $kind;
        $this->value = $value;
    }

    /** Leave the background exactly as it is (a basic account cannot change it). */
    public static function unchanged()  { return new self('unchanged', null); }
    public static function color($hex)  { return new self('color', (string)$hex); }
    public static function image($path) { return new self('image', (string)$path); }
    /** Switch to image without replacing the stored path. */
    public static function keepImage()  { return new self('keep-image', null); }

    public function kind()  { return $this->kind; }
    public function value() { return $this->value; }
}

class Display
{
    private $row;

    /** @param array $row a `displays` row, optionally with last_published_by_name joined in */
    public function __construct(array $row)
    {
        $this->row = $row;
    }

    public function id()            { return intval($this->row['id']); }
    public function tag()           { return (string)$this->row['tag']; }
    public function title()         { return (string)$this->row['title']; }
    public function location()      { return isset($this->row['location']) ? (string)$this->row['location'] : ''; }
    public function canvasWidth()   { return intval($this->row['canvas_width']); }
    public function canvasHeight()  { return intval($this->row['canvas_height']); }
    public function isActive()      { return intval($this->row['is_active']) === 1; }

    public function backgroundType()  { return $this->row['bg_type'] === 'image' ? 'image' : 'color'; }
    public function backgroundValue() { return (string)$this->row['bg_val']; }

    /**
     * Same canvas shape, to the pixel.
     *
     * The one rule ADR-0004 turns on: a layout may be duplicated only between
     * Displays of identical dimensions, because positions are absolute pixels and
     * there is no undo to recover from a silently rescaled sign. Lives on the
     * value object so the store, the use case and the UI all ask the same
     * question instead of each comparing two pairs of integers.
     */
    public function sameShapeAs(Display $other)
    {
        return $this->canvasWidth() === $other->canvasWidth()
            && $this->canvasHeight() === $other->canvasHeight();
    }

    /** "1920 × 1080" — for a heading, a picker row or a confirm. */
    public function dimensionsLabel()
    {
        return $this->canvasWidth() . ' × ' . $this->canvasHeight();
    }

    /** Portrait, landscape or square — the word that makes a dimensions column readable. */
    public function orientation()
    {
        if ($this->canvasHeight() > $this->canvasWidth()) { return 'portrait'; }
        if ($this->canvasWidth() > $this->canvasHeight()) { return 'landscape'; }
        return 'square';
    }

    /**
     * The publish stamp (ADR-0006): an opaque token identifying this Display's
     * current layout. A Builder holds the one it loaded and submits it back; a
     * publish whose stamp no longer matches is refused. Opaque on purpose —
     * callers compare it, they don't interpret it.
     */
    public function layoutStamp()   { return (string)intval($this->row['layout_revision']); }

    public function lastPublishedAt()     { return $this->row['last_published_at'] ?: null; }
    public function lastPublishedById()   { return $this->row['last_published_by'] ? intval($this->row['last_published_by']) : 0; }
    public function lastPublishedByName() { return isset($this->row['last_published_by_name']) ? (string)$this->row['last_published_by_name'] : ''; }

    // Edit-lock columns. Read-only until Phase 5 gives them behaviour.
    public function lockHolderId()   { return $this->row['lock_holder_id'] ? intval($this->row['lock_holder_id']) : 0; }
    public function lockActivityAt() { return $this->row['lock_activity_at'] ?: null; }

    /** "sky, Aug 5 at 2:04pm" — the material for a refused-publish message. Empty when never published. */
    public function lastPublishDescription()
    {
        $at = $this->lastPublishedAt();
        if (!$at) { return ''; }
        $who  = $this->lastPublishedByName() !== '' ? $this->lastPublishedByName() : 'someone else';
        $when = date('M j \a\t g:ia', strtotime($at));
        return $who . ', ' . $when;
    }

    /**
     * The Display as the Viewer and Builder consume it, under the `display` key of
     * a layout snapshot. `bg_type`/`bg_val` keep the exact key names the retired
     * single-row `canvas_settings` used, so the background reading code was
     * unchanged when Phase 2 moved both clients onto this array.
     */
    public function toClientArray()
    {
        return [
            'id'            => $this->id(),
            'tag'           => $this->tag(),
            'title'         => $this->title(),
            'location'      => $this->location(),
            'canvas_width'  => $this->canvasWidth(),
            'canvas_height' => $this->canvasHeight(),
            'bg_type'       => $this->backgroundType(),
            'bg_val'        => $this->backgroundValue(),
            'is_active'     => $this->isActive() ? 1 : 0,
            'layout_stamp'  => $this->layoutStamp(),
        ];
    }
}

class DisplayStore
{
    /** Screen name tag rules: lowercase letters, digits and hyphens, 2–32 characters. */
    const TAG_MIN = 2;
    const TAG_MAX = 32;

    /** Column widths, so a too-long title is refused rather than silently truncated by MySQL. */
    const TITLE_MAX    = 120;
    const LOCATION_MAX = 160;

    /**
     * Canvas bounds. Wide enough for anything a store hangs on a wall — a video
     * wall, a portrait menu board, a narrow ticker strip — and narrow enough that
     * a typo (2 px, or 19200 px) is caught at creation, when it is still fixable.
     * Dimensions cannot be changed afterwards (ADR-0004).
     */
    const CANVAS_MIN = 200;
    const CANVAS_MAX = 10000;

    private $pdo;
    private $healAttempted = false;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ---- Reads --------------------------------------------------------------

    /** The Display with this screen name tag, or null. Accepts raw user input. */
    public function forTag($tag)
    {
        $tag = self::normalizeTag($tag);
        if (!self::isValidTag($tag)) { return null; }
        return $this->one("WHERE d.tag = ?", [$tag]);
    }

    public function forId($id)
    {
        $id = intval($id);
        if ($id <= 0) { return null; }
        return $this->one("WHERE d.id = ?", [$id]);
    }

    /** Every Display, oldest first. */
    public function all()
    {
        $out = [];
        foreach ($this->rows("ORDER BY d.id ASC", []) as $row) {
            $out[] = new Display($row);
        }
        return $out;
    }

    /** How many Displays exist at all, whoever is asking. */
    public function count()
    {
        return count($this->all());
    }

    /**
     * Every Display of exactly these dimensions — the Displays a new one may be
     * duplicated from (ADR-0004). Ordered oldest first, like all().
     */
    public function withDimensions($width, $height)
    {
        $out = [];
        foreach ($this->rows("WHERE d.canvas_width = ? AND d.canvas_height = ? ORDER BY d.id ASC",
                             [intval($width), intval($height)]) as $row) {
            $out[] = new Display($row);
        }
        return $out;
    }

    // The Builder's entry rule used to live here as sole(). It is now "the one
    // Display this account may open", which is a question about an account's
    // grants rather than about the table — so it lives with the Actor that holds
    // them, and DisplayRequest::locate() asks it (BUILD-REFERENCE.md §3, §4d).

    // ---- Writes used by the publish transaction ----------------------------
    // These four are called by LayoutStore from inside its transaction. They
    // live here because every statement against `displays` lives here — that is
    // what stops a second copy of "which Display?" appearing elsewhere.

    /**
     * This Display's authoritative layout revision, read under a row lock so two
     * publishes arriving together serialise instead of both reading the same
     * number and both passing the staleness check. False if it has been deleted.
     *
     * Overridable as an internal seam: `FOR UPDATE` is the one statement in this
     * file no other engine understands, and the self-test runs the real publish
     * path against SQLite by replacing just this method.
     */
    public function lockLayoutRevision(Display $display)
    {
        $stmt = $this->pdo->prepare("SELECT layout_revision FROM displays WHERE id = ? FOR UPDATE");
        $stmt->execute([$display->id()]);
        return $stmt->fetchColumn();
    }

    /** Apply a background intent. Only ever reached for an admin publish. */
    public function applyBackground(Display $display, Background $bg)
    {
        switch ($bg->kind()) {
            case 'color':
            case 'image':
                $this->pdo->prepare("UPDATE displays SET bg_type = ?, bg_val = ? WHERE id = ?")
                          ->execute([$bg->kind(), $bg->value(), $display->id()]);
                break;
            case 'keep-image':
                // Switch to the image background already stored, leaving the path.
                $this->pdo->prepare("UPDATE displays SET bg_type = 'image' WHERE id = ?")
                          ->execute([$display->id()]);
                break;
            case 'unchanged':
            default:
                break;
        }
    }

    /** Advance the stamp, record who published and when, and return the new stamp. */
    public function recordPublish(Display $display, $actorId)
    {
        $this->pdo->prepare(
            // CURRENT_TIMESTAMP rather than NOW(): identical in MySQL, and it
            // keeps this statement runnable by the self-test's SQLite fixture.
            "UPDATE displays
                SET layout_revision = layout_revision + 1,
                    last_published_at = CURRENT_TIMESTAMP,
                    last_published_by = ?
              WHERE id = ?"
        )->execute([intval($actorId) ?: null, $display->id()]);

        $stmt = $this->pdo->prepare("SELECT layout_revision FROM displays WHERE id = ?");
        $stmt->execute([$display->id()]);
        return (string)intval($stmt->fetchColumn());
    }

    /**
     * Advance the stamp for a change that is not a publish — hiding or deleting
     * one element. Deliberately leaves last_published_at/by alone, since nobody
     * published, while still invalidating any Builder holding the older layout.
     */
    public function advanceLayoutRevision(Display $display)
    {
        $this->pdo->prepare("UPDATE displays SET layout_revision = layout_revision + 1 WHERE id = ?")
                  ->execute([$display->id()]);
    }

    // ---- Administering Displays ---------------------------------------------
    // Called by DisplayAdmin, which validates first and holds the transaction.
    // These are the statements; the rules about what a *complete* Display needs
    // are one level up, so that "create" and "create as a duplicate" cannot end
    // up with two different ideas of a valid Display.

    /**
     * Insert a Display and return it as stored.
     *
     * Takes an already-validated set of fields — a caller reaching this method
     * with a duplicate tag gets the unique-key exception, which is the database
     * enforcing what DisplayAdmin already checked, not an expected path.
     */
    public function insert(array $fields)
    {
        $this->pdo->prepare(
            "INSERT INTO displays (tag, title, location, canvas_width, canvas_height, bg_type, bg_val, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            self::normalizeTag($fields['tag']),
            (string)$fields['title'],
            isset($fields['location']) && $fields['location'] !== '' ? (string)$fields['location'] : null,
            intval($fields['canvas_width']),
            intval($fields['canvas_height']),
            ($fields['bg_type'] ?? 'color') === 'image' ? 'image' : 'color',
            (string)($fields['bg_val'] ?? '#1a1a2e'),
            !empty($fields['is_active']) ? 1 : 0,
        ]);
        return $this->forId($this->pdo->lastInsertId());
    }

    /**
     * Change the reference details and the screen name tag.
     *
     * Canvas dimensions are deliberately absent: they are fixed at creation
     * (ADR-0004), and the way to not offer a resize is to have no statement that
     * can perform one.
     */
    public function updateDetails(Display $display, array $fields)
    {
        $this->pdo->prepare(
            "UPDATE displays SET tag = ?, title = ?, location = ? WHERE id = ?"
        )->execute([
            self::normalizeTag($fields['tag']),
            (string)$fields['title'],
            isset($fields['location']) && $fields['location'] !== '' ? (string)$fields['location'] : null,
            $display->id(),
        ]);
        return $this->forId($display->id());
    }

    /**
     * Turn a Display on or off. Off means Screens show "This display is turned
     * off" (ADR-0003) while the layout is kept and stays editable.
     */
    public function setActive(Display $display, $active)
    {
        $this->pdo->prepare("UPDATE displays SET is_active = ? WHERE id = ?")
                  ->execute([$active ? 1 : 0, $display->id()]);
        return $this->forId($display->id());
    }

    /**
     * Remove the Display row itself.
     *
     * Its elements are removed first, by DisplayAdmin through LayoutStore, rather
     * than relying on the `ON DELETE CASCADE` — the live database is behind the
     * repo and that constraint may never have applied (BUILD-REFERENCE §2.10).
     * Deleting the row and orphaning a layout would leave rows no scoped query
     * can ever see again.
     */
    public function deleteRow(Display $display)
    {
        $this->pdo->prepare("DELETE FROM displays WHERE id = ?")->execute([$display->id()]);
    }

    // ---- Tag rules ----------------------------------------------------------

    /** Fold user input toward a valid tag without inventing one: trim, lowercase. */
    public static function normalizeTag($tag)
    {
        return strtolower(trim((string)$tag));
    }

    public static function isValidTag($tag)
    {
        $len = strlen($tag);
        if ($len < self::TAG_MIN || $len > self::TAG_MAX) { return false; }
        return preg_match('/^[a-z0-9-]+$/', $tag) === 1;
    }

    /**
     * A candidate tag from a title: "Lobby Screen #2" → "lobby-screen-2".
     *
     * A suggestion, not a guarantee — an admin may replace it, and a title of
     * only punctuation yields '' rather than something invented. The result is
     * still checked by isValidTag() before use.
     */
    public static function suggestTag($title)
    {
        $tag = strtolower(trim((string)$title));
        $tag = preg_replace('/[^a-z0-9]+/', '-', $tag);
        $tag = trim($tag, '-');
        if (strlen($tag) > self::TAG_MAX) {
            $tag = rtrim(substr($tag, 0, self::TAG_MAX), '-');
        }
        return $tag;
    }

    /** Is this tag taken? `$exceptId` lets a Display keep its own tag while renaming. */
    public function tagExists($tag, $exceptId = 0)
    {
        $existing = $this->forTag($tag);
        if (!$existing) { return false; }
        return $existing->id() !== intval($exceptId);
    }

    /** Both dimensions present, whole, and inside the bounds above. */
    public static function isValidCanvasSize($width, $height)
    {
        foreach ([$width, $height] as $n) {
            if (intval($n) != $n) { return false; }   // loose: rejects "1920.5" and "abc"
            $n = intval($n);
            if ($n < self::CANVAS_MIN || $n > self::CANVAS_MAX) { return false; }
        }
        return true;
    }

    // ---- Internals ----------------------------------------------------------

    private function one($where, array $params)
    {
        $rows = $this->rows($where . " LIMIT 1", $params);
        return $rows ? new Display($rows[0]) : null;
    }

    private function rows($where, array $params)
    {
        $sql = "SELECT d.*, u.username AS last_published_by_name
                FROM displays d
                LEFT JOIN users u ON d.last_published_by = u.id
                " . $where;
        try {
            return $this->select($sql, $params);
        } catch (PDOException $e) {
            if (!$this->healSchema($e)) { throw $e; }
            return $this->select($sql, $params);
        }
    }

    private function select($sql, array $params)
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Recover from a genuinely absent schema, once per request.
     *
     * The public `get_layout` poll deliberately runs no migrations — every Screen
     * hits it every 30 seconds forever (BUILD-REFERENCE.md §2.7). But the very
     * first request after a deploy may well be that poll, and a sign that stays
     * dark until an admin happens to sign in is a worse outcome than one
     * convergence run. So convergence is triggered by the *failure* — "table
     * doesn't exist", SQLSTATE 42S02 — and not by the request. Once the table
     * exists the poll never reaches this path again.
     */
    private function healSchema(PDOException $e)
    {
        if ($this->healAttempted) { return false; }
        if ($e->getCode() !== '42S02') { return false; }   // not "undefined table"
        $this->healAttempted = true;
        ensureSignageSchema($this->pdo);
        return true;
    }
}
