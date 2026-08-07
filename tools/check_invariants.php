<?php
// ============================================================
// CONSISTENCY CHECKS — the greps from BUILD-REFERENCE §5, run
// ============================================================
//   php tools/check_invariants.php
//
// §5 lists a page of greps and, beside each, the files that are allowed to match
// and why. Running them was a manual step nobody could be relied on to do, and
// reading the output was a manual step after that — which is #51's other half.
// This turns the mechanical ones into a pass/fail.
//
// Two things it does that a shell grep cannot:
//
//   1. **It ignores comments.** Several of the §5 greps hit prose rather than
//      code. `lib/accounts.php` explains that deleting a user used to mean
//      `DELETE FROM users`, and `lib/layout_store.php` explains why
//      `catch (Exception)` was wrong — both would fail a naive grep for the very
//      rules they are documenting. Source is run through token_get_all() and the
//      comments are dropped, so only real statements are matched.
//
//   2. **It asserts the whole set of files, not just the count.** A rule is
//      "these files may match and no others", so a new file gaining a statement
//      it should not have is a failure even though the old ones still pass.
//
// What it deliberately does NOT cover is listed at the bottom and printed on
// every run, because a checker that quietly covers half of §5 reads like one that
// covers all of it.
//
// CLI only, and never reachable from the browser (tools/.htaccess).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);

// ---- Rules -------------------------------------------------------------------
// 'in'     — directory under the repo root to search, '' for everything
// 'skip'   — path prefixes to leave out, with a reason in the rule's own comment
// 'expect' — the files allowed to match, repo-relative and sorted. [] means none.
//
// This file is skipped by every rule, always: it quotes all of them by construction
// and would fail every one of them against itself.

$rules = [
    [
        'name'   => 'no surviving single-row assumption (`WHERE id = 1`)',
        'regex'  => '/WHERE\s+`?id`?\s*=\s*\'?1\'?/i',
        'in'     => '',
        // The suite addresses the seeded admin as account 1 throughout, which is a
        // fixture convention and not the retired assumption. This rule is about app
        // code, where the pattern meant the one canvas_settings row.
        'skip'   => ['tools/'],
        'expect' => [],
        'why'    => 'the app drove one sign out of one row; every query is scoped by Display now',
    ],
    [
        'name'   => 'lib/ catches Throwable, never Exception',
        'regex'  => '/catch\s*\(\s*Exception\s/',
        'in'     => 'lib',
        'expect' => [],
        'why'    => 'a TypeError is an Error, so `catch (Exception)` let a malformed payload '
                  . 'escape after the DELETEs had already run',
    ],
    [
        'name'   => 'nothing reads the raw request body',
        'regex'  => '#php://input#',
        'in'     => '',
        'expect' => [],
        'why'    => 'UploadLimit::bodyWasDropped() infers the post_max_size case from an empty '
                  . '$_POST, which only holds while nothing has consumed the body',
    ],
    [
        'name'   => 'accounts are closed, never deleted',
        'regex'  => '/DELETE\s+FROM\s+`?users`?/i',
        'in'     => '',
        'expect' => ['tools/selftest_layout.php'],
        'why'    => 'a freed account id handed to somebody new silently changes whose a stale '
                  . 'grant, a held lock or a publish record is (invariant 14)',
    ],
    [
        'name'   => 'one module reads the catalogue',
        'regex'  => '/information_schema\./i',
        'in'     => 'lib',
        'expect' => ['lib/schema.php'],
        'why'    => 'a second query is a second opinion about which columns exist; ask '
                  . 'readSchemaFacts() instead',
    ],
    [
        'name'   => 'every schema statement goes through the gated helper',
        'regex'  => '/schemaTry\(\$pdo/',
        'in'     => '',
        'expect' => ['lib/schema.php'],
        'why'    => 'a statement added anywhere else bypasses signageSchemaPlan() and is '
                  . 'therefore ungated and untested (invariant 19)',
    ],
    [
        'name'   => '`displays` has one writer',
        'regex'  => '/(INTO|UPDATE|FROM|JOIN|TABLE)\s+`?displays`?\b/i',
        'in'     => '',
        'expect' => ['lib/displays.php', 'lib/schema.php',
                     'tools/rehearse_phase1.php', 'tools/selftest_layout.php', 'tools/test_fixture.php'],
        'why'    => 'DisplayStore owns the table; the lock rules are decided from one query',
    ],
    [
        'name'   => '`display_permissions` has one writer',
        'regex'  => '/(INTO|UPDATE|FROM|JOIN|TABLE)\s+`?display_permissions`?\b/i',
        'in'     => '',
        'expect' => ['lib/grants.php', 'lib/schema.php',
                     'tools/rehearse_phase1.php', 'tools/selftest_layout.php', 'tools/test_fixture.php'],
        'why'    => 'a grant is the row\'s existence; GrantStore decides what that means',
    ],
    [
        'name'   => '`password_resets` has one writer, and the guess budget is not in a session',
        'regex'  => '/(INTO|UPDATE|FROM|TABLE)\s+`?password_resets`?\b|reset_attempts/i',
        'in'     => '',
        'expect' => ['lib/password_resets.php',
                     'tools/selftest_layout.php', 'tools/test_fixture.php'],
        'why'    => 'a counter kept in the visitor\'s session belongs to whoever is guessing '
                  . '(invariant 13)',
    ],
    [
        'name'   => '`block_styles` has one writer',
        'regex'  => '/(INTO|UPDATE|FROM|JOIN|TABLE)\s+`?block_styles`?\b/i',
        'in'     => '',
        'expect' => ['lib/brand_styles.php', 'lib/schema.php', 'tools/rehearse_phase1.php',
                     'tools/selftest_layout.php', 'tools/test_fixture.php'],
        'why'    => 'two writers disagreeing about what a partial POST means is how Brand '
                  . 'Standards reset every sign to black Arial 16',
    ],
    [
        'name'   => 'one module encodes JSON',
        'regex'  => '/json_encode\s*\(/',
        'in'     => '',
        'expect' => ['lib/error_policy.php', 'lib/http_reply.php', 'tools/selftest_layout.php'],
        // error_policy.php keeps one: the last-resort notice, which is the reply sent
        // when everything else has already failed and so cannot route through
        // HttpReply. It has checked for false since it was written.
        'why'    => 'json_encode returns false and `echo false` prints nothing, so a payload '
                  . 'holding one bad byte left as a zero-length 200 and a sign kept its old '
                  . 'layout for good (#26)',
    ],
    [
        'name'   => 'one module decides what a visitor sees when something breaks',
        'regex'  => '/display_errors|error_reporting\(|set_exception_handler|register_shutdown_function/',
        'in'     => 'lib',
        'expect' => ['lib/error_policy.php'],
        'why'    => 'a second file setting any of these is a second opinion (invariant 16)',
    ],
    [
        'name'   => 'one module knows what the upload ceiling is',
        'regex'  => '/post_max_size|upload_max_filesize|MAX_BYTES/',
        'in'     => 'lib',
        'expect' => ['lib/upload_limits.php', 'lib/server_report.php'],
        'why'    => 'a number in any other file is an opinion about a limit it cannot see '
                  . '(invariant 18)',
    ],
    [
        'name'   => 'no value is escaped for HTML and then used as JavaScript',
        'regex'  => '/on[a-z]+\s*=\s*"[^"]*\'[^"]*Markup::/i',
        'in'     => '',
        'expect' => [],
        'why'    => 'the HTML parser decodes an attribute before the JavaScript parser reads '
                  . 'it, so the &#039; ENT_QUOTES just produced is a plain quote again and the '
                  . 'string ends there — Markup::jsInAttr() is the whole argument, never part '
                  . 'of one (#15)',
    ],
    [
        'name'   => 'one module escapes for HTML',
        'regex'  => '/htmlspecialchars\s*\(/',
        'in'     => '',
        // error_policy.php keeps its own, for the same reason it keeps a json_encode:
        // it draws the last-resort notice, the one shown when everything else has
        // already failed, and a dependency there is a dependency in the one path that
        // must not have any. Its call passes the flags in full.
        'expect' => ['lib/error_policy.php', 'lib/markup.php'],
        'why'    => 'the default flag set changed in PHP 8.1, so an unflagged call escapes '
                  . 'single quotes on one host and not on another, and blanks the whole value '
                  . 'on one byte of bad UTF-8 (#15)',
    ],
    [
        'name'   => 'one path to the credentials that live outside the webroot',
        'regex'  => '/private\/db_credentials\.php/',
        'in'     => '',
        // tools/audit_colors.php deliberately does not include db_connect.php: that
        // file installs the error policy and arms the alert mailer, so a mistyped
        // --host would email the store's admins because somebody ran an audit. The
        // cost of not including it is that it knows where the credentials are, and
        // the cost of knowing that twice is this rule.
        'expect' => ['db_connect.php', 'tools/audit_colors.php'],
        'why'    => 'a second opinion about where the credentials live is one that goes stale '
                  . 'silently — the file is outside the repo and nothing else can catch it',
    ],
    [
        'name'   => 'the lock columns are read and written in one place',
        'regex'  => '/(SET|WHERE|SELECT|,)\s*`?lock_(holder_id|activity_at|taken_at)`?\s*(=|,|\s|$)/i',
        'in'     => 'lib',
        // lib/schema.php names them in the CREATE TABLE it converges — a catalogue
        // entry, "a column this database should have", which §5 distinguishes from
        // a read of the table. Only lib/displays.php may query them.
        'expect' => ['lib/displays.php', 'lib/schema.php'],
        'why'    => 'a read and a write that disagree about who holds a sign disagree silently '
                  . '(§4t)',
    ],
];

// ---- Running them --------------------------------------------------------------

/** Every .php file under a directory, repo-relative, sorted, minus the skips. */
function phpFilesUnder($root, $sub, array $skip = [])
{
    // Always: this file quotes every pattern it tests for.
    $skip[] = 'tools/check_invariants.php';
    $skip[] = 'vendor/';

    $base  = $sub === '' ? $root : $root . '/' . $sub;
    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
        $path = str_replace($root . '/', '', $file->getPathname());
        foreach ($skip as $prefix) {
            if (strpos($path, $prefix) === 0) { continue 2; }
        }
        $found[] = $path;
    }
    sort($found);
    return $found;
}

/**
 * The file's source with every comment removed and each one replaced by a newline,
 * so reported line numbers still mean something.
 */
function codeWithoutComments($source)
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }
    return $out;
}

$failures = [];
$checked  = 0;

foreach ($rules as $rule) {
    $matched = [];
    $lines   = [];

    foreach (phpFilesUnder($root, $rule['in'], isset($rule['skip']) ? $rule['skip'] : []) as $path) {
        $code = codeWithoutComments(file_get_contents($root . '/' . $path));
        if (preg_match_all($rule['regex'], $code, $m, PREG_OFFSET_CAPTURE)) {
            $matched[] = $path;
            foreach ($m[0] as $hit) {
                $lines[] = $path . ':' . (substr_count(substr($code, 0, $hit[1]), "\n") + 1)
                         . '  ' . trim($hit[0]);
            }
        }
    }

    sort($matched);
    $expected = $rule['expect'];
    sort($expected);
    $checked++;

    if ($matched === $expected) {
        echo "  ok   " . $rule['name'] . "\n";
        continue;
    }

    $unexpected = array_values(array_diff($matched, $expected));
    $missing    = array_values(array_diff($expected, $matched));

    echo "  FAIL " . $rule['name'] . "\n";
    echo "       " . $rule['why'] . "\n";
    foreach ($unexpected as $path) {
        echo "       + $path matches and is not on the allowed list\n";
        foreach ($lines as $line) {
            if (strpos($line, $path . ':') === 0) { echo "           $line\n"; }
        }
    }
    foreach ($missing as $path) {
        // Not a violation on its own — the code may have moved on — but the list
        // has to be corrected rather than left describing a file that no longer
        // matches, or it stops meaning anything.
        echo "       - $path is on the allowed list but no longer matches; update the list\n";
    }
    $failures[] = $rule['name'];
}

// ---- The one that is about a link rather than a statement ----------------------
// ADR-0003: every Viewer URL names its Display. A bare viewer.php link is the "no
// display specified" notice, which is what help.php was pointing at.
//
// The pattern is §5's own — `viewer.php` immediately followed by a quote — and the
// precision matters in both directions. Broadened to "viewer.php not followed by a
// ?", it flags help.php explaining in prose that a bare URL shows the notice, and
// the self-test's alert keys like 'fatal|viewer.php:22'. A closing quote is what
// makes a string a link rather than a mention.
$badLinks = [];
foreach (phpFilesUnder($root, '') as $path) {
    $code = codeWithoutComments(file_get_contents($root . '/' . $path));
    if (preg_match_all('/viewer\.php["\']/', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $hit) {
            $badLinks[] = $path . ':' . (substr_count(substr($code, 0, $hit[1]), "\n") + 1);
        }
    }
}
$checked++;
if (!$badLinks) {
    echo "  ok   every viewer.php reference carries a Display (ADR-0003)\n";
} else {
    echo "  FAIL a viewer.php reference does not name a Display (ADR-0003)\n";
    foreach ($badLinks as $where) { echo "       $where\n"; }
    $failures[] = 'viewer.php links carry a Display';
}

// ---- Nothing HTML-escaped goes inside a <script> --------------------------------
// The other half of #15, and the half a plain grep cannot decide: the same value, the
// same escaping call, and whether it is safe depends on which element it lands in.
//
// Inside `<script>` the HTML parser decodes nothing — the content is raw text until
// `</script`. So `Markup::text()` there is not merely useless, it is misleading in
// two directions: `&#039;` reaches the reader as six literal characters, and — the
// part that bites — `htmlspecialchars()` does not touch a backslash. A value ending
// in one, dropped into `var x = '…';`, escapes the quote that was meant to close the
// string, and everything after it is code.
//
// Region-aware because it has to be: admin_panel.php mentions `<script>` inside a PHP
// comment explaining why SVG uploads are refused, and a regex that believed it would
// treat two thirds of the file as JavaScript. Only T_INLINE_HTML moves the state, so
// what PHP comments say about script tags cannot move it.
$badInScript = [];
foreach (phpFilesUnder($root, '', ['lib/', 'tools/']) as $rel) {
    $inScript = false;
    $echoing  = null;
    foreach (token_get_all(file_get_contents($root . '/' . $rel)) as $t) {
        if (is_array($t) && $t[0] === T_INLINE_HTML) {
            $open  = strripos($t[1], '<script');
            $close = strripos($t[1], '</script');
            if ($open !== false || $close !== false) {
                $inScript = ($open !== false && ($close === false || $open > $close));
            }
            continue;
        }
        if (is_array($t) && ($t[0] === T_OPEN_TAG_WITH_ECHO || $t[0] === T_ECHO)) {
            $echoing = '';
            continue;
        }
        if ($echoing === null) { continue; }
        if (is_array($t) && $t[0] === T_CLOSE_TAG) {
            if ($inScript && strpos($echoing, 'Markup::') !== false) {
                $badInScript[] = $rel . ': ' . trim(preg_replace('/\s+/', ' ', $echoing));
            }
            $echoing = null;
            continue;
        }
        $echoing .= is_array($t) ? $t[1] : $t;
    }
}
$checked++;
if (!$badInScript) {
    echo "  ok   nothing escaped for HTML is echoed inside a <script> (#15)\n";
} else {
    echo "  FAIL a value escaped for HTML is echoed inside a <script> (#15)\n";
    foreach ($badInScript as $where) { echo "       $where\n"; }
    echo "       HttpReply::jsValue() is the escaping for that element.\n";
    $failures[] = 'HTML escaping inside a <script>';
}

// ---- What this does not cover ---------------------------------------------------
echo "\nStill by eye — §5 greps this cannot decide:\n";
foreach ([
    'canvas_elements — the endpoint NAME get_canvas_elements is indistinguishable '
        . 'from the table by pattern alone',
    'ErrorPolicy::report callers — a new one is allowed, but has to be read for '
        . 'whether it can repeat (invariant 20)',
    'ensureSignageSchema() call POSITION — within the first lines of an entry point, '
        . 'before any transaction exists (invariant 21)',
    'grants_accounts / grants_displays — both names must appear twice each, which is '
        . 'about the form\'s shape rather than about which files match',
    'schema.sql against lib/schema.php — now covered instead by the MySQL self-test '
        . 'run, which asserts convergence has nothing left to do (#48)',
] as $note) {
    echo "  · $note\n";
}

echo "\n$checked consistency checks, " . count($failures) . " failed\n";
if ($failures) {
    foreach ($failures as $f) { echo "  FAILED: $f\n"; }
    exit(1);
}
exit(0);
