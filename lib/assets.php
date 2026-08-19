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
//
// ------------------------------------------------------------
// **A row's type is read from the row, never from whoever is asking.**
//
// The Library's edit form used to post the type back in a hidden field, and
// `crud.php` decided from that field alone what the new content was allowed to
// be: plain-texted for `text`, checked against the image allow-list for `image`.
// A hidden field is a request parameter like any other, so both rules were
// optional. Sending `edit_type=image` while editing a text entry skipped
// `toPlainText()` and wrote markup into a value ADR-0002 says is plain text;
// sending `edit_type=text` while editing an image entry skipped the allow-list
// and pointed every sign reading that entry at an `.svg` on any host.
//
// The type is not editable, it is not derivable from the new content, and the
// database already knows it — so `update()` reads the row and decides from that.
// The caller supplies a label and a content candidate, and cannot opt out of
// either rule by leaving something out or by sending something extra.
// ------------------------------------------------------------

require_once __DIR__ . '/plain_text.php';

/**
 * What happened to an edit, as a value rather than a bare `false`.
 *
 * `update()` had returned true for a write that matched no row at all, so the
 * page said "Asset updated successfully" for an id that no longer existed. Four
 * outcomes now, because the page has to say four different things and must not
 * work out which by reading a message string (BUILD-REFERENCE §1).
 */
class AssetEdit
{
    const OK      = 'ok';        // stored
    const MISSING = 'missing';   // no such row; nothing was changed
    const REFUSED = 'refused';   // the row will not hold that content
    const FAILED  = 'failed';    // the database refused; nothing was changed

    private $kind;
    private $message;

    private function __construct($kind, $message)
    {
        $this->kind    = $kind;
        $this->message = $message;
    }

    public static function ok()               { return new self(self::OK, 'Asset updated successfully.'); }
    public static function missing($message)  { return new self(self::MISSING, $message); }
    public static function refused($message)  { return new self(self::REFUSED, $message); }
    public static function failed($message)   { return new self(self::FAILED, $message); }

    public function isOk()    { return $this->kind === self::OK; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }
}

class AssetLibrary
{
    /**
     * The two types the Library page can create.
     *
     * The table is *written* with more than these: publishing pools a block's own
     * content under the *element's* type, so pool() is called with `carousel`,
     * `table` and `marquee` (see LayoutStore::publish). Those carry JSON or a media
     * path and are stored exactly as they arrive — stripping one would destroy it.
     *
     * Whether such a row lands is the column's answer, not this class's, and on the
     * engine the shop runs it does not: `assets.type` is `ENUM('text','image','video')`
     * in schema.sql, so MySQL refuses the other three outright. pool() catches that and
     * answers 0, publish() leaves the content on the element, and the sign is right
     * either way — but this comment claimed the row was ordinary, and it was only ever
     * ordinary on the SQLite fixture, which is where the check asserting it ran (§4ba).
     */
    const TYPE_TEXT  = 'text';
    const TYPE_IMAGE = 'image';

    /**
     * What an image entry may point at, wherever the reference came from — an
     * upload's extension, a path typed into the add form, or a path typed into
     * the edit form. One list, so the three cannot drift apart; SVG is absent on
     * purpose, since it is markup a browser executes.
     */
    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

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
     * What an admin should know about this row's stored content, in a few words —
     * or null when there is nothing to say.
     *
     * The rules above apply to every *write*. They say nothing about the rows that
     * were already here: a text entry holding markup from before ADR-0002, an image
     * entry pointing at an `.svg` from before the add form checked. Nothing rewrites
     * those. Rewriting stored content on read is a write nobody asked for, on a
     * table with no undo, and it would change what a sign says without anybody
     * pressing anything.
     *
     * What was wrong was that they were *invisible*: nothing showed the state, and
     * the first anybody learned of it was a refusal when they happened to edit one.
     * So the Library page asks this per row and marks the ones worth a look, and the
     * decision to change anything stays a person's.
     *
     * The text case is deliberately the exact predicate "saving this would change
     * it" rather than a guess at whether the markup is intentional — that way the
     * warning is never wrong about the thing it warns about.
     */
    public static function contentIssue(array $row)
    {
        $type    = isset($row['type'])    ? (string)$row['type']    : '';
        $content = isset($row['content']) ? (string)$row['content'] : '';

        if ($content === '') {
            return 'it is empty, so every block reading it shows nothing';
        }
        if ($type === self::TYPE_IMAGE && !self::isAllowedImageRef($content)) {
            return 'it points at a file type this app no longer allows, so the picture may not load';
        }
        if ($type === self::TYPE_TEXT && toPlainText($content) !== $content) {
            return 'it holds formatting that saving it here would remove';
        }
        return null;
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
     * The type is chosen here and never again: nothing changes a row's type, so
     * this is the one moment a caller's word for it is taken, and it is taken only
     * for the two the add form offers. A row of some third type created here could
     * be edited afterwards only by guessing what it was.
     *
     * Returns the new id, or 0 if the row could not be written.
     */
    public function create($type, $content, $label)
    {
        $type = (string)$type;
        if ($type !== self::TYPE_TEXT && $type !== self::TYPE_IMAGE) { return 0; }
        try {
            $this->insert($type, (string)$content, (string)$label, false);
            return intval($this->pdo->lastInsertId());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Change a row's label and content. Returns an AssetEdit.
     *
     * **The stored row decides what its content may be** — see the header. The
     * caller passes a candidate; what is written is that candidate put through the
     * rule for the type the database holds, and a candidate that breaks the rule is
     * refused rather than adjusted. The type itself is never written, so an entry
     * cannot change kind under an admin who is editing it.
     *
     * Three refusals, all of them things the page used to decide or to miss:
     *
     *   · **No such row.** The bare `UPDATE` this replaced matched nothing and
     *     reported success, so deleting an entry in one tab and saving it in
     *     another printed "Asset updated successfully" over nothing at all.
     *   · **Emptied.** Blanking the content blanks the line on every sign reading
     *     from this entry, within thirty seconds and with no undo. The page's own
     *     guard for this was `!empty($content)`, which also refused a price block
     *     reading exactly `0` — the emptiness test is `=== ''`.
     *   · **Not an image.** An image entry may only point at something in
     *     IMAGE_EXTENSIONS. It is the same check the add form makes, in the place
     *     that knows the row is an image entry rather than the place that was told.
     *
     * Editing is also how a pooled row stops being pooled: somebody who renames
     * "Auto: Open 7 days" to "Opening hours" has adopted it, and a row a person
     * has named must survive every sweep. That is why the marker is cleared here
     * rather than left alone — leaving it would let the tidy button remove
     * something an admin had just finished naming.
     */
    public function update($assetId, $label, $content)
    {
        $assetId = intval($assetId);
        $row     = $this->forId($assetId);
        if ($row === null) {
            return AssetEdit::missing('That library entry no longer exists, so there was'
                                    . ' nothing to save. Nothing was changed.');
        }

        $type    = isset($row['type']) ? (string)$row['type'] : '';
        $content = self::contentFor($type, $content);

        if ($content === '') {
            return AssetEdit::refused('A library entry cannot be left empty — every block'
                                    . ' reading it would go blank. Nothing was changed.');
        }
        if ($type === self::TYPE_IMAGE && !self::isAllowedImageRef($content)) {
            return AssetEdit::refused('Only JPG, PNG, GIF and WEBP images are allowed —'
                                    . ' SVG and other types are blocked. Nothing was changed.');
        }

        try {
            $sql = $this->markerExists()
                 ? "UPDATE assets SET label = ?, content = ?, auto_pooled = 0 WHERE id = ?"
                 : "UPDATE assets SET label = ?, content = ? WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([(string)$label, $content, $assetId]);
            return AssetEdit::ok();
        } catch (Throwable $e) {
            return AssetEdit::failed('That asset could not be updated. Nothing was changed.');
        }
    }

    /**
     * The content a row of this type may hold, prepared for storage.
     *
     * Text is plain text (ADR-0002) wherever it entered the app, which is the half
     * of this rule a hidden form field used to be able to switch off. Everything
     * else — an image path, and the JSON a pooled carousel, table or marquee row
     * carries — is stored as it arrived, because stripping markup out of JSON
     * leaves neither markup nor JSON.
     */
    private static function contentFor($type, $content)
    {
        $content = (string)$content;
        return ($type === self::TYPE_TEXT) ? toPlainText($content) : trim($content);
    }

    /**
     * May an image entry point at this?
     *
     * The reference is a stored path or URL and may carry the Builder's `|fit`
     * suffix and a query string, neither of which is part of the filename. What is
     * left has to end in one of IMAGE_EXTENSIONS — an allow-list, so a type nobody
     * thought about is refused rather than permitted.
     */
    public static function isAllowedImageRef($ref)
    {
        $path = explode('|', (string)$ref)[0];   // drop any |fit suffix (e.g. |contain)
        $path = strtok($path, '?#');             // drop query string / fragment
        $ext  = strtolower(pathinfo((string)$path, PATHINFO_EXTENSION));
        return in_array($ext, self::IMAGE_EXTENSIONS, true);
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
            // toPlainText() rather than strip_tags(), which is not a parser: it
            // deletes from a "<" to the end of the value, so a block reading
            // "Kids <12 eat free" was labelled "Auto: Kids". Same funnel as the
            // content itself, so the label cannot disagree with what the sign says.
            $label = self::AUTO_LABEL_PREFIX
                   . self::firstCharacters(toPlainText((string)$content), self::LABEL_CHARS);
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
