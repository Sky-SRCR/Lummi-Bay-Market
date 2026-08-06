<?php
// ============================================================
// ASSET LIBRARY — the shared pool, and why a publish stopped sharing rows
// ============================================================
// Every statement against `assets` in the application lives here. It was the last
// table in the app with no owner: `crud.php` held five statements of its own,
// `api.php` a sixth, and `lib/layout_store.php` wrote a seventh from inside a
// publish transaction. Three files with an opinion about one table, and they
// disagreed about the most consequential thing in it.
//
// **Publishing used to share rows, and that is now over.**
//
// A text block published with no library link had its text moved into `assets`
// and the element left pointing at the new row — *reusing an existing row with
// identical content* rather than inserting a duplicate. It reads like good
// housekeeping. What it actually built was this:
//
//   · The deli board and the lobby screen both say "OPEN 7 DAYS". Both are
//     published. They now share one `assets` row, and neither element keeps a
//     copy of its own text (`manual_content` is set to NULL when the link is
//     made).
//   · An admin edits that row in the Library to "OPEN 7 DAYS A WEEK". Both signs
//     change, within thirty seconds, with nobody publishing anything.
//   · An admin deletes it. `canvas_elements.asset_id` is ON DELETE SET NULL and
//     the element has no text of its own left, so *both* signs lose that line —
//     permanently, because the next publish from either Builder writes the
//     emptiness back. There is no undo anywhere in this app.
//
// The de-duplication was never a feature anybody asked for; it was an
// optimisation for a database with one sign in it, where two elements sharing a
// row could only ever be two elements on the same canvas. So `pool()` now always
// inserts. A pooled row belongs to the one element that caused it, and editing or
// deleting it can reach exactly one sign.
//
// **The cost of that, paid honestly: pooled rows accumulate.** Publishing is
// destructive — it deletes the layout and re-inserts it — so the third time
// somebody fixes a typo and publishes, the two earlier rows are pointed at by
// nothing at all. Rows nothing points to are junk, and junk in the Library is not
// harmless: it is what an admin scrolls through looking for the promo banner.
//
// So a pooled row is marked as pooled (`auto_pooled`), and the marker is what
// makes it safe to sweep. Two sweeps, because there are two ways a row is
// orphaned and only one of them happens where a publish can see it:
//
//   · A publish drops the rows *its own previous layout* referenced and nothing
//     references now, inside the same transaction. Scoped to those ids on
//     purpose — a table-wide DELETE would take locks across every Display's rows
//     and could deadlock with a publish to a different sign.
//   · Deleting a block from the admin Work Area releases a reference with no
//     publish anywhere near it. Those are reachable only by looking at the whole
//     table, so the Library page offers an admin the count and a button.
//
// Nothing here ever deletes a row a person made. `auto_pooled = 0` — an uploaded
// image, a text snippet typed into the Library and not yet placed on a sign — is
// untouchable by both sweeps even when no element points at it, because "not used
// yet" is a normal state for something somebody made on purpose.
//
// Counting references means reading `canvas_elements`, and that is LayoutStore's
// table, not this one. So neither module answers the whole question: LayoutStore
// says which asset ids are referenced, this module says which of the rest are
// pooled, and the caller puts the two together. See crud.php.
//
// The one read of `assets` outside this file is the LEFT JOIN in
// LayoutStore::snapshot(), which resolves each element's content in the same
// query that fetches the element. It stays there deliberately: it is read-only,
// it is on the path a Screen polls every thirty seconds, and splitting it would
// mean one extra query per block on every sign in the building.

class AssetLibrary
{
    /**
     * What a pooled row's label starts with, and — on a database where the
     * marker column never landed — the only thing that identifies one.
     *
     * The prefix has been written by every publish since long before this module
     * existed, so it is also how the column gets backfilled (see lib/schema.php).
     */
    const AUTO_LABEL_PREFIX = 'Auto: ';

    /** How much of the content goes into a pooled row's label. */
    const LABEL_CHARS = 20;

    private $pdo;

    /** Cached per request: every method asks, and the answer cannot change. */
    private $hasMarker = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ---- Reads --------------------------------------------------------------

    /** The whole library, newest first: the Library page and the Builder's dropdown. */
    public function all()
    {
        try {
            return $this->pdo->query("SELECT * FROM assets ORDER BY id DESC")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /** One row, or null. */
    public function forId($assetId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM assets WHERE id = ? LIMIT 1");
            $stmt->execute([intval($assetId)]);
            $row = $stmt->fetch();
            return $row ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Which pooled rows are not in the given list of referenced ids.
     *
     * The caller supplies the referenced ids because they come from
     * `canvas_elements` — see the header. An empty list is a real answer, not a
     * missing argument: a database with no elements at all means every pooled row
     * is junk, and that is exactly the case after somebody clears a sign.
     */
    public function pooledNotIn(array $referencedIds)
    {
        $referenced = [];
        foreach ($referencedIds as $id) { $referenced[intval($id)] = true; }

        $out = [];
        foreach ($this->pooledIds() as $id) {
            if (!isset($referenced[$id])) { $out[] = $id; }
        }
        return $out;
    }

    // ---- Writes a person makes ---------------------------------------------

    /**
     * Save something an admin typed or uploaded. Never marked as pooled, so no
     * sweep can ever remove it — an image in the library that is not on a sign yet
     * is somebody's next job, not junk.
     *
     * Returns the new id, or 0 if the row could not be written.
     */
    public function create($type, $content, $label)
    {
        try {
            $this->insert((string)$type, (string)$content, (string)$label, false);
            return intval($this->pdo->lastInsertId());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Change a row's label and content.
     *
     * Editing is how a pooled row stops being pooled: somebody who renames
     * "Auto: Open 7 days" to "Opening hours" has adopted it, and a row a person
     * has named must survive every sweep. That is why the marker is cleared here
     * rather than left alone — leaving it would let the tidy button remove
     * something an admin had just finished naming.
     */
    public function update($assetId, $label, $content)
    {
        try {
            $sql = $this->markerExists()
                 ? "UPDATE assets SET label = ?, content = ?, auto_pooled = 0 WHERE id = ?"
                 : "UPDATE assets SET label = ?, content = ? WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([(string)$label, (string)$content, intval($assetId)]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Remove one row, whatever made it. The caller decides whether that is safe. */
    public function delete($assetId)
    {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM assets WHERE id = ?");
            $stmt->execute([intval($assetId)]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ---- The write a publish makes -----------------------------------------

    /**
     * Put a block's own content into the library and return the row's id.
     *
     * **Always inserts.** The row belongs to the element being published and to
     * nothing else — see the header for what sharing one cost. Marked as pooled,
     * which is the only thing that makes the sweeps safe.
     *
     * Returns 0 if the row could not be written; the caller keeps the content on
     * the element instead, which is the safe direction — a block with its own
     * text renders, a block pointing at a row that does not exist does not.
     */
    public function pool($type, $content)
    {
        try {
            $label = self::AUTO_LABEL_PREFIX
                   . self::firstCharacters(strip_tags((string)$content), self::LABEL_CHARS);
            $this->insert((string)$type, (string)$content, $label, true);
            return intval($this->pdo->lastInsertId());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Delete the pooled rows among these ids. Returns how many went.
     *
     * The caller has established that nothing references them — it owns the table
     * that would know. What this method will not do, whatever it is told, is
     * remove a row a person made: every delete carries the marker predicate, so a
     * caller that miscounts can only ever fail to tidy up, never blank a sign.
     *
     * One statement per id rather than an `IN (…)` list: the ids come from a
     * publish that is holding a transaction open, and a narrow delete takes
     * narrow locks. There are never many.
     */
    public function discardPooled(array $assetIds)
    {
        $gone = 0;
        foreach ($assetIds as $id) {
            $id = intval($id);
            if ($id <= 0) { continue; }
            try {
                if ($this->markerExists()) {
                    $stmt = $this->pdo->prepare("DELETE FROM assets WHERE id = ? AND auto_pooled = 1");
                    $stmt->execute([$id]);
                } else {
                    // No marker column: the label prefix is what a pooled row has
                    // always carried, and it is still a predicate the database
                    // applies rather than something this code decides.
                    $stmt = $this->pdo->prepare("DELETE FROM assets WHERE id = ? AND label LIKE ?");
                    $stmt->execute([$id, self::AUTO_LABEL_PREFIX . '%']);
                }
                $gone += $stmt->rowCount();
            } catch (Throwable $e) {
                // A row the database will not let go of (a reference this caller
                // did not know about) leaves the library untidy. That is the right
                // way for a sweep to fail.
            }
        }
        return $gone;
    }

    // ---- Internals ----------------------------------------------------------

    /** Every pooled row's id. */
    private function pooledIds()
    {
        try {
            if ($this->markerExists()) {
                $stmt = $this->pdo->query("SELECT id FROM assets WHERE auto_pooled = 1");
            } else {
                $stmt = $this->pdo->prepare("SELECT id FROM assets WHERE label LIKE ?");
                $stmt->execute([self::AUTO_LABEL_PREFIX . '%']);
            }
            $out = [];
            foreach ($stmt->fetchAll() as $row) { $out[] = intval($row['id']); }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function insert($type, $content, $label, $pooled)
    {
        if ($this->markerExists()) {
            $this->pdo->prepare(
                "INSERT INTO assets (type, content, label, auto_pooled) VALUES (?,?,?,?)"
            )->execute([$type, $content, $label, $pooled ? 1 : 0]);
            return;
        }
        $this->pdo->prepare("INSERT INTO assets (type, content, label) VALUES (?,?,?)")
                  ->execute([$type, $content, $label]);
    }

    /**
     * Is the marker column there?
     *
     * Asked the same way AccountStore asks about `closed_at`: a `SELECT … LIMIT 0`
     * rather than information_schema, so the answer is the same on the self-test's
     * SQLite fixture as on MySQL. **False is a workable answer, not a failure** —
     * the label prefix predates the column and still identifies a pooled row on a
     * database where the ALTER never landed. Settings → Database Structure says
     * whether it did.
     */
    private function markerExists()
    {
        if ($this->hasMarker !== null) { return $this->hasMarker; }
        try {
            $this->pdo->query("SELECT auto_pooled FROM assets LIMIT 0");
            $this->hasMarker = true;
        } catch (Throwable $e) {
            $this->hasMarker = false;
        }
        return $this->hasMarker;
    }

    /**
     * The first $max characters — never a fraction of one.
     *
     * `substr` counts bytes, so any multi-byte character straddling the cut was
     * halved, and the result is not valid UTF-8. Bound into a utf8mb4 column on a
     * MySQL in strict mode that is error 1366, which rolled the whole publish back
     * — permanently, because the label is rebuilt identically on every retry, and
     * with no message pointing at the text block responsible. An em dash or a
     * curly quote landing on byte 18 was enough; both are ordinary in signage.
     *
     * mbstring is not assumed present on the live host, hence the fallback: drop
     * trailing bytes until what remains is valid UTF-8.
     */
    public static function firstCharacters($text, $max)
    {
        if (function_exists('mb_substr')) {
            return mb_substr((string)$text, 0, $max, 'UTF-8');
        }
        $out = substr((string)$text, 0, $max);
        while ($out !== '' && !preg_match('//u', $out)) {
            $out = substr($out, 0, -1);
        }
        return $out;
    }
}
