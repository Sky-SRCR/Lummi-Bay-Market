<?php
// ============================================================
// REHEARSAL — Phase 1 against a copy of live data
// ============================================================
// The self-test proves the logic on SQLite. This proves the parts only MySQL can
// answer: that the idempotent DDL applies to the real table as it stands on the
// server, that the backfill leaves no unscoped row, and that publishing to one
// Display leaves the others alone on the actual engine.
//
// Named for Phase 1 because that is the migration with the risk in it; it has
// grown to check every table this build adds, grants included. The name stays so
// the deployment checklist keeps pointing at the same file.
//
//   php tools/rehearse_phase1.php --host=localhost --db=COPY_NAME \
//        --user=USER --pass=PASS --confirm-copy
//
// Run it against a COPY. It creates two throwaway Displays, publishes to them,
// and deletes them again — it never publishes over an existing layout — but it
// does write, and there is no undo anywhere in this app. The --confirm-copy flag
// is there to make that a decision rather than an accident.
//
// Safe to run twice: it converges the schema, then cleans up after itself.
//
// One side effect worth knowing about: convergence now writes a line to the app's
// error log when a statement the catalogue said was missing is refused anyway, so a
// run against a database that cannot take one of them creates the log directory and
// an entry in it. No email goes out — that needs the recipient cache an admin page
// writes — and this tool prints its own report either way.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/displays.php';
require_once __DIR__ . '/../lib/layout_store.php';
require_once __DIR__ . '/../lib/grants.php';

// ---- Arguments --------------------------------------------------------------

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = isset($m[2]) ? $m[2] : true;
    }
}

if (!isset($opts['confirm-copy'])) {
    fwrite(STDERR, "Refusing to run without --confirm-copy.\n\n"
        . "This script writes to the database you point it at. Point it at a copy\n"
        . "of the live database, never the live one, then pass --confirm-copy.\n");
    exit(2);
}
foreach (['host', 'db', 'user'] as $needed) {
    if (empty($opts[$needed])) {
        fwrite(STDERR, "Missing --$needed.\n");
        exit(2);
    }
}

$pdo = new PDO(
    "mysql:host={$opts['host']};dbname={$opts['db']};charset=utf8mb4",
    $opts['user'],
    isset($opts['pass']) && $opts['pass'] !== true ? $opts['pass'] : '',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$failures = [];
function report($ok, $label)
{
    global $failures;
    echo ($ok ? "  ok   " : "  FAIL ") . $label . "\n";
    if (!$ok) { $failures[] = $label; }
}
function heading($s) { echo "\n$s\n"; }

// ---- Before ----------------------------------------------------------------

heading("Before convergence — {$opts['db']}");

$elementsBefore = intval($pdo->query("SELECT COUNT(*) FROM canvas_elements")->fetchColumn());
echo "  canvas_elements rows: $elementsBefore\n";

$hasDisplays = false;
try {
    $pdo->query("SELECT 1 FROM displays LIMIT 1");
    $hasDisplays = true;
} catch (Exception $e) {}
echo "  displays table: " . ($hasDisplays ? "already present" : "not yet created") . "\n";

// ---- Converge ---------------------------------------------------------------

heading('Schema convergence');

ensureSignageSchema($pdo);

$cols = [];
foreach ($pdo->query("SHOW COLUMNS FROM canvas_elements")->fetchAll() as $c) {
    $cols[$c['Field']] = $c;
}
report(isset($cols['display_id']), 'canvas_elements.display_id exists');
report(isset($cols['display_id']) && $cols['display_id']['Null'] === 'NO', 'display_id is NOT NULL');

$unscoped = 0;
if (isset($cols['display_id'])) {
    // A row with no Display would be invisible to every scoped query while still
    // occupying the canvas — the one migration outcome worth failing loudly on.
    $unscoped = intval($pdo->query("SELECT COUNT(*) FROM canvas_elements WHERE display_id IS NULL")->fetchColumn());
}
report($unscoped === 0, "no unscoped elements remain (found $unscoped)");

$indexed = false;
foreach ($pdo->query("SHOW INDEX FROM canvas_elements")->fetchAll() as $ix) {
    if ($ix['Column_name'] === 'display_id') { $indexed = true; }
}
report($indexed, 'display_id is indexed');

$fk = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
      WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'canvas_elements'
        AND COLUMN_NAME = 'display_id' AND REFERENCED_TABLE_NAME = 'displays'"
);
$fk->execute([$opts['db']]);
report(intval($fk->fetchColumn()) > 0, 'display_id is foreign-keyed to displays');

// display_permissions is the Phase 4 table. Its two foreign keys are what stop a
// grant outliving the Display or the account it names — and they are added by
// schemaTry(), which swallows a failure, so this is the only place that says
// whether they actually applied on this database.
$hasGrants = false;
try {
    $pdo->query("SELECT 1 FROM display_permissions LIMIT 1");
    $hasGrants = true;
} catch (Exception $e) {}
report($hasGrants, 'display_permissions exists');

if ($hasGrants) {
    $gfk = $pdo->prepare(
        "SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'display_permissions'
            AND REFERENCED_TABLE_NAME IS NOT NULL"
    );
    $gfk->execute([$opts['db']]);
    $refs = [];
    foreach ($gfk->fetchAll() as $row) { $refs[] = $row['REFERENCED_TABLE_NAME']; }
    report(in_array('displays', $refs, true), 'a grant is foreign-keyed to its Display');
    report(in_array('users', $refs, true),    'and to its account');
}

// The edit-lock columns. lock_taken_at arrives after `displays` already exists on
// any database that converged for an earlier phase, so it is added by its own
// ALTER — and an ALTER that silently did not apply is the failure this reports.
$dcols = [];
foreach ($pdo->query("SHOW COLUMNS FROM displays")->fetchAll() as $c) {
    $dcols[$c['Field']] = $c;
}
report(isset($dcols['lock_holder_id']),   'displays.lock_holder_id exists');
report(isset($dcols['lock_taken_at']),    'displays.lock_taken_at exists');
report(isset($dcols['lock_activity_at']), 'displays.lock_activity_at exists');

$store  = new DisplayStore($pdo);
$legacy = $store->forTag(LEGACY_DISPLAY_TAG);
report($legacy !== null, 'the drive-thru Display exists');

if ($legacy) {
    echo "  " . $legacy->tag() . ": {$legacy->canvasWidth()}×{$legacy->canvasHeight()}, "
        . "background {$legacy->backgroundType()} {$legacy->backgroundValue()}, "
        . "stamp {$legacy->layoutStamp()}\n";

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM canvas_elements WHERE display_id = ?");
    $stmt->execute([$legacy->id()]);
    $mine = intval($stmt->fetchColumn());
    report($mine === $elementsBefore,
        "every pre-existing element was backfilled to it ($mine of $elementsBefore)");
}

// Idempotence: the whole point of the pattern.
$ranTwice = true;
try {
    // ensureSignageSchema() latches per request, so call the statements again by
    // running it in a fresh process would be truer — this at least proves the
    // second call is a no-op rather than an error.
    ensureSignageSchema($pdo);
} catch (Exception $e) {
    $ranTwice = false;
}
report($ranTwice, 'convergence can be re-run without error');

// The stronger claim, and the one only a real MySQL database can settle: after
// converging, the catalogue says there is nothing left to do. This is what proves
// the gating in signageSchemaPlan() matches what MySQL actually reports — the
// self-test can only check the plan against a hand-written expectation of the
// catalogue, never against the catalogue itself.
$after = signageSchemaPlan(readSchemaFacts($pdo));
$leftDdl = $steps = [];
foreach ($after as $entry) {
    if (isset($entry['sql']))  { $leftDdl[] = $entry['why']; }
    if (isset($entry['step'])) { $steps[]   = $entry['step']; }
}
report(count($leftDdl) === 0,
    'a converged database is issued no further ALTER or CREATE (' . count($leftDdl) . ' still wanted)');
foreach ($leftDdl as $why) { echo "  still wanted: $why\n"; }

// Two steps have to remain: no catalogue can answer "are there any rows".
report($steps === ['seed_block_styles', 'seed_legacy_display'],
    'and only the two row counts remain (' . implode(', ', $steps) . ')');

// ---- Scoping on the real engine --------------------------------------------

heading('Publishing is scoped (two throwaway Displays)');

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$tagA   = 'rehearsal-a-' . $suffix;
$tagB   = 'rehearsal-b-' . $suffix;

// Direct inserts: DisplayStore::create() arrives in Phase 3, and this tool
// switches to it then.
$mk = $pdo->prepare("INSERT INTO displays (tag,title,canvas_width,canvas_height) VALUES (?,?,?,?)");
$mk->execute([$tagA, 'Rehearsal A', 1920, 1080]);
$mk->execute([$tagB, 'Rehearsal B', 1080, 1920]);

$layouts = new LayoutStore($pdo, $store);
$a = $store->forTag($tagA);
$b = $store->forTag($tagB);

function rehearsalLayout($text)
{
    return [
        ['type' => 'section', 'temp_id' => 's1', 'x_pos' => 0, 'y_pos' => 0, 'width' => 400, 'height' => 300],
        ['type' => 'text', 'block_subtype' => 'price', 'parent_temp_id' => 's1',
         'manual_content' => $text, 'width' => 160, 'height' => 60],
    ];
}
function rehearsalPublish(LayoutStore $layouts, Display $d, $text, $stamp)
{
    return $layouts->publish($d, new PublishRequest(
        rehearsalLayout($text), Background::unchanged(), 0, true, $stamp
    ));
}

$countFor = function ($displayId) use ($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM canvas_elements WHERE display_id = ?");
    $stmt->execute([$displayId]);
    return intval($stmt->fetchColumn());
};

$legacyCountBefore = $legacy ? $countFor($legacy->id()) : 0;

report(rehearsalPublish($layouts, $a, 'A one', $a->layoutStamp())->isOk(), 'publish to A succeeds');
report(rehearsalPublish($layouts, $b, 'B one', $b->layoutStamp())->isOk(), 'publish to B succeeds');
report($countFor($a->id()) === 2, 'A has its two elements');
report($countFor($b->id()) === 2, 'B has its two elements');

$a = $store->forTag($tagA);
report(rehearsalPublish($layouts, $a, 'A two', $a->layoutStamp())->isOk(), 'republishing A succeeds');
report($countFor($b->id()) === 2, 'B is untouched by A being republished');
report(!$legacy || $countFor($legacy->id()) === $legacyCountBefore,
    'the drive-thru layout is untouched throughout');

$stale = rehearsalPublish($layouts, $a, 'A three', '0');
report($stale->kind() === 'stale', 'a stale publish to A is refused');
report($countFor($a->id()) === 2, 'and wrote nothing');

// A grant on a throwaway Display, so the cleanup below can show whether a deleted
// Display really takes its grants with it on this engine. Uses any existing
// account — the row is removed either way, and grants nobody anything real
// because A is deleted a few lines from now.
$grants     = new GrantStore($pdo);
$anAccount  = intval($pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1")->fetchColumn());
$grantedA   = false;
if ($hasGrants && $anAccount) {
    $grants->grant($a->id(), $anAccount);
    $grantedA = in_array($a->id(), $grants->displayIdsFor($anAccount), true);
    report($grantedA, 'a grant can be stored and read back');
}

// The edit lock, on the same throwaway Display. MySQL is where the claim's WHERE
// clause has to compare a bound DATETIME string against the column, and where the
// second LEFT JOIN has to produce the holder's name — neither of which SQLite can
// answer for. Display A is deleted a few lines below, so no real sign is affected.
$accounts = [];
foreach ($pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 2")->fetchAll() as $row) {
    $accounts[] = intval($row['id']);
}
if ($accounts) {
    $held = $store->claimLock($a, $accounts[0]);
    report($held && $held->lockState()->heldBy($accounts[0]), 'an edit lock can be taken and read back');
    report($held && $held->lockState()->holderName() !== '',  'and comes back with the holder\'s name');

    if (count($accounts) > 1) {
        $held = $store->claimLock($a, $accounts[1]);
        report($held && $held->lockState()->heldBy($accounts[0]),
               'a second account cannot take a lock that is being held');
    }

    $freed = $store->releaseLock($a, $accounts[0]);
    report($freed && $freed->lockState()->isFree(), 'and releasing it frees the display');
}

// ---- Cleanup ---------------------------------------------------------------

heading('Cleanup');

$del = $pdo->prepare("DELETE FROM displays WHERE tag = ?");
$del->execute([$tagA]);
$del->execute([$tagB]);
report($store->forTag($tagA) === null && $store->forTag($tagB) === null, 'throwaway Displays removed');

$orphans = intval($pdo->query(
    "SELECT COUNT(*) FROM canvas_elements ce
      LEFT JOIN displays d ON ce.display_id = d.id
     WHERE d.id IS NULL"
)->fetchColumn());
report($orphans === 0, 'their elements cascaded away, leaving no orphans');

if ($grantedA) {
    // The app deletes grants explicitly before deleting the Display, so a failure
    // here means the constraint is absent rather than the app being wrong — but it
    // is worth knowing, because it is the same constraint the elements rely on.
    $orphanGrants = intval($pdo->query(
        "SELECT COUNT(*) FROM display_permissions dp
          LEFT JOIN displays d ON dp.display_id = d.id
         WHERE d.id IS NULL"
    )->fetchColumn());
    report($orphanGrants === 0, 'and so did their grants');
}
report(!$legacy || $countFor($legacy->id()) === $legacyCountBefore,
    'the drive-thru layout is exactly as it was before this run');

echo "\n" . (count($failures) ? count($failures) . " FAILED\n" : "Rehearsal clean.\n");
exit(count($failures) ? 1 : 0);
