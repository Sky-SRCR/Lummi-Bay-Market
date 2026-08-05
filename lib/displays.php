<?php
// ============================================================
// DISPLAYS
// ============================================================
// A Display is one configured sign: its screen name tag, canvas size, title,
// location, background, active state, publish stamp and edit lock (CONTEXT.md).
//
// Two things live here and nowhere else:
//   Display      — one Display's facts, in the app's vocabulary rather than the
//                  database's, including the compatibility shape the Viewer and
//                  Builder still read as `settings`.
//   DisplayStore — every statement that touches the `displays` table, the tag
//                  rules, and the recovery when the table is not there yet.
//
// No caller outside this file may write SQL against `displays`. Phases 3–5 add
// methods here (create/rename/deactivate/delete, lock take/heartbeat/release)
// rather than SQL anywhere else.

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

    /** How many Displays exist. Phase 3's Builder entry rule turns on this. */
    public function count()
    {
        return count($this->all());
    }

    /**
     * PHASE-1 TRANSITIONAL — the only Display, when there is exactly one.
     *
     * Requests that name no Display resolve through here while the live Screen
     * and the SmartSign2Go widget still point at a bare `viewer.php`, and while
     * the Builder and admin panel have no Display picker. Returning null as soon
     * as a second Display exists is the safety property: a write can never be
     * silently routed to the wrong sign, it fails instead (BUILD-REFERENCE.md §3).
     *
     * Deleted in Phase 2 (Viewer) / Phase 3 (Builder, admin panel).
     */
    public function sole()
    {
        $rows = $this->rows("ORDER BY d.id ASC", []);
        return count($rows) === 1 ? new Display($rows[0]) : null;
    }

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
