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
// seconds forever. There is one exception, and it has its own front door —
// `repairSchemaAfterFailure()` below, which DisplayStore calls when a query has
// already failed because a table is not there.
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
//
// ---- Saying so when one really does fail ------------------------------------
// `schemaTry()` has always swallowed failures, because most of them mean "already
// applied". The cost was that a statement which genuinely could not run — no CREATE
// privilege, a tighten refused by data that violates it — was indistinguishable
// from the twelve that failed by design every request, and nothing anywhere said so.
// The login-lockout columns were missing on the live database for months.
//
// Gating made that distinguishable for the first time, and this reports it. The rule
// is narrow on purpose, and it is the whole safety argument:
//
//   **Only a statement the catalogue positively said was missing is ever reported.**
//
// Each plan entry carries the `need` it was included on. `true` means the catalogue
// was read and the thing is not there; `null` means it could not be read and the
// statement is a guess. A guess that fails is not news — on a host with no readable
// catalogue, twelve of them fail every request — so `null` is never reported, and an
// admin's inbox cannot be filled by a host that hides `information_schema`.
//
// Throttled to one report an hour per distinct set of failures, log included, since
// a permanently refused statement is retried on every signed-in page load and on the
// Viewer's self-heal path every 30 seconds per Screen.
//
// The message names the statements in words and points at Settings → Database
// Structure rather than restating what each missing column costs. That list already
// exists, in `ServerReport::convergence()`, and two lists of consequences would
// eventually disagree about one.
//
// ---- The other door: a repair nobody asked for -------------------------------
// `ensureSignageSchema()` is *deliberate*. It runs at the top of an authenticated
// page, before a transaction is open and before anybody is mid-write, because that
// is where the call is written.
//
// `repairSchemaAfterFailure()` is the opposite: a query somewhere already failed
// with "no such table", and the caller wants to know whether trying again is worth
// it. It exists because the first request after a deploy may well be a Screen's
// poll, and a sign that stays dark until an admin happens to sign in is worse than
// one convergence run on the public path. But it is reached from a place nobody
// chose, at a moment nobody chose, so three things have to be true first — and each
// is a defect that was live before this gate existed:
//
//   1. **No transaction may be open.** DDL commits the surrounding transaction in
//      MySQL and says nothing about having done so. `LayoutStore::publish()` deletes
//      a Display's whole layout and re-inserts it inside one transaction, and its
//      last two calls — `recordPublish()` and `claimLock()` — read the `displays`
//      row through the same LEFT JOIN on `users` that can raise this error. A repair
//      fired from there would commit the publish, then rethrow, then report
//      "Publish failed. Nothing was saved." to somebody whose work had in fact been
//      saved. With no undo anywhere in this app, that is the worst outcome it has.
//      Refusing to repair costs a dark sign for 30 seconds; not refusing costs a lie
//      about a write.
//   2. **Only one repair at a time, installation-wide.** Six Screens poll on the
//      same 30-second tick and all six fail together. Unguarded, all six read the
//      catalogue, all six see the same column missing, and five of them lose the
//      `ALTER` — which fails with "duplicate column name" on a `need` the catalogue
//      said was `true`, which is exactly the shape that emails an admin. The alert
//      built above would have announced its own success as a failure, on deploy day,
//      six times. Two concurrent `ALTER`s on `canvas_elements` are also the metadata
//      lock pile-up that gating the plan set out to avoid.
//   3. **Not again for five minutes.** A repair that *cannot* succeed — no CREATE
//      privilege, a tighten the data refuses — is otherwise retried every 30 seconds
//      by every Screen, forever, on the one query that must never be slow. The sign
//      is already dark; trying twelve times an hour instead of seven thousand loses
//      nothing.
//
// The lock is an `flock` rather than a stamp or a directory, so it is released by the
// operating system when the process ends. A repair interrupted by a timeout must not
// leave a lock behind that stops the next request fixing the database.

// This depends on ErrorPolicy, and only for reporting. Safe because ErrorPolicy
// depends on nothing itself — no database, no session, no config — so requiring it
// keeps the rule that this file is includable without starting a session or loading
// page-level helpers (see BUILD-REFERENCE.md §1).
// The login-lockout columns stay where ADR-0001 put them — added by the pre-auth
// login/reset pages, never from a data-access path.

require_once __DIR__ . '/error_policy.php';

// How long a repair triggered by a *failed query* waits before trying again. Not a
// limit on ensureSignageSchema(): an admin opening a page is entitled to converge
// every time. This is the Screens' path, where nobody chose to run anything.
if (!defined('SCHEMA_REPAIR_RETRY_SECONDS')) {
    define('SCHEMA_REPAIR_RETRY_SECONDS', 300);
}

// The file whose lock means "a repair is running right now". Its name is visible
// here because it appears in the state directory beside the log, and an admin who
// finds it should be able to grep for it.
if (!defined('SCHEMA_REPAIR_LOCK')) {
    define('SCHEMA_REPAIR_LOCK', 'schema-repair.lock');
}

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
 *   ['why' => …, 'need' => …, 'sql'  => …]   a statement to run
 *   ['why' => …, 'need' => …, 'step' => …]   a named step that has to read rows
 *
 * `need` is carried through rather than discarded because it is what makes a
 * failure meaningful: `true` says the catalogue was read and this really is
 * missing, so a failure is worth telling an admin about. `null` says the catalogue
 * could not be read and the statement is a guess, so a failure is the normal case
 * and reporting it would be noise. See reportSchemaFailures().
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
        $plan[] = ['why' => $why, 'need' => $need, 'sql' => $statement];
    };
    $step = function ($need, $name) use (&$plan) {
        if ($need === false) { return; }
        $plan[] = ['why' => $name, 'need' => $need, 'step' => $name];
    };

    // ---- users: the login lockout's three columns (ADR-0001) ----------------
    // These were three unconditional `ALTER`s issued by `ensureLockoutColumns()`
    // in auth.php, from login.php, on every sign-in POST. That made them the one
    // piece of DDL in the app reachable **without an account**: a credential-
    // stuffing bot — which is the exact threat ADR-0001 was written about — was
    // issuing three no-op table alterations per guess, each taking a metadata lock
    // on `users`. Convergence with a gate is what every other statement here gets,
    // and there was never a reason for these three to be the exception.
    //
    // The consequence, stated because it is real: on a database where they have
    // never been added, the *first* sign-in happens without them, since nothing
    // pre-auth converges any more. That is exactly the state
    // `AccountStore::findForSignIn()` already answers for — signing in without a
    // brute-force counter beats nobody signing in at all — and it lasts until the
    // first authenticated page load, which is the Builder that same sign-in lands
    // on.
    $sql($facts->needsColumn('users', 'failed_attempts'), 'users.failed_attempts',
         "ALTER TABLE users ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0");
    $sql($facts->needsColumn('users', 'last_failed_at'), 'users.last_failed_at',
         "ALTER TABLE users ADD COLUMN last_failed_at DATETIME NULL");
    $sql($facts->needsColumn('users', 'locked_until'), 'users.locked_until',
         "ALTER TABLE users ADD COLUMN locked_until DATETIME NULL");

    // The column that makes an account number permanent (invariant 14). It arrived
    // the same ungated way as the three above — `AccountStore::ensureSchema()`, one
    // `ALTER` on every admin-panel load — which is milder than the login one was
    // (authenticated, one statement, a page nobody hammers) and is the same defect.
    // The panel converges before it builds an `AccountStore`, so the store's cached
    // "is the column there" answer is taken after this has run, not before.
    $sql($facts->needsColumn('users', 'closed_at'), 'users.closed_at',
         "ALTER TABLE users ADD COLUMN closed_at DATETIME NULL DEFAULT NULL");

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
    // A row count, not a catalogue fact, so it is a step. Skipped outright when the
    // catalogue says there is no table to seed into.
    //
    // Its need is `true`, not `null`, on a host whose catalogue cannot be read — and
    // that is deliberate, because the need never came from the catalogue in the
    // first place. A row count runs and answers on any host, so a failure here means
    // something real (no table to read, or an INSERT refused) and is worth reporting
    // wherever it happens. Same for the Display seed below. Every *statement* in the
    // plan is the other way round: its need is the catalogue's word, and without the
    // catalogue it is a guess. See reportSchemaFailures().
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

    // Always, and on a known need for the reason given above the block-style seed:
    // only a row count can say whether the drive-thru Display is there, and a fresh
    // install from schema.sql has the table with nothing in it.
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
 *
 * Call this from an authenticated page, before opening a transaction. A caller that
 * has *already failed* a query wants `repairSchemaAfterFailure()` instead — it asks
 * the questions this one is entitled to assume the answers to.
 *
 * Two requests arriving together get one convergence between them, not two. The
 * loser does nothing and renders against the database as it stands, which is what it
 * would have done anyway while the winner's `ALTER`s were still running; what it no
 * longer does is race the winner for the same column and report losing as a failure.
 */
function ensureSignageSchema(PDO $pdo)
{
    if (!SchemaLatch::take()) { return; }

    withSchemaRepairLock(function () use ($pdo) {
        reportSchemaFailures(runSchemaPlan($pdo, signageSchemaPlan(readSchemaFacts($pdo))));
        return true;
    });
}

/**
 * Converge because something already broke — see the header, "The other door".
 *
 * Returns true only if convergence actually ran. `$why` carries the reason it did
 * not, for the self-test and for anybody reading this later wondering which of the
 * three refusals they are looking at.
 */
function repairSchemaAfterFailure(PDO $pdo, &$why = null)
{
    $why = '';

    // First, because it is the one refusal that protects data rather than load. DDL
    // commits the open transaction in MySQL, silently, and the caller is about to be
    // told its write failed.
    if ($pdo->inTransaction()) {
        $why = 'a transaction is open';
        return false;
    }

    // Convergence already ran on this request and would return immediately, so
    // saying no here is honest — and it leaves the retry window unspent for the
    // Screens, which are the callers that actually need it. Without this an
    // authenticated page, which always converges first, would burn the window on
    // every failure and there would be nothing left when a Screen asked.
    if (!SchemaLatch::pending()) {
        $why = 'convergence already ran on this request';
        return false;
    }

    if (!ErrorPolicy::firstInWindow('schema-repair', SCHEMA_REPAIR_RETRY_SECONDS)) {
        $why = 'the last repair was less than ' . SCHEMA_REPAIR_RETRY_SECONDS . ' seconds ago';
        return false;
    }

    ensureSignageSchema($pdo);
    return true;
}

/**
 * Is this database error "that table is not there"?
 *
 * Pure, and takes the two strings rather than the exception, so both shapes can be
 * put to it directly. MySQL answers SQLSTATE 42S02 — "base table or view not found"
 * — which is the one that matters live. SQLite answers a generic HY000 and says
 * so only in the message, which is the one the self-test can produce: before this
 * was widened, the recovery path could not be executed by the fixture at all, and
 * so never had been.
 *
 * Deliberately narrow. A missing *column* is 42S22 and says "Unknown column"; a
 * missing *database* is 42000. Neither is repaired by convergence and neither may
 * trigger it.
 */
function schemaErrorSaysTableMissing($sqlstate, $message)
{
    if ((string)$sqlstate === '42S02') { return true; }
    return stripos((string)$message, 'no such table') !== false;
}

/**
 * Hold the "one repair at a time" lock for the duration of $work.
 *
 * Returns whatever $work returned, or false if the lock is held elsewhere. When
 * there is nowhere to write a lock file the work runs unguarded: an install with no
 * writable directory has no log and no alerts either — Settings → This Server says
 * so in as many words — and it needs the repair more than the coordination.
 *
 * There is no catch around $work, and that is the point of using `flock` rather than
 * a stamp file or a directory: the lock belongs to the open file, so PHP releases it
 * when $handle falls out of scope — including when it falls out of scope because an
 * exception is unwinding, and including when the process is killed mid-`ALTER`. A
 * repair that dies must never leave behind a lock that stops the next request fixing
 * the database. The self-test asserts that property rather than trusting it.
 */
function withSchemaRepairLock(callable $work)
{
    $path = ErrorPolicy::stateFile(SCHEMA_REPAIR_LOCK);
    if ($path === '') { return $work(); }

    $handle = @fopen($path, 'c');
    if (!$handle) { return $work(); }

    // Non-blocking. A page load must not wait behind somebody else's ALTER on a
    // table this request may not even touch.
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        return false;
    }

    $out = $work();
    @flock($handle, LOCK_UN);
    @fclose($handle);
    return $out;
}

/**
 * The "at most once per request" latch, as something that can be cleared.
 *
 * It used to be a `static` inside ensureSignageSchema(), which meant the self-test
 * could exercise the entry point exactly once per run — so whether the entry point
 * actually *reports* a failure, rather than the reporting function working when
 * called by hand, was untested. A mutation that removed the reporting call from the
 * entry point altogether failed nothing. This is what closes that.
 */
class SchemaLatch
{
    private static $done = false;

    /** True if the caller got the latch; false if convergence already ran. */
    public static function take()
    {
        if (self::$done) { return false; }
        self::$done = true;
        return true;
    }

    /** True while convergence has not yet run on this request. */
    public static function pending()
    {
        return !self::$done;
    }

    /** For the self-test. Nothing in the app clears this. */
    public static function forget()
    {
        self::$done = false;
    }
}

/**
 * Do the work the plan describes, and return the entries that failed, each with the
 * database's own reason under `error`.
 *
 * A statement the catalogue said was missing and which then failed anyway is a
 * *genuine* failure, and until convergence gated itself there was no way to tell one
 * of those from the twelve that fail by design every request. That is what makes the
 * return value worth having, and reportSchemaFailures() is what does something
 * with it.
 */
function runSchemaPlan(PDO $pdo, array $plan)
{
    $failed = [];
    foreach ($plan as $entry) {
        $ok    = true;
        $error = '';
        if (isset($entry['sql'])) {
            $ok = schemaTry($pdo, $entry['sql'], $error);
        } elseif (isset($entry['step'])) {
            $ok = runSchemaStep($pdo, $entry['step'], $error);
        }
        if (!$ok) {
            $entry['error'] = $error;
            $failed[] = $entry;
        }
    }
    return $failed;
}

/**
 * Tell somebody about the statements that genuinely could not run.
 *
 * Two rules, and the first is the one that matters:
 *
 *   * **Only `need === true`.** That is "the catalogue was read, and this is not
 *     there". A `null` need is a guess made because the catalogue could not be read,
 *     and on such a host twelve statements fail every single request — reporting
 *     those would fill an inbox with the normal case and teach an admin to ignore
 *     the alert that matters.
 *   * **Once an hour per distinct set.** A refused statement is retried on every
 *     signed-in page load, and on the Viewer's self-heal path every 30 seconds per
 *     Screen. The key is the failures themselves, so a *new* failure appearing sends
 *     a new report immediately rather than waiting out the old window.
 *
 * The message names the statements in the words the plan gave them and points at the
 * Settings screen, which already lists every runtime-added column and what a missing
 * one costs. Restating that here would be a second list of consequences to keep in
 * agreement with the first.
 *
 * Returns true when something was written or sent, for the self-test.
 */
function reportSchemaFailures(array $failed)
{
    $real = schemaFailuresWorthReporting($failed);
    if (!$real) { return false; }

    $names = [];
    $lines = [];
    foreach ($real as $entry) {
        $why     = isset($entry['why']) ? (string)$entry['why'] : 'an unnamed change';
        $names[] = $why;
        $reason  = isset($entry['error']) ? trim(str_replace(["\r", "\n"], ' ', $entry['error'])) : '';
        if (strlen($reason) > 200) { $reason = substr($reason, 0, 200) . '…'; }
        $lines[] = '  * ' . $why . ($reason === '' ? '' : ' — ' . $reason);
    }

    // The key is what failed, not when: the same set stays quiet for an hour, and a
    // different set is a different problem and says so straight away.
    sort($names);
    $key = 'schema-refused|' . sha1(implode('|', $names));

    $count  = count($real);
    $detail = $count . ' schema ' . ($count === 1 ? 'update' : 'updates')
            . ' the database says it needs could not be applied. The app is still '
            . "running; whatever they were meant to add is not there.\n\n"
            . implode("\n", array_slice($lines, 0, 10))
            . ($count > 10 ? "\n  * … and " . ($count - 10) . " more\n" : "\n")
            . "\nAdmin Panel → Settings → Database Structure lists every column this "
            . "app adds by itself and what a missing one costs. A row that is green "
            . "there is already in place, and the statement is being refused for some "
            . "other reason — most likely a name the database chose for itself — in "
            . "which case nothing is wrong with the data.\n\n"
            . "This is retried on the next signed-in page load. At most one of these "
            . "is sent per hour, and a different set of failures sends a new one "
            . "straight away.";

    return ErrorPolicy::report($key, $detail, 'Schema updates are being refused',
                               ErrorPolicy::REPORT_WINDOW);
}

/**
 * The subset of failures that mean something. See reportSchemaFailures() for why
 * `need === true` is the whole test, and note that it is deliberately strict about
 * the *type*: an entry with no `need` at all — one a caller assembled by hand — is
 * not reported either, because nothing established that it was needed.
 */
function schemaFailuresWorthReporting(array $failed)
{
    $out = [];
    foreach ($failed as $entry) {
        if (isset($entry['need']) && $entry['need'] === true) { $out[] = $entry; }
    }
    return $out;
}

/**
 * The steps that cannot be decided from the catalogue, because they are about
 * rows rather than structure. Named rather than passed as callables so the plan
 * stays a plain array a test can compare against.
 */
function runSchemaStep(PDO $pdo, $step, &$error = null)
{
    $error = '';
    switch ($step) {
        case 'seed_block_styles':    return seedBlockStyles($pdo, $error);
        case 'backfill_auto_pooled': return backfillPooledMarker($pdo, $error);
        case 'seed_legacy_display':  return seedLegacyDisplay($pdo, $error);
        case 'backfill_display_id':  return backfillDisplayId($pdo, $error);
    }
    return true;   // an unknown step is nothing to do, not a failure to report
}

/**
 * Fill in any missing branded block type. Counts first: a read that finds all six
 * costs less than a six-row INSERT IGNORE, and unlike the insert it takes no locks
 * on a table the Brand Standards form may be saving to at the same moment.
 */
function seedBlockStyles(PDO $pdo, &$error = null)
{
    $error = '';
    try {
        $have = intval($pdo->query("SELECT COUNT(*) FROM block_styles")->fetchColumn());
    } catch (Throwable $e) {
        $error = 'block_styles could not be read: ' . $e->getMessage();
        return false;   // no table to count, and none to seed into
    }
    if ($have >= SCHEMA_BLOCK_STYLE_COUNT) { return true; }
    return schemaTry($pdo, SCHEMA_BLOCK_STYLE_SEED, $error);
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
function backfillPooledMarker(PDO $pdo, &$error = null)
{
    return schemaTry($pdo, "UPDATE assets SET auto_pooled = 1
                             WHERE auto_pooled = 0 AND label LIKE 'Auto: %'", $error);
}

/** Point every element that predates Display scoping at the drive-thru sign. */
function backfillDisplayId(PDO $pdo, &$error = null)
{
    $error    = '';
    $legacyId = legacyDisplayId($pdo);
    if (!$legacyId) {
        // Nothing to backfill to, which means the seed above did not manage to make
        // the drive-thru Display. That is the failure worth reporting; this one is
        // its consequence, and the tighten after it will refuse for the same reason.
        $error = 'there is no Display to hand unscoped elements to';
        return false;
    }
    return schemaTry($pdo, "UPDATE canvas_elements SET display_id = " . intval($legacyId)
                         . " WHERE display_id IS NULL", $error);
}

/**
 * Create the Display that the single pre-multi-display layout belongs to,
 * inheriting the background from the canvas_settings row it replaces.
 *
 * Does nothing once any Display exists — including when an admin has already
 * renamed this one, since the tag is theirs to change (ADR-0003) and a second
 * "drive-thru" must not appear behind their back.
 */
function seedLegacyDisplay(PDO $pdo, &$error = null)
{
    $error = '';
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM displays")->fetchColumn();
    } catch (Throwable $e) {
        $error = 'the displays table is not there: ' . $e->getMessage();
        return false;   // CREATE TABLE above failed; nothing to seed into
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
        // A unique-tag collision means another request seeded it between the count
        // above and this insert. Nothing is wrong — the Display exists, which is all
        // this was for — and reporting it would send an admin an email about two
        // people signing in at the same moment on the first request after a deploy.
        if (legacyDisplayId($pdo) > 0) { return true; }

        $error = 'the drive-thru Display could not be created: ' . $e->getMessage();
        return false;
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
 * simply re-attempted on the next authenticated request.
 *
 * What changed is that the reason no longer dies here. `$error` carries the
 * database's own message up to runSchemaPlan(), which is what lets an alert say
 * *why* a statement was refused rather than only that it was.
 */
function schemaTry(PDO $pdo, $sql, &$error = null)
{
    try {
        $pdo->exec($sql);
        $error = '';
        return true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return false;
    }
}
