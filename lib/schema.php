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
// then return thousands of rows to answer a question about ten tables. (Eight when
// this was written; `brands` and `workspace_themes` arrived with the v2 roadmap and
// the count is corrected here rather than left to drift.)
if (!defined('SCHEMA_TABLES')) {
    define('SCHEMA_TABLES', [
        'users', 'password_resets', 'assets', 'block_styles', 'brands',
        'canvas_elements', 'canvas_settings', 'displays', 'display_permissions',
        'workspace_themes',
    ]);
}

// The two ENUM definitions, written once so the statement that sets them and the
// comparison that decides whether to run it cannot drift apart. Lower case and
// no spaces, which is the form `information_schema.COLUMNS.COLUMN_TYPE` reports;
// MySQL accepts it verbatim in an ALTER.
//
// Built from LayoutRules rather than spelled out again, because "written once"
// has to include the publish path as well. It did not: the column knew the seven
// types and the publish knew none of them, so an unknown `type` was accepted by
// the store and then stored as '' by a non-strict MySQL (#29). One list now
// decides both what may be published and what the column may hold.
require_once __DIR__ . '/layout_rules.php';

if (!defined('SCHEMA_ELEMENT_TYPE_ENUM')) {
    define('SCHEMA_ELEMENT_TYPE_ENUM', LayoutRules::enumSql(LayoutRules::ELEMENT_TYPES));
}
if (!defined('SCHEMA_BLOCK_SUBTYPE_ENUM')) {
    define('SCHEMA_BLOCK_SUBTYPE_ENUM', LayoutRules::enumSql(LayoutRules::BLOCK_SUBTYPES));
}

// The six branded block types and where each one starts. One row per type **per
// Brand** must exist for Brand Standards to be editable at all: that form saves
// with UPDATE … WHERE brand_id = ? AND block_type = ?, so a missing row makes the
// save a silent no-op — the field reverts on reload and nothing says why. All six
// are listed rather than just the two a later build added, because the four
// originals are missing on a database that predates them (schema.sql seeds the
// same set).
//
// The values themselves are `BrandStyles::STARTING_POINTS`, not a list here, for the
// reason the ENUMs above are built from LayoutRules: "written once" has to include
// the other writers. There are three now — this file seeds the Brand it creates for
// a database that predates Brands, `BrandAdmin` seeds every Brand made afterwards,
// and `schema.sql` writes them for a fresh install — and three copies is three
// chances for a new Brand to start somewhere the last one did not.
//
// Rows rather than one INSERT string, which is what re-keying on the Brand cost this
// seed. The statement has to name a `brand_id` now, and it inserts only the
// (Brand, type) pairs that are actually absent rather than sending all six behind an
// `INSERT IGNORE`: the ignoring form is spelled differently on the two engines this
// app is tested against, so a seed written that way is one the SQLite fixture can
// never execute — which is #11's rule, and the reason the old one never ran in a
// test at all.
require_once __DIR__ . '/brand_styles.php';

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
    private $indexes;       // table => index name => list of columns, or true for "exists, columns unknown"
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

    /**
     * Which columns an index is over, in order — or null when that cannot be said.
     *
     * Null covers three different "cannot say"s on purpose, because the caller does
     * the same thing with all of them: no catalogue was read, the index is not there,
     * or the facts were built by a caller that only recorded *that* the index exists.
     * That last one is why the value may be `true` rather than a list: every shape
     * written before an index's columns mattered says `true`, and reading `true` as
     * "over no columns" would answer a confident wrong list rather than "I did not
     * look".
     */
    public function indexColumns($table, $index)
    {
        if ($this->hasIndex($table, $index) !== true) { return null; }
        $cols = $this->indexes[strtolower($table)][strtolower($index)];
        return is_array($cols) ? $cols : null;
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
     * Does this table's PRIMARY KEY have to be re-made over these columns?
     *
     * The one statement in the plan that *replaces* structure rather than adding it:
     * `block_styles` was keyed by `block_type` alone and is keyed by
     * `(brand_id, block_type)` once a Brand owns a set of standards (ADR-0011). So
     * this is the gate that most needs to be right — `DROP PRIMARY KEY, ADD PRIMARY
     * KEY` run a second time against an already re-keyed table does not fail
     * harmlessly the way a duplicate `ADD COLUMN` does, it rebuilds the table.
     *
     * Answering from the *columns* rather than from the index's existence is the
     * whole point: every table here has a PRIMARY, so `hasIndex()` says yes both
     * before and after, and a gate built on it would either never run or always run.
     *
     * Null — "run it and let schemaTry() swallow what that means" — is answered for
     * a catalogue that could not be read at all, for one that recorded the index
     * without its columns, and for a table the catalogue says has no PRIMARY at all.
     * The first two are honestly "I did not look", and the plan's standing answer to
     * that is the behaviour this file had before it started asking. The third is a
     * table nothing here built, where `DROP PRIMARY KEY` will fail and reporting it
     * would be noise about a database this app did not make.
     *
     * False only when the key is already exactly these columns, in this order, and
     * when the table is not there at all — a table this file creates declares its key
     * in the CREATE, and one it does not create cannot be altered.
     */
    public function needsPrimaryKey($table, array $wanted)
    {
        if ($this->tableMissing($table)) { return false; }
        if (!$this->known)               { return null; }
        $has = $this->indexColumns($table, 'PRIMARY');
        if ($has === null)               { return null; }
        return array_map('strtolower', $has) !== array_map('strtolower', $wanted);
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

        // Ordered by SEQ_IN_INDEX, because a composite key is its columns *in order*
        // — `(brand_id, block_type)` and `(block_type, brand_id)` are different keys,
        // and a gate that compared them as sets would call a re-key already done.
        $indexes = [];
        $stmt = $pdo->prepare(
            "SELECT TABLE_NAME AS t, INDEX_NAME AS n, COLUMN_NAME AS c
               FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($marks)
              ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
        );
        $stmt->execute($tables);
        foreach ($stmt->fetchAll() as $row) { $indexes[$row['t']][$row['n']][] = (string)$row['c']; }

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

    // ---- brands: the identity a sign wears (ADR-0011) -----------------------
    // A Brand is a named, reusable identity several Displays share: the six branded
    // block-type standards, a palette offered as swatches, a logo asset and a default
    // canvas background. It exists because the installation stopped being one store —
    // it drives signs in several venues on one property, and one set of colours across
    // all of them is not a shared look, it is a defect that reaches every screen.
    $sql($facts->needsTableCreate('brands'), 'brands table', "CREATE TABLE IF NOT EXISTS brands (
        id            INT(11)      NOT NULL AUTO_INCREMENT,
        name          VARCHAR(80)  NOT NULL,
        logo_asset_id INT(11)      DEFAULT NULL COMMENT 'the venue logo, placed by the Builder in one click',
        bg_type       ENUM('color','image') NOT NULL DEFAULT 'color',
        bg_val        VARCHAR(255) NOT NULL DEFAULT '#1a1a2e',
        palette_1     VARCHAR(7)   DEFAULT NULL,
        palette_2     VARCHAR(7)   DEFAULT NULL,
        palette_3     VARCHAR(7)   DEFAULT NULL,
        palette_4     VARCHAR(7)   DEFAULT NULL,
        palette_5     VARCHAR(7)   DEFAULT NULL,
        palette_6     VARCHAR(7)   DEFAULT NULL,
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY name (name),
        KEY logo_asset_id (logo_asset_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // SET NULL rather than CASCADE: a logo tidied out of the Asset Library must not
    // take the venue's colours and typography with it. The Brand loses its logo and
    // says so; every sign wearing it keeps rendering.
    $sql($facts->needsConstraint('brands', 'brands_ibfk_1'), 'Brand → logo asset',
         "ALTER TABLE brands ADD CONSTRAINT brands_ibfk_1
          FOREIGN KEY (logo_asset_id) REFERENCES assets (id) ON DELETE SET NULL");

    // Always, and on a known need for the reason given at the Display seed below:
    // only a row count can say whether the first Brand is there, and every backfill
    // after this one has nothing to point at until it is.
    $step(true, 'seed_first_brand');

    // ---- block_styles: one row per branded block type, per Brand ------------
    // Re-keyed from `block_type` alone onto `(brand_id, block_type)`. Added nullable,
    // backfilled, tightened, then re-keyed — the same order `canvas_elements.display_id`
    // uses below and for the same reason: the live table already holds the store's six
    // rows, and a NOT NULL column with no default cannot be added to a table with rows
    // in it.
    //
    // The tighten is written out rather than left to the re-key. MySQL silently
    // converts a nullable column into a PRIMARY KEY as NOT NULL, so relying on that
    // would work and would leave the `MODIFY` in the plan on the next request too —
    // the catalogue was read before either ran, so both gates were decided together.
    // An ALTER that "does nothing" takes the same metadata lock as one that does.
    $sql($facts->needsColumn('block_styles', 'brand_id'), 'block_styles.brand_id',
         "ALTER TABLE block_styles ADD COLUMN brand_id INT(11) DEFAULT NULL");

    $step($facts->needsNotNull('block_styles', 'brand_id'), 'backfill_block_style_brand');

    $sql($facts->needsNotNull('block_styles', 'brand_id'), 'block_styles.brand_id is NOT NULL',
         "ALTER TABLE block_styles MODIFY COLUMN brand_id INT(11) NOT NULL");

    // The one statement here that replaces structure instead of adding it, which is
    // why its gate reads the key's *columns* rather than asking whether a PRIMARY
    // exists. Every table has one, so an existence test would answer the same before
    // and after and this would either never run or run on every request — and unlike
    // a duplicate ADD COLUMN, a second DROP/ADD PRIMARY KEY does not fail harmlessly,
    // it rebuilds the table.
    $sql($facts->needsPrimaryKey('block_styles', ['brand_id', 'block_type']),
         'block_styles re-keyed on (brand_id, block_type)',
         "ALTER TABLE block_styles DROP PRIMARY KEY, ADD PRIMARY KEY (brand_id, block_type)");

    // CASCADE, unlike the logo above: a Brand's standards are part of the Brand and
    // mean nothing without it. Deleting a Brand that any Display still wears is
    // refused long before this — by BrandStore, naming the Displays — so what this
    // cascades is the standards of a Brand nobody was using.
    $sql($facts->needsConstraint('block_styles', 'block_styles_ibfk_1'), 'brand standards → Brand',
         "ALTER TABLE block_styles ADD CONSTRAINT block_styles_ibfk_1
          FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE CASCADE");

    // A row count, not a catalogue fact, so it is a step. Runs after the re-key
    // because what it seeds is six rows *for each Brand*, which it cannot write
    // until the column those rows are keyed by is there.
    //
    // Its need is `true`, not `null`, on a host whose catalogue cannot be read — and
    // that is deliberate, because the need never came from the catalogue in the
    // first place. A row count runs and answers on any host, so a failure here means
    // something real (no table to read, or an INSERT refused) and is worth reporting
    // wherever it happens. Same for the Display seed below. Every *statement* in the
    // plan is the other way round: its need is the catalogue's word, and without the
    // catalogue it is a guess. See reportSchemaFailures().
    $step(!$facts->tableMissing('block_styles'), 'seed_block_styles');

    // ---- workspace_themes: what the application itself is painted in --------
    // The other noun (decision 1): a Brand is what a customer sees on a TV, a
    // Workspace Theme is what an employee's screen is painted in. Nothing here ever
    // reaches a Screen, which is why the columns are named after *roles in the
    // chrome* — nav, work area, panel, the status colours, the canvas selection
    // outline — and why there is no column for anything drawn on the canvas
    // (decision 11, and a check refuses one).
    //
    // Thirteen colour columns, one per role, each `NOT NULL` with today's value as
    // its default. So a row is complete by construction: a theme is never half a set
    // of colours, and a column added to this table later starts every existing theme
    // at the value the app was already using. `SiteChrome::ROLES` is the same list,
    // and `selftest_layout` asserts that this statement and that constant name the
    // same thirteen — two lists is how they come to disagree, and only one of them
    // can be read by the database.
    //
    // **There is no seed, and that is a change from the plan.** It said today's
    // `branding_config.php` values "become a seeded theme named Store default". A
    // seeded row is a *copy* of that file, and the first time somebody edits Site
    // Branding the copy disagrees with the file while still being called the store
    // default — the same two-readers defect `SiteChrome::load()`'s docblock refuses
    // for the file itself. So the store default is not a row at all: it is
    // `branding_config.php` plus the documented defaults, answered by `SiteChrome`
    // when no theme is worn, and `users.workspace_theme_id IS NULL` is how an account
    // says it wants that. Nothing is inserted here, nothing is backfilled, and
    // convergence therefore cannot repaint anybody's screen.
    $sql($facts->needsTableCreate('workspace_themes'), 'workspace_themes table',
         "CREATE TABLE IF NOT EXISTS workspace_themes (
        id            INT(11)     NOT NULL AUTO_INCREMENT,
        name          VARCHAR(80) NOT NULL,
        nav_bg        VARCHAR(7)  NOT NULL DEFAULT '#1a252f',
        nav_border    VARCHAR(7)  NOT NULL DEFAULT '#0d1b24',
        nav_text      VARCHAR(7)  NOT NULL DEFAULT '#ffffff',
        accent        VARCHAR(7)  NOT NULL DEFAULT '#3498db',
        work_area     VARCHAR(7)  NOT NULL DEFAULT '#2c3e50',
        panel         VARCHAR(7)  NOT NULL DEFAULT '#1a252f',
        panel_border  VARCHAR(7)  NOT NULL DEFAULT '#34495e',
        status_good   VARCHAR(7)  NOT NULL DEFAULT '#27ae60',
        status_warn   VARCHAR(7)  NOT NULL DEFAULT '#7d6608',
        status_bad    VARCHAR(7)  NOT NULL DEFAULT '#7b3f3f',
        status_busy   VARCHAR(7)  NOT NULL DEFAULT '#4b3869',
        status_note   VARCHAR(7)  NOT NULL DEFAULT '#7a4a12',
        selection     VARCHAR(7)  NOT NULL DEFAULT '#e74c3c',
        PRIMARY KEY (id),
        UNIQUE KEY name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Which theme an account chose. Nullable, and null is not a missing answer — it
    // is the answer "use the store default", which is what every account has until
    // somebody picks something else and what decision 14 says must work from any
    // state. So there is no backfill and no `NOT NULL` to tighten to later: the
    // column arrives meaning exactly what every existing row wants it to mean.
    $sql($facts->needsColumn('users', 'workspace_theme_id'), 'users.workspace_theme_id',
         "ALTER TABLE users ADD COLUMN workspace_theme_id INT(11) DEFAULT NULL");
    $sql($facts->needsIndex('users', 'workspace_theme_id'), 'users.workspace_theme_id indexed',
         "ALTER TABLE users ADD KEY workspace_theme_id (workspace_theme_id)");

    // No `ON DELETE` clause, so RESTRICT: deleting a theme somebody has chosen is
    // refused. The alternative — SET NULL — would move three people back to the store
    // default on one click without telling them, which is the merge invariant 5 exists
    // to prevent, and the same reasoning `displays_ibfk_3` carries for a Brand in use.
    // `WorkspaceThemeStore` refuses it first and names the accounts; this is what
    // covers a database this app is not the only thing writing to.
    $sql($facts->needsConstraint('users', 'users_ibfk_1'), 'account → Workspace Theme',
         "ALTER TABLE users ADD CONSTRAINT users_ibfk_1
          FOREIGN KEY (workspace_theme_id) REFERENCES workspace_themes (id)");

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
        brand_id          INT(11)      NOT NULL COMMENT 'the Brand this sign wears (ADR-0011)',
        created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY tag (tag),
        KEY last_published_by (last_published_by),
        KEY lock_holder_id (lock_holder_id),
        KEY brand_id (brand_id)
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

    // The Brand every existing sign wears. Added nullable, backfilled, tightened —
    // the same three steps as `canvas_elements.display_id` below, and for the same
    // reason: the live `displays` table already holds every sign in the shop.
    //
    // Absent from this plan when convergence *creates* `displays`, because the CREATE
    // above declares it — which is what `needsColumn()` answers false for a missing
    // table about. The seed below then supplies it, and it can, because the first
    // Brand is seeded further up this same plan.
    $sql($facts->needsColumn('displays', 'brand_id'), 'displays.brand_id',
         "ALTER TABLE displays ADD COLUMN brand_id INT(11) DEFAULT NULL");

    // Always, and on a known need for the reason given above the block-style seed:
    // only a row count can say whether the drive-thru Display is there, and a fresh
    // install from schema.sql has the table with nothing in it.
    $step(true, 'seed_legacy_display');

    // Every sign that predates Brands wears the one the seed just made. Runs while
    // the column can still hold a NULL, exactly like the display_id backfill: a row
    // that arrives unbranded later — a partly applied migration, a hand edit — would
    // otherwise render with no standards at all and nothing would say why.
    $step($facts->needsNotNull('displays', 'brand_id'), 'backfill_display_brand');

    // Only succeeds once nothing is NULL, which is the condition worth enforcing:
    // ADR-0011 makes this NOT NULL because a sign with no identity has no sensible
    // rendering, and a Display left unbranded is one whose branded blocks would fall
    // back to their own columns — which invariant 34 has just stopped publish writing.
    $sql($facts->needsNotNull('displays', 'brand_id'), 'displays.brand_id is NOT NULL',
         "ALTER TABLE displays MODIFY COLUMN brand_id INT(11) NOT NULL");
    $sql($facts->needsIndex('displays', 'brand_id'), 'displays.brand_id indexed',
         "ALTER TABLE displays ADD KEY brand_id (brand_id)");

    // No ON DELETE clause, so the default RESTRICT stands, and that is the point:
    // deleting a Brand a Display still wears is refused by BrandStore with a sentence
    // naming the signs, and this is the database saying the same thing to anything
    // that reaches the table another way. The alternative — SET NULL or CASCADE —
    // would repaint or destroy three signs in a restaurant on one click, which is
    // the merge the standing rule refuses (ADR-0011).
    $sql($facts->needsConstraint('displays', 'displays_ibfk_3'), 'Display → Brand',
         "ALTER TABLE displays ADD CONSTRAINT displays_ibfk_3
          FOREIGN KEY (brand_id) REFERENCES brands (id)");

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
        case 'seed_first_brand':            return seedFirstBrand($pdo, $error);
        case 'backfill_block_style_brand':  return backfillBlockStyleBrand($pdo, $error);
        case 'seed_block_styles':           return seedBlockStyles($pdo, $error);
        case 'backfill_auto_pooled':        return backfillPooledMarker($pdo, $error);
        case 'seed_legacy_display':         return seedLegacyDisplay($pdo, $error);
        case 'backfill_display_brand':      return backfillDisplayBrand($pdo, $error);
        case 'backfill_display_id':         return backfillDisplayId($pdo, $error);
    }
    return true;   // an unknown step is nothing to do, not a failure to report
}

/**
 * Create the first Brand, which every existing sign and every existing set of
 * standards is then pointed at.
 *
 * Named after `SITE_NAME`, so the store's own name is what an admin opening Display
 * Branding for the first time sees — not "Brand 1", which reads like something the
 * upgrade left half-done. Guarded rather than assumed: this file is includable
 * without `config.php` (see the header), so the constant may genuinely not be there.
 *
 * Does nothing once any Brand exists — including when an admin has already renamed
 * this one, for the same reason `seedLegacyDisplay()` stands off a renamed tag.
 */
function seedFirstBrand(PDO $pdo, &$error = null)
{
    $error = '';
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM brands")->fetchColumn();
    } catch (Throwable $e) {
        $error = 'the brands table is not there: ' . $e->getMessage();
        return false;   // CREATE TABLE above failed; nothing to seed into
    }
    if (intval($count) > 0) { return true; }

    try {
        $pdo->prepare("INSERT INTO brands (name) VALUES (?)")->execute([firstBrandName()]);
        return true;
    } catch (Throwable $e) {
        // A unique-name collision means another request seeded it between the count
        // and this insert — the same race `seedLegacyDisplay()` answers, on the same
        // first-request-after-a-deploy. The Brand exists, which is all this was for.
        if (firstBrandId($pdo) > 0) { return true; }

        $error = 'the first Brand could not be created: ' . $e->getMessage();
        return false;
    }
}

/**
 * What to call the Brand every existing sign is about to wear.
 *
 * Pure and separate so the self-test can put a long name, an empty one and a
 * missing constant through it — none of which this process can be in more than one
 * of at a time, which is §4o's reason for every other pure rule in this file.
 *
 * A name too long for the column falls back to the generic rather than being cut:
 * `substr()` counts bytes and the column counts characters, so trimming a name with
 * one multi-byte character in it can hand MySQL a string ending mid-character —
 * which is refused outright, turning a cosmetic problem into a Brand that was never
 * created. An admin renames this on the first visit either way.
 */
function firstBrandName()
{
    $fallback = 'Store Brand';
    if (!defined('SITE_NAME') || !is_string(SITE_NAME)) { return $fallback; }
    $name = trim(SITE_NAME);
    if ($name === '' || strlen($name) > 80) { return $fallback; }
    return $name;
}

/**
 * The Brand unbranded rows belong to: the oldest one. Returns 0 when there is none.
 *
 * Deliberately not "the one named after SITE_NAME" the way `legacyDisplayId()` looks
 * for its tag first. A tag is a URL contract a person may deliberately keep; a Brand
 * name is a label, and matching on it would hand every unbranded row to whichever
 * Brand happened to share the store's name after somebody renamed things.
 */
function firstBrandId(PDO $pdo)
{
    try {
        $id = $pdo->query("SELECT id FROM brands ORDER BY id ASC LIMIT 1")->fetchColumn();
        return $id ? intval($id) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Point every set of standards that predates Brands at the first one. */
function backfillBlockStyleBrand(PDO $pdo, &$error = null)
{
    $error   = '';
    $brandId = firstBrandId($pdo);
    if (!$brandId) {
        // Nothing to backfill to, which means the seed above did not manage to make
        // the first Brand. That is the failure worth reporting; this is its
        // consequence, and the tighten after it refuses for the same reason.
        $error = 'there is no Brand to hand the existing standards to';
        return false;
    }
    return schemaTry($pdo, "UPDATE block_styles SET brand_id = " . intval($brandId)
                         . " WHERE brand_id IS NULL", $error);
}

/** Point every sign that predates Brands at the first one. */
function backfillDisplayBrand(PDO $pdo, &$error = null)
{
    $error   = '';
    $brandId = firstBrandId($pdo);
    if (!$brandId) {
        $error = 'there is no Brand to hand the existing displays to';
        return false;
    }
    return schemaTry($pdo, "UPDATE displays SET brand_id = " . intval($brandId)
                         . " WHERE brand_id IS NULL", $error);
}

/**
 * Fill in any missing branded block type, for every Brand.
 *
 * Counts first: a read that finds them all costs less than the inserts, and unlike
 * an insert it takes no locks on a table the Brand Standards form may be saving to
 * at the same moment.
 *
 * Only the absent (Brand, type) pairs are written, computed rather than left to an
 * `INSERT IGNORE` — which MySQL and SQLite spell differently, so the ignoring form
 * was a statement the fixture could never execute and therefore never did (#11).
 * Existing rows are not touched: the store's own numbers win, and this only fills
 * gaps.
 */
function seedBlockStyles(PDO $pdo, &$error = null)
{
    $error = '';

    try {
        $brandIds = [];
        foreach ($pdo->query("SELECT id FROM brands ORDER BY id ASC")->fetchAll() as $row) {
            $brandIds[] = intval($row['id']);
        }
    } catch (Throwable $e) {
        $error = 'the brands table could not be read: ' . $e->getMessage();
        return false;
    }
    if (!$brandIds) {
        $error = 'there is no Brand to hold the branded block standards';
        return false;
    }

    try {
        $have = [];
        foreach ($pdo->query("SELECT brand_id, block_type FROM block_styles")->fetchAll() as $row) {
            $have[intval($row['brand_id']) . '|' . $row['block_type']] = true;
        }
    } catch (Throwable $e) {
        $error = 'block_styles could not be read: ' . $e->getMessage();
        return false;   // no table to count, and none to seed into
    }
    if (count($have) >= count($brandIds) * count(BrandStyles::STARTING_POINTS)) { return true; }

    $insert = "INSERT INTO block_styles
        (brand_id,block_type,font_family,font_size,font_color,font_weight,font_style,line_height)
        VALUES (?,?,?,?,?,?,?,?)";
    try {
        $stmt = $pdo->prepare($insert);
    } catch (Throwable $e) {
        $error = 'the branded block standards could not be seeded: ' . $e->getMessage();
        return false;
    }

    $ok = true;
    foreach ($brandIds as $brandId) {
        foreach (BrandStyles::STARTING_POINTS as $type => $values) {
            if (isset($have[$brandId . '|' . $type])) { continue; }
            try {
                $stmt->execute(array_merge([$brandId, $type], $values));
            } catch (Throwable $e) {
                // Carry on rather than stopping: one refused row must not leave the
                // other five types of that Brand — or every later Brand — unseeded,
                // since each missing row is its own silently-unsaveable form field.
                $error = 'a branded block standard could not be seeded: ' . $e->getMessage();
                $ok    = false;
            }
        }
    }
    return $ok;
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

    // The Brand the first sign wears. Supplied rather than left out, because on a
    // fresh install the CREATE TABLE above declares `brand_id NOT NULL` and there is
    // no default for it to fall back on. Seeded further up this same plan, so by here
    // it is there — and when it is not, saying so beats an insert failing on a
    // constraint whose name means nothing to whoever reads the alert.
    $brandId = firstBrandId($pdo);
    if (!$brandId) {
        $error = 'there is no Brand for the drive-thru Display to wear';
        return false;
    }

    try {
        $pdo->prepare(
            "INSERT INTO displays (tag, title, location, canvas_width, canvas_height, bg_type, bg_val, is_active, brand_id)
             VALUES (?, ?, NULL, 1920, 1080, ?, ?, 1, ?)"
        )->execute([LEGACY_DISPLAY_TAG, 'Drive-Thru', $bgType, $bgVal, $brandId]);
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

// ============================================================
// THE FIRST BUILD — running schema.sql, from PHP
// ============================================================
// Everything above this line converges a database that already exists. It cannot build
// one from nothing and is not meant to: `signageSchemaPlan()` creates `brands`,
// `workspace_themes`, `displays` and `display_permissions`, and every other statement in
// it alters a table it expects to find. `users`, `canvas_elements`, `assets`,
// `block_styles` and `password_resets` are created by `schema.sql` and by nothing else.
//
// Until there was an installer, "run schema.sql" meant phpMyAdmin's Import tab or a
// shell, and the app never had to read that file. Now it does, on the one request in an
// installation's life where the tables do not exist yet — so the file has to be split
// into statements, and splitting SQL on `;` is the kind of shortcut that works on every
// file somebody tests it against.
//
// `schema.sql` today holds two things that would break a naive split: `--` comments on
// their own lines throughout, and `COMMENT '…'` strings on a third of the columns. A
// semicolon inside either one is a statement boundary to a `explode(';')` and is not one
// to MySQL. Neither file has such a semicolon *today*, which is exactly why this is
// written as a scanner rather than a split: the day somebody writes
// `COMMENT 'the tag; the address'` the failure is a syntax error on a fresh install, on
// a machine nobody is watching, in the middle of creating the table every sign's layout
// lives in.

/**
 * One SQL script, split into the statements it is made of.
 *
 * Pure — script in, statements out — which is what lets the suite hand it the shapes a
 * real file will not have for another year. It tracks the four things that can hold a
 * semicolon and mean nothing by it: a single-quoted string, a double-quoted string, a
 * backtick-quoted identifier, and both spellings of comment. Escapes are handled the way
 * MySQL reads them: a backslash inside a quoted string escapes the next character, and a
 * doubled quote inside a string of the same kind is that character.
 *
 * Comments are dropped rather than carried, so a statement is what the engine is asked
 * and nothing else — a failure reported with its statement should print the statement
 * and not the paragraph above it. Trailing whitespace goes with them; an empty statement
 * (a stray `;`, or a file ending in one) is not returned at all, because `exec('')` is
 * an error on some engines and a no-op on others and neither is worth the branch.
 *
 * `DELIMITER` is deliberately not understood. It is a client instruction rather than SQL
 * — the server has never heard of it — and it exists for triggers and stored procedures,
 * neither of which this app has or should have. A script using it would be split wrongly
 * here, so `applySchemaScript()` refuses one outright rather than half-running it.
 *
 * @param string $script the file's contents
 * @return array statements, in order, each without its trailing semicolon
 */
function sqlStatements($script)
{
    $script = (string) $script;
    $length = strlen($script);
    $out    = [];
    $buffer = '';
    $quote  = '';      // the quote character we are inside, or ''
    $i      = 0;

    while ($i < $length) {
        $c = $script[$i];

        if ($quote !== '') {
            $buffer .= $c;
            if ($c === '\\' && $quote !== '`' && $i + 1 < $length) {
                // A backslash escapes the next character inside a quoted string — but
                // never inside a backtick identifier, where MySQL treats it literally.
                $buffer .= $script[$i + 1];
                $i += 2;
                continue;
            }
            if ($c === $quote) {
                // A doubled quote is that character, not the end of the string.
                if ($i + 1 < $length && $script[$i + 1] === $quote) {
                    $buffer .= $script[$i + 1];
                    $i += 2;
                    continue;
                }
                $quote = '';
            }
            $i++;
            continue;
        }

        // `-- ` and `#` run to the end of the line. MySQL requires whitespace after the
        // two dashes; `--x` is not a comment to it, and a rule that dropped the rest of
        // the line would silently delete SQL.
        if ($c === '-' && $i + 2 < $length && $script[$i + 1] === '-'
            && ($script[$i + 2] === ' ' || $script[$i + 2] === "\t" || $script[$i + 2] === "\n"
                || $script[$i + 2] === "\r")) {
            $end = strcspn($script, "\n", $i);
            $i  += $end;
            continue;
        }
        if ($c === '#') {
            $end = strcspn($script, "\n", $i);
            $i  += $end;
            continue;
        }
        if ($c === '/' && $i + 1 < $length && $script[$i + 1] === '*') {
            $close = strpos($script, '*/', $i + 2);
            $i = ($close === false) ? $length : $close + 2;
            continue;
        }

        if ($c === "'" || $c === '"' || $c === '`') {
            $quote   = $c;
            $buffer .= $c;
            $i++;
            continue;
        }

        if ($c === ';') {
            $statement = trim($buffer);
            if ($statement !== '') { $out[] = $statement; }
            $buffer = '';
            $i++;
            continue;
        }

        $buffer .= $c;
        $i++;
    }

    $statement = trim($buffer);
    if ($statement !== '') { $out[] = $statement; }
    return $out;
}

/**
 * Build a database from a script, and say which statements the engine refused.
 *
 * **Not `schemaTry()`, and the difference is the whole point.** Convergence swallows a
 * refusal because the request that triggered it is somebody looking at a page, and the
 * statement will be re-attempted on the next one. This runs once, on an empty database,
 * with an installer waiting for an answer — so a refusal here is the answer, and every
 * one of them is carried back with the database's own message and the statement that
 * drew it. A `CREATE TABLE` the user has no privilege for is the case this exists to
 * report: HANDOFF §5 records an install presenting as *"Base table or view not found"*
 * when the real fault was a `CREATE` the user could not issue, and the whole reason that
 * was hard to read is that nothing had printed the refusal.
 *
 * Statements are run in order and a failure does not stop the run. That is deliberate:
 * `schema.sql` creates nine tables and the foreign keys between them, so one refusal
 * usually causes several more, and a run that stopped at the first would report one
 * problem out of a set that is one problem. The installer prints them all and refuses to
 * continue, rather than continuing to the first admin over half a schema.
 *
 * @param string $script the contents of schema.sql
 * @param array  $failures out: one ['statement' => …, 'error' => …] per refusal
 * @return bool true when the engine accepted every statement
 */
function applySchemaScript(PDO $pdo, $script, array &$failures = [])
{
    $failures = [];

    // A client instruction this does not implement, refused rather than mis-split. See
    // sqlStatements(): the server has never heard of DELIMITER, and a script needing it
    // is a script with a stored procedure in it, which this app does not have.
    if (preg_match('/^\s*DELIMITER\b/mi', (string) $script)) {
        $failures[] = ['statement' => 'DELIMITER',
                       'error'     => 'this script sets a custom statement delimiter, which the '
                                    . 'installer does not read. Import it with phpMyAdmin or the '
                                    . 'mysql client instead.'];
        return false;
    }

    foreach (sqlStatements($script) as $statement) {
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            $failures[] = ['statement' => $statement, 'error' => $e->getMessage()];
        }
    }
    return !$failures;
}
