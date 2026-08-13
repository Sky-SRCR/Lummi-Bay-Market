<?php
// ============================================================
// TEST FIXTURE — the database the self-tests run against
// ============================================================
// The publish path is the one change in this project that can empty every sign
// at once, so the self-tests run the *real* modules against a database shaped
// like the live schema. Two engines, chosen by the environment:
//
//   (default)              an in-memory SQLite database, built by the DDL below.
//                          Needs nothing installed, runs in about a second, and
//                          is what you get from `php tools/selftest_layout.php`.
//
//   SELFTEST_MYSQL_DSN set  a real MySQL/MariaDB database, built by running
//                          schema.sql — the actual file a rebuild would use.
//
// SQLite proves the logic: scoping predicates, the staleness comparison, the
// section rules, sanitising, cascade behaviour. What it could never prove was
// anything that depends on being MySQL, and #48 counted twelve such divergences.
// The one that mattered was row locking: `SELECT … FOR UPDATE` has no SQLite
// equivalent, so the fixture stubbed it out and the lock the publish transaction
// takes before it deletes anything was the single most important line in the
// repo with no test over it at all. On MySQL that stub is gone (see
// newTestDisplayStore) and the real statement runs.
//
// Running schema.sql to build the MySQL fixture is deliberate and closes a second
// gap: nothing read that file, so a column missing from it failed silently on a
// future rebuild and nowhere else (invariant 15). Now the whole suite runs on
// what it produces, and a drift between schema.sql and lib/schema.php shows up
// here rather than on the sign.
//
// Still out of scope for both, and still tools/rehearse_phase1.php's job against
// a copy of live data: the convergence *statements*, which only have something to
// do on a database that lags the repo. schema.sql produces one that does not.
//
// CLI only, and never reachable from the browser (tools/.htaccess).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/displays.php';
require_once __DIR__ . '/../lib/layout_store.php';
require_once __DIR__ . '/../lib/grants.php';
require_once __DIR__ . '/../lib/display_request.php';
require_once __DIR__ . '/../lib/display_admin.php';
require_once __DIR__ . '/../lib/password_resets.php';
require_once __DIR__ . '/../lib/server_report.php';
require_once __DIR__ . '/../lib/accounts.php';
require_once __DIR__ . '/../lib/login_attempt.php';
require_once __DIR__ . '/../lib/request_scheme.php';
require_once __DIR__ . '/../lib/error_policy.php';   // pulls in lib/alerts.php
require_once __DIR__ . '/../lib/assets.php';
require_once __DIR__ . '/../lib/upload_limits.php';
require_once __DIR__ . '/../lib/http_reply.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/branding.php';
require_once __DIR__ . '/../lib/color_audit.php';
require_once __DIR__ . '/../lib/markup.php';

// ---- Which engine is under the suite ----------------------------------------

/**
 * The MySQL DSN to run against, or null for the in-memory SQLite default.
 *
 * A DSN rather than a flag because CI, a developer's laptop and a throwaway
 * container all name the database differently, and none of them should have to
 * edit this file to say so.
 */
function testMysqlDsn()
{
    $dsn = getenv('SELFTEST_MYSQL_DSN');
    return ($dsn === false || $dsn === '') ? null : $dsn;
}

function testIsMysql()   { return testMysqlDsn() !== null; }
function testEngineName(){ return testIsMysql() ? 'MySQL' : 'SQLite'; }

/**
 * DisplayStore with its one non-portable statement swapped out — SQLite only.
 *
 * `SELECT … FOR UPDATE` has no SQLite equivalent. Replacing that single method
 * leaves every other statement in the publish path — the deletes, the inserts,
 * the scoping predicates, the stamp arithmetic — exactly the code that runs in
 * production. Nothing else is stubbed.
 *
 * This class must never be constructed directly by a test. Ask
 * newTestDisplayStore(), which hands back the *real* store on MySQL, so the row
 * lock the publish transaction depends on is genuinely taken there.
 */
class TestDisplayStore extends DisplayStore
{
    private $testPdo;

    public function __construct(PDO $pdo)
    {
        parent::__construct($pdo);
        $this->testPdo = $pdo;
    }

    public function lockLayoutRevision(Display $display)
    {
        $stmt = $this->testPdo->prepare("SELECT layout_revision FROM displays WHERE id = ?");
        $stmt->execute([$display->id()]);
        return $stmt->fetchColumn();
    }
}

/**
 * The store the rest of the fixture builds on: real on MySQL, stubbed on SQLite.
 *
 * One function so that "is the row lock real in this run?" has a single answer,
 * rather than each factory below deciding for itself and one of them being missed.
 */
function newTestDisplayStore(PDO $pdo)
{
    return testIsMysql() ? new DisplayStore($pdo) : new TestDisplayStore($pdo);
}

/** The real LayoutStore, wired to whichever DisplayStore this engine allows. */
function newTestLayoutStore(PDO $pdo)
{
    return new LayoutStore($pdo, newTestDisplayStore($pdo));
}

/** The real AccountAdmin over the real stores — nothing about it is stubbed. */
function newTestAccountAdmin(PDO $pdo)
{
    return new AccountAdmin($pdo, new AccountStore($pdo), new GrantStore($pdo), newTestDisplayStore($pdo));
}

/**
 * Add an account the fixture did not seed, and return its id.
 *
 * password_hash is written explicitly rather than left to a column default: the
 * SQLite fixture below gives it one, schema.sql deliberately does not, and MySQL
 * in strict mode refuses the insert rather than inventing an empty string.
 */
function makeTestAccount(PDO $pdo, $username, $role = 'basic')
{
    $pdo->prepare("INSERT INTO users (username, email, role, password_hash) VALUES (?, ?, ?, '')")
        ->execute([$username, $username . '@example.test', $role]);
    return intval($pdo->lastInsertId());
}

/** The real DisplayAdmin over the real stores — nothing about it is stubbed. */
function newTestDisplayAdmin(PDO $pdo)
{
    $displays = newTestDisplayStore($pdo);
    return new DisplayAdmin($pdo, $displays, new LayoutStore($pdo, $displays),
                            new GrantStore($pdo), new BrandStore($pdo));
}

/**
 * An Actor built the way the app builds one: from an account row plus whatever
 * grants that account actually holds. Going through GrantStore rather than
 * Actor::withGrants() is the point — it is the reading of the grants that the
 * self-test wants covered, not just the deciding.
 */
function newTestActor(PDO $pdo, $accountId, $role)
{
    return Actor::signedIn(
        ['id' => $accountId, 'username' => 'account' . $accountId, 'role' => $role],
        new GrantStore($pdo)
    );
}

/** Grant one account one Display, without going through the admin use case. */
function grantTestAccess(PDO $pdo, $displayId, $accountId)
{
    (new GrantStore($pdo))->grant($displayId, $accountId);
}

/**
 * A fresh database with the live structure and one admin + one basic account.
 *
 * Whichever engine this run is on. Tests call this and stay engine-agnostic; the
 * two that cannot — the ones that need a catalogue which *disagrees* with the
 * tables — call newSqliteTestDb() and say why.
 */
function newTestDb()
{
    return testIsMysql() ? newMysqlTestDb() : newSqliteTestDb();
}

/**
 * The two seed accounts every test starts from: account 1 admin, account 2 basic.
 *
 * Shared by both engines so a fixture cannot drift into meaning something
 * different depending on what it is running against.
 */
function seedTestAccounts(PDO $pdo)
{
    $pdo->exec("INSERT INTO users (username, email, role, password_hash) VALUES
        ('sky','sky@example.test','admin',''), ('clerk','clerk@example.test','basic','')");
}

/** A fresh in-memory SQLite database with the live structure. */
function newSqliteTestDb()
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA foreign_keys = ON");   // so ON DELETE CASCADE is real here too

    // is_active mirrors the live column: the session sync reads it on every
    // authenticated request, so a fixture without it cannot test that a
    // deactivated account's open tab stops working.
    // The three lockout columns and closed_at are here because convergence adds
    // them to the live table at runtime, so a fixture without them is not shaped
    // like the live schema — and the server report's whole job is to notice a column
    // that never applied. A fixture that is missing one by accident would make that
    // report look broken while it was working correctly.
    // password_hash is here because a password reset writes it, and a fixture
    // without it cannot tell a reset that changed the password from one that only
    // said it had.
    // username and email are UNIQUE because they are UNIQUE on the live table, and
    // one use case depends on it: AccountAdmin::edit() reports the whole change as
    // failed when the email is already somebody else's, and it has to be able to
    // *fail* to prove that the role and the grants went back with it. A fixture
    // without the constraint would have made that check assert a rollback that never
    // had anything to roll back.
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL DEFAULT '',
        role TEXT NOT NULL DEFAULT 'basic',
        is_active INTEGER NOT NULL DEFAULT 1,
        failed_attempts INTEGER NOT NULL DEFAULT 0,
        last_failed_at TEXT DEFAULT NULL,
        locked_until TEXT DEFAULT NULL,
        email TEXT NOT NULL DEFAULT '' UNIQUE,
        closed_at TEXT DEFAULT NULL
    )");

    $pdo->exec("CREATE TABLE displays (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tag TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        location TEXT,
        canvas_width INTEGER NOT NULL,
        canvas_height INTEGER NOT NULL,
        bg_type TEXT NOT NULL DEFAULT 'color',
        bg_val TEXT NOT NULL DEFAULT '#1a1a2e',
        is_active INTEGER NOT NULL DEFAULT 1,
        layout_revision INTEGER NOT NULL DEFAULT 0,
        last_published_at TEXT,
        last_published_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        lock_holder_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        lock_taken_at TEXT,
        lock_activity_at TEXT,
        -- NOT NULL with no default, exactly as the live column is declared. A default
        -- of 1 here would let every insert that forgets a Brand pass, which is the one
        -- thing this column exists to stop.
        brand_id INTEGER NOT NULL REFERENCES brands(id),
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE display_permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        display_id INTEGER NOT NULL REFERENCES displays(id) ON DELETE CASCADE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (display_id, user_id)
    )");

    // The reset-token table, minus the AUTO_INCREMENT spelling. `attempts` is
    // the guess budget the self-test cares about; it is created here rather than
    // added by ResetTokenStore::ensureSchema() so the tests exercise the shape a
    // converged live database actually has.
    $pdo->exec("CREATE TABLE password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        passcode TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        used INTEGER NOT NULL DEFAULT 0,
        attempts INTEGER NOT NULL DEFAULT 0
    )");

    $pdo->exec("CREATE TABLE assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        content TEXT NOT NULL,
        label TEXT,
        auto_pooled INTEGER NOT NULL DEFAULT 0
    )");

    // Keyed on (brand_id, block_type), like the live table since ADR-0011. The
    // composite PRIMARY KEY is the shape that matters: a suite running against the
    // old single-column key would let two Brands' `price` rows collide and never
    // notice, which is the whole thing the re-key is for.
    $pdo->exec("CREATE TABLE brands (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        logo_asset_id INTEGER REFERENCES assets(id) ON DELETE SET NULL,
        bg_type TEXT NOT NULL DEFAULT 'color',
        bg_val TEXT NOT NULL DEFAULT '#1a1a2e',
        palette_1 TEXT, palette_2 TEXT, palette_3 TEXT,
        palette_4 TEXT, palette_5 TEXT, palette_6 TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE block_styles (
        brand_id INTEGER NOT NULL REFERENCES brands(id) ON DELETE CASCADE,
        block_type TEXT NOT NULL,
        font_family TEXT, font_size INTEGER, font_color TEXT,
        font_weight TEXT, font_style TEXT, line_height REAL,
        PRIMARY KEY (brand_id, block_type)
    )");

    $pdo->exec("CREATE TABLE canvas_elements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        display_id INTEGER NOT NULL REFERENCES displays(id) ON DELETE CASCADE,
        section_id INTEGER REFERENCES canvas_elements(id) ON DELETE CASCADE,
        type TEXT NOT NULL,
        block_subtype TEXT DEFAULT 'free',
        x_pos INTEGER NOT NULL DEFAULT 0,
        y_pos INTEGER NOT NULL DEFAULT 0,
        width INTEGER NOT NULL DEFAULT 200,
        height INTEGER NOT NULL DEFAULT 100,
        manual_content TEXT,
        asset_id INTEGER REFERENCES assets(id) ON DELETE SET NULL,
        section_bg TEXT,
        font_family TEXT DEFAULT 'Arial',
        font_size INTEGER DEFAULT 16,
        font_color TEXT DEFAULT '#000000',
        font_weight TEXT DEFAULT 'normal',
        font_style TEXT DEFAULT 'normal',
        line_height REAL DEFAULT 1.40,
        locked INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        text_align TEXT NOT NULL DEFAULT '',
        z_index INTEGER NOT NULL DEFAULT 1,
        hidden INTEGER NOT NULL DEFAULT 0
    )");

    // The Brand every fixture Display wears. Id 1, matching what schema.sql seeds
    // and what convergence creates, so a test that hardcodes a brand_id and one that
    // reads it off a Display agree.
    $pdo->exec("INSERT INTO brands (id, name) VALUES (1, 'Test Brand')");

    // All six branded types, matching BrandStyles::STARTING_POINTS. One row was
    // enough while the only question was "does a snapshot carry typography"; it is
    // not enough to test that a save leaves the types it was not given alone.
    $pdo->exec("INSERT INTO block_styles (brand_id,block_type,font_family,font_size,font_color,font_weight,font_style,line_height) VALUES
        (1,'section_header','Arial',36,'#ffffff','bold','normal',1.30),
        (1,'item_title',    'Arial',24,'#ffffff','bold','normal',1.30),
        (1,'item_title_2',  'Arial',24,'#27ae60','bold','normal',1.30),
        (1,'price',         'Arial',30,'#e74c3c','bold','normal',1.20),
        (1,'price_2',       'Arial',30,'#e74c3c','bold','normal',1.20),
        (1,'description',   'Arial',16,'#bdc3c7','normal','normal',1.40)");

    seedTestAccounts($pdo);

    return $pdo;
}

/**
 * A fresh MySQL database with the live structure, built by running schema.sql.
 *
 * A whole database per call, not a cleaned-out one. The suite builds 63 fixtures
 * and several of them are alive at the same time on purpose — the scoping checks
 * hold one database while a second proves a rename cannot reach into it — so a
 * shared connection that wiped itself on each call would quietly delete the rows
 * an earlier `$pdo` was still being asserted against. SQLite gives every
 * `sqlite::memory:` its own database for free, and this is the MySQL equivalent.
 *
 * Databases are named from the one in the DSN plus this process's pid, so two
 * runs on one server cannot collide, and all of them are dropped on the way out.
 *
 * PDO is configured exactly as db_connect.php configures it in production —
 * ERRMODE_EXCEPTION, FETCH_ASSOC, and emulated prepares OFF. That last one is not
 * cosmetic: with emulation on, every column comes back as a string, and a suite
 * full of `checkSame(1, $row['hidden'])` would be asserting against a fixture
 * that behaves differently from the app it is testing.
 */
function newMysqlTestDb()
{
    static $n = 0;

    $name = testMysqlDbName() . '_t' . getmypid() . '_' . (++$n);
    $admin = testMysqlAdminConnection();
    $admin->exec("DROP DATABASE IF EXISTS `$name`");
    $admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $GLOBALS['_testMysqlDbs'][] = $name;

    $pdo = new PDO(testMysqlDsnFor($name), getenv('SELFTEST_MYSQL_USER') ?: null,
                   getenv('SELFTEST_MYSQL_PASS') ?: null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    foreach (sqlStatements(file_get_contents(__DIR__ . '/../schema.sql')) as $sql) {
        $pdo->exec($sql);
    }
    seedTestAccounts($pdo);

    return $pdo;
}

/**
 * A *second* connection to the database `newMysqlTestDb()` most recently made.
 *
 * There is exactly one thing this exists for, and it cannot be faked: two publishes
 * colliding on the same Display row (#35). A row lock is only a row lock across
 * connections — the same PDO handle re-entering its own transaction waits for
 * nothing — so proving that the second publish gives up cleanly rather than being
 * killed by PHP's time limit needs a genuine second session.
 *
 * MySQL only, and it says so rather than returning something that looks usable:
 * SQLite's fixture is `:memory:`, which no second connection can even reach.
 */
function secondConnectionToLatestTestDb()
{
    if (!testIsMysql()) {
        throw new RuntimeException('a second connection needs the MySQL leg (SELFTEST_MYSQL_DSN)');
    }
    if (!$GLOBALS['_testMysqlDbs']) {
        throw new RuntimeException('no test database has been created yet');
    }
    $name = $GLOBALS['_testMysqlDbs'][count($GLOBALS['_testMysqlDbs']) - 1];

    return new PDO(testMysqlDsnFor($name), getenv('SELFTEST_MYSQL_USER') ?: null,
                   getenv('SELFTEST_MYSQL_PASS') ?: null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

$GLOBALS['_testMysqlDbs'] = [];

register_shutdown_function(function () {
    if (!testIsMysql() || !$GLOBALS['_testMysqlDbs']) { return; }
    try {
        $admin = testMysqlAdminConnection();
        foreach ($GLOBALS['_testMysqlDbs'] as $name) {
            $admin->exec("DROP DATABASE IF EXISTS `$name`");
        }
    } catch (Throwable $e) {
        // A server that has gone away has already taken the throwaway databases
        // with it, and a shutdown handler is the wrong place to fail a run.
    }
});

/** The connection used to create and drop the throwaway databases. */
function testMysqlAdminConnection()
{
    static $admin = null;
    if ($admin === null) {
        $admin = new PDO(testMysqlDsn(), getenv('SELFTEST_MYSQL_USER') ?: null,
                         getenv('SELFTEST_MYSQL_PASS') ?: null,
                         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return $admin;
}

/** The `dbname` out of the configured DSN — the stem every fixture is named from. */
function testMysqlDbName()
{
    if (preg_match('/(?:^|;)dbname=([^;]+)/', testMysqlDsn(), $m)) { return $m[1]; }
    return 'lbm_selftest';
}

/** The configured DSN pointed at a different database. */
function testMysqlDsnFor($name)
{
    $dsn = testMysqlDsn();
    if (preg_match('/(?:^|;)dbname=([^;]+)/', $dsn)) {
        return preg_replace('/((?:^|;)dbname=)[^;]+/', '${1}' . $name, $dsn);
    }
    return rtrim($dsn, ';') . ';dbname=' . $name;
}

/**
 * Split a .sql file into executable statements.
 *
 * Naive splitting on ";" is wrong for this file and quietly so: two of the
 * column COMMENTs in schema.sql contain a semicolon ('Owning Display; every
 * query is scoped by this'), so a plain explode cuts a CREATE TABLE in half and
 * the error names a syntax problem that is not there. Quote state is tracked,
 * `--` line comments are dropped, and `\\` escapes inside a string are honoured.
 */
function sqlStatements($sql)
{
    $lines = [];
    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) { continue; }
        $lines[] = $line;
    }
    $sql = implode("\n", $lines);

    $statements = [];
    $current    = '';
    $inString   = false;
    $quote      = '';

    for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
        $c = $sql[$i];

        if ($inString) {
            $current .= $c;
            if ($c === '\\' && $i + 1 < $n) { $current .= $sql[++$i]; continue; }
            if ($c === $quote) { $inString = false; }
            continue;
        }

        if ($c === "'" || $c === '"') { $inString = true; $quote = $c; $current .= $c; continue; }

        if ($c === ';') {
            if (trim($current) !== '') { $statements[] = trim($current); }
            $current = '';
            continue;
        }

        $current .= $c;
    }

    if (trim($current) !== '') { $statements[] = trim($current); }
    return $statements;
}

/**
 * What `information_schema` reports for a fully converged live database.
 *
 * Written out by hand, and it has to be: the statements convergence issues are
 * MySQL-only, so a SQLite fixture can never execute them and can never be asked
 * what they produced. This is the *expectation* — "after every ALTER has landed,
 * the catalogue says this" — which is the same reason `ServerReport::convergence()`
 * lists its columns rather than deriving them. A derived list agrees with the
 * database by construction and proves nothing.
 *
 * Only the columns, indexes and constraints the plan actually consults are here.
 * A converged `canvas_elements` has more columns than these six; none of them
 * changes a decision, and listing them would suggest they did. Take something away
 * from a copy of this shape and the plan should ask for exactly that thing back.
 */
function convergedSchemaShape()
{
    $col = function ($type, $nullable = false) {
        return ['type' => $type, 'nullable' => $nullable];
    };

    return [
        'columns' => [
            'canvas_elements' => [
                'id'            => $col('int(11)'),
                'type'          => $col(SCHEMA_ELEMENT_TYPE_ENUM),
                'block_subtype' => $col(SCHEMA_BLOCK_SUBTYPE_ENUM, true),
                'text_align'    => $col('varchar(16)'),
                'z_index'       => $col('int(11)'),
                'hidden'        => $col('tinyint(1)'),
                'display_id'    => $col('int(11)'),
            ],
            'assets'   => ['id' => $col('int(11)'), 'auto_pooled' => $col('tinyint(1)')],
            'displays' => [
                'id'            => $col('int(11)'),
                'lock_taken_at' => $col('datetime', true),
                // Not nullable, which is the fact the tighten is gated on. A shape
                // carrying it as nullable would keep the MODIFY in a converged plan.
                'brand_id'      => $col('int(11)'),
            ],
            'display_permissions' => ['id' => $col('int(11)')],
            'block_styles'        => [
                'block_type' => $col('varchar(50)'),
                'brand_id'   => $col('int(11)'),
            ],
            // One column is enough to say the table is there, which is all
            // needsTableCreate() asks. `brands` is created whole by one statement,
            // so there is no ALTER for a missing column of it to answer.
            'brands'              => ['id' => $col('int(11)')],
            // The three ADR-0001 lockout columns are part of a converged shape as
            // of the day they became gated plan entries rather than three ALTERs
            // fired from the pre-auth login page. A shape without them would make
            // the "a converged database is issued no DDL" check pass for the wrong
            // reason — by never asking.
            'users'               => [
                'id'              => $col('int(11)'),
                'failed_attempts' => $col('int(11)'),
                'last_failed_at'  => $col('datetime', true),
                'locked_until'    => $col('datetime', true),
                'closed_at'       => $col('datetime', true),
            ],
        ],
        'indexes' => [
            'canvas_elements' => ['PRIMARY' => true, 'display_id' => true],
            'displays'        => ['PRIMARY' => true, 'tag' => true, 'brand_id' => true],
            // Spelled out as columns rather than `true`, because this is the one key
            // whose *columns* a gate reads: the re-key from `block_type` alone is
            // skipped only when the catalogue says the key is already these two, in
            // this order. `true` here would mean "cannot tell", and the plan would
            // keep asking for a DROP/ADD PRIMARY KEY on every request.
            'block_styles'    => ['PRIMARY' => ['brand_id', 'block_type']],
        ],
        'constraints' => [
            'canvas_elements'     => ['canvas_elements_ibfk_3' => true],
            'displays'            => ['displays_ibfk_1' => true, 'displays_ibfk_2' => true,
                                      'displays_ibfk_3' => true],
            'display_permissions' => ['display_permissions_ibfk_1' => true,
                                      'display_permissions_ibfk_2' => true],
            'brands'              => ['brands_ibfk_1' => true],
            'block_styles'        => ['block_styles_ibfk_1' => true],
        ],
    ];
}

/** A SchemaFacts from a shape, converged or deliberately damaged. */
function schemaFactsFrom(array $shape)
{
    return SchemaFacts::of($shape['columns'], $shape['indexes'], $shape['constraints']);
}

/**
 * A SQLite database wearing MySQL's catalogue, so `readSchemaFacts()` can be run
 * for real instead of trusted.
 *
 * SQLite will attach a second database under any name, `information_schema`
 * included, and `sqliteCreateFunction` supplies the `DATABASE()` the queries call.
 * That means the actual query text is executed here — the table names, the column
 * aliases, the `IN` list, the YES/NO of `IS_NULLABLE`. A renamed alias or a typo in
 * one of the three catalogue tables would otherwise show up first on the live
 * server, as a database that had silently stopped converging.
 *
 * What it does not prove is that MySQL's catalogue says what this pretends it says.
 * Only tools/rehearse_phase1.php can settle that, against a copy of live data.
 *
 * Pass an existing database as $onto to give the real fixture tables a catalogue
 * that disagrees with them — which is how the case worth worrying about is built:
 * a column that is really there, on a table the catalogue never mentioned.
 *
 * SQLite only, in both forms, and unavoidably: making a catalogue lie is the whole
 * technique, and MySQL's information_schema cannot be made to. A caller passing
 * $onto must therefore build it with newSqliteTestDb() rather than newTestDb(),
 * even on a MySQL run — which is checked below rather than left as a convention,
 * because the failure otherwise is a confusing one about ATTACH.
 */
function fakeCatalogue(array $shape, PDO $onto = null)
{
    if ($onto !== null && $onto->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        throw new RuntimeException(
            'fakeCatalogue() needs a SQLite database — build this one with newSqliteTestDb().');
    }

    $pdo = $onto;
    if ($pdo === null) {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    $pdo->exec("ATTACH DATABASE ':memory:' AS information_schema");
    $pdo->sqliteCreateFunction('DATABASE', function () { return 'lbm'; }, 0);

    $pdo->exec("CREATE TABLE information_schema.COLUMNS
                (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT,
                 COLUMN_TYPE TEXT, IS_NULLABLE TEXT)");
    // COLUMN_NAME and SEQ_IN_INDEX are here because the real query reads them: a
    // composite key is its columns *in order*, and the `block_styles` re-key is gated
    // on the PRIMARY being exactly (brand_id, block_type). A catalogue fixture that
    // only carried index names could not execute that query at all, which is the
    // whole reason this fake exists rather than a hand-built SchemaFacts.
    $pdo->exec("CREATE TABLE information_schema.STATISTICS
                (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT,
                 COLUMN_NAME TEXT, SEQ_IN_INDEX INTEGER)");
    $pdo->exec("CREATE TABLE information_schema.TABLE_CONSTRAINTS
                (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, CONSTRAINT_NAME TEXT)");

    $col = $pdo->prepare("INSERT INTO information_schema.COLUMNS VALUES ('lbm',?,?,?,?)");
    foreach ($shape['columns'] as $table => $columns) {
        foreach ($columns as $name => $spec) {
            $col->execute([$table, $name, $spec['type'], !empty($spec['nullable']) ? 'YES' : 'NO']);
        }
    }
    // An index given as `true` carries one row naming the index and no column, which
    // is what MySQL could never report — but the shape is what a caller wrote, and a
    // caller who did not say which columns has to read back as "cannot tell" rather
    // than as a confident empty list. A list of columns becomes one row each, numbered
    // from 1, exactly as SEQ_IN_INDEX is.
    $ix = $pdo->prepare("INSERT INTO information_schema.STATISTICS VALUES ('lbm',?,?,?,?)");
    foreach ($shape['indexes'] as $table => $names) {
        foreach ($names as $name => $columns) {
            if (!is_array($columns)) { $columns = [$name]; }
            $seq = 0;
            foreach ($columns as $column) { $ix->execute([$table, $name, $column, ++$seq]); }
        }
    }
    $ct = $pdo->prepare("INSERT INTO information_schema.TABLE_CONSTRAINTS VALUES ('lbm',?,?)");
    foreach ($shape['constraints'] as $table => $names) {
        foreach (array_keys($names) as $name) { $ct->execute([$table, $name]); }
    }
    return $pdo;
}

/** The plan for a shape, in one step. */
function schemaPlanFor(array $shape)
{
    return signageSchemaPlan(schemaFactsFrom($shape));
}

/** Just the statements a plan would run, whitespace flattened for comparing. */
function planStatements(array $plan)
{
    $out = [];
    foreach ($plan as $entry) {
        if (isset($entry['sql'])) { $out[] = preg_replace('/\s+/', ' ', trim($entry['sql'])); }
    }
    return $out;
}

/** Just the named row-reading steps, in the order the plan puts them. */
function planSteps(array $plan)
{
    $out = [];
    foreach ($plan as $entry) {
        if (isset($entry['step'])) { $out[] = $entry['step']; }
    }
    return $out;
}

/** Every entry's `why` in order — statements and steps together, so order is testable. */
function planOrder(array $plan)
{
    $out = [];
    foreach ($plan as $entry) { $out[] = $entry['why']; }
    return $out;
}

/** True when any statement in the plan mentions the given fragment. */
function planWants(array $plan, $fragment)
{
    foreach (planStatements($plan) as $sql) {
        if (strpos($sql, $fragment) !== false) { return true; }
    }
    return false;
}

/**
 * @param int $brandId which Brand the sign wears. Defaults to 1, the Brand every
 *                     fixture seeds — a test that does not care about Brands should
 *                     not have to name one, and a test that does passes a second id.
 */
function makeTestDisplay(PDO $pdo, $tag, $title = 'Sign', $w = 1920, $h = 1080, $brandId = 1)
{
    $pdo->prepare("INSERT INTO displays (tag,title,canvas_width,canvas_height,brand_id) VALUES (?,?,?,?,?)")
        ->execute([$tag, $title, $w, $h, $brandId]);
    return loadTestDisplay($pdo, $pdo->lastInsertId());
}

/**
 * A `brands` row and nothing else — no standards behind it.
 *
 * The state convergence's seed and `BrandAdmin::create()` both exist to prevent, so
 * a test that wants to prove either of them has to be able to produce it.
 */
function makeTestBrandRow(PDO $pdo, $name)
{
    $pdo->prepare("INSERT INTO brands (name) VALUES (?)")->execute([$name]);
    return intval($pdo->lastInsertId());
}

/**
 * A second Brand, complete with its six sets of standards.
 *
 * The fixture seeds one Brand, which is enough for every test that is not about
 * Brands and useless for every test that is: one Brand cannot show that two do not
 * share a row. This is what the re-key is proved with.
 */
function makeTestBrand(PDO $pdo, $name, array $overrides = [])
{
    $brandId = makeTestBrandRow($pdo, $name);
    (new BrandStyles($pdo))->seedFor($brandId);

    foreach ($overrides as $type => $fields) {
        (new BrandStyles($pdo))->save($brandId, [$type => $fields]);
    }
    return $brandId;
}

/**
 * Load a Display exactly the way the app does.
 *
 * Through the store, deliberately, rather than with a SELECT of its own. This
 * function used to carry its own copy of that query and it drifted: the store
 * learned to join the lock holder's `is_active` and `role`, this did not, and so
 * every test loading a Display through here was handed a row missing the columns
 * two rules are decided from. Those rules then read their absent-means-unknown
 * defaults and the tests agreed with themselves — which is the exact failure mode
 * `tools/rehearse_phase1.php` was rewritten to stop doing (#14).
 *
 * A Display is not the kind of object a test should assemble by hand. There is one
 * query that builds one, it lives in DisplayStore, and this is a shortcut to it.
 */
function loadTestDisplay(PDO $pdo, $id)
{
    return newTestDisplayStore($pdo)->forId(intval($id));
}

/**
 * Push a held lock's last interaction into the past, so the idle window can be
 * tested without waiting a quarter of an hour.
 *
 * The honest way to do it: LockState decides lapsed-or-held by comparing that column
 * to the clock, so ageing the column is exactly what fifteen quiet minutes would
 * have done. Nothing about the lock rules is stubbed.
 */
function ageTestLock(PDO $pdo, $displayId, $seconds)
{
    $pdo->prepare("UPDATE displays SET lock_activity_at = ? WHERE id = ?")
        // gmdate, matching what DisplayStore writes. Local time here would agree
        // with a UTC container by accident and hide the bug this mirrors.
        ->execute([gmdate('Y-m-d H:i:s', time() - intval($seconds)), intval($displayId)]);
}

/**
 * Push an issued passcode's expiry into the past, so the 30-minute lifetime can
 * be tested without waiting for it. gmdate, matching what the store writes.
 */
function expireTestResetToken(PDO $pdo, $accountId)
{
    $pdo->prepare("UPDATE password_resets SET expires_at = ? WHERE user_id = ?")
        ->execute([gmdate('Y-m-d H:i:s', time() - 60), intval($accountId)]);
}

/**
 * Make one table refuse every insert, whichever engine this is.
 *
 * A trigger rather than dropping the table, because the tables this is used on are
 * referenced by others: dropping `assets` would fail the element insert too and
 * prove nothing about the pool write specifically. The two engines spell "refuse
 * this" quite differently — SQLite raises, MySQL signals a SQLSTATE — and what is
 * under test is how the app handles a refusal, not how the refusal was worded.
 */
function makeTableUnwritable(PDO $pdo, $table)
{
    $table   = preg_replace('/[^a-z_]/', '', $table);
    $trigger = 'no_writes_' . $table;

    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON $table
                    BEGIN SELECT RAISE(ABORT, '$table is read-only'); END");
    } else {
        $pdo->exec("CREATE TRIGGER $trigger BEFORE INSERT ON $table
                    FOR EACH ROW SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = '$table is read-only'");
    }
}

/**
 * Switch referential integrity off and on, whichever engine this is.
 *
 * Used by exactly one check, and it earns its place: it proves the app deletes a
 * section's children itself rather than leaning on ON DELETE CASCADE — which
 * matters because the constraint that would do it is one lib/schema.php never
 * converges, so a live database that predates schema.sql does not have it.
 */
function setTestForeignKeys(PDO $pdo, $on)
{
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) {
        $pdo->exec("PRAGMA foreign_keys = " . ($on ? 'ON' : 'OFF'));
    } else {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = " . ($on ? '1' : '0'));
    }
}

/** How many reset tokens exist for one account, spent or not. */
function resetTokenCount(PDO $pdo, $accountId)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE user_id = ?");
    $stmt->execute([intval($accountId)]);
    return intval($stmt->fetchColumn());
}

function elementsOf(PDO $pdo, $displayId)
{
    $stmt = $pdo->prepare("SELECT * FROM canvas_elements WHERE display_id = ? ORDER BY id ASC");
    $stmt->execute([intval($displayId)]);
    return $stmt->fetchAll();
}

/** Every element row, whatever Display it belongs to — for proving nothing leaked. */
function allElements(PDO $pdo)
{
    return $pdo->query("SELECT * FROM canvas_elements ORDER BY id ASC")->fetchAll();
}

/** Every grant row — for proving a destroyed Display took its grants with it. */
function allGrants(PDO $pdo)
{
    return $pdo->query("SELECT display_id, user_id FROM display_permissions
                        ORDER BY display_id ASC, user_id ASC")->fetchAll();
}

/**
 * AlertMailer with the one line that reaches the outside world replaced.
 *
 * What is worth testing about an alerter is who it writes to and how often — the
 * rate limiter especially, because failing open there means one email per Screen
 * per poll. `mail()` on a machine with no MTA proves none of that and can block
 * for as long as the host's sendmail takes to give up.
 */
class TestAlertMailer extends AlertMailer
{
    public $sent = [];

    protected function deliver($to, $subject, $body, $headers)
    {
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];
        return true;
    }
}

/**
 * A BrandingConfig whose write to the temporary file comes up short — a full disk,
 * a quota, the request killed mid-write. It is the failure the whole swap-in-place
 * design exists for, and nothing else can reach it: `file_put_contents` cannot be
 * made to run out of room on demand, so the seam is `putTemp()` and this is why it
 * is `protected` rather than inlined.
 *
 * What the test asserts around it is not that the save fails. It is that the file
 * every page of the app requires is exactly as complete afterwards as it was
 * before.
 */
class ShortWriteBrandingConfig extends BrandingConfig
{
    /** Where the replacement was being built — the only way to see it from outside. */
    public $lastTemp = '';

    protected function putTemp($temp, $php)
    {
        $this->lastTemp = $temp;
        return parent::putTemp($temp, substr($php, 0, intdiv(strlen($php), 2)));
    }
}

/**
 * A BrandingConfig whose eight settings are whatever the test says they are.
 *
 * `current()` reads the constants this process loaded, and in a self-test run those
 * are all eight defaults — so "the save kept what was in force" and "the save wrote
 * the defaults" are the same bytes, and a test cannot tell a merge from a reset.
 * Pinning them apart is the only way to see the difference that decision #36's
 * eight-positional-argument call got wrong.
 */
class PinnedBrandingConfig extends BrandingConfig
{
    private $pinned;

    public function __construct($dir, array $pinned)
    {
        parent::__construct($dir);
        $this->pinned = $pinned;
    }

    public function current()
    {
        return $this->pinned;
    }
}

/**
 * A throwaway directory for the log and the alert stamps, removed when the run
 * ends. Not the app's own `logs/` — a self-test that writes into the deployment
 * it is testing has changed the thing it is measuring.
 */
function newTestStateDir()
{
    $dir = sys_get_temp_dir() . '/lbm-selftest-' . getmypid() . '-' . count($GLOBALS['_testStateDirs']);
    // Emptied rather than merely created. The name is keyed on the process id, and a
    // container reuses those freely — so a directory left behind by an earlier run
    // was inherited whole by a later one. That is not a tidiness problem: the install
    // paths section asserts *which* credentials file is found, and finding one this
    // run never wrote made it fail; a check that reads a stale file can pass for the
    // wrong reason just as easily.
    removeTestDirTree($dir);
    @mkdir($dir, 0700, true);
    $GLOBALS['_testStateDirs'][] = $dir;
    return $dir;
}

/**
 * Delete a directory and everything under it, at any depth.
 *
 * The cleanup below used to be one level deep, and the install paths tests write
 * `private/db_credentials.php` — a file inside a subdirectory. `rmdir` on a
 * non-empty `private/` failed, so `rmdir` on the parent failed too, and every run of
 * the suite left a directory in /tmp for ever. 429 of them had accumulated by the
 * time one collided with a reused process id and failed a check that had nothing to
 * do with the change being tested.
 */
function removeTestDirTree($dir)
{
    if (!@is_dir($dir)) { return; }
    // Two globs: `*` does not match a leading dot, and the branding swap's temporary
    // file is deliberately hidden. A missed dotfile leaves the rmdir failing.
    $entries = array_merge((array)@glob($dir . '/*'), (array)@glob($dir . '/.[!.]*'));
    foreach ($entries as $entry) {
        if (@is_dir($entry)) { removeTestDirTree($entry); } else { @unlink($entry); }
    }
    @rmdir($dir);
}

$GLOBALS['_testStateDirs'] = [];

register_shutdown_function(function () {
    foreach ($GLOBALS['_testStateDirs'] as $dir) { removeTestDirTree($dir); }
});

/**
 * Run a snippet in a PHP process that has loaded nothing, and return what it printed.
 *
 * The one instrument that reaches a rule guarded by `defined()`. A `define()` cannot be
 * undone, so a module whose behaviour differs between "the constant is set" and "it is
 * not" has one of those two branches unreachable from any suite running in a single
 * process — and this suite loads `auth.php` at the top, which loads `config.php`, which
 * defines all ten branding names. Every check written in here about an *absent* setting
 * was therefore asserting the present case and passing for the wrong reason. That is
 * what #50 means by a check that cannot fail, and `tools/mutate.php` found one of them
 * by deleting the branch it claimed to cover (§4aq).
 *
 * Deliberately narrow: the snippet requires what it needs and echoes one string. It is
 * not a second fixture — nothing here builds a database — because the rules worth
 * reaching this way are the pure ones that read a constant.
 *
 * `LBM_ROOT` is defined for it, since `__DIR__` in a temporary file is the temp
 * directory. stderr is folded into stdout so a snippet that dies says so in the
 * failure message rather than answering the empty string.
 */
function inFreshProcess($code)
{
    // `tempnam()` creates the file it names, and PHP will only run one ending in .php,
    // so both paths exist and both have to go — the version that removed only the
    // second left one empty file per call, and `tools/mutate.php` calls this three
    // times per mutant.
    $stub = tempnam(sys_get_temp_dir(), 'lbm-sub');
    $file = $stub . '.php';
    file_put_contents($file, "<?php\ndefine('LBM_ROOT', "
                             . var_export(dirname(__DIR__), true) . ");\n" . $code . "\n");
    $out = array();
    $status = 0;
    exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $status);
    @unlink($file);
    @unlink($stub);
    return trim(implode("\n", $out));
}

// ---- Minimal assertions -----------------------------------------------------

$GLOBALS['_checks']   = 0;
$GLOBALS['_fails']    = [];
$GLOBALS['_reported'] = false;

// A suite that cannot fail is not a suite. Three ways this one used to report
// "0 failed" while genuinely broken, all closed here:
//
//   1. A PHP warning was invisible. Thirty-six "Undefined array key" warnings
//      from inside a module still printed a clean run, and on the 7.1 target
//      they are quieter still. Any diagnostic is now a failed check.
//   2. A fatal part-way through printed no summary line at all, so anything
//      reading the output — rather than the exit code — learned nothing, and
//      every check after the crash was silently skipped.
//   3. Deleting a whole section of the file printed "193 checks, 0 failed" and
//      exited 0, because nothing anchored the expected count. reportChecks()
//      now takes that number.
// One narrowing, added when the error policy landed: a diagnostic the code
// deliberately suppressed with `@` is not a failure. The app suppresses in exactly
// the places where a failure is an expected outcome rather than a defect — writing
// the error log, stamping the alert rate-limiter, sending mail on a host with no
// MTA — and those paths cannot be tested at all if reaching them fails the suite.
// Unsuppressed diagnostics, which are what the hardening was for, still fail it.
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) { return true; }
    $GLOBALS['_checks']++;
    $label = 'no PHP diagnostics during the run — got "' . $message . '" at '
           . basename($file) . ':' . $line;
    $GLOBALS['_fails'][] = $label;
    echo "  FAIL $label\n";
    stopEarlyIfAsked('diagnostic', $label);
    return true;   // handled; do not also print it through the default handler
});

/**
 * Leave on the first failure, when a caller has asked to be told *whether* the suite
 * fails rather than shown everything that does.
 *
 * `tools/mutate.php` runs this suite once per mutant and reads one bit off each run,
 * so a mutant that dies in the first section must not pay for the other 1700 checks —
 * at ten seconds a run, the difference is a tool nobody will wait for and one that
 * answers in a couple of minutes. Off unless `SELFTEST_STOP_ON_FAIL` is set, because
 * for a person the whole point of the run is the *list*: the first failure of nine is
 * rarely the one that explains them.
 *
 * It prints its own summary and marks the run reported, so the shutdown handler above
 * does not also call this an early stop. The exit code is the same 1 a full failing
 * run gives, and the KILLED line names which kind of failure it was — an assertion,
 * a PHP diagnostic, or the count anchor — because a mutant killed only by a warning
 * is not the suite standing over that line's behaviour (§4aq).
 */
function stopEarlyIfAsked($kind, $label)
{
    if (!getenv('SELFTEST_STOP_ON_FAIL')) { return; }
    $GLOBALS['_reported'] = true;
    echo "\nKILLED by $kind after " . $GLOBALS['_checks'] . " checks: $label\n";
    exit(1);
}

register_shutdown_function(function () {
    if ($GLOBALS['_reported']) { return; }
    $err = error_get_last();
    echo "\nENDED WITHOUT REPORTING after " . $GLOBALS['_checks'] . " checks";
    echo $err ? " — fatal: " . $err['message'] . " at " . basename($err['file']) . ':' . $err['line'] . "\n"
              : " — the suite stopped early.\n";
    exit(1);
});

function check($condition, $label)
{
    $GLOBALS['_checks']++;
    if ($condition) {
        echo "  ok   $label\n";
    } else {
        $GLOBALS['_fails'][] = $label;
        echo "  FAIL $label\n";
        stopEarlyIfAsked('assertion', $label);
    }
}

function checkSame($expected, $actual, $label)
{
    $same = ($expected === $actual);
    check($same, $label . ($same ? '' : ' — expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)));
}

/**
 * The message must mention this, whatever else it says.
 *
 * For the wording of a refusal, where the exact sentence is allowed to change but
 * the fact it names has to survive being reworded.
 */
function checkMentions($haystack, $needle, $label)
{
    $found = strpos((string)$haystack, (string)$needle) !== false;
    check($found, $label . ($found ? '' : ' — "' . $haystack . '" does not mention "' . $needle . '"'));
}

/**
 * The message must mention this, in whatever case the speaker chose.
 *
 * For text the *database* wrote rather than the app: SQLite says "duplicate column
 * name", MySQL says "Duplicate column name". Which of them is talking is not
 * something a check about error reporting should care about.
 */
function checkMentionsAnyCase($haystack, $needle, $label)
{
    $found = stripos((string)$haystack, (string)$needle) !== false;
    check($found, $label . ($found ? '' : ' — "' . $haystack . '" does not mention "' . $needle . '"'));
}

function section($title)
{
    echo "\n$title\n";
}

/**
 * $expected is the number of checks this file is supposed to run. Passing it is
 * the only thing that makes deleting checks visible: without it, a suite that
 * silently stopped running half its assertions still reported a clean pass.
 * Adding checks means updating the number, on purpose.
 */
function reportChecks($expected = null)
{
    $GLOBALS['_reported'] = true;
    $fails = $GLOBALS['_fails'];

    if ($expected !== null && $GLOBALS['_checks'] !== $expected) {
        $fails[] = 'the suite ran every check it is supposed to — expected '
                 . $expected . ' checks, ran ' . $GLOBALS['_checks'];
        stopEarlyIfAsked('the count anchor', $fails[count($fails) - 1]);
    }

    echo "\n" . $GLOBALS['_checks'] . " checks, " . count($fails) . " failed\n";
    if ($fails) {
        foreach ($fails as $f) { echo "  FAILED: $f\n"; }
        exit(1);
    }
    exit(0);
}
