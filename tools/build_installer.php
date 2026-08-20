<?php
// ============================================================
// THE INSTALLER PACKAGE — assembled, and asked what it forgot
// ============================================================
//   php tools/build_installer.php [--out=DIR]
//
// A repo is not a package. Eleven of this tree's directories and every one of its
// `.md` files have no business on a server, `docs/DEPLOY-SKIP.md` says so at length,
// and the way that instruction has been carried out until now is a person reading a
// list and dragging folders. That works until the tree gains a file — and both
// directions of getting it wrong are quiet:
//
//   * **A file left out.** `lib/` is one module per table and every page requires
//     several. A module that misses the upload is a fatal on the first page that
//     needs it, which on a Screen is a blank sign in the shop. Nothing in the
//     package can tell you; the first symptom is a customer looking at a black TV.
//   * **A file put in.** `HANDOFF.md` names the live database, the credentials path
//     outside the webroot and where the error log goes. The root `.htaccess` denies
//     `.md` as a backstop and the backstop has never been the plan — the plan is
//     that the file is not there. A doc that ships is served on any host whose
//     `AllowOverride` is off, and answers 200 to whoever asked.
//
// So this decides it once, from the tracked file list, and **refuses to build a
// package it cannot account for**. Every tracked path falls under exactly one rule:
// it goes into `app/` (the folder whose contents become the webroot), it goes to the
// package root (the instructions, which are for a person and not for Apache), or it
// is omitted with the reason written down. A path matching no rule is not guessed
// at — the build fails and names it. That is the whole design: `composer.json`
// appearing at the root one day is a failing build rather than a file that either
// shipped or did not, depending on which glob somebody wrote first.
//
// Both halves of that are held, and they fail differently, which is why both are here:
//
//   * A path under no rule is **unclassified** — loud, at build time, naming the path.
//   * A rule matching nothing is **stale** — the shape a rename leaves behind, and the
//     reason `$notGates` in `check_invariants.php` is held to existing too. A rule for
//     `tools/*.js` that matches nothing means either the suites moved or the rule is
//     wrong, and a package built past it is one whose coverage nobody has read since.
//
// And one check that reads the code rather than the paths: **every static `require`
// in a shipped file names a file that is also shipped.** The path rules are globs and
// a glob is hard to forget a file out of — what a glob cannot see is a new dependency
// pointing somewhere the package does not reach. `lib/` and the page scripts are the
// whole of it today, so this passes trivially on this tree and is written for the day
// something reaches outside it. `__DIR__ . '/…'`, a bare relative name and a `../` all
// resolve; a variable path does not and is left alone, because `db_connect.php`'s
// credentials include is a variable on purpose and lives outside the webroot by design.
//
// What it deliberately does not do is decide anything for an install that already
// exists. `setup.php` and `branding_config.php` are in this package because a fresh
// install needs both — the first admin has to be created and `config.php` loads the
// generated settings file — and both are in group A and group B of
// `docs/DEPLOY-SKIP.md`, which is to say they are the two files that must **not** go
// over the top of a running install. A package that quietly dropped them would be an
// update package wearing an installer's name, and the person holding it could not tell
// which one they had. INSTALL.md says which is which; there is one artifact and it is
// the one for an empty database.
//
// CLI only, and never reachable from the browser (tools/.htaccess).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);

// The module that decides where credentials live and what the file is called. This
// tool ships a filled-in-the-blanks copy of that file, and it must be the name the app
// looks for — so it asks rather than spelling it. `check_invariants.php` holds the whole
// repo to that: one file names `db_credentials`, and a second opinion about it goes
// stale in silence, because the real file is outside the repo where nothing can see it.
require_once $root . '/lib/install_paths.php';

// And the module that decides what goes *in* that file, so the blank form in the package
// and the filled-in one the installer writes are one piece of text with one set of
// comments. Two copies of the file a person edits by hand is two things to change and one
// chance to forget, and the forgotten copy would be the one they are looking at.
require_once $root . '/lib/installer.php';

// `install.php`'s own zip reader, declared without running the installer — which is what
// the INSTALLER_INSPECT guard at the bottom of that file is for. The payload built below
// is read back through the very function that will read it on a shop's server, so "the
// archive is fine" is this build's answer rather than the next person's problem.
define('INSTALLER_INSPECT', true);
require_once $root . '/install.php';

/** The name a store sees, which is nobody's venue until they set one up. */
define('PACKAGE_SLUG', 'store-display-system');

/**
 * Where the credentials file goes and what it is called, asked of the module that
 * decides it. Returns [privateDirName, sharedFileName, exampleSecondInstallFileName].
 *
 * The folder name below is a stand-in for whatever the install is really called; what
 * is read off it is the *shape* of the answer, which is all the package needs.
 */
function credentialsNaming($exampleFolder)
{
    $shared   = InstallPaths::credentialsCandidates('/home/ACCOUNT/public_html/anywhere');
    $specific = InstallPaths::credentialsCandidates('/home/ACCOUNT/public_html/' . $exampleFolder);
    $sharedPath = $shared[count($shared) - 1];
    return [basename(dirname($sharedPath)), basename($sharedPath), basename($specific[0])];
}

// ---- Where each tracked path goes, and why -------------------------------------
/**
 * The rules, in order — first match wins.
 *
 * `to` is `app` for the folder that becomes the webroot, `root` for the package's own
 * top level, and `''` for omitted. Each carries the sentence that would otherwise be
 * in somebody's head: a rule whose reason is not written down is a rule the next
 * person has to re-derive from the fact that it is there.
 *
 * The order is load-bearing exactly once: `INSTALL.md` is named before the rule that
 * omits documentation, because it is documentation and it is the one piece of it the
 * package is for.
 */
function installerRules()
{
    return [
        [
            'label' => 'the instructions',
            'to'    => 'root',
            'match' => function ($dir, $base) { return $dir === '' && $base === 'INSTALL.md'; },
            'why'   => 'the package root, never the webroot — it is read by a person before '
                     . 'anything is uploaded, and the root .htaccess denies .md anyway',
        ],
        [
            'label' => 'the root guard',
            'to'    => 'app',
            'match' => function ($dir, $base) { return $dir === '' && $base === '.htaccess'; },
            'why'   => 'the security headers, the .sql and .md denials, the HTTPS redirect and '
                     . 'the viewer.php framing exception every Screen depends on. Many FTP '
                     . 'clients skip dotfiles by default, which is why INSTALL.md asks for it '
                     . 'by name',
        ],
        [
            'label' => 'the page scripts',
            'to'    => 'app',
            'match' => function ($dir, $base) {
                return $dir === '' && substr($base, -4) === '.php';
            },
            'why'   => 'flat at the root with relative includes — they are adapters and they '
                     . 'stay there (BUILD-REFERENCE section 1)',
        ],
        [
            'label' => 'the schema',
            'to'    => 'app',
            'match' => function ($dir, $base) { return $dir === '' && $base === 'schema.sql'; },
            'why'   => 'what a fresh database is built from; the root .htaccess denies .sql to '
                     . 'a browser, and the app converges anything this file lags',
        ],
        [
            'label' => 'the modules and their guard',
            'to'    => 'app',
            'match' => function ($dir, $base) { return $dir === 'lib'; },
            'why'   => 'one module per table, required by every page — plus the .htaccess that '
                     . 'is the only thing making them unreachable by URL',
        ],
        [
            'label' => 'the development tooling',
            'to'    => '',
            'match' => function ($dir, $base) {
                return $dir === 'tools' || strpos($dir, 'tools/') === 0;
            },
            'why'   => 'nothing on a shop\'s server runs them and none of them could: the node '
                     . 'suites need a Node that is not there, the PHP ones assert about a '
                     . 'checkout rather than an install, mutate.php rewrites source files one '
                     . 'line at a time on purpose, and selftest_layout.php alone is larger '
                     . 'than the biggest page in the app. The live install carries them '
                     . 'because it grew that way, which is a fact about that server and not '
                     . 'an argument for putting them on a new one',
        ],
        [
            'label' => 'the documentation',
            'to'    => '',
            'match' => function ($dir, $base) {
                return substr($base, -3) === '.md' || $dir === 'docs' || strpos($dir, 'docs/') === 0;
            },
            'why'   => 'HANDOFF.md names the live database, the credentials path and where the '
                     . 'error log goes. Nothing on the server reads any of it (DEPLOY-SKIP B)',
        ],
        [
            'label' => 'the CI workflow',
            'to'    => '',
            'match' => function ($dir, $base) {
                return $dir === '.github' || strpos($dir, '.github/') === 0;
            },
            'why'   => 'gates run before a merge, not on a shop\'s host',
        ],
        [
            'label' => 'the agent skills',
            'to'    => '',
            'match' => function ($dir, $base) { return strpos($dir, '.claude') === 0; },
            'why'   => 'they configure the tooling this repo is written with and mean nothing '
                     . 'to an install',
        ],
        [
            'label' => 'git metadata',
            'to'    => '',
            'match' => function ($dir, $base) { return $dir === '' && $base === '.gitignore'; },
            'why'   => 'it describes a working copy. The repository itself is never uploaded — '
                     . 'a served .git/ hands out the whole history (DEPLOY-SKIP B)',
        ],
    ];
}

/**
 * Where every tracked path goes — or that nobody has said.
 *
 * Pure: paths in, plan out, so the probes below can hand it a tree that does not exist.
 *
 * @return array ['place' => [repoPath => packagePath], 'omit' => [repoPath => reason],
 *                'unclassified' => [...paths], 'stale' => [...rule labels]]
 */
function installerPlan(array $tracked)
{
    $rules = installerRules();
    $place = [];
    $omit  = [];
    $unclassified = [];
    $used  = [];

    foreach ($tracked as $path) {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') { continue; }
        $cut  = strrpos($path, '/');
        $dir  = ($cut === false) ? '' : substr($path, 0, $cut);
        $base = ($cut === false) ? $path : substr($path, $cut + 1);

        $hit = null;
        foreach ($rules as $i => $rule) {
            $matches = $rule['match'];
            if ($matches($dir, $base)) { $hit = $i; break; }
        }
        if ($hit === null) { $unclassified[] = $path; continue; }

        $used[$hit] = true;
        if ($rules[$hit]['to'] === '')          { $omit[$path]  = $rules[$hit]['why']; }
        elseif ($rules[$hit]['to'] === 'app')   { $place[$path] = 'app/' . $path; }
        else                                    { $place[$path] = $base; }
    }

    $stale = [];
    foreach ($rules as $i => $rule) {
        if (!isset($used[$i])) { $stale[] = $rule['label']; }
    }

    sort($unclassified);
    sort($stale);
    ksort($place);
    ksort($omit);
    return ['place' => $place, 'omit' => $omit,
            'unclassified' => $unclassified, 'stale' => $stale];
}

/**
 * Anything in the plan that would land in the webroot and must never be there.
 *
 * The rules above already decide this, so this is the same rule met a second way —
 * deliberately, and for the reason invariant 33 has two halves. The rules are ordered
 * and a reordering is a one-line diff: move the documentation rule above `INSTALL.md`
 * and the package still builds, still passes every path check, and quietly carries
 * every `.md` in the repo into `app/`. This is the assertion that does not care how
 * the plan was arrived at, only what it says.
 *
 * @return array [packagePath => what is wrong with it]
 */
function forbiddenInWebroot(array $place)
{
    $bad = [];
    foreach ($place as $repoPath => $packagePath) {
        if (strpos($packagePath, 'app/') !== 0) { continue; }
        $inside = substr($packagePath, 4);
        $first  = (strpos($inside, '/') === false) ? $inside : substr($inside, 0, strpos($inside, '/'));

        if (substr($inside, -3) === '.md') {
            $bad[$packagePath] = 'documentation in the webroot — HANDOFF.md names the live '
                               . 'database and the credentials path (DEPLOY-SKIP B)';
        } elseif (substr($inside, -3) === '.js' || substr($inside, -4) === '.yml'
                  || substr($inside, -5) === '.yaml') {
            $bad[$packagePath] = 'nothing on the server runs it';
        } elseif ($first === 'docs' || $first === '.github' || $first === '.claude'
                  || $first === '.git' || $first === '.gitignore') {
            $bad[$packagePath] = 'a repository directory, not an install directory';
        }
    }
    ksort($bad);
    return $bad;
}

// ---- What a shipped file needs that the package may not have -------------------
/**
 * Every file a `require`/`include` in this source names, repo-relative.
 *
 * Tokens rather than a regex, for the reason `codeWithoutComments()` exists in
 * `check_invariants.php`: this repo explains itself in prose, and a comment or a
 * string quoting a require is not one. `__DIR__ . '/x.php'`, a bare `'x.php'` and a
 * `'/../lib/x.php'` all resolve — relative to the *including* file's directory, which
 * is what makes the same call mean different files from the root and from `tools/`.
 *
 * A path built from a variable resolves to nothing and is skipped rather than guessed.
 * That is not a gap being tolerated: `db_connect.php` finds its credentials through
 * `InstallPaths` precisely so the file can live outside the webroot, and a checker
 * that demanded it be in the package would be demanding the one thing this app is
 * built not to do.
 */
function requiredPaths($source, $ofPath)
{
    $cut = strrpos($ofPath, '/');
    $dir = ($cut === false) ? '' : substr($ofPath, 0, $cut);

    $ts  = token_get_all($source);
    $n   = count($ts);
    $out = [];

    for ($i = 0; $i < $n; $i++) {
        if (!is_array($ts[$i])) { continue; }
        if ($ts[$i][0] !== T_REQUIRE && $ts[$i][0] !== T_REQUIRE_ONCE
            && $ts[$i][0] !== T_INCLUDE && $ts[$i][0] !== T_INCLUDE_ONCE) { continue; }

        $literal = '';
        $sawDir  = false;
        $dynamic = false;
        for ($j = $i + 1; $j < $n; $j++) {
            $t = $ts[$j];
            if (!is_array($t)) {
                if ($t === ';') { break; }
                if ($t === '.' || $t === '(' || $t === ')') { continue; }
                $dynamic = true;
                continue;
            }
            if ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                continue;
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                $literal .= substr($t[1], 1, -1);
                continue;
            }
            if ($t[0] === T_DIR) { $sawDir = true; continue; }
            $dynamic = true;
        }

        if ($dynamic || $literal === '') { continue; }
        $joined = $sawDir
            ? $dir . $literal
            : ($dir === '' ? $literal : $dir . '/' . $literal);
        $resolved = normalisePath($joined);
        if ($resolved !== '') { $out[] = $resolved; }
    }

    $out = array_values(array_unique($out));
    sort($out);
    return $out;
}

/** `a//b/../c` → `a/c`. A path that climbs above the root answers ''. */
function normalisePath($path)
{
    $parts = explode('/', str_replace('\\', '/', $path));
    $stack = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') { continue; }
        if ($part === '..') {
            if (!$stack) { return ''; }
            array_pop($stack);
            continue;
        }
        $stack[] = $part;
    }
    return implode('/', $stack);
}

// ---- Reading the tree ----------------------------------------------------------
/**
 * The tracked paths, from git, and a refusal if git cannot answer.
 *
 * The tracked list is the authority rather than a directory walk, and that is the
 * point: an untracked scratch file, a half-finished module, a `.env` somebody left
 * beside `config.php` — a walk ships all three. If there is no git to ask, this
 * refuses instead of falling back, because the fallback is the one that puts a file
 * nobody meant on a shop's webroot.
 */
function trackedPaths($root, &$why)
{
    $why    = '';
    $out    = [];
    $status = 0;
    exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $out, $status);
    if ($status !== 0 || !$out) {
        $why = 'git ls-files answered nothing here, so there is no tracked file list to '
             . 'build from. Run this in a checkout: a directory walk would ship whatever '
             . 'happens to be lying beside the app.';
        return [];
    }
    return $out;
}

/** The commit this package was cut from, or '' when git will not say. */
function packageVersion($root)
{
    $out    = [];
    $status = 0;
    exec('git -C ' . escapeshellarg($root) . ' rev-parse --short HEAD 2>/dev/null',
         $out, $status);
    if ($status !== 0 || !$out) { return ''; }
    return trim($out[0]);
}

/** For a failed check's own sentence. Never for a payload — that is HttpReply's job. */
function showList(array $items)
{
    if (!$items) { return '(none)'; }
    return implode(', ', $items);
}

// ============================================================
// The checks
// ============================================================

$failures = [];
$checked  = 0;

function ok($line)
{
    echo "  ok   $line\n";
}

function bad($line, &$failures, $summary)
{
    echo "  FAIL $line\n";
    $failures[] = $summary;
}

echo "Building the installer package\n\n";

$tracked = trackedPaths($root, $whyNot);
if (!$tracked) {
    echo "  FAIL $whyNot\n\n0 checks, 1 failed\n";
    exit(1);
}
$plan = installerPlan($tracked);

// ---- Every tracked path is accounted for ---------------------------------------
$checked++;
if (!$plan['unclassified']) {
    ok('every tracked path is either shipped or omitted for a stated reason ('
       . count($plan['place']) . ' shipped, ' . count($plan['omit']) . ' omitted)');
} else {
    bad('a tracked path is under no rule, so nobody has said whether it ships',
        $failures, 'a tracked path no rule classifies');
    foreach ($plan['unclassified'] as $path) { echo "       + $path\n"; }
    echo "       Add it to installerRules() with the reason it does or does not belong\n";
    echo "       on a shop's webroot. A package built past this is one whose contents\n";
    echo "       depend on which glob happened to be written first.\n";
}

// ---- And no rule has stopped meaning anything ----------------------------------
$checked++;
if (!$plan['stale']) {
    ok('every rule in installerRules() matched something in the tree');
} else {
    bad('a rule matches nothing, which is what a rename leaves behind',
        $failures, 'a stale rule in installerRules()');
    foreach ($plan['stale'] as $label) { echo "       ? $label\n"; }
}

// ---- Nothing that must not reach a webroot is in app/ --------------------------
$forbidden = forbiddenInWebroot($plan['place']);
$checked++;
if (!$forbidden) {
    ok('nothing in app/ is a file DEPLOY-SKIP says must not be in the webroot');
} else {
    bad('the plan puts something in the webroot that must never be there',
        $failures, 'a forbidden file placed in app/');
    foreach ($forbidden as $path => $reason) { echo "       + $path — $reason\n"; }
}

// ---- Every static require in a shipped file names a shipped file ---------------
$dangling = [];
foreach ($plan['place'] as $repoPath => $packagePath) {
    if (substr($repoPath, -4) !== '.php') { continue; }
    if (strpos($packagePath, 'app/') !== 0) { continue; }
    $source = (string) file_get_contents($root . '/' . $repoPath);
    foreach (requiredPaths($source, $repoPath) as $needs) {
        if (isset($plan['place'][$needs])) { continue; }
        $dangling[] = $repoPath . ' requires ' . $needs
                    . (isset($plan['omit'][$needs]) ? ' (omitted: ' . $plan['omit'][$needs] . ')'
                                                    : ' (not in the tree)');
    }
}
sort($dangling);
$checked++;
if (!$dangling) {
    ok('every static require in a shipped file names a file the package also ships');
} else {
    bad('a shipped file requires something the package does not carry — a fatal on the '
        . 'first page that needs it, and on a Screen that is a blank sign',
        $failures, 'a shipped file requires a file the package omits');
    foreach ($dangling as $line) { echo "       + $line\n"; }
}

// ---- And those three, seen to fail --------------------------------------------
// Invariant 30. The plan is pure, so the interesting trees are ones that do not exist:
// the file somebody adds next, the rename that empties a rule, and the reordering that
// turns the documentation rule into a wildcard over `INSTALL.md`.
$planProbes = [
    [['composer.json'], 'unclassified', ['composer.json'],
     'a new kind of file at the root is reported rather than guessed at — the hole this exists for'],
    [['HANDOFF.md'], 'omit-only', ['HANDOFF.md'],
     'a doc is omitted, never shipped: it names the live database and the credentials path'],
    [['INSTALL.md'], 'place', ['INSTALL.md' => 'INSTALL.md'],
     'and the one doc that does travel goes to the package root, not into app/'],
    [['lib/new_module.php'], 'place', ['lib/new_module.php' => 'app/lib/new_module.php'],
     'a module added to lib/ ships without anyone editing this file'],
    [['tools/selftest_new.php'], 'omit-only', ['tools/selftest_new.php'],
     'a new PHP gate stays in the repo — it asserts about a checkout, not an install'],
    [['tools/selftest_new.js'], 'omit-only', ['tools/selftest_new.js'],
     'and so does a new node suite: there is no Node on the server'],
    [['docs/mockups/x.html'], 'omit-only', ['docs/mockups/x.html'],
     'and something under docs/ that is not even a .md is still omitted'],
    [['.github/workflows/y.yml'], 'omit-only', ['.github/workflows/y.yml'],
     'CI stays in the repo'],
    [['.claude/skills/z/SKILL.md'], 'omit-only', ['.claude/skills/z/SKILL.md'],
     'so do the agent skills'],
    [['.gitignore'], 'omit-only', ['.gitignore'],
     'and git metadata'],
    [['.htaccess', 'lib/.htaccess'], 'place',
     ['.htaccess' => 'app/.htaccess', 'lib/.htaccess' => 'app/lib/.htaccess'],
     'both .htaccess files ship — lib/ is unreachable only because of its own, and an FTP '
     . 'client that skips dotfiles is how that guard goes missing'],
    [['tools/.htaccess'], 'omit-only', ['tools/.htaccess'],
     'and the third is omitted with the folder it was guarding, rather than shipped as a '
     . 'guard over nothing'],
];
foreach ($planProbes as $probe) {
    list($paths, $kind, $want, $label) = $probe;
    $got = installerPlan($paths);
    $checked++;
    $pass = false;
    if ($kind === 'unclassified') {
        $pass = ($got['unclassified'] === $want && !$got['place'] && !$got['omit']);
    } elseif ($kind === 'omit-only') {
        $pass = (array_keys($got['omit']) === $want && !$got['place'] && !$got['unclassified']);
    } else {
        $pass = ($got['place'] === $want && !$got['omit'] && !$got['unclassified']);
    }
    if ($pass) {
        ok($label);
    } else {
        bad("the plan is wrong about: $label", $failures, "installerPlan: $label");
        echo '       placed ' . showList(array_values($got['place']))
           . ' · omitted ' . showList(array_keys($got['omit']))
           . ' · unclassified ' . showList($got['unclassified']) . "\n";
    }
}

// The staleness half, which nothing above can show: the real tree has every rule
// matching, so the only way to see this fail is a tree missing one rule's files.
$checked++;
$stalePlan = installerPlan(['lib/displays.php']);
$staleWanted = 9;
if (count($stalePlan['stale']) === $staleWanted) {
    ok('a tree that exercises one rule reports the other ' . $staleWanted
       . ' as stale, so a rename cannot leave a rule that means nothing');
} else {
    bad('the staleness half does not report a rule nothing matched', $failures,
        'installerPlan staleness');
    echo '       expected ' . $staleWanted . ' stale, got ' . count($stalePlan['stale'])
       . ': ' . showList($stalePlan['stale']) . "\n";
}

$forbiddenProbes = [
    [['docs/x.md' => 'app/docs/x.md'], 1,
     'a doc that reached app/ is reported whatever rule put it there'],
    [['tools/s.js' => 'app/tools/s.js'], 1,
     'so is a node suite'],
    [['.github/w.yml' => 'app/.github/w.yml'], 1,
     'so is a workflow'],
    [['INSTALL.md' => 'INSTALL.md'], 0,
     'but the instructions at the package root are not in the webroot and are fine'],
    [['lib/displays.php' => 'app/lib/displays.php', '.htaccess' => 'app/.htaccess'], 0,
     'and a module and a dotfile guard are what the webroot is for'],
];
foreach ($forbiddenProbes as $probe) {
    list($place, $want, $label) = $probe;
    $checked++;
    $got = forbiddenInWebroot($place);
    if (count($got) === $want) {
        ok($label);
    } else {
        bad("the webroot rule is wrong about: $label", $failures, "forbiddenInWebroot: $label");
        echo '       expected ' . $want . ', got ' . count($got) . ': '
           . showList(array_keys($got)) . "\n";
    }
}

$requireProbes = [
    ["<?php require_once 'db_connect.php';", 'builder.php', ['db_connect.php'],
     'a bare relative name resolves against the including file'],
    ["<?php require_once __DIR__ . '/lib/brands.php';", 'setup.php', ['lib/brands.php'],
     'and so does __DIR__, which is how every page in lib/ is reached'],
    ["<?php require_once __DIR__ . '/../lib/branding.php';", 'tools/selftest_installed.php',
     ['lib/branding.php'],
     'a ../ from tools/ lands in lib/ — the same call means a different file from there'],
    ["<?php require_once \$credentialsFile;", 'db_connect.php', [],
     'a variable path is left alone: the credentials file is outside the webroot by design'],
    ["<?php require_once __DIR__ . '/lib/' . \$name . '.php';", 'api.php', [],
     'and so is a path only half built from literals'],
    ["<?php // require_once 'gone.php';\n\$a = 1;", 'help.php', [],
     'a require in a comment is prose, which is most of what this repo is'],
    ["<?php \$s = \"require_once 'gone.php';\";", 'help.php', [],
     'and one inside a string is not a require at all'],
    ["<?php require_once '../../outside.php';", 'login.php', [],
     'a path climbing above the repo names nothing here and is not invented'],
];
foreach ($requireProbes as $probe) {
    list($source, $of, $want, $label) = $probe;
    $checked++;
    $got = requiredPaths($source, $of);
    if ($got === $want) {
        ok($label);
    } else {
        bad("the require reader is wrong about: $label", $failures, "requiredPaths: $label");
        echo '       expected ' . showList($want) . ', got ' . showList($got) . "\n";
    }
}

// ---- The package's two destinations are the two the app expects ----------------
// INSTALL.md draws a picture — the app in `public_html/signs/`, the credentials in
// `private/` one level above it — and the picture is only true because
// `InstallPaths::credentialsCandidates()` walks up two folders. If that ever became one,
// the instructions would be wrong, the package's second folder would be in the webroot,
// and nothing else here would notice: the file it names is outside the repo.
$appDir = '/home/ACCOUNT/public_html/signs';
$shared = InstallPaths::credentialsCandidates($appDir);
$sharedPath = $shared[count($shared) - 1];
$checked++;
if (strpos($sharedPath, '/home/ACCOUNT/') === 0
    && strpos($sharedPath, '/home/ACCOUNT/public_html/') !== 0) {
    ok('the credentials file the package supplies is looked for above the webroot, '
       . 'which is the picture INSTALL.md draws');
} else {
    bad('the app looks for its credentials inside the webroot, so the package\'s second '
        . 'folder and INSTALL.md step 2 are both wrong', $failures,
        'the credentials path is not above the webroot');
    echo "       it looks at $sharedPath for an install at $appDir\n";
}

// And the half that keeps a rehearsal copy off a live sign: two folders at the same
// depth must not reach the same first candidate, or an unmodified copy connects to the
// database the first one is publishing to.
$checked++;
$oneFirst = InstallPaths::credentialsCandidates('/home/ACCOUNT/public_html/signs');
$twoFirst = InstallPaths::credentialsCandidates('/home/ACCOUNT/public_html/signs-test');
if ($oneFirst[0] !== $twoFirst[0]) {
    ok('two installs on one account are offered different credentials files, which is '
       . 'what the template in this package explains how to use');
} else {
    bad('two installs at the same depth reach the same credentials file first — an '
        . 'unmodified second copy publishes to the first one\'s signs', $failures,
        'two installs share a first credentials candidate');
}

// ---- One door, and it is in the package ----------------------------------------
// `setup.php` was the first-admin form and `install.php` has absorbed it. Two public
// "make yourself an administrator" pages is two windows to close, two files to remember
// and two pages that have to agree about what "already installed" means — so this is a
// rule and not a tidy-up, and the way it comes back is somebody restoring a file from
// history because a doc still mentions it.
$checked++;
if (!isset($plan['place']['setup.php']) && !isset($plan['omit']['setup.php'])) {
    ok('setup.php is gone: there is one first-administrator door and install.php is it');
} else {
    bad('setup.php is back in the tree, so the app has two public first-administrator '
        . 'forms', $failures, 'setup.php is back');
}

$checked++;
if (isset($plan['place']['install.php']) && $plan['place']['install.php'] === 'app/install.php') {
    ok('install.php ships into app/, so the manual route has an installer too');
} else {
    bad('install.php is not shipped into app/, so a package unpacked by hand has no way '
        . 'to create its first administrator', $failures, 'install.php is not in app/');
}

// ---- Nothing is assembled over a plan that does not hold ----------------------
// The checks below this line are about the artifact rather than the rules, so they can
// only run once it exists. These are the ones that decide whether it is worth building
// at all.
if ($failures) {
    echo "\n$checked checks, " . count($failures) . " failed\n";
    foreach ($failures as $f) { echo "  FAILED: $f\n"; }
    echo "\nNo package written. A package this cannot account for is worse than none:\n";
    echo "it looks finished.\n";
    exit(1);
}

// ============================================================
// The package
// ============================================================

$outDir = $root . '/dist';
foreach (array_slice($argv, 1) as $arg) {
    if (strpos($arg, '--out=') === 0) { $outDir = substr($arg, 6); }
}
$outDir = rtrim($outDir, '/');

$version = packageVersion($root);
$name    = PACKAGE_SLUG . ($version === '' ? '' : '-' . $version);
$stage   = $outDir . '/' . $name;

// Removing a directory is the one destructive thing here, so it is allowed to remove
// exactly one shape of name — its own. `--out=/home/user` with a bug above it should
// fail, not empty somebody's home directory. Nothing published can be taken back and
// that rule does not stop at the database.
if (is_dir($stage)) {
    if (strpos(basename($stage), PACKAGE_SLUG) !== 0) {
        echo "Refusing to remove $stage — it is not a package directory.\n";
        exit(1);
    }
    removeTree($stage);
}

/** Depth-first, files before their directory, and it never follows a symlink. */
function removeTree($dir)
{
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) { removeTree($path); } else { unlink($path); }
    }
    rmdir($dir);
}

/** mkdir -p, and say so rather than letting a copy fail one line later. */
function ensureDir($dir)
{
    if (is_dir($dir)) { return true; }
    if (!mkdir($dir, 0755, true)) {
        echo "Could not create $dir\n";
        exit(1);
    }
    return true;
}

ensureDir($stage);

$manifest = [];
foreach ($plan['place'] as $repoPath => $packagePath) {
    $target = $stage . '/' . $packagePath;
    ensureDir(dirname($target));
    if (!copy($root . '/' . $repoPath, $target)) {
        echo "Could not copy $repoPath\n";
        exit(1);
    }
    if (strpos($packagePath, 'app/') === 0) {
        $manifest[$packagePath] = [filesize($target), hash_file('sha256', $target)];
    }
}

// ---- The one file the package supplies that the repo does not ------------------
// The credentials, outside the webroot, as blanks to fill in — for anyone taking the
// manual route rather than letting `install.php` write it. The text is
// `Installer::credentialsSource()`'s, which is what the installer writes with real values
// in it, so the two cannot drift apart. It is shipped under its own folder and not under
// `app/` because that is the whole point of it: the two destinations in this package are
// two different folders on the server, and one of them is not reachable by a browser.
list($privateDir, $sharedName, $exampleName) = credentialsNaming('signs-test');

ensureDir($stage . '/' . $privateDir);
file_put_contents($stage . '/' . $privateDir . '/' . $sharedName,
                  Installer::credentialsSource($root));

// ---- And the one file that carries the rest of them ----------------------------
// The self-extracting copy, at the package root: `install.php` with the app inside it.
// One file to upload, which is one file that cannot lose part of itself in transit — the
// manual route's worst failure is an FTP client skipping `lib/.htaccess`, and a folder
// listing cannot see that it did.
//
// The payload holds `app/`'s files minus `install.php` itself: the installer is already
// on the server by the time it runs, and it deletes itself at the end rather than
// unpacking a second copy of itself over the top.
$payloadZip = $outDir . '/' . $name . '-payload.zip';
if (file_exists($payloadZip)) { unlink($payloadZip); }

$payloadFiles = [];
foreach ($manifest as $packagePath => $facts) {
    $inside = substr($packagePath, 4);           // strip 'app/'
    if ($inside === 'install.php') { continue; }
    $payloadFiles[$inside] = $stage . '/' . $packagePath;
}
ksort($payloadFiles);

$pz = new ZipArchive();
if ($pz->open($payloadZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "Could not open $payloadZip for writing\n";
    exit(1);
}
foreach ($payloadFiles as $inside => $onDisk) { $pz->addFile($onDisk, $inside); }
$pz->close();

$payloadBinary = (string) file_get_contents($payloadZip);
unlink($payloadZip);

// The marker is spelled in single quotes and joined, never interpolated: a `$` inside a
// double-quoted string is a variable, and the first draft of these three lines replaced
// the empty string with the empty string and wrote a 460 KB installer carrying nothing.
$marker          = '$APP_PAYLOAD';
$installerSource = (string) file_get_contents($root . '/install.php');
$carrying        = str_replace($marker . " = '';",
                               $marker . " = '" . base64_encode($payloadBinary) . "';",
                               $installerSource, $swapped);
if ($swapped !== 1) {
    echo "install.php no longer holds exactly one empty payload line to fill in.\n";
    exit(1);
}
file_put_contents($stage . '/install.php', $carrying);

// ---- The archive, read back by the code that will read it on the server --------
// Not "the zip was written". `installerZipEntries()` is the function `install.php` runs
// on somebody else's host, with no second chance and nobody watching, so the build runs
// it here — on the bytes it just produced — and compares every entry against the file on
// disk. A payload that cannot be read is a package that installs nothing, and the only
// place that is cheap to find out is here.
$readBack = installerZipEntries($payloadBinary, $zipWhy);
$checked++;
if ($readBack === null) {
    bad('the payload cannot be read by the reader that will have to read it: ' . $zipWhy,
        $failures, 'the payload is unreadable');
} else {
    $names = [];
    $wrong = [];
    foreach ($readBack as $entry) {
        if ($entry['dir']) { continue; }
        $names[] = $entry['name'];
        if (!isset($payloadFiles[$entry['name']])) {
            $wrong[] = $entry['name'] . ' is in the archive and not in the package';
            continue;
        }
        if ($entry['data'] !== file_get_contents($payloadFiles[$entry['name']])) {
            $wrong[] = $entry['name'] . ' reads back as different bytes';
        }
    }
    sort($names);
    $want = array_keys($payloadFiles);
    sort($want);
    if (!$wrong && $names === $want) {
        ok('the payload reads back through install.php\'s own reader as the '
           . count($want) . ' files it was built from');
    } else {
        bad('the payload does not read back as what went into it', $failures,
            'the payload does not round-trip');
        foreach ($wrong as $line) { echo "       + $line\n"; }
        foreach (array_diff($want, $names) as $missing) {
            echo "       + $missing is in the package and not in the archive\n";
        }
    }
}

// And that the carrying copy is the tracked file plus a payload, and nothing else. The
// self-extracting installer is generated, so it is the one file in the package nobody
// reviews — this is what holds it to being the reviewed one with a string in it.
$checked++;
if (strlen($carrying) > strlen($installerSource)
    && str_replace($marker . " = '" . base64_encode($payloadBinary) . "';",
                   $marker . " = '';", $carrying) === $installerSource) {
    ok('the self-extracting installer is the tracked install.php and one filled-in line');
} else {
    bad('the self-extracting installer differs from install.php by more than its payload',
        $failures, 'the carrying installer is not the tracked one');
}

// ---- And that every one of them ran -------------------------------------------
// The same anchor as `check_invariants.php` and `selftest_layout.php`, for the same
// reason: a gate that prints "clean" whether it ran four checks or twenty-eight is a
// gate whose coverage can shrink without anybody reading a diff. `check_invariants.php`
// was found without one (section 4bi) and the rehearsal printed clean over 59 checks or
// 7 (section 4bk). Update the number on purpose.
$expectedChecks = 37;
$checked++;
if ($checked === $expectedChecks) {
    ok("this build ran every check it is supposed to ($checked)");
} else {
    bad('this build did not run every check it is supposed to', $failures,
        'the build ran every check it is supposed to — expected ' . $expectedChecks
        . ', ran ' . $checked);
    echo "       expected $expectedChecks, ran $checked\n";
}

echo "\n$checked checks, " . count($failures) . " failed\n";
if ($failures) {
    foreach ($failures as $f) { echo "  FAILED: $f\n"; }
    echo "\nThe package was assembled and is being removed: a package that failed a check\n";
    echo "and is left on disk is one somebody uploads.\n";
    removeTree($stage);
    exit(1);
}

// ---- What was built, so an upload can be checked against it --------------------
$built = gmdate('Y-m-d H:i:s') . ' UTC';
$versionText = "Store Display System — installer package\n"
    . str_repeat('=', 40) . "\n\n"
    . 'Package    ' . $name . "\n"
    . 'Commit     ' . ($version === '' ? '(not a git checkout)' : $version) . "\n"
    . 'Built      ' . $built . "\n"
    . 'Files      ' . count($manifest) . " in app/\n\n"
    . "Requires   PHP 8.2 or newer, MySQL 5.7 or newer, and a MySQL database\n"
    . "           you can create tables in.\n\n"
    . "Read INSTALL.md before uploading anything. The two folders in this package go\n"
    . "to two different places on the server, and one of them is not the webroot.\n\n"
    . "MANIFEST.txt lists every file in app/ with its size and SHA-256, so an upload\n"
    . "that dropped a file can be found without guessing at which one.\n";
file_put_contents($stage . '/VERSION.txt', $versionText);

$manifestText = "Every file in app/, with the size and SHA-256 it left here with.\n"
    . "Package " . $name . ", built " . $built . ".\n\n"
    . "An FTP client that skips dotfiles is the mistake this is for: the three\n"
    . ".htaccess files are the only thing making lib/ and tools/ unreachable, and a\n"
    . "folder listing cannot tell you the guard did not arrive.\n\n";
ksort($manifest);
foreach ($manifest as $packagePath => $facts) {
    $manifestText .= $facts[1] . '  ' . str_pad((string) $facts[0], 8, ' ', STR_PAD_LEFT)
                   . '  ' . $packagePath . "\n";
}
file_put_contents($stage . '/MANIFEST.txt', $manifestText);

// ---- And a zip of the lot -----------------------------------------------------
$zipPath = $outDir . '/' . $name . '.zip';
if (file_exists($zipPath)) { unlink($zipPath); }

if (!class_exists('ZipArchive')) {
    echo "\nPackage assembled at $stage\n";
    echo "No ZipArchive in this PHP, so no .zip was written — zip the folder by hand.\n";
    exit(0);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    echo "Could not open $zipPath for writing\n";
    exit(1);
}
addTreeToZip($zip, $stage, $name);
$zip->close();

/** Every file under a directory, into the zip under one top-level folder. */
function addTreeToZip($zip, $dir, $prefix)
{
    $entries = scandir($dir);
    sort($entries);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            $zip->addEmptyDir($prefix . '/' . $entry);
            addTreeToZip($zip, $path, $prefix . '/' . $entry);
        } else {
            $zip->addFile($path, $prefix . '/' . $entry);
        }
    }
}

echo "\nPackage   $stage\n";
echo 'Zip       ' . $zipPath . ' (' . number_format(filesize($zipPath)) . " bytes)\n";
echo 'SHA-256   ' . hash_file('sha256', $zipPath) . "\n";
echo 'Contents  ' . count($manifest) . " files in app/, plus INSTALL.md, VERSION.txt,\n";
echo '          MANIFEST.txt and ' . $privateDir . '/' . $sharedName . "\n";
exit(0);
