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
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'basic',
        is_active INTEGER NOT NULL DEFAULT 1
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

    $pdo->exec("CREATE TABLE assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        content TEXT NOT NULL,
        label TEXT
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

    $pdo->exec("INSERT INTO users (username, role) VALUES ('sky','admin'), ('clerk','basic')");

    return $pdo;
}

function makeTestDisplay(PDO $pdo, $tag, $title = 'Sign', $w = 1920, $h = 1080)
{
    $pdo->prepare("INSERT INTO displays (tag,title,canvas_width,canvas_height) VALUES (?,?,?,?)")
        ->execute([$tag, $title, $w, $h]);
    return loadTestDisplay($pdo, $pdo->lastInsertId());
}

function loadTestDisplay(PDO $pdo, $id)
{
    $stmt = $pdo->prepare("SELECT d.*, u.username AS last_published_by_name,
                                  lu.username AS lock_holder_name
                             FROM displays d
                             LEFT JOIN users u  ON d.last_published_by = u.id
                             LEFT JOIN users lu ON d.lock_holder_id    = lu.id
                            WHERE d.id = ?");
    $stmt->execute([intval($id)]);
    return new Display($stmt->fetch());
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
set_error_handler(function ($severity, $message, $file, $line) {
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
