<?php
// ============================================================
// SCHEMA CONVERGENCE
// ============================================================
// One entry point — ensureSignageSchema($pdo) — brings whatever structure the
// live database currently has up to what this code expects. There is no
// migration tool and no version table: the live database is edited in place and
// is known to lag the repo (see docs/BUILD-REFERENCE.md §2.10), so every
// statement here must be safe to run against a database that has already had it
// applied, and safe to run in any order relative to the others.
//
// Callers know one fact: call this on an authenticated request before touching
// signage tables. Everything else — which columns are new, which statement is
// skipped because the catalogue says it already landed, how the drive-thru
// Display is created out of the single-row canvas_settings it replaces — is in
// here.
//
// The public get_layout poll must NOT run this: every Screen hits it every 30
// seconds forever. See DisplayStore, which converges once on a genuinely absent
// schema and then never again.
//
// ---- Why it asks before it alters -------------------------------------------
// This used to run all twenty statements on every authenticated request. Twelve of
// them failed by design, which is cheap — and cheap is not the same as free:
//
//   * Eight of them *succeeded*, every request, forever. Three were
//     `ALTER TABLE canvas_elements MODIFY COLUMN` — the two ENUM widenings and the
//     `display_id NOT NULL` tighten. `ALTER TABLE canvas_elements MODIFY
//     COLUMN type ENUM(…)` is not a no-op just because the definition is already
//     what it asks for — MySQL performs the ALTER, and an ALTER takes an
//     exclusive metadata lock on the table that holds every sign's layout. A
//     publish transaction holding that table makes the ALTER wait, and every
//     query that arrives behind a waiting ALTER waits too, including the Screens'
//     30-second polls. One person opening the Builder could stall the signs.
//   * DDL commits the surrounding transaction in MySQL, silently. Nothing here
//     is called inside one today, and now there is nothing to commit either.
//   * One statement was an outright bug. The `assets.auto_pooled` backfill reads
//     the `Auto: ` label prefix, and it ran on every request — so a pooled row an
//     admin adopted by saving it (which clears the marker) but left named
//     "Auto: …" was re-marked within one page load, and the Library's Tidy up
//     button could then delete what somebody had claimed. Gating the backfill to
//     the one request that adds the column is what fixes it.
//
// So: read `information_schema` once, build the list of statements the catalogue
// proves are missing, and run only those. A converged database gets three
// catalogue reads, two small COUNTs, and no DDL at all — and catalogue reads take
// no lock on any table the app uses.
//
// The decision is separated from the doing on purpose. `signageSchemaPlan()` is
// pure: facts in, an ordered list of work out. That is the whole of the gating
// logic, and it is the only part of this file the self-test can execute, because
// the statements themselves are MySQL-only and the fixture is SQLite.
//
// When the catalogue cannot be read at all — not MySQL, or a host that hides it —
// `SchemaFacts::unknown()` says so and every statement goes into the plan. That is
// exactly the old behaviour, which is the right thing to fall back to.

// Nothing is required here on purpose: this file must be includable without
// starting a session or loading page-level helpers (see BUILD-REFERENCE.md §1).
// The login-lockout columns stay where ADR-0001 put them — added by the pre-auth
// login/reset pages, never from a data-access path.

// The name tag of the Display that the pre-multi-display layout becomes.
// Referenced by the Phase 2 cutover ("…/viewer.php?display=drive-thru").
if (!defined('LEGACY_DISPLAY_TAG')) {
    define('LEGACY_DISPLAY_TAG', 'drive-thru');
}

// The tables convergence has an opinion about. The catalogue read is filtered to
// these rather than asking for the whole database, because on shared hosting one
// MySQL database can hold several applications and `information_schema` would
// then return thousands of rows to answer a question about eight tables.
if (!defined('SCHEMA_TABLES')) {
    define('SCHEMA_TABLES', [
        'users', 'password_resets', 'assets', 'block_styles',
        'canvas_elements', 'canvas_settings', 'displays', 'display_permissions',
    ]);
}

// The two ENUM definitions, written once so the statement that sets them and the
// comparison that decides whether to run it cannot drift apart. Lower case and
// no spaces, which is the form `information_schema.COLUMNS.COLUMN_TYPE` reports;
// MySQL accepts it verbatim in an ALTER.
if (!defined('SCHEMA_ELEMENT_TYPE_ENUM')) {
    define('SCHEMA_ELEMENT_TYPE_ENUM',
        "enum('section','text','image','video','carousel','marquee','table')");
}
if (!defined('SCHEMA_BLOCK_SUBTYPE_ENUM')) {
    define('SCHEMA_BLOCK_SUBTYPE_ENUM',
        "enum('free','section_header','item_title','item_title_2','price','price_2','description')");
}

// The six branded block types, seeded as one statement. One row per type must
// exist for Brand Standards to be editable at all: that form saves with
// UPDATE … WHERE block_type = ?, so a missing row makes the save a silent no-op —
// the field reverts on reload and nothing says why. INSERT IGNORE, so the store's
// own numbers are never touched; this only fills gaps. All six are listed rather
// than just the two a later build added, because the four originals are missing on
// a database that predates them (schema.sql seeds the same set).
if (!defined('SCHEMA_BLOCK_STYLE_SEED')) {
    define('SCHEMA_BLOCK_STYLE_SEED', "INSERT IGNORE INTO block_styles
        (block_type,font_family,font_size,font_color,font_weight,font_style,line_height) VALUES
        ('section_header','Arial',36,'#ffffff','bold','normal',1.30),
        ('item_title','Arial',24,'#ffffff','bold','normal',1.30),
        ('item_title_2','Arial',24,'#27ae60','bold','normal',1.30),
        ('price','Arial',30,'#e74c3c','bold','normal',1.20),
        ('price_2','Arial',30,'#e74c3c','bold','normal',1.20),
        ('description','Arial',16,'#bdc3c7','normal','normal',1.40)");
}
if (!defined('SCHEMA_BLOCK_STYLE_COUNT')) {
    define('SCHEMA_BLOCK_STYLE_COUNT', 6);
}

/**
 * What the database catalogue says is already there.
 *
 * A pure value object: three maps in, questions answered, no PDO and no queries.
 * Every answer is three-valued — true, false, or **null meaning "cannot tell"** —
 * because "I did not manage to look" must never be read as "it is not there", and
 * must never be read as "it is" either. The plan turns null into "run the
 * statement and let it fail harmlessly", which is what this file did for years.
 */
class SchemaFacts
{
    private $columns;       // table => column => ['type' => string, 'nullable' => bool]
    private $indexes;       // table => index name => true
    private $constraints;   // table => constraint name => true
    private $known;

    private function __construct(array $columns, array $indexes, array $constraints, $known)
    {
        $this->columns     = $columns;
        $this->indexes     = $indexes;
        $this->constraints = $constraints;
        $this->known       = (bool)$known;
    }

    /**
     * Facts read from a catalogue. Also the constructor the self-test uses, which
     * is the only way to state "a fully converged live database" on a machine
     * that has no MySQL.
     */
    public static function of(array $columns, array $indexes = [], array $constraints = [])
    {
        return new self(
            self::lowerKeys($columns),
            self::lowerKeys($indexes),
            self::lowerKeys($constraints),
            true
        );
    }

    /** No catalogue could be read. Every question answers null. */
    public static function unknown()
    {
        return new self([], [], [], false);
    }

    /** False when nothing could be read, in which case every answer is null. */
    public function known()
    {
        return $this->known;
    }

    /** True / false / null. */
    public function hasTable($table)
    {
        if (!$this->known) { return null; }
        return isset($this->columns[strtolower($table)]);
    }

    /** True only when the catalogue was read and the table is definitely absent. */
    public function tableMissing($table)
    {
        return $this->hasTable($table) === false;
    }

    /** True / false / null. */
    public function hasColumn($table, $column)
    {
        if (!$this->known) { return null; }
        return isset($this->columns[strtolower($table)][strtolower($column)]);
    }

    /** The catalogue's own type string, or null when unknown or absent. */
    public function columnType($table, $column)
    {
        if ($this->hasColumn($table, $column) !== true) { return null; }
        return $this->columns[strtolower($table)][strtolower($column)]['type'];
    }

    /** True / false / null — whether the column currently accepts NULL. */
    public function columnAllowsNull($table, $column)
    {
        if ($this->hasColumn($table, $column) !== true) { return null; }
        return !empty($this->columns[strtolower($table)][strtolower($column)]['nullable']);
    }

    /** True / false / null. */
    public function hasIndex($table, $index)
    {
        if (!$this->known) { return null; }
        return isset($this->indexes[strtolower($table)][strtolower($index)]);
    }

    /** True / false / null. */
    public function hasConstraint($table, $name)
    {
        if (!$this->known) { return null; }
        return isset($this->constraints[strtolower($table)][strtolower($name)]);
    }

    // ---- The needs. Each returns true (do it) / false (skip) / null (cannot tell)

    /**
     * Does this table have to be created?
     *
     * The statements are `CREATE TABLE IF NOT EXISTS`, so running one against an
     * existing table was already harmless — but it is still a round trip and a
     * MySQL warning, and skipping it is what makes a converged plan genuinely
     * empty rather than nearly empty.
     */
    public function needsTableCreate($table)
    {
        $has = $this->hasTable($table);
        return ($has === null) ? null : !$has;
    }

    /**
     * Does this column have to be added?
     *
     * No when it is there, and no when the *table* is absent — because the only
     * tables this file creates declare their columns in the CREATE TABLE, so an
     * ALTER after it would be redundant, and a table nothing creates cannot be
     * altered at all.
     */
    public function needsColumn($table, $column)
    {
        if ($this->tableMissing($table)) { return false; }
        $has = $this->hasColumn($table, $column);
        return ($has === null) ? null : !$has;
    }

    /**
     * Does this column's type have to be set?
     *
     * Compared with whitespace stripped and lower-cased, which makes it exact
     * against what MySQL reports. Anything that does not match — including a
     * future MySQL that words it differently — is treated as a difference, so the
     * ALTER runs. That is the safe direction: a needless ALTER is what this build
     * is removing, but a skipped one would be a missing column definition.
     */
    public function needsColumnType($table, $column, $wanted)
    {
        $has = $this->hasColumn($table, $column);
        if ($has === null)  { return null; }
        if ($has === false) { return false; }   // no column, nothing to modify
        return self::normalise($this->columnType($table, $column)) !== self::normalise($wanted);
    }

    /**
     * Does this column have to be tightened to NOT NULL?
     *
     * Yes when it is nullable, and yes when it is absent but the table is there —
     * because the plan adds it nullable first and then tightens it, in that order,
     * which is the only order that works on a table that already holds rows.
     */
    public function needsNotNull($table, $column)
    {
        if ($this->tableMissing($table)) { return false; }
        $has = $this->hasColumn($table, $column);
        if ($has === null)  { return null; }
        if ($has === false) { return true; }
        return $this->columnAllowsNull($table, $column);
    }

    /**
     * Skip only when the index is definitely there, *by that name*.
     *
     * MySQL names an index it creates for a foreign key after the constraint, so a
     * database that got the FK before the explicit key has the column indexed under
     * a name this does not recognise, and one redundant index gets added. The plan
     * orders the key before the constraint so that does not happen here; a
     * hand-migrated database could still land in it, and one spare index on a table
     * of a few hundred rows is the whole cost.
     */
    public function needsIndex($table, $index)
    {
        if ($this->tableMissing($table)) { return false; }
        $has = $this->hasIndex($table, $index);
        return ($has === null) ? null : !$has;
    }

    /**
     * Skip only when the constraint is definitely there.
     *
     * Unlike a column, an absent table does *not* mean skip: the two tables this
     * file creates get their foreign keys from their own ALTER statements, never
     * from the CREATE TABLE, so a table created a moment ago still needs them.
     */
    public function needsConstraint($table, $name)
    {
        $has = $this->hasConstraint($table, $name);
        return ($has === null) ? null : !$has;
    }

    // ---- Internals ----------------------------------------------------------

    private static function normalise($type)
    {
        return strtolower(preg_replace('/\s+/', '', (string)$type));
    }

    /** Case-fold one or two levels of keys, so a caller need not. */
    private static function lowerKeys(array $map)
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (is_array($value)) {
                $inner = [];
                foreach ($value as $k => $v) { $inner[strtolower($k)] = $v; }
                $value = $inner;
            }
            $out[strtolower($key)] = $value;
        }
        return $out;
    }
}

/**
 * Ask the catalogue what is there. Three reads, filtered to SCHEMA_TABLES.
 *
 * The one place in the repo that reads `information_schema` — ServerReport asks
 * this rather than writing its own query, so there is a single answer to "how do
 * we find out what columns exist". Any failure at all returns
 * SchemaFacts::unknown(), which is not a degraded mode so much as the mode this
 * file ran in before it started asking.
 */
function readSchemaFacts(PDO $pdo)
{
    $tables = SCHEMA_TABLES;
    $marks  = implode(',', array_fill(0, count($tables), '?'));

    try {
        $columns = [];
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ty, IS_NULLABLE AS nul
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)"
        );
        $stmt->execute($tables);
        foreach ($stmt->fetchAll() as $row) {
            $columns[$row['t']][$row['c']] = [
                'type'     => (string)$row['ty'],
                'nullable' => strtoupper((string)$row['nul']) === 'YES',
            ];
        }

        // A table with no columns is not a table. If the columns read came back
        // empty the catalogue is readable but tells us nothing useful — treat that
        // as unknown rather than as "the whole database is missing", which would
        // put every CREATE TABLE in the plan against a database that has them.
        if (!$columns) { return SchemaFacts::unknown(); }

        $indexes = [];
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME AS t, INDEX_NAME AS n
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)"
        );
        $stmt->execute($tables);
        foreach ($stmt->fetchAll() as $row) { $indexes[$row['t']][$row['n']] = true; }

        $constraints = [];
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME AS t, CONSTRAINT_NAME AS n
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)"
        );
        $stmt->execute($tables);
        foreach ($stmt->fetchAll() as $row) { $constraints[$row['t']][$row['n']] = true; }

        return SchemaFacts::of($columns, $indexes, $constraints);
    } catch (Throwable $e) {
        return SchemaFacts::unknown();
    }
}

/**
 * The whole of convergence as an ordered list of work, decided from facts alone.
 *
 * Each entry is one of:
 *   ['why' => …, 'sql'  => …]   a statement to run
 *   ['why' => …, 'step' => …]   a named step that has to read rows to decide
 *
 * The steps are here rather than after the statements because the order matters:
 * the drive-thru Display has to exist before elements can be backfilled to it,
 * and the backfill has to finish before `display_id` can be made NOT NULL. Two
 * steps are always in the plan — no catalogue can answer "are there any rows" —
 * but both are a small COUNT that usually does nothing.
 *
 * Pure. Nothing here touches a database, which is what makes it testable.
 */
function signageSchemaPlan(SchemaFacts $facts)
{
    $plan = [];

    // A need of false skips; true and null both run. Null is "the catalogue could
    // not tell us", and the answer to that is the behaviour this file always had:
    // try it, and let schemaTry() swallow the failure that means "already done".
    $sql = function ($need, $why, $statement) use (&$plan) {
        if ($need === false) { return; }
        $plan[] = ['why' => $why, 'sql' => $statement];
    };
    $step = function ($need, $name) use (&$plan) {
        if ($need === false) { return; }
        $plan[] = ['why' => $name, 'step' => $name];
    };

    // ---- canvas_elements: columns added since the original install ----------
    // Carried over verbatim from the inline ALTERs that used to sit at the top of
    // api.php, so behaviour on an out-of-date database is unchanged.
    $sql($facts->needsColumn('canvas_elements', 'text_align'), 'canvas_elements.text_align',
         "ALTER TABLE canvas_elements ADD COLUMN text_align VARCHAR(16) NOT NULL DEFAULT ''");
    $sql($facts->needsColumn('canvas_elements', 'z_index'), 'canvas_elements.z_index',
         "ALTER TABLE canvas_elements ADD COLUMN z_index INT NOT NULL DEFAULT 1");
    $sql($facts->needsColumn('canvas_elements', 'hidden'), 'canvas_elements.hidden',
         "ALTER TABLE canvas_elements ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0");

    // Two of the three ALTERs that used to run on every single request, whatever
    // the column already said. See the header.
    $sql($facts->needsColumnType('canvas_elements', 'type', SCHEMA_ELEMENT_TYPE_ENUM),
         'canvas_elements.type widened',
         "ALTER TABLE canvas_elements MODIFY COLUMN type " . SCHEMA_ELEMENT_TYPE_ENUM . " NOT NULL");

    // The Builder offers Title 2 and Price 2 as block subtypes and block_styles
    // seeds both, but the ENUM never listed them — so publishing a layout that
    // uses one either fails the whole transaction (strict mode) or silently blanks
    // the subtype. Widening an ENUM changes no stored data.
    $sql($facts->needsColumnType('canvas_elements', 'block_subtype', SCHEMA_BLOCK_SUBTYPE_ENUM),
         'canvas_elements.block_subtype widened',
         "ALTER TABLE canvas_elements MODIFY COLUMN block_subtype "
         . SCHEMA_BLOCK_SUBTYPE_ENUM . " DEFAULT 'free'");

    // ---- block_styles: one row per branded block type -----------------------
    // A row count, not a catalogue fact, so it is a step. Skipped outright when
    // there is no table to seed into.
    $step(!$facts->tableMissing('block_styles'), 'seed_block_styles');

    // ---- assets: which rows a publish made, rather than a person ------------
    // Publishing copies a text block's content into the library and points the
    // block at the copy, so ordinary editing leaves rows behind that nothing uses.
    // Those are safe to sweep; a row an admin typed or uploaded is not, even when
    // no sign uses it yet. This column is the difference, and without it the sweep
    // falls back to the `Auto: ` label prefix every pooled row has always carried.
    $needsMarker = $facts->needsColumn('assets', 'auto_pooled');
    $sql($needsMarker, 'assets.auto_pooled',
         "ALTER TABLE assets ADD COLUMN auto_pooled TINYINT(1) NOT NULL DEFAULT 0");

    // Backfill from that prefix, and **only on the request that adds the column**.
    // The column arrives as 0 for every existing row, which would exempt the whole
    // accumulated pool from tidying, so it has to happen once. It must not happen
    // twice: saving a pooled row adopts it by clearing the marker
    // (AssetLibrary::update) while leaving the label alone, so a statement that
    // re-reads the prefix would un-adopt it on the next page load and let Tidy up
    // delete what somebody had claimed. `$needsMarker` is exactly "the column is
    // not there yet", which is the one request where no row can have been adopted.
    $step($needsMarker, 'backfill_auto_pooled');

    // ---- displays -----------------------------------------------------------
    // One row per configured sign. Absorbs canvas_settings (background) and
    // carries the canvas dimensions that were hardcoded as 1920×1080, the publish
    // stamp (ADR-0006) and the edit-lock columns (ADR-0007).
    $sql($facts->needsTableCreate('displays'), 'displays table', "CREATE TABLE IF NOT EXISTS displays (
        id                INT(11)      NOT NULL AUTO_INCREMENT,
        tag               VARCHAR(32)  NOT NULL COMMENT 'screen name tag — the Viewer URL contract',
        title             VARCHAR(120) NOT NULL,
        location          VARCHAR(160) DEFAULT NULL,
        canvas_width      INT(11)      NOT NULL,
        canvas_height     INT(11)      NOT NULL,
        bg_type           ENUM('color','image') NOT NULL DEFAULT 'color',
        bg_val            VARCHAR(255) NOT NULL DEFAULT '#1a1a2e',
        is_active         TINYINT(1)   NOT NULL DEFAULT 1,
        layout_revision   INT(11)      NOT NULL DEFAULT 0 COMMENT 'publish stamp; increments on every publish',
        last_published_at DATETIME     DEFAULT NULL,
        last_published_by INT(11)      DEFAULT NULL,
        lock_holder_id    INT(11)      DEFAULT NULL COMMENT 'edit lock holder',
        lock_taken_at     DATETIME     DEFAULT NULL COMMENT 'when the holder started editing',
        lock_activity_at  DATETIME     DEFAULT NULL COMMENT 'last real interaction by the holder',
        created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY tag (tag),
        KEY last_published_by (last_published_by),
        KEY lock_holder_id (lock_holder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // CREATE TABLE above is a no-op on a database that already has `displays`, so
    // a column added to it after that database converged needs its own statement.
    // lock_taken_at is what lets a read-only Builder say *since when* somebody has
    // been editing, rather than only that they are.
    $sql($facts->needsColumn('displays', 'lock_taken_at'), 'displays.lock_taken_at',
         "ALTER TABLE displays ADD COLUMN lock_taken_at DATETIME DEFAULT NULL");

    // Both point at accounts and must survive an account being deleted, so the
    // Display is never taken with it. Added separately for the same reason.
    $sql($facts->needsConstraint('displays', 'displays_ibfk_1'), 'displays → users (published by)',
         "ALTER TABLE displays ADD CONSTRAINT displays_ibfk_1
          FOREIGN KEY (last_published_by) REFERENCES users (id) ON DELETE SET NULL");
    $sql($facts->needsConstraint('displays', 'displays_ibfk_2'), 'displays → users (lock holder)',
         "ALTER TABLE displays ADD CONSTRAINT displays_ibfk_2
          FOREIGN KEY (lock_holder_id) REFERENCES users (id) ON DELETE SET NULL");

    // Always: only a row count can say whether the drive-thru Display is there,
    // and a fresh install from schema.sql has the table with nothing in it.
    $step(true, 'seed_legacy_display');

    // ---- canvas_elements.display_id ----------------------------------------
    // Added nullable, backfilled, then tightened — in that order, because the live
    // table already holds the drive-thru layout and a NOT NULL column with no
    // default cannot be added to it.
    $sql($facts->needsColumn('canvas_elements', 'display_id'), 'canvas_elements.display_id',
         "ALTER TABLE canvas_elements ADD COLUMN display_id INT(11) DEFAULT NULL");

    // Backfill: everything that predates Display scoping is the drive-thru sign.
    // Runs whenever the column can still hold a NULL — a row that arrives unscoped
    // later (a partly applied migration, a hand edit) would otherwise be invisible
    // to every scoped query while still occupying the canvas. Once the column is
    // NOT NULL there is nothing it could find, which is why it stops.
    $step($facts->needsNotNull('canvas_elements', 'display_id'), 'backfill_display_id');

    // Only succeeds once nothing is NULL, which is exactly the condition we want
    // it to enforce. An unscoped row left behind keeps the column nullable, and
    // rehearse/selftest surfaces it rather than the app silently dropping rows.
    $sql($facts->needsNotNull('canvas_elements', 'display_id'), 'display_id is NOT NULL',
         "ALTER TABLE canvas_elements MODIFY COLUMN display_id INT(11) NOT NULL");
    $sql($facts->needsIndex('canvas_elements', 'display_id'), 'display_id indexed',
         "ALTER TABLE canvas_elements ADD KEY display_id (display_id)");

    // ON DELETE CASCADE is the Phase 3 "delete destroys the Display and its
    // layout" rule, enforced by the database rather than by remembering to delete
    // elements first.
    $sql($facts->needsConstraint('canvas_elements', 'canvas_elements_ibfk_3'),
         'canvas_elements → displays',
         "ALTER TABLE canvas_elements ADD CONSTRAINT canvas_elements_ibfk_3
          FOREIGN KEY (display_id) REFERENCES displays (id) ON DELETE CASCADE");

    // ---- display_permissions ------------------------------------------------
    // One row per grant: this account may edit this Display (ADR-0005). A grant is
    // the row's existence — there is deliberately no "level" column, because what
    // an account may do once inside comes from users.role.
    //
    // Admins are never granted anything; they hold every Display by role. So an
    // empty table means "no basic account can edit anything yet", which is the
    // right default for a store that has been running on admin accounts only.
    $sql($facts->needsTableCreate('display_permissions'), 'display_permissions table',
         "CREATE TABLE IF NOT EXISTS display_permissions (
        id         INT(11)   NOT NULL AUTO_INCREMENT,
        display_id INT(11)   NOT NULL,
        user_id    INT(11)   NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY display_user (display_id, user_id),
        KEY user_id (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // A grant is meaningless once either side is gone, and a stale row pointing at
    // a reused id would hand someone access they were never given. Added
    // separately, because CREATE TABLE above is a no-op on an existing table.
    $sql($facts->needsConstraint('display_permissions', 'display_permissions_ibfk_1'),
         'grant → Display',
         "ALTER TABLE display_permissions ADD CONSTRAINT display_permissions_ibfk_1
          FOREIGN KEY (display_id) REFERENCES displays (id) ON DELETE CASCADE");
    $sql($facts->needsConstraint('display_permissions', 'display_permissions_ibfk_2'),
         'grant → account',
         "ALTER TABLE display_permissions ADD CONSTRAINT display_permissions_ibfk_2
          FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE");

    return $plan;
}

/**
 * Converge the signage schema. Idempotent, and runs its statements at most once
 * per request. Never throws: a statement that cannot apply (already applied, or
 * blocked by data) leaves the database as it was.
 */
function ensureSignageSchema(PDO $pdo)
{
    static $done = false;
    if ($done) { return; }
    $done = true;

    runSchemaPlan($pdo, signageSchemaPlan(readSchemaFacts($pdo)));
}

/**
 * Do the work the plan describes, and return the entries that failed.
 *
 * The return value is new and matters: a statement the catalogue said was missing
 * and which then failed anyway is a *genuine* failure, and until convergence
 * gated itself there was no way to tell one of those from the twelve that fail
 * by design every request. Nothing acts on it yet — that is a separate decision —
 * but the caller that wants to alert an admin now has something true to alert on.
 */
function runSchemaPlan(PDO $pdo, array $plan)
{
    $failed = [];
    foreach ($plan as $entry) {
        $ok = true;
        if (isset($entry['sql'])) {
            $ok = schemaTry($pdo, $entry['sql']);
        } elseif (isset($entry['step'])) {
            $ok = runSchemaStep($pdo, $entry['step']);
        }
        if (!$ok) { $failed[] = $entry; }
    }
    return $failed;
}

/**
 * The steps that cannot be decided from the catalogue, because they are about
 * rows rather than structure. Named rather than passed as callables so the plan
 * stays a plain array a test can compare against.
 */
function runSchemaStep(PDO $pdo, $step)
{
    switch ($step) {
        case 'seed_block_styles':    return seedBlockStyles($pdo);
        case 'backfill_auto_pooled': return backfillPooledMarker($pdo);
        case 'seed_legacy_display':  return seedLegacyDisplay($pdo);
        case 'backfill_display_id':  return backfillDisplayId($pdo);
    }
    return true;   // an unknown step is nothing to do, not a failure to report
}

/**
 * Fill in any missing branded block type. Counts first: a read that finds all six
 * costs less than a six-row INSERT IGNORE, and unlike the insert it takes no locks
 * on a table the Brand Standards form may be saving to at the same moment.
 */
function seedBlockStyles(PDO $pdo)
{
    try {
        $have = intval($pdo->query("SELECT COUNT(*) FROM block_styles")->fetchColumn());
    } catch (Throwable $e) {
        return false;   // no table to count, and none to seed into
    }
    if ($have >= SCHEMA_BLOCK_STYLE_COUNT) { return true; }
    return schemaTry($pdo, SCHEMA_BLOCK_STYLE_SEED);
}

/**
 * Mark every row the old pooling behaviour left behind, from the `Auto: ` label
 * prefix it has always carried.
 *
 * Runs on one request only — see the gate in signageSchemaPlan(). Scoped to rows
 * still at 0 so it writes as little as possible; a hand-made row an admin happened
 * to name "Auto: …" is claimed by this, and the consequence is that an unused one
 * can be tidied away. That is why the label field says what it says in crud.php.
 */
function backfillPooledMarker(PDO $pdo)
{
    return schemaTry($pdo, "UPDATE assets SET auto_pooled = 1
                             WHERE auto_pooled = 0 AND label LIKE 'Auto: %'");
}

/** Point every element that predates Display scoping at the drive-thru sign. */
function backfillDisplayId(PDO $pdo)
{
    $legacyId = legacyDisplayId($pdo);
    if (!$legacyId) { return false; }   // nothing to backfill to; the tighten will refuse
    return schemaTry($pdo, "UPDATE canvas_elements SET display_id = " . intval($legacyId)
                         . " WHERE display_id IS NULL");
}

/**
 * Create the Display that the single pre-multi-display layout belongs to,
 * inheriting the background from the canvas_settings row it replaces.
 *
 * Does nothing once any Display exists — including when an admin has already
 * renamed this one, since the tag is theirs to change (ADR-0003) and a second
 * "drive-thru" must not appear behind their back.
 */
function seedLegacyDisplay(PDO $pdo)
{
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM displays")->fetchColumn();
    } catch (Throwable $e) {
        return false;   // table missing — CREATE TABLE above failed; nothing to seed into
    }
    if (intval($count) > 0) { return true; }

    // canvas_settings is retired by this build, so this is the one and only place
    // that still reads it: to carry the background forward.
    $bgType = 'color';
    $bgVal  = '#1a1a2e';
    try {
        $row = $pdo->query("SELECT bg_type, bg_val FROM canvas_settings ORDER BY id ASC LIMIT 1")->fetch();
        if ($row) {
            $bgType = ($row['bg_type'] === 'image') ? 'image' : 'color';
            if (isset($row['bg_val']) && $row['bg_val'] !== '') { $bgVal = $row['bg_val']; }
        }
    } catch (Throwable $e) {
        // No canvas_settings (fresh install) — defaults are the same ones it held.
    }

    try {
        $pdo->prepare(
            "INSERT INTO displays (tag, title, location, canvas_width, canvas_height, bg_type, bg_val, is_active)
             VALUES (?, ?, NULL, 1920, 1080, ?, ?, 1)"
        )->execute([LEGACY_DISPLAY_TAG, 'Drive-Thru', $bgType, $bgVal]);
        return true;
    } catch (Throwable $e) {
        return false;   // unique tag collision — another request seeded it first
    }
}

/**
 * The Display that unscoped rows belong to: the one carrying LEGACY_DISPLAY_TAG,
 * or failing that the oldest Display, so a renamed tag still backfills correctly.
 * Returns 0 when there is nothing to backfill to.
 */
function legacyDisplayId(PDO $pdo)
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM displays WHERE tag = ? LIMIT 1");
        $stmt->execute([LEGACY_DISPLAY_TAG]);
        $id = $stmt->fetchColumn();
        if ($id) { return intval($id); }

        $id = $pdo->query("SELECT id FROM displays ORDER BY id ASC LIMIT 1")->fetchColumn();
        return $id ? intval($id) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Run one convergence statement, swallowing the failure that means "already
 * applied". Still swallows, because the catalogue can be silent about a
 * constraint or wrong about a partly applied ALTER, and a convergence failure
 * must never break the request that happened to trigger it — the statement is
 * simply re-attempted on the next authenticated request. What changed is that the
 * failure is now *reported upward* by runSchemaPlan() instead of vanishing here.
 */
function schemaTry(PDO $pdo, $sql)
{
    try {
        $pdo->exec($sql);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
