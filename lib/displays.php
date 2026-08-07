<?php
// ============================================================
// DISPLAYS
// ============================================================
// A Display is one configured sign: its screen name tag, canvas size, title,
// location, background, active state, publish stamp and edit lock (CONTEXT.md).
//
// Three things live here and nowhere else:
//   Display      — one Display's facts, in the app's vocabulary rather than the
//                  database's.
//   LockState    — whether it is being edited right now, and by whom (ADR-0007).
//   DisplayStore — every statement that touches the `displays` table, the tag
//                  and canvas-size rules, and the recovery when the table is not
//                  there yet.
//
// No caller outside this file may write SQL against `displays`.
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

/**
 * Who is editing a Display, and whether they still are (ADR-0007).
 *
 * A lock is held by *activity*, not presence: the Builder reports the time of the
 * last real interaction, and a lock whose last interaction is older than
 * IDLE_LAPSE_SECONDS is simply free. That comparison happens here, on every read,
 * which is why nothing has to be scheduled to clean locks up — there is no cron on
 * this host and a lapsed lock that needed sweeping would outlive the tab that left
 * it. The consequence is that "free" and "lapsed" are the same state everywhere.
 *
 * *Whose* activity also has to still count. A lock recorded against an account that
 * can no longer sign in is not a colleague mid-edit, it is a leftover — and reading
 * it as a lock blocked a whole Display for fifteen minutes, under the name of
 * somebody who was locked out. Freeing the lock at the moment access changes is the
 * other half of this (see DisplayAdmin::setActive and AccountAdmin::edit) and it is
 * the half that only covers the paths somebody thought of; this one covers the rest,
 * including any row already stranded before the fix existed.
 *
 * A value object: built from a `displays` row, never stored. Two accounts asking
 * at the same moment get the same answer.
 */
class LockState
{
    /**
     * How long a lock survives without a real interaction, and when its holder is
     * warned that it is about to go.
     *
     * 15 minutes is ADR-0007's judgement call — long enough that reading, thinking
     * or a phone call does not cost you the sign, short enough that a Builder left
     * open on a back-office monitor frees it before it matters. Both numbers are
     * sent to the Builder rather than duplicated in its JavaScript, so the warning
     * cannot drift away from the window it warns about.
     */
    const IDLE_LAPSE_SECONDS = 900;
    const WARN_AFTER_SECONDS = 780;

    private $holderId;
    private $holderName;
    private $takenAt;
    private $activityAt;
    private $idleSeconds;
    private $holderActive;

    /**
     * @param bool $holderActive whether the holder may still sign in. Defaults to
     *                           true, because a caller that did not join `users`
     *                           has not learned the holder is locked out — it has
     *                           learned nothing, and the safe reading of nothing is
     *                           to leave a colleague's lock alone.
     */
    public function __construct($holderId, $holderName, $takenAt, $activityAt, $holderActive = true)
    {
        $this->holderId     = intval($holderId);
        $this->holderName   = (string)$holderName;
        $this->takenAt      = $takenAt    ?: null;
        $this->activityAt   = $activityAt ?: null;
        $this->holderActive = (bool)$holderActive;
        // Both sides of this subtraction are PHP's clock — see DisplayStore's lock
        // statements, which bind a PHP-formatted timestamp for exactly that reason.
        $this->idleSeconds = $this->activityAt === null
            ? self::IDLE_LAPSE_SECONDS
            : max(0, time() - self::toEpoch($this->activityAt));
    }

    /**
     * Is somebody editing this Display right now?
     *
     * A holder with no recorded activity counts as nobody: the row says someone
     * started and never reported working, which is the state a half-finished write
     * or a hand edit would leave, and the safe reading of it is "free".
     *
     * So does a holder who can no longer sign in. Their Builder cannot beat, so the
     * lock would sit out the full idle window with their name on every colleague's
     * read-only banner — and they could not release it even deliberately, because
     * releasing goes through the seam that has just started refusing them.
     */
    public function isHeld()
    {
        if ($this->holderId <= 0) { return false; }
        if (!$this->holderActive) { return false; }
        return $this->idleSeconds < self::IDLE_LAPSE_SECONDS;
    }

    public function isFree() { return !$this->isHeld(); }

    public function holderId()   { return $this->isHeld() ? $this->holderId : 0; }
    public function holderName() { return $this->isHeld() ? $this->holderName : ''; }

    /** Held, by this account — the question the Builder asks about itself. */
    public function heldBy($accountId)
    {
        return $this->isHeld() && $this->holderId === intval($accountId);
    }

    /**
     * Held, by somebody else — the question that decides a read-only Builder and
     * refuses a publish. Deliberately not `!heldBy()`: a free Display is neither.
     */
    public function heldByOther($accountId)
    {
        return $this->isHeld() && $this->holderId !== intval($accountId);
    }

    /**
     * A stored lock timestamp as an epoch second.
     *
     * The stored form is UTC (see DisplayStore's lock statements), and strtotime
     * reads a bare 'Y-m-d H:i:s' in the *server's* zone — so it has to be told.
     * A row written before this build stored local time and will read up to a few
     * hours stale here, which errs towards "lapsed": the lock frees early rather
     * than sticking, and one heartbeat rewrites it correctly.
     */
    private static function toEpoch($stamp)
    {
        $epoch = strtotime($stamp . ' UTC');
        return $epoch === false ? 0 : $epoch;
    }

    /** How long since the holder's last real interaction. Seconds. */
    public function idleSeconds() { return $this->idleSeconds; }

    /** "2:04pm" — when the holder started, for a banner. Empty when free. */
    public function takenAtLabel()
    {
        if (!$this->isHeld() || $this->takenAt === null) { return ''; }
        // Stored in UTC, shown in the server's local time — the one place the two
        // are allowed to meet, because this string is only ever read by a person.
        return date('g:ia', self::toEpoch($this->takenAt));
    }

    /** "sky, editing since 2:04pm" — the material for a refused publish. Empty when free. */
    public function holderDescription()
    {
        if (!$this->isHeld()) { return ''; }
        $who   = $this->holderName !== '' ? $this->holderName : 'someone else';
        $since = $this->takenAtLabel();
        return $since === '' ? $who : $who . ', editing since ' . $since;
    }
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

    // Edit-lock columns. Raw facts; the rule that turns them into "is anyone
    // editing this?" is LockState, so nothing has to remember the idle window.
    public function lockHolderId()   { return $this->row['lock_holder_id'] ? intval($this->row['lock_holder_id']) : 0; }
    public function lockActivityAt() { return $this->row['lock_activity_at'] ?: null; }

    /**
     * Is the account holding this Display an admin?
     *
     * Asked by one caller, for one reason: retiring a Display takes it away from a
     * `basic` account and leaves it with an admin (Actor::mayOpen — a Display out of
     * service stays editable by admins), so it decides whose lock retiring frees.
     * False when nobody holds it, and false when the joined role is missing, which
     * errs towards freeing rather than towards stranding.
     */
    public function lockHolderIsAdmin()
    {
        if ($this->lockHolderId() <= 0) { return false; }
        return isset($this->row['lock_holder_role']) && $this->row['lock_holder_role'] === 'admin';
    }

    /**
     * Who is editing this Display, and whether they still are (ADR-0007).
     *
     * Deliberately absent from toClientArray(): that array is what the public
     * `get_layout` poll returns to every Screen, and who is editing a sign is
     * nobody's business from the street.
     */
    public function lockState()
    {
        return new LockState(
            $this->lockHolderId(),
            // Guarded rather than assumed: a hand-written query that skips the
            // holder join, or a database where the lock_taken_at ALTER has not
            // applied, simply has no such key — and a lock still works without a
            // name or a start time to print.
            isset($this->row['lock_holder_name']) ? $this->row['lock_holder_name'] : '',
            isset($this->row['lock_taken_at'])    ? $this->row['lock_taken_at']    : null,
            $this->lockActivityAt(),
            // Same guard, and the same reason: absent means unknown, not locked out.
            isset($this->row['lock_holder_active']) ? intval($this->row['lock_holder_active']) === 1 : true
        );
    }

    /**
     * "Dana has been editing Drive-Thru since 2:15pm." — for a refusal that has to
     * name who is in the way. Empty when this Display is free.
     */
    public function editingSentence()
    {
        $lock = $this->lockState();
        if (!$lock->isHeld()) { return ''; }
        $who   = $lock->holderName() !== '' ? $lock->holderName() : 'Someone';
        $since = $lock->takenAtLabel();
        return $who . ' has been editing ' . $this->title()
             . ($since !== '' ? ' since ' . $since : '') . '.';
    }

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
     * The first Display someone other than this account is editing right now, or
     * null if nobody is.
     *
     * Brand Standards is the one edit that is not scoped to a Display: the six
     * branded block types are shared by every sign, and their typography is part of
     * every snapshot, so a change reaches every Screen on the next 30-second poll
     * with no publish at all. A single Display's lock therefore cannot guard it —
     * but any held lock is somebody sizing blocks against the typography that is
     * about to change under them, and they would never be told.
     *
     * So the answer is "refuse while anyone else is editing anything". Lapsed locks
     * are free and do not block, which is why this asks LockState rather than
     * testing the column: a Builder left open on a back-office monitor stops
     * counting after the idle window, same as everywhere else.
     */
    public function editedByAnyoneElse($accountId)
    {
        foreach ($this->all() as $display) {
            if ($display->lockState()->heldByOther($accountId)) { return $display; }
        }
        return null;
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

    // ---- The edit lock (ADR-0007) -------------------------------------------
    // One account edits a Display at a time. Every statement here stamps the time
    // of a *real interaction*, not the time of the request: a lock is held by work,
    // and a Builder left open on a back-office monitor must not hold a sign all
    // afternoon. LockState turns those stamps into held-or-free on read.
    //
    // Both sides of that comparison are PHP's clock, which is why these bind a
    // PHP-formatted timestamp instead of CURRENT_TIMESTAMP. It is the one place in
    // this file that subtracts one time from another, and if MySQL and PHP disagree
    // about the hour a 15-minute window becomes an hour long or already expired.
    //
    // And they are UTC — gmdate, not date. Local wall-clock strings are not
    // monotonic: on the autumn fall-back the hour from 1:00 to 2:00 happens twice,
    // second-pass strings sort *below* first-pass ones, and strtotime resolves the
    // repeated hour to its first occurrence. That combination broke the lock in
    // three different directions for an hour every year — anyone could steal an
    // actively-held lock, then for the rest of the hour every idle age read as an
    // hour so nothing was read-only and no publish was refused, and afterwards a
    // free Display could be claimed by nobody. UTC has no repeated hour. The only
    // local time in this file is what a human reads, and that is formatted on the
    // way out, not stored.

    /**
     * Take the lock, keep it, or take it back — one statement for all three.
     *
     * Claims when the lock is free, already this account's, or lapsed; leaves it
     * alone when somebody else is actively holding it. That is the heartbeat's rule
     * as well, which is why there is no separate heartbeat method: taking and
     * keeping differ only in whether there was a holder, and two methods would be
     * two places to get the lapse cutoff wrong.
     *
     * @param int $idleSeconds how long ago the holder's last real interaction was.
     *                         The Builder sends the true age rather than "now", so
     *                         a heartbeat can never quietly extend a lock on a
     *                         Display nobody has touched.
     * @return Display|null the Display as it now stands — ask its lockState()
     *                      whether the claim succeeded. Null if it has been deleted.
     */
    public function claimLock(Display $display, $accountId, $idleSeconds = 0)
    {
        $accountId   = intval($accountId);
        $idleSeconds = max(0, intval($idleSeconds));

        // Nothing to claim on behalf of, and nothing to claim once the reported
        // idle age is past the window: storing it would record a lock that is
        // already lapsed, and a caller sending that is asking who holds it now.
        if ($accountId <= 0 || $idleSeconds >= LockState::IDLE_LAPSE_SECONDS) {
            return $this->forId($display->id());
        }

        $now      = time();
        $activity = gmdate('Y-m-d H:i:s', $now - $idleSeconds);
        $cutoff   = gmdate('Y-m-d H:i:s', $now - LockState::IDLE_LAPSE_SECONDS);

        // One conditional UPDATE rather than a read and then a write: two people
        // opening the same Display in the same second must not both be told it is
        // theirs. lock_taken_at survives a heartbeat from the same account, so
        // "editing since" means since they started rather than since their last
        // click — but a lapse ends that session, so coming back starts a new one.
        //
        // lock_holder_id is assigned *last* on purpose. MySQL evaluates a SET list
        // left to right and a later expression sees the values already assigned,
        // while SQLite reads the original row throughout — so a CASE that consults
        // lock_holder_id has to be written before that column is overwritten, or the
        // two engines disagree about which holder it is asking about.
        //
        // The last disjunct is the same rule LockState::isHeld applies on read: a
        // lock recorded against an account that can no longer sign in is not a lock.
        // It has to be *here* as well as there, or the two disagree and the disagreement
        // is silent — a colleague would be shown an editable canvas because the read
        // said free, then have every claim quietly do nothing, and find out at the
        // publish. A correlated NOT EXISTS rather than a join, because a multi-table
        // UPDATE is MySQL-only and the test fixture is SQLite.
        $this->pdo->prepare(
            "UPDATE displays
                SET lock_taken_at    = CASE WHEN lock_holder_id = ? AND lock_activity_at > ?
                                            THEN lock_taken_at ELSE ? END,
                    lock_activity_at = ?,
                    lock_holder_id   = ?
              WHERE id = ?
                AND (lock_holder_id IS NULL
                     OR lock_holder_id = ?
                     OR lock_activity_at IS NULL
                     OR lock_activity_at <= ?
                     OR NOT EXISTS (SELECT 1 FROM users
                                     WHERE users.id = displays.lock_holder_id
                                       AND users.is_active = 1))"
        )->execute([
            $accountId, $cutoff, $activity,
            $activity,
            $accountId,
            $display->id(),
            $accountId,
            $cutoff,
        ]);

        return $this->forId($display->id());
    }

    /**
     * Give the lock up, if this account is the one holding it.
     *
     * Called when the Builder is left. Naming the holder in the WHERE clause is the
     * point: a tab that closes late — after its lock lapsed and a colleague took
     * over — must not free the lock that colleague is now working under.
     */
    public function releaseLock(Display $display, $accountId)
    {
        $this->releaseLockOn($display->id(), $accountId);
        return $this->forId($display->id());
    }

    /**
     * The same release, by id, answering whether there was one to release.
     *
     * Two callers want different things from it. The Builder leaving a page wants
     * the Display back to re-read its lock state, which is what releaseLock()
     * above returns. An admin taking somebody's access away wants to know whether
     * a lock was actually freed, because that decides what the panel tells them —
     * and it is holding a transaction, where re-reading a row through forId() would
     * be a query whose answer nobody uses.
     *
     * Naming the holder in the WHERE clause matters here as much as there: revoking
     * one person's grant must not free the lock a *colleague* is working under on
     * the same Display.
     *
     * @return bool whether this account was holding it
     */
    public function releaseLockOn($displayId, $accountId)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE displays
                SET lock_holder_id = NULL, lock_taken_at = NULL, lock_activity_at = NULL
              WHERE id = ? AND lock_holder_id = ?"
        );
        $stmt->execute([intval($displayId), intval($accountId)]);
        // rowCount() is safe to read here on both engines: the WHERE clause requires
        // a non-null holder, so a matched row always has lock_holder_id changing to
        // NULL — MySQL's "rows changed" and SQLite's "rows matched" cannot disagree.
        return $stmt->rowCount() > 0;
    }

    /**
     * Hand the lock to this account, whoever held it — an admin taking over
     * (ADR-0007's force-unlock), behind a confirm.
     *
     * It transfers rather than clears, which is the difference between a takeover
     * that sticks and one that silently undoes itself: a cleared lock is a free
     * lock, and the ousted Builder's next heartbeat would claim it straight back
     * within the minute. Transferring also gives that tab something definite to
     * report — somebody else holds this now — instead of looking like a glitch.
     */
    public function seizeLock(Display $display, $accountId)
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            "UPDATE displays SET lock_holder_id = ?, lock_taken_at = ?, lock_activity_at = ? WHERE id = ?"
        )->execute([intval($accountId), $now, $now, $display->id()]);
        return $this->forId($display->id());
    }

    /**
     * Drop every lock this account holds, on every Display.
     *
     * Called when the account is deleted. `ON DELETE SET NULL` should do it, but
     * that constraint is added by schemaTry() and may never have applied — and a
     * lock naming a deleted account is a sign nobody can edit for fifteen minutes,
     * held by a name the banner cannot even print.
     *
     * @return int locks released
     */
    public function releaseLocksHeldBy($accountId)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE displays
                SET lock_holder_id = NULL, lock_taken_at = NULL, lock_activity_at = NULL
              WHERE lock_holder_id = ?"
        );
        $stmt->execute([intval($accountId)]);
        return $stmt->rowCount();
    }

    // ---- Tag rules ----------------------------------------------------------

    /** Fold user input toward a valid tag without inventing one: trim, lowercase. */
    public static function normalizeTag($tag)
    {
        // Anything that is not a scalar cannot be folded toward a tag, and casting it
        // pretends otherwise: `(string)` on an array is `Array` plus a warning in the
        // log, and the line below then lowercases that into `array` — which
        // `isValidTag()` accepts, so the cast did not produce something that would be
        // refused further down. It produced a tag (decision #27). Every path that turns
        // a request into a tag comes through here, so a form posting `tag[]=x` named a
        // sign `array`, and one posting `confirm_tag[]=x` then *spelled the
        // confirmation* for deleting the sign already tagged that — the one action in
        // this app that loses work with no way back. Empty is the honest answer: the input said
        // nothing a tag can be, and empty is already what every caller checks for.
        if (!is_scalar($tag)) { return ''; }
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
        // The lock holder's own row comes back with the lock: their name for a
        // banner, and — since #22 — whether they may still sign in and whether they
        // are an admin. Both are facts about the lock rather than about the person:
        // one decides whether the lock counts at all (LockState::isHeld), the other
        // whose lock retiring a Display frees (DisplayAdmin::setActive). Joined here
        // so every read of a Display answers them, rather than each caller
        // remembering to ask.
        $sql = "SELECT d.*, u.username AS last_published_by_name,
                       lu.username  AS lock_holder_name,
                       lu.is_active AS lock_holder_active,
                       lu.role      AS lock_holder_role
                FROM displays d
                LEFT JOIN users u  ON d.last_published_by = u.id
                LEFT JOIN users lu ON d.lock_holder_id    = lu.id
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
     * convergence run. So convergence is triggered by the *failure* — "that table
     * is not there" — and not by the request. Once the table exists the poll never
     * reaches this path again.
     *
     * Whether it is *safe* to converge from here is not this file's question to
     * answer: `repairSchemaAfterFailure()` owns it, because the answer is about DDL
     * and transactions and how often the whole installation has already tried. All
     * this decides is whether the error was the kind a repair could fix. A `false`
     * from either half means the caller rethrows, which is the right outcome — the
     * table really is missing, and saying so beats pretending otherwise.
     */
    private function healSchema(PDOException $e)
    {
        if ($this->healAttempted) { return false; }
        if (!schemaErrorSaysTableMissing($e->getCode(), $e->getMessage())) { return false; }
        $this->healAttempted = true;
        return repairSchemaAfterFailure($this->pdo);
    }
}
