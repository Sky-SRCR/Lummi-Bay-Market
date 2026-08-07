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
// LayoutStore::elementRows(), which resolves each element's content in the same
// query that fetches the element. It stays there deliberately: it is read-only,
// it is on the path a Screen polls every thirty seconds, and splitting it would
// mean one extra query per block on every sign in the building.
//
// **A row's type is a fact about the row, never something a form states.**
//
// `content` means completely different things depending on `type`: words a sign
// prints, or a path a sign fetches. Everything that decides what may be written —
// whether to strip markup, whether an extension is allowed, whether a file may be
// uploaded into it at all — hangs off that one column. The Library's edit form used
// to carry the type back in a hidden field and the page believed it, which meant a
// POST could pick which rules applied to a row it was not describing: markup into a
// text row, an `.svg` into an image row, an uploaded image path into a text row that
// a sign would then print as words. None of those need a hostile author; the first
// one is what a browser extension replaying a form does.
//
// So the type is read here, from the row, and `saveEdit()` is the only way to change
// one. There is no argument to it that names a type.

require_once __DIR__ . '/plain_text.php';

/**
 * What happened to an edit made in the Library, as a value.
 *
 * The page branches on isOk() and prints message(); it never works out what
 * happened by reading the sentence. Same shape as DisplayResult — see
 * lib/display_admin.php — because a page that composes its own explanation from
 * which line failed ends up describing something other than what is now true.
 */
class AssetEdit
{
    const OK      = 'ok';
    const MISSING = 'missing';   // no such row, or it could not be read
    const REFUSED = 'refused';   // the content does not belong in a row of this type
    const FAILED  = 'failed';    // the database refused; nothing was changed

    private $kind;
    private $type;
    private $message;

    private function __construct($kind, $type, $message)
    {
        $this->kind    = $kind;
        $this->type    = $type;
        $this->message = $message;
    }

    public static function saved($type)            { return new self(self::OK, $type, ''); }
    public static function missing($message)       { return new self(self::MISSING, '', $message); }
    public static function refused($type, $msg)    { return new self(self::REFUSED, $type, $msg); }
    public static function failed($type, $message) { return new self(self::FAILED, $type, $message); }

    public function isOk()    { return $this->kind === self::OK; }
    public function kind()    { return $this->kind; }
    public function message() { return $this->message; }

    /** The row's stored type, which is what decided the outcome. Empty if it had none. */
    public function type()    { return $this->type; }
}

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

    /**
     * The two types a person may create here, and what a stored reference of each
     * kind may end in.
     *
     * `assets.type` is `ENUM('text','image','video')`, so a `video` row can exist
     * on a database that has one even though nothing in the Library makes one —
     * which is why editing knows the extensions for it. The list is the same one
     * the upload check uses; keeping a second copy in the page is how the create
     * path and the edit path came to disagree about `.svg` in the first place.
     */
    const CREATABLE_TYPES  = ['text', 'image'];
    const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogv', 'ogg'];

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
     * The type is the one argument here a caller legitimately supplies: the row
     * does not exist yet, so there is nothing else to ask. It is checked against
     * the two the Library offers, because a type nothing can edit is a row nobody
     * can ever correct — and on MySQL an ENUM outside the three is a strict-mode
     * error that would surface as "could not be saved" with no reason given.
     *
     * Returns the new id, or 0 if the row could not be written.
     */
    public function create($type, $content, $label)
    {
        if (!in_array((string)$type, self::CREATABLE_TYPES, true)) { return 0; }
        try {
            $this->insert((string)$type, (string)$content, (string)$label, false);
            return intval($this->pdo->lastInsertId());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Apply an edit to a row, with the row's own type deciding what the content is.
     *
     * The only way to change a library entry. There is deliberately no argument
     * naming a type: the caller has a form, and a form can say anything — see the
     * header for what a page that believed one could be made to write.
     *
     * `$uploadedPath` is the file the page has already accepted and moved, or null
     * for "no file was sent". It is only meaningful on an image row; anywhere else
     * it is a POST the Library never drew, and the answer is no rather than a path
     * written into a row a sign renders as words.
     *
     * Editing is also how a pooled row stops being pooled: somebody who renames
     * "Auto: Open 7 days" to "Opening hours" has adopted it, and a row a person has
     * named must survive every sweep. The marker is cleared here rather than left
     * alone, or the tidy button could remove something an admin just finished naming.
     */
    public function saveEdit($assetId, $label, $postedContent, $uploadedPath = null)
    {
        $row = $this->forId($assetId);
        if (!$row) {
            return AssetEdit::missing(
                'That library entry could not be loaded, so nothing was changed.'
                . ' It may have been deleted while this page was open.');
        }

        $type = (string)$row['type'];

        if ($uploadedPath !== null && $type !== 'image') {
            return AssetEdit::refused($type,
                'That entry does not hold an image, so a file cannot be uploaded into it.'
                . ' Nothing was changed.');
        }

        if ($type === 'text') {
            // Invariant 6: a text block's content is plain text, decided by the row
            // rather than by which branch the page happened to take.
            $content = toPlainText((string)$postedContent);
        } elseif ($uploadedPath !== null) {
            $content = (string)$uploadedPath;
        } else {
            $content = trim((string)$postedContent);
        }

        // `=== ''` rather than empty(): a price block reading exactly "0" is falsy in
        // PHP, and the old guard refused to save one while saying nothing at all.
        if ($content === '') {
            return AssetEdit::refused($type,
                'Nothing was saved: an entry with no content blanks that line on every'
                . ' block reading from it, and there is no undo.');
        }

        if ($type === 'image' && !self::isUsableRef($content, self::IMAGE_EXTENSIONS)) {
            return AssetEdit::refused($type,
                'Only JPG, PNG, GIF and WEBP images are allowed — SVG and other types are'
                . ' blocked. Nothing was changed.');
        }
        if ($type === 'video' && !self::isUsableRef($content, self::VIDEO_EXTENSIONS)) {
            return AssetEdit::refused($type,
                'Only MP4, WEBM and OGG video files are allowed. Nothing was changed.');
        }

        if (!$this->writeEdit($assetId, $label, $content)) {
            return AssetEdit::failed($type,
                'That entry could not be updated. Nothing was changed.');
        }
        return AssetEdit::saved($type);
    }

    /** The write itself, once saveEdit() has established what may be written. */
    private function writeEdit($assetId, $label, $content)
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

    // ---- What a stored reference may be -------------------------------------

    /**
     * Does this path or URL end in one of the given extensions?
     *
     * The check the file-upload path already does by MIME, applied to the other way
     * a file gets into the library: somebody typing a path. Without it an `.svg`
     * goes in by reference and reaches a sign as `<img src>`, which is a script the
     * TV runs — and the upload path blocks exactly that, so the two ways in
     * disagreed.
     *
     * Two things get stripped before the extension is read, because both are
     * ordinary here and neither is part of the filename: the `|fit` suffix a
     * background carries (`uploads/deli.jpg|contain`), and a query string or
     * fragment on a URL.
     */
    public static function isUsableRef($ref, array $extensions)
    {
        return in_array(self::refExtension($ref), $extensions, true);
    }

    /** The lower-cased extension of a stored reference, or '' if it has none. */
    public static function refExtension($ref)
    {
        $path = explode('|', (string)$ref)[0];   // drop any |fit suffix (e.g. |contain)
        $path = strtok($path, '?#');             // drop query string / fragment
        if ($path === false || $path === '') { return ''; }
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
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
