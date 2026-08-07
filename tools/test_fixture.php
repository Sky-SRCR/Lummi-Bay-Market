<?php
// ============================================================
// TEST FIXTURE — an in-memory database for the self-tests
// ============================================================
// There is no MySQL where this code is written, and the publish path is the one
// change in this project that can empty every sign at once. So the self-tests
// run the *real* modules against an in-memory SQLite database shaped like the
// live schema. What that proves is the logic: scoping predicates, the staleness
// comparison, the section rules, sanitising, cascade behaviour. What it cannot
// prove is MySQL dialect and DDL — that is what tools/rehearse_phase1.php does
// against a copy of live data.
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
require_once __DIR__ . '/../lib/error_policy.php';   // pulls in lib/alerts.php
require_once __DIR__ . '/../lib/assets.php';
require_once __DIR__ . '/../lib/upload_limits.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/branding.php';

/**
 * DisplayStore with its one non-portable statement swapped out.
 *
 * `SELECT … FOR UPDATE` has no SQLite equivalent. Replacing that single method
 * leaves every other statement in the publish path — the deletes, the inserts,
 * the scoping predicates, the stamp arithmetic — exactly the code that runs in
 * production. Nothing else is stubbed.
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

/** The real LayoutStore, wired to the SQLite-safe DisplayStore. */
function newTestLayoutStore(PDO $pdo)
{
    return new LayoutStore($pdo, new TestDisplayStore($pdo));
}

/** The real AccountAdmin over the real stores — nothing about it is stubbed. */
function newTestAccountAdmin(PDO $pdo)
{
    return new AccountAdmin($pdo, new AccountStore($pdo), new GrantStore($pdo), new TestDisplayStore($pdo));
}

/** Add an account the fixture did not seed, and return its id. */
function makeTestAccount(PDO $pdo, $username, $role = 'basic')
{
    $pdo->prepare("INSERT INTO users (username, email, role) VALUES (?, ?, ?)")
        ->execute([$username, $username . '@example.test', $role]);
    return intval($pdo->lastInsertId());
}

/** The real DisplayAdmin over the real stores — nothing about it is stubbed. */
function newTestDisplayAdmin(PDO $pdo)
{
    $displays = new TestDisplayStore($pdo);
    return new DisplayAdmin($pdo, $displays, new LayoutStore($pdo, $displays), new GrantStore($pdo));
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

/** A fresh database with the live structure and one admin + one basic account. */
function newTestDb()
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA foreign_keys = ON");   // so ON DELETE CASCADE is real here too

    // is_active mirrors the live column: the session sync reads it on every
    // authenticated request, so a fixture without it cannot test that a
    // deactivated account's open tab stops working.
    // The three lockout columns are here because auth.php adds them to the live
    // table at runtime, so a fixture without them is not shaped like the live
    // schema — and the server report's whole job is to notice a column that never
    // applied. A fixture that is missing one by accident would make that report
    // look broken while it was working correctly.
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
    // added by ensureSchema() so the tests exercise the shape a converged live
    // database actually has.
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

    $pdo->exec("CREATE TABLE block_styles (
        block_type TEXT PRIMARY KEY,
        font_family TEXT, font_size INTEGER, font_color TEXT,
        font_weight TEXT, font_style TEXT, line_height REAL
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

    // All six branded types, matching the seed in lib/schema.php. One row was
    // enough while the only question was "does a snapshot carry typography"; it is
    // not enough to test that a save leaves the types it was not given alone.
    $pdo->exec("INSERT INTO block_styles (block_type,font_family,font_size,font_color,font_weight,font_style,line_height) VALUES
        ('section_header','Arial',36,'#ffffff','bold','normal',1.30),
        ('item_title',    'Arial',24,'#ffffff','bold','normal',1.30),
        ('item_title_2',  'Arial',24,'#27ae60','bold','normal',1.30),
        ('price',         'Arial',30,'#e74c3c','bold','normal',1.20),
        ('price_2',       'Arial',30,'#e74c3c','bold','normal',1.20),
        ('description',   'Arial',16,'#bdc3c7','normal','normal',1.40)");

    $pdo->exec("INSERT INTO users (username, email, role) VALUES
        ('sky','sky@example.test','admin'), ('clerk','clerk@example.test','basic')");

    return $pdo;
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
            'displays' => ['id' => $col('int(11)'), 'lock_taken_at' => $col('datetime', true)],
            'display_permissions' => ['id' => $col('int(11)')],
            'block_styles'        => ['block_type' => $col('varchar(50)')],
            'users'               => ['id' => $col('int(11)')],
        ],
        'indexes' => [
            'canvas_elements' => ['PRIMARY' => true, 'display_id' => true],
            'displays'        => ['PRIMARY' => true, 'tag' => true],
        ],
        'constraints' => [
            'canvas_elements'     => ['canvas_elements_ibfk_3' => true],
            'displays'            => ['displays_ibfk_1' => true, 'displays_ibfk_2' => true],
            'display_permissions' => ['display_permissions_ibfk_1' => true,
                                      'display_permissions_ibfk_2' => true],
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
 */
function fakeCatalogue(array $shape, PDO $onto = null)
{
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
    $pdo->exec("CREATE TABLE information_schema.STATISTICS
                (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, INDEX_NAME TEXT)");
    $pdo->exec("CREATE TABLE information_schema.TABLE_CONSTRAINTS
                (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, CONSTRAINT_NAME TEXT)");

    $col = $pdo->prepare("INSERT INTO information_schema.COLUMNS VALUES ('lbm',?,?,?,?)");
    foreach ($shape['columns'] as $table => $columns) {
        foreach ($columns as $name => $spec) {
            $col->execute([$table, $name, $spec['type'], !empty($spec['nullable']) ? 'YES' : 'NO']);
        }
    }
    $ix = $pdo->prepare("INSERT INTO information_schema.STATISTICS VALUES ('lbm',?,?)");
    foreach ($shape['indexes'] as $table => $names) {
        foreach (array_keys($names) as $name) { $ix->execute([$table, $name]); }
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

function makeTestDisplay(PDO $pdo, $tag, $title = 'Sign', $w = 1920, $h = 1080)
{
    $pdo->prepare("INSERT INTO displays (tag,title,canvas_width,canvas_height) VALUES (?,?,?,?)")
        ->execute([$tag, $title, $w, $h]);
    return loadTestDisplay($pdo, $pdo->lastInsertId());
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
    return (new TestDisplayStore($pdo))->forId(intval($id));
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
    @mkdir($dir, 0700, true);
    $GLOBALS['_testStateDirs'][] = $dir;
    return $dir;
}

$GLOBALS['_testStateDirs'] = [];

register_shutdown_function(function () {
    foreach ($GLOBALS['_testStateDirs'] as $dir) {
        // Two globs: `*` does not match a leading dot, and the branding swap's
        // temporary file is deliberately hidden. A missed dotfile leaves the rmdir
        // failing and a directory behind in /tmp on every run.
        $files = array_merge((array)@glob($dir . '/*'), (array)@glob($dir . '/.[!.]*'));
        foreach ($files as $file) {
            if (@is_dir($file)) { @rmdir($file); } else { @unlink($file); }
        }
        @rmdir($dir);
    }
});

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
    return true;   // handled; do not also print it through the default handler
});

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
    }

    echo "\n" . $GLOBALS['_checks'] . " checks, " . count($fails) . " failed\n";
    if ($fails) {
        foreach ($fails as $f) { echo "  FAILED: $f\n"; }
        exit(1);
    }
    exit(0);
}
