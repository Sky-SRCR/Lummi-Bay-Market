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
    return new DisplayAdmin($pdo, $displays, new LayoutStore($pdo, $displays));
}

/** A fresh database with the live structure and one admin + one basic account. */
function newTestDb()
{
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA foreign_keys = ON");   // so ON DELETE CASCADE is real here too

    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'basic'
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
        lock_activity_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
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

    $pdo->exec("INSERT INTO block_styles (block_type,font_family,font_size,font_color,font_weight,font_style,line_height)
                VALUES ('price','Arial',30,'#e74c3c','bold','normal',1.2)");

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
    $stmt = $pdo->prepare("SELECT d.*, u.username AS last_published_by_name
                             FROM displays d LEFT JOIN users u ON d.last_published_by = u.id
                            WHERE d.id = ?");
    $stmt->execute([intval($id)]);
    return new Display($stmt->fetch());
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

// ---- Minimal assertions -----------------------------------------------------

$GLOBALS['_checks'] = 0;
$GLOBALS['_fails']  = [];

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

function reportChecks()
{
    $fails = $GLOBALS['_fails'];
    echo "\n" . $GLOBALS['_checks'] . " checks, " . count($fails) . " failed\n";
    if ($fails) {
        foreach ($fails as $f) { echo "  FAILED: $f\n"; }
        exit(1);
    }
    exit(0);
}
