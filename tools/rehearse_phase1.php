<?php
// ============================================================
// REHEARSAL — Phase 1 against a copy of live data
// ============================================================
// The self-test proves the logic on SQLite. This proves the parts only MySQL can
// answer: that the idempotent DDL applies to the real table as it stands on the
// server, that the backfill leaves no unscoped row, that publishing to one Display
// leaves the others alone on the actual engine — and that DDL really does commit an
// open transaction, which is the premise the whole of invariant 21 rests on and
// which SQLite's transactional DDL cannot demonstrate. It uses a throwaway table for
// that one and drops it again.
//
// Four of its checks used to be weaker than they read, and all four are now the
// thing they claimed to be:
//
//   - "convergence can be re-run without error" was true of a database that
//     rejected every statement, because the second run stopped at the per-request
//     latch having done nothing. The latch is dropped first now.
//   - "no unscoped elements remain (found 0)" printed ok when the column it counts
//     was missing entirely — the one case that needs the alarm.
//   - it published a `section` and a `price`, both of which existed before Phase 1
//     widened the ENUMs, so a database where the widening never applied passed
//     clean. It now publishes every value both ENUMs list and reads them back.
//   - a foreign key was checked for existing, not for cascading; a constraint that
//     restricts passed, and then the cleanup at the bottom threw and left two
//     throwaway Displays in the database. The rule is checked, and the cleanup no
//     longer depends on it.
//
// It also reports `block_styles`, whose seed can fail without stopping anything, and
// the five columns that pages rather than convergence add.
//
// Named for Phase 1 because that is the migration with the risk in it; it has
// grown to check every table this build adds, grants included. The name stays so
// the deployment checklist keeps pointing at the same file.
//
//   php tools/rehearse_phase1.php --host=localhost --db=COPY_NAME \
//        --user=USER --pass=PASS --confirm-copy
//
//   --port=3307 too, if the copy is not on the default port.
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
require_once __DIR__ . '/../lib/brand_styles.php';
// Named explicitly although layout_store.php already reaches it: this file reads as a
// checklist of what it touches, and `brands` is one of the tables it now writes through.
require_once __DIR__ . '/../lib/brands.php';
// For `SiteChrome::ROLES` — the thirteen chrome roles the `workspace_themes` columns are
// checked against below, rather than against a list written out again here.
require_once __DIR__ . '/../lib/workspace_themes.php';

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

// --port is optional and rarely needed against a live copy, which sits on 3306.
// It exists because the other places this now runs — a developer's second server,
// a CI service container — do not always.
$port = (isset($opts['port']) && $opts['port'] !== true) ? ';port=' . intval($opts['port']) : '';

$pdo = new PDO(
    "mysql:host={$opts['host']}{$port};dbname={$opts['db']};charset=utf8mb4",
    $opts['user'],
    isset($opts['pass']) && $opts['pass'] !== true ? $opts['pass'] : '',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$failures   = [];
$checkCount = 0;
function report($ok, $label)
{
    global $failures, $checkCount;
    $checkCount++;
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

// A row with no Display would be invisible to every scoped query while still
// occupying the canvas — the one migration outcome worth failing loudly on.
//
// "Cannot tell" is a failure here, not a pass. The version this replaces initialised
// the count to 0 and only asked the database when the column existed, so a run
// against a database where `display_id` never applied printed "ok no unscoped
// elements remain (found 0)" — the reassuring answer, from the one situation that
// most needs the alarming one.
if (isset($cols['display_id'])) {
    $unscoped = intval($pdo->query("SELECT COUNT(*) FROM canvas_elements WHERE display_id IS NULL")->fetchColumn());
    report($unscoped === 0, "no unscoped elements remain (found $unscoped)");
} else {
    report(false, 'no unscoped elements remain — cannot be told, display_id is not there to ask about');
}

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

/**
 * What the database will do to the rows on the other side when a parent goes.
 *
 * Existence is not the property anything relies on: the app deletes a Display and
 * expects its elements and its grants to go with it. A constraint that exists but
 * restricts instead of cascading passes an existence check, and then the deletion an
 * admin asked for fails halfway — or, in this script, the cleanup at the bottom
 * throws and leaves two throwaway Displays sitting in the database it was pointed at.
 */
function deleteRule(PDO $pdo, $db, $table, $column)
{
    $stmt = $pdo->prepare(
        "SELECT rc.DELETE_RULE
           FROM information_schema.REFERENTIAL_CONSTRAINTS rc
           JOIN information_schema.KEY_COLUMN_USAGE k
             ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
            AND k.CONSTRAINT_NAME   = rc.CONSTRAINT_NAME
          WHERE rc.CONSTRAINT_SCHEMA = ? AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?
          LIMIT 1"
    );
    $stmt->execute([$db, $table, $column]);
    $rule = $stmt->fetchColumn();
    return $rule === false ? 'none' : (string)$rule;
}

$elementRule = deleteRule($pdo, $opts['db'], 'canvas_elements', 'display_id');
report($elementRule === 'CASCADE',
    "deleting a Display takes its elements with it (ON DELETE $elementRule)");

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

    $grantRule   = deleteRule($pdo, $opts['db'], 'display_permissions', 'display_id');
    $accountRule = deleteRule($pdo, $opts['db'], 'display_permissions', 'user_id');
    report($grantRule === 'CASCADE',   "a deleted Display takes its grants with it (ON DELETE $grantRule)");
    report($accountRule === 'CASCADE', "and so does a deleted account (ON DELETE $accountRule)");
}

// ---- brands: the identity every sign wears (ADR-0011) -----------------------
// The riskiest part of this migration, and the part no self-test can reach: it
// re-keys a table in place on a database that is driving signs. Everything below is
// asked of the real catalogue after convergence has run.
heading('Brands, and the re-key that has to happen exactly once');

$hasBrands = false;
try {
    $pdo->query("SELECT 1 FROM brands LIMIT 1");
    $hasBrands = true;
} catch (Exception $e) {}
report($hasBrands, 'the brands table exists');

$brandRows = [];
if ($hasBrands) {
    $brandRows = $pdo->query("SELECT id, name FROM brands ORDER BY id ASC")->fetchAll();
    report(count($brandRows) > 0,
        'at least one Brand exists for every sign to wear (' . count($brandRows) . ')');
    if ($brandRows) {
        echo "  brand 1 is \"" . $brandRows[0]['name'] . "\" — the name an admin sees first\n";
    }
}

// The re-key itself. `block_styles` was keyed on block_type alone; a database where
// this did not land has one set of standards for the whole property, and the second
// Brand an admin creates cannot have its own — the insert collides on the old key.
$bsKey = [];
foreach ($pdo->query("SHOW KEYS FROM block_styles WHERE Key_name = 'PRIMARY'")->fetchAll() as $k) {
    $bsKey[intval($k['Seq_in_index'])] = $k['Column_name'];
}
ksort($bsKey);
$bsKey = array_values($bsKey);
report($bsKey === ['brand_id', 'block_type'],
    'block_styles is keyed on (brand_id, block_type) (' . implode(', ', $bsKey) . ')');

$bsCols = [];
foreach ($pdo->query("SHOW COLUMNS FROM block_styles")->fetchAll() as $c) { $bsCols[$c['Field']] = $c; }
report(isset($bsCols['brand_id']), 'block_styles.brand_id exists');
report(isset($bsCols['brand_id']) && $bsCols['brand_id']['Null'] === 'NO',
    'and is NOT NULL, so no set of standards belongs to no Brand');

// The backfill, checked as rows rather than as structure. A row left unbranded is
// invisible to every scoped read — the venue's typography would simply stop applying.
$orphanStyles = intval($pdo->query(
    "SELECT COUNT(*) FROM block_styles bs LEFT JOIN brands b ON bs.brand_id = b.id
      WHERE b.id IS NULL")->fetchColumn());
report($orphanStyles === 0, "no set of standards points at a Brand that is gone (found $orphanStyles)");

// ---- block_styles: the rows Brand Standards edits ---------------------------
// Seeding them is a *step*, and a step's failure is reported but not fatal, so this
// is the only place that says whether the rows are really there on this database. A
// missing row makes the Brand Standards form a silent no-op: it saves with
// UPDATE … WHERE brand_id = ? AND block_type = ?, so the field reverts on reload and
// nothing says why. Asked per Brand, because that is what the key is now.
foreach ($brandRows as $brandRow) {
    $styled = [];
    try {
        $q = $pdo->prepare("SELECT block_type FROM block_styles WHERE brand_id = ?");
        $q->execute([$brandRow['id']]);
        foreach ($q->fetchAll() as $row) { $styled[] = $row['block_type']; }
    } catch (Exception $e) {
        // No table. The report below names every type as missing, which is the truth.
    }
    $missingStyles = array_values(array_diff(BrandStyles::types(), $styled));
    report(count($missingStyles) === 0,
        'every branded block type has a typography row for "' . $brandRow['name'] . '" ('
        . (count($missingStyles) ? 'missing: ' . implode(', ', $missingStyles) : 'all '
          . count(BrandStyles::types()) . ' present') . ')');
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

// The other half of the Brand migration, and the one that can empty a shop: a sign
// with no Brand renders its branded blocks from its own stored typography, which
// invariant 34 has just stopped publish writing — so an unbranded Display is a sign
// whose prices go 16px Arial black on the next publish. Added nullable, backfilled,
// then tightened, and it is the tighten that proves the backfill left nothing behind.
report(isset($dcols['brand_id']), 'displays.brand_id exists');
report(isset($dcols['brand_id']) && $dcols['brand_id']['Null'] === 'NO',
    'and is NOT NULL, so no sign is left without an identity');

$unbranded = intval($pdo->query(
    "SELECT COUNT(*) FROM displays d LEFT JOIN brands b ON d.brand_id = b.id
      WHERE b.id IS NULL")->fetchColumn());
report($unbranded === 0, "every sign wears a Brand that exists (found $unbranded that do not)");

// RESTRICT, not CASCADE or SET NULL. Deleting a Brand three signs wear must be
// refused rather than quietly repainting or destroying them — there is no undo.
$brandRule = deleteRule($pdo, $opts['db'], 'displays', 'brand_id');
report($brandRule === 'RESTRICT' || $brandRule === 'NO ACTION',
    "a Brand in use cannot be deleted out from under its signs (ON DELETE $brandRule)");
report(isset($dcols['lock_activity_at']), 'displays.lock_activity_at exists');

// ---- workspace_themes: what the application is painted in (v2 step 5) -------
// The safest table in this plan and still worth rehearsing, because two of its three
// statements are the ones only MySQL performs: a `KEY` on `users` and a foreign key with
// no `ON DELETE` clause. The SQLite suite cannot ask about either — it declares no
// foreign keys at all, on purpose — so this is the only place the RESTRICT that stops a
// theme being deleted out from under somebody is ever observed.
$hasThemes = true;
try {
    $pdo->query("SELECT 1 FROM workspace_themes LIMIT 1");
} catch (Throwable $e) {
    $hasThemes = false;
}
report($hasThemes, 'the workspace_themes table exists');

if ($hasThemes) {
    // Thirteen colour columns, each NOT NULL with a default, so a theme is never half a
    // set of colours. Asked against `SiteChrome::ROLES` rather than a list here, which is
    // the same rule the plan's CREATE TABLE is held to by the self-test.
    $tcols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM workspace_themes")->fetchAll() as $c) {
        $tcols[$c['Field']] = $c;
    }
    $missingRoles = $nullableRoles = [];
    foreach (array_keys(SiteChrome::ROLES) as $role) {
        if (!isset($tcols[$role]))                 { $missingRoles[]  = $role; continue; }
        if ($tcols[$role]['Null'] !== 'NO')        { $nullableRoles[] = $role; }
    }
    report(count($missingRoles) === 0,
        'every chrome role has a column' . ($missingRoles ? ': missing ' . implode(', ', $missingRoles) : ''));
    report(count($nullableRoles) === 0,
        'and none of them is nullable' . ($nullableRoles ? ': ' . implode(', ', $nullableRoles) : ''));

    // No seed, deliberately: the store default is `branding_config.php` plus the
    // documented defaults, not a copy of them in a row. So an empty table on a database
    // that has just converged is the *expected* state, and this reports the count rather
    // than judging it — a shop that has made themes is equally correct.
    $themeCount = intval($pdo->query("SELECT COUNT(*) FROM workspace_themes")->fetchColumn());
    echo "  ----   $themeCount workspace theme" . ($themeCount === 1 ? '' : 's')
       . " on this database; convergence seeds none, so zero is the expected state\n";
}

$ucols = [];
foreach ($pdo->query("SHOW COLUMNS FROM users")->fetchAll() as $c) { $ucols[$c['Field']] = $c; }
report(isset($ucols['workspace_theme_id']), 'users.workspace_theme_id exists');
report(isset($ucols['workspace_theme_id']) && $ucols['workspace_theme_id']['Null'] === 'YES',
    'and is nullable, because null is the answer "use the store default" rather than a gap');

$themeIndexed = false;
foreach ($pdo->query("SHOW KEYS FROM users")->fetchAll() as $k) {
    if ($k['Column_name'] === 'workspace_theme_id') { $themeIndexed = true; }
}
report($themeIndexed, 'and indexed, because every signed-in page load reads through it');

if ($hasThemes) {
    // RESTRICT rather than SET NULL, for the reason `displays.brand_id` is: moving three
    // people back to the store default on one click, without telling them, is the merge
    // invariant 5 exists to prevent. The app refuses it first and names them; this is the
    // half that covers a database this app is not the only thing writing to.
    $themeRule = deleteRule($pdo, $opts['db'], 'users', 'workspace_theme_id');
    report($themeRule === 'RESTRICT' || $themeRule === 'NO ACTION',
        "a theme somebody is wearing cannot be deleted out from under them (ON DELETE $themeRule)");

    $orphanChoice = intval($pdo->query(
        "SELECT COUNT(*) FROM users u LEFT JOIN workspace_themes t ON u.workspace_theme_id = t.id
          WHERE u.workspace_theme_id IS NOT NULL AND t.id IS NULL")->fetchColumn());
    report($orphanChoice === 0,
        "nobody is pointed at a theme that is gone (found $orphanChoice)");
}

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
//
// The latch has to be dropped first, and that is the entire content of this check.
// Without it the second call returned at `if (!SchemaLatch::take())` having run
// nothing, so "convergence can be re-run without error" was true of a PDO that
// rejected every statement it was ever given: run one swallows its failures, run two
// attempts none. A check that cannot fail is worse than no check, because the report
// it prints is read as coverage.
$ranTwice = true;
$reallyRan = false;
try {
    SchemaLatch::forget();
    ensureSignageSchema($pdo);
    // take() has flipped it, which is how we know the call went past the latch and
    // really issued its statements against this database a second time.
    $reallyRan = !SchemaLatch::pending();
} catch (Exception $e) {
    $ranTwice = false;
}
report($ranTwice && $reallyRan, 'convergence really runs a second time, and without error');

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
report($steps === ['seed_first_brand', 'seed_block_styles', 'seed_legacy_display'],
    'and only the three row counts remain (' . implode(', ', $steps) . ')');

// ---- The premise invariant 21 rests on --------------------------------------

heading('DDL really does commit an open transaction (MySQL only)');

// The self-test cannot show this: SQLite has transactional DDL, so a CREATE inside
// a transaction rolls back with everything else. MySQL commits, silently, which is
// the entire reason repairSchemaAfterFailure() refuses while a transaction is open —
// a schema repair fired from inside LayoutStore::publish() would commit half a
// publish and then report that it had failed. If this check ever stops failing to
// roll back, MySQL has changed and the invariant can be revisited; until then it is
// the thing being defended against, demonstrated rather than assumed.
$scratch = 'lbm_rehearsal_ddl_' . substr(bin2hex(random_bytes(3)), 0, 6);
$pdo->exec("CREATE TABLE $scratch (id INT(11) NOT NULL AUTO_INCREMENT, PRIMARY KEY (id))
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->beginTransaction();
$pdo->exec("INSERT INTO $scratch (id) VALUES (1)");
$pdo->exec("ALTER TABLE $scratch ADD COLUMN note VARCHAR(8) NULL");   // the implicit commit
try { $pdo->rollBack(); } catch (Throwable $e) { }
$survived = intval($pdo->query("SELECT COUNT(*) FROM $scratch")->fetchColumn());
report($survived === 1,
    'a row a rolled-back transaction inserted survived the ALTER inside it '
    . "($survived row(s) — 1 means the DDL committed it, which is the hazard)");
$pdo->exec("DROP TABLE $scratch");

// And the refusal itself, on the real engine.
$pdo->beginTransaction();
SchemaLatch::forget();
$why = '';
report(repairSchemaAfterFailure($pdo, $why) === false && strpos($why, 'transaction') !== false,
    'a repair asked for inside a transaction is refused (' . $why . ')');
$pdo->rollBack();
SchemaLatch::forget();

// ---- Scoping on the real engine --------------------------------------------

heading('Publishing is scoped (two throwaway Displays)');

$suffix = substr(bin2hex(random_bytes(3)), 0, 6);
$tagA   = 'rehearsal-a-' . $suffix;
$tagB   = 'rehearsal-b-' . $suffix;

// Direct inserts: DisplayStore::create() arrives in Phase 3, and this tool
// switches to it then.
$mk = $pdo->prepare("INSERT INTO displays (tag,title,canvas_width,canvas_height,brand_id) VALUES (?,?,?,?,1)");
$mk->execute([$tagA, 'Rehearsal A', 1920, 1080]);
$mk->execute([$tagB, 'Rehearsal B', 1080, 1920]);

$layouts = new LayoutStore($pdo, $store);
$a = $store->forTag($tagA);
$b = $store->forTag($tagB);

/**
 * Every value the two widened ENUMs list, read out of the definition itself.
 *
 * Derived rather than typed out, so that widening an ENUM in lib/schema.php widens
 * what this rehearsal publishes on the next run without anybody remembering to.
 */
function enumValues($definition)
{
    preg_match_all("/'([^']*)'/", $definition, $m);
    return $m[1];
}

/**
 * A layout that uses every element type and every block subtype the schema claims
 * to allow.
 *
 * The version this replaces published a `section` and one `price` — both of which
 * existed in the ENUM *before* Phase 1 widened it. So a database where
 * `MODIFY block_subtype` had never applied passed the rehearsal clean, and the
 * first real publish using Title 2 or Price 2 either failed outright (strict mode)
 * or silently stored an empty subtype, which renders with the wrong typography on
 * the sign and nowhere else. The rehearsal exists to catch exactly that, one run
 * before it reaches a screen.
 */
function rehearsalLayout($text)
{
    $layout = [
        ['type' => 'section', 'temp_id' => 's1', 'x_pos' => 0, 'y_pos' => 0, 'width' => 400, 'height' => 300],
    ];

    // One text block per subtype. The first carries the caller's words so the
    // scoping checks below still have something recognisable to look at.
    foreach (enumValues(SCHEMA_BLOCK_SUBTYPE_ENUM) as $i => $subtype) {
        $layout[] = ['type' => 'text', 'block_subtype' => $subtype, 'parent_temp_id' => 's1',
                     'manual_content' => $i === 0 ? $text : ('subtype ' . $subtype),
                     'width' => 160, 'height' => 60];
    }

    // And one block per element type. `section` is the container above; `text` is
    // covered seven times over already.
    foreach (enumValues(SCHEMA_ELEMENT_TYPE_ENUM) as $type) {
        if ($type === 'section' || $type === 'text') { continue; }
        $layout[] = ['type' => $type, 'parent_temp_id' => 's1',
                     'manual_content' => '[]', 'width' => 200, 'height' => 100];
    }

    return $layout;
}

/** How many rows one rehearsal publish should leave behind. */
function rehearsalElementCount()
{
    return count(rehearsalLayout('x'));
}
function rehearsalPublish(LayoutStore $layouts, Display $d, $text, $stamp)
{
    return $layouts->publish($d, new PublishRequest(
        rehearsalLayout($text), Background::unchanged(), BrandChoice::unchanged(), 0, true, $stamp
    ));
}

$countFor = function ($displayId) use ($pdo) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM canvas_elements WHERE display_id = ?");
    $stmt->execute([$displayId]);
    return intval($stmt->fetchColumn());
};

$legacyCountBefore = $legacy ? $countFor($legacy->id()) : 0;

$expect = rehearsalElementCount();

report(rehearsalPublish($layouts, $a, 'A one', $a->layoutStamp())->isOk(), 'publish to A succeeds');
report(rehearsalPublish($layouts, $b, 'B one', $b->layoutStamp())->isOk(), 'publish to B succeeds');
report($countFor($a->id()) === $expect, "A has its $expect elements");
report($countFor($b->id()) === $expect, "B has its $expect elements");

// What came back out is the check the ENUM widening needs: MySQL with strict mode
// off stores an empty string for a value the column does not list, and says nothing.
$read = $pdo->prepare("SELECT type, block_subtype FROM canvas_elements WHERE display_id = ?");
$read->execute([$a->id()]);
$gotTypes = $gotSubtypes = [];
foreach ($read->fetchAll() as $row) {
    $gotTypes[]    = $row['type'];
    $gotSubtypes[] = $row['block_subtype'];
}
$lostSubtypes = array_values(array_diff(enumValues(SCHEMA_BLOCK_SUBTYPE_ENUM), $gotSubtypes));
$lostTypes    = array_values(array_diff(enumValues(SCHEMA_ELEMENT_TYPE_ENUM), $gotTypes));
report(count($lostSubtypes) === 0,
    'every block subtype came back exactly as published ('
    . (count($lostSubtypes) ? 'lost: ' . implode(', ', $lostSubtypes) : 'all ' . count($gotSubtypes)) . ')');
report(count($lostTypes) === 0,
    'and so did every element type ('
    . (count($lostTypes) ? 'lost: ' . implode(', ', $lostTypes) : 'all of them') . ')');

$a = $store->forTag($tagA);
report(rehearsalPublish($layouts, $a, 'A two', $a->layoutStamp())->isOk(), 'republishing A succeeds');
report($countFor($a->id()) === $expect, 'and leaves A with one layout, not two');
report($countFor($b->id()) === $expect, 'B is untouched by A being republished');
report(!$legacy || $countFor($legacy->id()) === $legacyCountBefore,
    'the drive-thru layout is untouched throughout');

$stale = rehearsalPublish($layouts, $a, 'A three', '0');
report($stale->kind() === 'stale', 'a stale publish to A is refused');
report($countFor($a->id()) === $expect, 'and wrote nothing');

// ---- The Brand a publish carries (v2 step 4) -------------------------------------
// `brand_id` is `NOT NULL` with a foreign key, and a publish is now one of the two
// things that writes it. Both of those are MySQL facts that SQLite's fixture states
// differently, and the suite's MySQL arm is the one that does not run where this repo
// is developed — so this tool is where `applyBrand()`'s UPDATE meets a real engine.
// A refusal is checked too, because an id naming nothing is exactly what
// `displays_ibfk_3` would turn into an exception if the check above it ever went away.
$brandsHere = new BrandStore($pdo);
$brandList  = $brandsHere->all();
$a          = $store->forTag($tagA);
if (count($brandList) > 1) {
    $away = null;
    foreach ($brandList as $candidate) {
        if ($candidate->id() !== $a->brandId()) { $away = $candidate; break; }
    }
    $moved = $layouts->publish($a, new PublishRequest(
        rehearsalLayout('A brand'), Background::unchanged(), BrandChoice::brand($away->id()),
        0, true, $a->layoutStamp()));
    report($moved->isOk(), 'a publish carrying a Brand succeeds');
    report($store->forTag($tagA)->brandId() === $away->id(),
        'and the throwaway sign wears "' . $away->name() . '" afterwards');
} else {
    // Printed rather than reported. A `report(true, …)` here would be a green line that
    // cannot go red, which is what #50 is about — and it would read as coverage of the
    // statement above it, which on this database nothing exercised.
    echo "  ----   only one Brand on this database, so nothing was published onto a second\n";
}

$a       = $store->forTag($tagA);
$noBrand = $layouts->publish($a, new PublishRequest(
    rehearsalLayout('A ghost'), Background::unchanged(), BrandChoice::brand(999999),
    0, true, $a->layoutStamp()));
report($noBrand->kind() === 'invalid', 'a publish naming a Brand that does not exist is refused');
report($countFor($a->id()) === $expect, 'and wrote no layout either');
report(!$pdo->inTransaction(), 'and left no transaction open');

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

// Everything this run made, removed in dependency order and without relying on a
// constraint to do it. The version this replaces deleted the Displays and let the
// cascade take the rest — so on a database whose foreign key restricts instead of
// cascading (which the check above now reports), the DELETE threw, nothing caught
// it, and the script exited leaving two throwaway Displays and their layouts behind
// in the copy it had been pointed at. The DELETE_RULE check is where that property
// is asserted; cleaning up is not the place to also be testing it.
$cleanupError = '';
try {
    $ids = [$a->id(), $b->id()];
    foreach ($ids as $id) {
        if ($hasGrants) {
            $pdo->prepare("DELETE FROM display_permissions WHERE display_id = ?")->execute([$id]);
        }
        $pdo->prepare("DELETE FROM canvas_elements WHERE display_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM displays WHERE id = ?")->execute([$id]);
    }
} catch (Throwable $e) {
    $cleanupError = $e->getMessage();
}
report($cleanupError === '', 'the throwaway Displays could be cleaned up'
    . ($cleanupError === '' ? '' : " ($cleanupError — REMOVE THEM BY HAND)"));
report($store->forTag($tagA) === null && $store->forTag($tagB) === null, 'throwaway Displays removed');

// Not a cascade check any more (the cleanup above is explicit) — a check on the
// database it was pointed at, which is a copy of live and may have carried orphans
// in with it. A row here is invisible to every scoped query and cannot be edited.
$orphans = intval($pdo->query(
    "SELECT COUNT(*) FROM canvas_elements ce
      LEFT JOIN displays d ON ce.display_id = d.id
     WHERE d.id IS NULL"
)->fetchColumn());
report($orphans === 0, "no element belongs to a Display that is not there (found $orphans)");

if ($grantedA) {
    // The same question as the orphan check above, and now for the same reason: both
    // the app and the cleanup delete grants explicitly, so a row here came in with the
    // copy rather than out of this run. It grants somebody access to a Display that
    // does not exist, which nothing in the admin panel can show or revoke.
    $orphanGrants = intval($pdo->query(
        "SELECT COUNT(*) FROM display_permissions dp
          LEFT JOIN displays d ON dp.display_id = d.id
         WHERE d.id IS NULL"
    )->fetchColumn());
    report($orphanGrants === 0, 'and so did their grants');
}
report(!$legacy || $countFor($legacy->id()) === $legacyCountBefore,
    'the drive-thru layout is exactly as it was before this run');

// ---- The columns convergence does not own -----------------------------------
// Five columns are added by pages rather than by signageSchemaPlan(), each on the
// first request that needs it, so a copy of live data can legitimately be without
// them and nothing here is broken if it is. They are printed rather than checked
// for that reason — but printed, because "which of these landed" was previously a
// question nobody could answer without a database console.

heading('Columns added by pages, not by convergence (for information)');

function noteColumn(PDO $pdo, $table, $column, $who)
{
    $there = false;
    try {
        $pdo->query("SELECT $column FROM $table LIMIT 0");
        $there = true;
    } catch (Exception $e) {}
    echo '  ' . ($there ? 'present' : 'ABSENT ') . "  $table.$column — added by $who\n";
}

noteColumn($pdo, 'users', 'failed_attempts', 'convergence, on any authenticated page');
noteColumn($pdo, 'users', 'last_failed_at',  'convergence, on any authenticated page');
noteColumn($pdo, 'users', 'locked_until',    'convergence, on any authenticated page');
noteColumn($pdo, 'users', 'closed_at',       'convergence, on any authenticated page');
noteColumn($pdo, 'password_resets', 'attempts', 'reset_password.php when it is opened');
echo "  Absent is survivable: every reader copes, and the column arrives on first use.\n";
echo "  The three lockout columns no longer arrive from login.php, and closed_at no\n";
echo "  longer from the admin panel's own ALTER — both are gated plan entries now.\n";
echo "  On a database missing them the first sign-in has no lockout, and the Builder\n";
echo "  it lands on adds them (BUILD-REFERENCE 4v). password_resets is the one that\n";
echo "  still converges from an unauthenticated page, deliberately.\n";

// ---- Did it ask everything it has? -----------------------------------------
// Until this section landed, deleting half this file printed "Rehearsal clean." and
// exited 0. That is the third failure mode `reportChecks()` was written for in
// `tools/test_fixture.php` — "193 checks, 0 failed" over a suite that had stopped
// running half its assertions — and this file was the last gate here without the
// answer to it. It is also the worst place to be missing one: this is the only gate
// that runs *nowhere but CI*, so a section that stopped being asked would go unread
// on every developer machine as well.
//
// The number cannot be a literal the way `reportChecks(2337)` is, because eleven of
// the `report()` calls above sit behind a fact about the database rather than behind
// a branch of the code: a copy of live data has accounts and several Brands, and a
// database built from `schema.sql` has one Brand and nobody at all. So the anchor is
// an expression — and every term in it is declared *here*, at the bottom, rather than
// beside the block it counts. That placement is the whole mechanism. Deleting a block
// above leaves its term behind, the sum stops matching, and the run fails; a term
// written next to its own block would be deleted along with it and the anchor would
// agree with the smaller file.
//
// The 48 is what the run reported minus the eleven conditional terms below, and it is
// the number to change when a check is added — on purpose, which is the point of an
// anchor.
//
// One thing writing it down immediately made visible, which is the argument for
// anchors in one paragraph: **five of these checks have never run on CI.** A database
// built from `schema.sql` seeds no accounts, so the edit lock and the grant are asked
// of nothing — and their own comments say they are here because MySQL is the only
// engine that can answer for the claim's bound `DATETIME` comparison and the holder
// name's second `LEFT JOIN`. Today the only run that reaches them is a deploy-day run
// against a copy of live data. Nothing was hiding that; nothing was saying it either,
// which is §4bf's whole shape. The rows below are printed rather than passed over in
// silence for that reason.
$checkGroups = [
    // what                                                     how many   asked here
    ['asked of every database',                                        48, true],
    ['the two grant foreign keys and their delete rules',               4,  $hasGrants],
    ['a Brand exists for every sign to wear',                           1,  $hasBrands],
    ['a typography row for each Brand',                count($brandRows), true],
    ['a column per chrome role, none of them nullable',                 2,  $hasThemes],
    ['the theme delete rule, and nobody left pointing at one',          2,  $hasThemes],
    ['the drive-thru Display, and its backfilled elements',             1,  (bool) $legacy],
    ['a publish carrying a second Brand',                               2,  count($brandList) > 1],
    ['a grant stored and read back',                                    1,  $hasGrants && $anAccount],
    ['the edit lock taken, named, and released',                        3,  (bool) $accounts],
    ['a second account refused a lock somebody holds',                  1,  count($accounts) > 1],
    ['the grants a deleted Display took with it',                       1,  $grantedA],
];

heading('Every check this rehearsal has');

$expected = 0;
$notAsked = [];
foreach ($checkGroups as $group) {
    list($what, $howMany, $askedHere) = $group;
    if ($askedHere) {
        $expected += $howMany;
        continue;
    }
    $notAsked[] = $howMany . ' — ' . $what;
}

// Not through report(): this is the summary of the count, so counting it would make
// the printed total one more than the number of ok/FAIL lines above it, and that
// total is the one thing here somebody can check by hand.
if ($checkCount === $expected) {
    echo "  ok   $checkCount checks, which is every one this database can be asked\n";
} else {
    echo "  FAIL this rehearsal did not ask every check it has\n";
    echo "       expected $expected, asked $checkCount\n";
    $failures[] = 'the rehearsal asked every check it has — expected ' . $expected
                . ', asked ' . $checkCount;
}
foreach ($notAsked as $line) {
    echo "  ----   not asked of this database: $line\n";
}
if ($notAsked) {
    echo "  ----   Not a failure: a database without accounts or a second Brand cannot\n";
    echo "         be asked these. It is printed because a check nobody runs and a\n";
    echo "         check nobody knows is not running are two different problems.\n";
}

echo "\n" . (count($failures) ? count($failures) . " FAILED\n" : "Rehearsal clean.\n");
exit(count($failures) ? 1 : 0);
