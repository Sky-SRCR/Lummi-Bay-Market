<?php
// ============================================================
// COLOUR AUDIT — read-only, safe against the live database
// ============================================================
//   php tools/audit_colors.php
//
// On the live server that is the whole command: the credentials come from the same
// private file every page uses. Anywhere else, name the database:
//
//   php tools/audit_colors.php --host=127.0.0.1 --db=COPY --user=USER --pass=PASS
//   --port=3307 too, if it is not on the default port.
//
// **This one is safe to point at live, and its neighbour is not.** rehearse_phase1.php
// sits in this directory and demands --confirm-copy because it writes; the habit that
// flag builds is the reason this file has to say plainly why it has none. Every
// statement here is a SELECT. It reports and changes nothing — which is not politeness
// but the point: the fault it looks for is a colour nobody can read, and writing a
// colour of our own over it is precisely the defect #21 and #41 exist to stop. A
// person picks the colour.
//
// It does not include db_connect.php, which every page does. That file installs the
// error policy and arms the alert mailer, so a mistyped --host would email the store's
// admins "database unreachable" because somebody ran an audit. It reads the same
// credentials file instead; check_invariants.php holds those two to the same path.
//
// Exit code is 0 when every stored colour reads, 1 when something needs a person, and
// 2 when it could not look. So it can be run from cron later without being rewritten.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/color_audit.php';

// ---- Arguments ---------------------------------------------------------------

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = isset($m[2]) ? $m[2] : true;
    }
}
$opt = function ($name) use ($opts) {
    return (isset($opts[$name]) && $opts[$name] !== true) ? $opts[$name] : null;
};

// ---- Where to look -------------------------------------------------------------
// Flags win when they are given; otherwise the app's own credentials, so the command
// on the server is the command in the deployment checklist and nothing else.

$host = $opt('host');
$db   = $opt('db');
$user = $opt('user');
$pass = $opt('pass');

if ($host === null || $db === null || $user === null) {
    // Asked of the module rather than spelled again here. This file deliberately does
    // not include db_connect.php — that installs the error policy and arms the alert
    // mailer, so a mistyped --host would email the store's admins because somebody ran
    // an audit — but knowing where the credentials live *twice* is its own problem, and
    // it grew teeth once the answer stopped being a single fixed path: with two installs
    // on one account, a second opinion here would audit the live database while the
    // app in this folder is pointed at a copy. `lib/install_paths.php` is pure and
    // includes nothing, so asking it costs none of what db_connect.php would.
    require_once __DIR__ . '/../lib/install_paths.php';
    $credentialsFile = InstallPaths::credentialsFile(dirname(__DIR__));
    if ($credentialsFile === '') {
        $tried = implode("\n  ", InstallPaths::credentialsCandidates(dirname(__DIR__)));
        fwrite(STDERR, "No database named.\n\n"
            . "Looked for the app's credentials at:\n  $tried\n\n"
            . "Neither is there, so name one instead:\n"
            . "  php tools/audit_colors.php --host=HOST --db=NAME --user=USER --pass=PASS\n");
        exit(2);
    }
    require_once $credentialsFile;

    // And the second half of the same question, which the comment above was already
    // describing without being able to enforce: finding a file is not being allowed to
    // use it. An install with none of its own falls through to the shared file, and that
    // is a guess — so the shared file names the install it belongs to and one it does not
    // name is refused (invariant 32, §4aw). This is the second of the two doors, and it
    // is the one nothing would have caught: `db_connect.php` refusing means somebody sees
    // a page that says so, while an audit run from the wrong folder produces a report
    // about the live database and prints it under this folder's name.
    //
    // Only on the fall-back path, which is where this whole block already lives. A run
    // with --db names its own database and is not guessing at anything.
    if ($credentialsFile === InstallPaths::sharedCredentialsFile(dirname(__DIR__))) {
        $refusal = InstallPaths::sharedClaimRefusal(
            dirname(__DIR__),
            defined(InstallPaths::CLAIM) ? constant(InstallPaths::CLAIM) : null
        );
        if ($refusal !== '') {
            fwrite(STDERR, $refusal . "\n\n"
                . "Or name the database on the command line, which guesses at nothing:\n"
                . "  php tools/audit_colors.php --host=HOST --db=NAME --user=USER --pass=PASS\n");
            exit(2);
        }
    }

    if ($host === null) { $host = defined('DB_HOST') ? DB_HOST : null; }
    if ($db   === null) { $db   = defined('DB_NAME') ? DB_NAME : null; }
    if ($user === null) { $user = defined('DB_USER') ? DB_USER : null; }
    if ($pass === null) { $pass = defined('DB_PASS') ? DB_PASS : ''; }
    if ($host === null || $db === null || $user === null) {
        fwrite(STDERR, "The credentials file does not define DB_HOST, DB_NAME and DB_USER.\n");
        exit(2);
    }
}

$port = ($opt('port') !== null) ? ';port=' . intval($opt('port')) : '';

try {
    $pdo = new PDO(
        "mysql:host={$host}{$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass === null ? '' : $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    fwrite(STDERR, "Could not connect to {$db} on {$host}: " . $e->getMessage() . "\n");
    exit(2);
}

// ---- Looking -------------------------------------------------------------------

// The one thing here that is not in the database. `branding_config.php` sits beside
// the pages rather than in a table, so --host and --db say nothing about which one is
// read: it is always this checkout's. Worth knowing when auditing a copy — the brand
// findings describe the machine the script is on, not the database it connected to.
Brand::load();

$displays = new DisplayStore($pdo);
$audit    = new ColorAudit($displays, new LayoutStore($pdo, $displays), new BrandStyles($pdo));

try {
    $findings = $audit->findings();
} catch (Throwable $e) {
    // A database that predates a column this reads answers with an SQLSTATE rather
    // than an empty list, and "no findings" is exactly the wrong thing to print then.
    fwrite(STDERR, "Could not read {$db}: " . $e->getMessage() . "\n");
    exit(2);
}

/**
 * A stored value as a terminal can safely print it.
 *
 * The whole premise is that this string was put there by hand and never checked, so
 * it can hold an escape sequence, and a report that repaints somebody's terminal to
 * tell them about a colour is not a report. Bytes outside printable ASCII are shown
 * as \xNN — the value has to be quotable to be searched for.
 */
function quoted($value)
{
    $safe = preg_replace_callback('/[^\x20-\x7e]/', function ($m) {
        return '\\x' . strtoupper(bin2hex($m[0]));
    }, (string)$value);
    return '"' . $safe . '"';
}

echo "\nColour audit — {$db} on {$host}\n";
echo "Nothing here is changed by this script.\n";

if (!$findings) {
    echo "\nEvery stored colour reads. Nothing to do.\n\n";
    exit(0);
}

$headings = [
    ColorAudit::BLOCKS_PUBLISH => 'CANNOT BE PUBLISHED',
    ColorAudit::WRONG_ON_SIGN  => 'WRONG ON THE SIGN, QUIETLY',
    ColorAudit::WRONG_IN_APP   => 'WRONG ON THE STAFF PAGES ONLY',
];

// A finding with no Display of its own is shared, and by what differs by kind: a
// Brand Standards row is shared by every sign, a brand colour by no sign at all.
// One phrase for both would send somebody to the shop floor over a nav bar.
$shared = [
    ColorAudit::WRONG_ON_SIGN => 'every sign — ',
    ColorAudit::WRONG_IN_APP  => 'every staff page — ',
];

$shown = '';
foreach ($findings as $f) {
    if ($f['kind'] !== $shown) {
        echo "\n" . $headings[$f['kind']] . "\n";
        $shown = $f['kind'];
    }
    $where = $f['scope'] !== '' ? $f['scope'] . ' — ' : $shared[$f['kind']];
    echo "\n  " . $where . $f['what'] . " holds " . quoted($f['value']) . "\n";
    echo "    " . $f['consequence'] . "\n";
    echo "    → " . $f['fix'] . "\n";
}

$blocked = [];
foreach ($findings as $f) {
    if ($f['kind'] === ColorAudit::BLOCKS_PUBLISH) { $blocked[$f['scope']] = true; }
}

echo "\n" . count($findings) . " stored colour" . (count($findings) === 1 ? '' : 's')
   . " cannot be read";
echo $blocked
    ? ", and " . count($blocked) . " Display" . (count($blocked) === 1 ? '' : 's')
      . " cannot be published until somebody picks one: " . implode(', ', array_keys($blocked)) . ".\n\n"
    : ". None of them stops a publish.\n\n";

exit(1);
