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
        'name'   => 'one module sanitises signage text',
        'regex'  => '/strip_tags\s*\(|html_entity_decode\s*\(/',
        'in'     => '',
        // lib/plain_text.php is the sanitiser, and the *order* of its statements is
        // load-bearing in both directions (§4am): a "<" that cannot open a tag is
        // escaped before the strip, and the decode runs after it. Two callers used to
        // reach for strip_tags themselves on already-plain text — crud.php's asset
        // preview and assets.php's auto-label — and both lost everything after a "<",
        // so a label disagreed with the sign it came off. Both ask toPlainText() now,
        // which is what makes this a one-file rule rather than a three-file one.
        // The self-test's html_entity_decode() calls run the other way, undoing an
        // escape to assert it happened; that is the only other place either belongs.
        'expect' => ['lib/plain_text.php', 'tools/selftest_layout.php'],
        'why'    => 'strip_tags() is not a parser: it deletes from a "<" to the end of a value '
                  . 'when nothing closes it, so a second caller is a second chance to lose a '
                  . 'price line silently (#49, §4am)',
    ],
    [
        'name'   => 'one path to the credentials that live outside the webroot',
        'regex'  => '/db_credentials/',
        'in'     => '',
        // lib/install_paths.php is the one owner now, and the rule got sharper when
        // it moved there. While the answer was a single fixed path, a second copy was
        // merely stale-able; with two installs on one account it is a live hazard —
        // the app in a folder pointed at a copy of the database, and something beside
        // it still resolving to the live one. db_connect.php and tools/audit_colors.php
        // both ask the module. The self-test names the paths because it asserts them.
        'expect' => ['lib/install_paths.php', 'tools/selftest_layout.php'],
        'why'    => 'a second opinion about where the credentials live is one that goes stale '
                  . 'silently — the file is outside the repo and nothing else can catch it',
    ],
    [
        'name'   => 'one module reads the store\'s own colours',
        'regex'  => '/BRAND_(NAV_BG|NAV_BORDER|ACCENT|TEXT)\b/',
        'in'     => '',
        // branding_config.php is the file itself. lib/branding.php holds the canonical
        // list of the eight names and renders the define() lines (§4y) — naming them is
        // what that module is for. admin_panel.php names four of them as the keys of a
        // save, not as values it paints with. lib/brand.php is the only *reader*.
        //
        // A page other than these is a page that has gone back to interpolating
        // whatever the file holds into its own stylesheet. The self-test is listed
        // because it pins a value to prove a save leaves the other seven alone; the
        // rule is about pages, and there is nowhere else for a test of it to live.
        'expect' => ['admin_panel.php', 'branding_config.php', 'lib/brand.php',
                     'lib/branding.php', 'tools/selftest_layout.php'],
        'why'    => 'these land in a <style> block, where there is no delimiter to escape '
                  . 'and a value that is not a colour is CSS — Color::read() is what makes '
                  . 'them safe, and it is called once (§4ai)',
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

// ---- Every echo on a page is accounted for --------------------------------------
// The rule above catches escaping something the *wrong way*. This one catches not
// escaping it at all, which is the failure that needs no mistake — just a new line on
// an existing page and nobody remembering that `{{ $u['name'] }}` is the whole of #15.
// (Short-echo tags are written `{{ … }}` here for the reason lib/markup.php's header
// gives: a real `?` followed by `>` inside a `//` comment ends PHP mode, and the file
// still lints clean.)
//
// It is a classifier rather than an allow-list. Every echoed expression on a page has
// to be one of five things, and each of the five is safe for a reason that can be
// checked here rather than looked up:
//
//   a door        Markup::text(), Markup::jsInAttr(), HttpReply::jsValue() — the three
//                 functions that exist to answer this question.
//   a literal     a quoted string or a number, written in the source.
//   a safe call   count(), intval(), intdiv(), floatval(), number_format(),
//                 urlencode(), rawurlencode(), and date() with a literal format. Each
//                 returns only digits, or only characters no parser is looking for —
//                 no quote, no angle bracket, no backslash — whatever it is handed.
//   a colour      Brand::navBg() and its three siblings, which return `#rrggbb` or a
//                 default because Color::read() decided (§4ai). The one case in this
//                 app where escaping would have been the wrong tool: they land in a
//                 <style> block, which has no delimiter to escape.
//   a number      a constant whose declaration is a numeric literal — a class constant
//                 declared in lib/, or a define() in config.php, which is where this
//                 app declares a non-database setting. Resolved here, not assumed:
//                 `Foo::BAR = 'x <b>'` does not pass, and neither does a BRAND_* name.
//
// A ternary is safe when both of its branches are, and a concatenation when every
// piece of it is. The condition of a ternary is not echoed, so it is not examined —
// `$u['is_active'] ? 'Active' : 'Inactive'` is two literals and a question about a
// database column. Both rules are recursive, which is what makes the five shapes
// enough: `' · ' . Markup::text($d->location())` is a literal and a door.
//
// Nothing is on a list of exceptions, deliberately. Fifteen echoes were converted to
// get here — ids to intval(), labels to Markup::text() — because an allow-list is a
// place to put the next one too, and the list stops being read at about the tenth
// entry. Widening the classifier is a change to what "safe" means and is reviewed as
// one; adding an int to the seven safe calls is not.
//
// Limits worth stating. `intval($x)` is trusted without asking what `$x` is, which is
// the point — that is what intval is for, and a checker that went looking would be
// re-implementing PHP. It reads one expression at a time and knows nothing about where
// a value came from, so it cannot tell a safe `$id` from an unsafe one and does not
// try: it asks only that the line say which it is. And an echo inside a `<script>` is
// checked by the rule above, not this one — they answer different questions about the
// same line, and both have to be satisfied.

$SAFE_CALLS  = ['count', 'intval', 'intdiv', 'floatval', 'number_format',
                'urlencode', 'rawurlencode', 'date'];
$SAFE_STATIC = ['Markup::text', 'Markup::jsInAttr', 'HttpReply::jsValue',
                'Brand::navBg', 'Brand::navBorder', 'Brand::accent', 'Brand::text'];

/**
 * Every `define('NAME', <number>);` in config.php, as 'NAME'.
 *
 * config.php is where this app declares a non-database setting, and it is the one
 * file whose whole job is declaring them — which is what makes the scan narrow
 * enough to be a rule rather than a search. A `define()` anywhere else does not
 * qualify a name, for the same reason a class constant has to be declared in lib/:
 * the classifier resolves the declaration instead of trusting the shape of the use.
 */
function numericGlobalConstants($root)
{
    $found = [];
    $file = $root . '/config.php';
    if (!@is_file($file)) { return $found; }
    $ts = array_values(array_filter(token_get_all(file_get_contents($file)),
        function ($t) { return !is_array($t) || ($t[0] !== T_WHITESPACE && $t[0] !== T_COMMENT
                                                 && $t[0] !== T_DOC_COMMENT); }));
    for ($i = 0; $i < count($ts); $i++) {
        // `define ( 'NAME' , <number> )` and nothing else — an expression for either
        // argument is a second opinion about PHP, and there are none of those here.
        if (is_array($ts[$i]) && $ts[$i][0] === T_STRING && strtolower($ts[$i][1]) === 'define'
            && isset($ts[$i + 5]) && $ts[$i + 1] === '('
            && is_array($ts[$i + 2]) && $ts[$i + 2][0] === T_CONSTANT_ENCAPSED_STRING
            && $ts[$i + 3] === ','
            && is_array($ts[$i + 4])
            && ($ts[$i + 4][0] === T_LNUMBER || $ts[$i + 4][0] === T_DNUMBER)
            && $ts[$i + 5] === ')') {
            $found[trim($ts[$i + 2][1], "'\"")] = true;
        }
    }
    return $found;
}

/** Every `const NAME = <number>;` declared in lib/, as 'Class::NAME'. */
function numericClassConstants($root)
{
    $found = [];
    foreach (phpFilesUnder($root, 'lib') as $rel) {
        $class = null;
        $ts = array_values(array_filter(token_get_all(file_get_contents($root . '/' . $rel)),
            function ($t) { return !is_array($t) || ($t[0] !== T_WHITESPACE && $t[0] !== T_COMMENT
                                                     && $t[0] !== T_DOC_COMMENT); }));
        for ($i = 0; $i < count($ts); $i++) {
            $t = $ts[$i];
            if (is_array($t) && $t[0] === T_CLASS && isset($ts[$i + 1]) && is_array($ts[$i + 1])) {
                $class = $ts[$i + 1][1];
                continue;
            }
            // `const NAME = <number> ;` and nothing else. A negative or an expression
            // is not matched — not because it would be unsafe, but because deciding
            // that here is a second opinion about PHP, and there are none of either.
            if ($class !== null && is_array($t) && $t[0] === T_CONST
                && isset($ts[$i + 4]) && is_array($ts[$i + 1]) && $ts[$i + 2] === '='
                && is_array($ts[$i + 3])
                && ($ts[$i + 3][0] === T_LNUMBER || $ts[$i + 3][0] === T_DNUMBER)
                && $ts[$i + 4] === ';') {
                $found[$class . '::' . $ts[$i + 1][1]] = true;
            }
        }
    }
    return $found;
}

/** The expression's tokens, whitespace and comments dropped. */
function expressionTokens($expr)
{
    $ts = token_get_all('<?php ' . $expr . ';');
    array_shift($ts);                 // the open tag this had to be given
    $out = [];
    foreach ($ts as $t) {
        if (is_array($t) && ($t[0] === T_WHITESPACE || $t[0] === T_COMMENT
                             || $t[0] === T_DOC_COMMENT)) { continue; }
        $out[] = $t;
    }
    if (end($out) === ';') { array_pop($out); }
    return $out;
}

/** Is this run of tokens one of the five shapes, or a ternary of two that are? */
function echoIsAccountedFor(array $ts, array $safeCalls, array $safeStatic, array $numericConsts)
{
    if (!$ts) { return false; }

    // ---- parentheses that wrap the whole thing, which say nothing ----
    // `$a ? ($b ? 'p' : 'q') : 'r'` is two safe branches, and without this the inner
    // one is a `(` the ternary scan below never sees past.
    while (count($ts) > 1 && $ts[0] === '(') {
        $d = 0; $end = null;
        for ($i = 0; $i < count($ts); $i++) {
            if ($ts[$i] === '(') { $d++; }
            if ($ts[$i] === ')') { $d--; if ($d === 0) { $end = $i; break; } }
        }
        if ($end !== count($ts) - 1) { break; }   // `(a) . (b)` — not a wrapper
        $ts = array_slice($ts, 1, count($ts) - 2);
        if (!$ts) { return false; }
    }

    // ---- a ternary, if there is one at the top level ----
    $depth = 0;
    for ($i = 0; $i < count($ts); $i++) {
        $t = $ts[$i];
        if ($t === '(' || $t === '[' || $t === '{') { $depth++; continue; }
        if ($t === ')' || $t === ']' || $t === '}') { $depth--; continue; }
        if ($depth !== 0 || $t !== '?') { continue; }
        // Find this `?`'s own `:`, stepping over any nested ternary between them.
        $d = 0; $nested = 0;
        for ($j = $i + 1; $j < count($ts); $j++) {
            $u = $ts[$j];
            if ($u === '(' || $u === '[' || $u === '{') { $d++; continue; }
            if ($u === ')' || $u === ']' || $u === '}') { $d--; continue; }
            if ($d !== 0) { continue; }
            if ($u === '?') { $nested++; continue; }
            // T_DOUBLE_COLON is its own token, so a bare ':' cannot be Foo::BAR.
            if ($u === ':') {
                if ($nested > 0) { $nested--; continue; }
                $left  = array_slice($ts, $i + 1, $j - $i - 1);
                $right = array_slice($ts, $j + 1);
                // `$a ?: $b` echoes the condition as well, so it has to hold up too.
                if (!$left && !echoIsAccountedFor(array_slice($ts, 0, $i),
                        $safeCalls, $safeStatic, $numericConsts)) { return false; }
                return ($left === [] || echoIsAccountedFor($left, $safeCalls, $safeStatic, $numericConsts))
                    && echoIsAccountedFor($right, $safeCalls, $safeStatic, $numericConsts);
            }
        }
        return false;   // a `?` with no `:` of its own is not an expression we can read
    }

    // ---- a concatenation, when every piece of it holds up on its own ----
    // Below the ternary, because `.` binds tighter: `$a ? 'x' : 'y' . 'z'` is a ternary
    // whose second branch is a concatenation, and splitting the other way round would
    // read the `?` as part of a piece.
    $depth = 0; $parts = []; $part = [];
    foreach ($ts as $t) {
        if ($t === '(' || $t === '[' || $t === '{') { $depth++; }
        if ($t === ')' || $t === ']' || $t === '}') { $depth--; }
        if ($depth === 0 && $t === '.') { $parts[] = $part; $part = []; continue; }
        $part[] = $t;
    }
    if ($parts) {
        $parts[] = $part;
        foreach ($parts as $p) {
            if (!echoIsAccountedFor($p, $safeCalls, $safeStatic, $numericConsts)) { return false; }
        }
        return true;
    }

    // ---- one literal, written out in the source ----
    if (count($ts) === 1 && is_array($ts[0])
        && in_array($ts[0][0], [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)) {
        return true;
    }

    // ---- a class constant whose declared value is a number ----
    if (count($ts) === 3 && is_array($ts[0]) && $ts[0][0] === T_STRING
        && is_array($ts[1]) && $ts[1][0] === T_DOUBLE_COLON
        && is_array($ts[2]) && $ts[2][0] === T_STRING) {
        return isset($numericConsts[$ts[0][1] . '::' . $ts[2][1]]);
    }

    // ---- a global constant whose define() in config.php is a number ----
    // Same shape and the same reason as the case above: what makes it safe is that
    // the declaration is a numeric literal, and that is resolved rather than assumed.
    // A `BRAND_*` name is not reachable this way — those are strings, so they fall
    // through to false, which is the colour rule's business (§4ai).
    if (count($ts) === 1 && is_array($ts[0]) && $ts[0][0] === T_STRING) {
        return isset($numericConsts[$ts[0][1]]);
    }

    // ---- one call, whose parentheses run to the end of the expression ----
    $name = null; $openAt = null;
    if (is_array($ts[0]) && $ts[0][0] === T_STRING && isset($ts[1]) && $ts[1] === '(') {
        $name = $ts[0][1]; $openAt = 1;
    } elseif (count($ts) > 3 && is_array($ts[0]) && $ts[0][0] === T_STRING
              && is_array($ts[1]) && $ts[1][0] === T_DOUBLE_COLON
              && is_array($ts[2]) && $ts[2][0] === T_STRING && $ts[3] === '(') {
        $name = $ts[0][1] . '::' . $ts[2][1]; $openAt = 3;
    }
    if ($name === null) { return false; }
    if (!in_array($name, $safeCalls, true) && !in_array($name, $safeStatic, true)) { return false; }

    $d = 0;
    for ($i = $openAt; $i < count($ts); $i++) {
        if ($ts[$i] === '(') { $d++; }
        if ($ts[$i] === ')') { $d--; if ($d === 0) { return $i === count($ts) - 1; } }
    }
    return false;
}

/** date() is only as safe as its format string, which therefore has to be written out. */
function dateFormatIsLiteral(array $ts)
{
    if (!is_array($ts[0]) || $ts[0][0] !== T_STRING || $ts[0][1] !== 'date') { return true; }
    return isset($ts[2]) && is_array($ts[2]) && $ts[2][0] === T_CONSTANT_ENCAPSED_STRING;
}

$numericConsts = numericClassConstants($root) + numericGlobalConstants($root);
$unaccounted   = [];
foreach (phpFilesUnder($root, '', ['lib/', 'tools/']) as $rel) {
    $echoing = null; $startLine = 0;
    foreach (token_get_all(file_get_contents($root . '/' . $rel)) as $t) {
        if (is_array($t) && ($t[0] === T_OPEN_TAG_WITH_ECHO || $t[0] === T_ECHO)) {
            $echoing = ''; $startLine = $t[2]; continue;
        }
        if ($echoing === null) { continue; }
        if ((is_array($t) && $t[0] === T_CLOSE_TAG) || $t === ';') {
            $expr = trim(preg_replace('/\s+/', ' ', $echoing));
            if ($expr !== '') {
                $ts = expressionTokens($expr);
                if (!$ts || !echoIsAccountedFor($ts, $SAFE_CALLS, $SAFE_STATIC, $numericConsts)
                    || !dateFormatIsLiteral($ts)) {
                    $unaccounted[] = $rel . ':' . $startLine . '  ' . $expr;
                }
            }
            $echoing = null; continue;
        }
        $echoing .= is_array($t) ? $t[1] : $t;
    }
}
$checked++;
if (!$unaccounted) {
    echo "  ok   every echo on a page is a door, a literal, a safe call or a number (#15)\n";
} else {
    echo "  FAIL an echo on a page is none of the shapes that are safe by construction (#15)\n";
    foreach ($unaccounted as $where) { echo "       $where\n"; }
    echo "       Markup::text() for a value, Markup::jsInAttr() for one an event handler\n";
    echo "       takes, HttpReply::jsValue() inside a <script>, intval() for an id.\n";
    $failures[] = 'an echo on a page is unaccounted for';
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
