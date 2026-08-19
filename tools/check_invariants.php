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
        'name'   => 'an account with no sign is refused the shared writes by one predicate',
        'regex'  => '/holdsASign\s*\(|NO_SIGN_REFUSAL/',
        'in'     => '',
        // lib/grants.php declares both — the predicate and the one sentence the refusal
        // is worded in. crud.php and api.php are the two doors: the Library's add form
        // and the image upload, the only writes a `basic` account can reach that are not
        // scoped to a Display by DisplayRequest. Both must appear, and appear together:
        // a door holding the predicate but wording its own refusal is the same rule met
        // twice in different English, which reads as two different problems.
        'expect' => ['api.php', 'crud.php', 'lib/grants.php', 'tools/selftest_layout.php'],
        'why'    => 'a shared library and an uploads folder are the two things in this app '
                  . 'nothing else scopes to a sign, so an account holding no sign wrote to '
                  . 'both from a page that had just told it there was nothing to edit '
                  . '(#33, invariant 29)',
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
        'name'   => 'one module reads a stored moment',
        'regex'  => '/strtotime\s*\(/',
        'in'     => '',
        // The self-test calls it directly on purpose, and in both directions: with the
        // ' UTC' suffix to assert a stamp is absolute, and *without* it to assert that
        // reading the same string as local time is hours out — which is the mutation
        // this rule exists to keep dead. That second form is the defect, so it may only
        // live in a file whose job is to fail when it comes back.
        'expect' => ['lib/store_clock.php', 'tools/selftest_layout.php'],
        'why'    => 'every stamp this app stores is UTC and strtotime() reads a bare '
                  . 'Y-m-d H:i:s in the process zone, so the suffix is the whole rule — '
                  . 'it was written out three times and the third copy left it off, and '
                  . 'nothing could see the difference (invariant 28, §4ap). Ask '
                  . 'StoreClock::epochOf()',
    ],
    [
        'name'   => 'no statement asks the database what time it is',
        // Case-sensitive, and that is not laziness: `builder.php` has a `now()` helper in
        // its own JavaScript, six calls of it, and an insensitive pattern reads all six as
        // SQL. Every keyword in this repo's SQL is upper case, and the reason a lower-case
        // one could not hide anyway is the module rules — SQL lives in `lib/`, which is
        // where the two allowed matches are.
        'regex'  => '/CURRENT_TIMESTAMP|\bNOW\s*\(\s*\)/',
        'in'     => '',
        // The two that may match are column *defaults* in a CREATE TABLE — a property
        // of the schema, which PHP cannot write and which db_connect.php puts in the
        // right frame by asking the connection for +00:00. What is forbidden is an
        // INSERT or UPDATE taking the time from MySQL, because that is MySQL's session
        // zone: a third clock beside PHP's and the store's, and the one nobody could see.
        'expect' => ['lib/schema.php', 'tools/test_fixture.php'],
        'why'    => 'recordPublish() wrote last_published_at this way and '
                  . 'lastPublishDescription() read it as though PHP had, so a refused '
                  . 'publish named an hour off by the difference between two zones nobody '
                  . 'had set (invariant 28, §4ap). Bind a gmdate() instead',
    ],
    [
        'name'   => 'one module decides which zone a person reads a time in',
        'regex'  => '/date_default_timezone_set\s*\(/',
        'in'     => '',
        // config.php calls StoreClock::apply(); it does not name the underlying function,
        // which is the shape of the rule. tools/ moves the process clock about on purpose,
        // to prove that what is *stored* does not depend on where it is set.
        'expect' => ['lib/store_clock.php', 'tools/selftest_layout.php'],
        'why'    => 'a second file setting the process zone is a second answer to "what '
                  . 'time is it in the shop", and the pages that disagree are the ones '
                  . 'nobody compares side by side (§4ap)',
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

    // ---- Four that used to be on the by-eye list (#50) --------------------------
    // Each was listed at the bottom of this file, and in §5, as something no pattern
    // could decide. Three of the four reasons turned out to be about the *pattern*
    // rather than about the question, and the fourth was about this file's rule format
    // rather than either. What decided each one is in its own comment, because
    // "mechanised" is not a thing to take on trust from a list that just got shorter.
    [
        'name'   => '`canvas_elements` has one writer, and the endpoint of that name is not it',
        // The stated obstacle was that `get_canvas_elements` — an API action, a string
        // in three files — is indistinguishable from the table. It is one lookbehind
        // apart from it. Everything else that used to make this grep noisy was prose,
        // which this file already drops: eight files explain what the table is for.
        'regex'  => '/(?<!get_)canvas_elements/',
        'in'     => '',
        'skip'   => ['tools/'],
        // layout_store.php owns every statement. schema.php converges its structure —
        // the columns, the two widened ENUMs, the display_id tighten and its backfill.
        // server_report.php names it once, in the list of columns this database should
        // have, which is a catalogue entry rather than a read (the same distinction the
        // lock-column rule above draws).
        'expect' => ['lib/layout_store.php', 'lib/schema.php', 'lib/server_report.php'],
        'why'    => 'the table every sign\'s layout lives in has one writer, so an '
                  . 'unscoped statement cannot be written twice — invariant 1',
    ],
    [
        'name'   => 'the admin alert has three callers, and a fourth is a decision',
        'regex'  => '/ErrorPolicy::report/',
        'in'     => '',
        'skip'   => ['tools/'],
        // The judgement §5 asks for — can this fire repeatedly on a condition the app
        // expected? — is still a person's, and it stays on the by-eye list below for
        // that reason. What is mechanical is *noticing*: a fourth caller used to be
        // invisible, and now it fails this check and has to be read before it lands.
        // db_connect.php mentions it in a comment saying why it does NOT report there,
        // which is exactly the kind of hit that made this look undecidable.
        'expect' => ['api.php', 'lib/http_reply.php', 'lib/schema.php'],
        'why'    => 'a new caller is a new thing an admin gets emailed about, and one that '
                  . 'can repeat on its own needs a window — invariant 20',
    ],
    [
        'name'   => 'the store\'s zone is named as a key in three files and read as a constant in none',
        // The stated obstacle was that a page naming the setting as the key of a save
        // looks identical to a page reading it. True of the quoted name — and the rule
        // is not about the quoted name. It is that one module reads the setting, and
        // that module reads it through `constant(self::SETTING)`, so the *bare* constant
        // is spelled nowhere in the repo at all. That is the rule below; this one is its
        // other half, holding the quoted spelling to the three files entitled to it:
        // branding.php declares the default, store_clock.php names it once as SETTING,
        // and admin_panel.php passes it as the key of a save.
        'regex'  => '/[\'"]STORE_TIMEZONE[\'"]/',
        'in'     => '',
        'skip'   => ['tools/'],
        'expect' => ['admin_panel.php', 'lib/branding.php', 'lib/store_clock.php'],
        'why'    => 'a fourth file spelling the name is a fourth opinion about where the '
                  . 'store\'s zone comes from (invariant 28, §4ap)',
    ],
    [
        'name'   => 'nothing reads STORE_TIMEZONE as a constant, not even its own module',
        // The strong form, and the one worth having: `constant(self::SETTING)` behind
        // `defined()` is how StoreClock reads it, which is what makes "absent" a value
        // the module can answer for rather than a fatal. A bare `STORE_TIMEZONE` in an
        // expression is a page reading the setting directly — and on an installation
        // whose generated config predates the setting, that is an undefined-constant
        // Error on whichever page did it.
        'regex'  => '/(?<![\'"\w])STORE_TIMEZONE(?![\'"\w])/',
        'in'     => '',
        'skip'   => ['tools/'],
        'expect' => [],
        'why'    => 'the setting is absent on every installation that has not saved the '
                  . 'Settings form, so reading it directly is an Error on that page — ask '
                  . 'StoreClock::zone(), which answers the documented default',
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
 *
 * Both kinds of comment, since #50. It used to drop only the PHP ones, and said so at
 * length — which meant an `<!-- … -->` on a page explaining why a line no longer calls
 * `strtotime()` failed invariant 28 against the very sentence explaining why the rule
 * holds. #44 found that while adding those greps, wrote the note it needed as a PHP
 * comment instead, and left the checker alone on purpose: what to do about PHP embedded
 * inside an HTML comment changes what all twenty-six rules see, which is a measurement
 * question rather than a fix to bundle into a rule.
 *
 * **The answer is that an HTML comment with PHP in it is code and stays.** A comment is
 * dropped only when it opens and closes inside one `T_INLINE_HTML` token, which is
 * exactly the case where nothing in it executes. Write
 *
 *     <!-- the price is <?= Markup::text($p) ?> -->
 *
 * and the `<!--` and `-->` land in two different tokens with a live call between them:
 * that call runs, its output reaches the page inside a comment a browser hides, and a
 * rule about it must still see it. Dropping the span between the two tokens would blind
 * every rule to whatever a page hid that way, which is the one outcome worse than the
 * false positive this fixes. Nothing has to be decided about the unterminated halves —
 * the pattern simply does not match them, so they stay as the HTML they are.
 *
 * Measured rather than assumed: with this in place all twenty-six rules match exactly
 * the files they matched before, and the five by-eye entries below are unchanged. The
 * repo has no HTML comment today whose text collides with a rule — the fix is for the
 * next one, and for the note #44 had to write in the other syntax to avoid it.
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
            if ($token[0] === T_INLINE_HTML) {
                $out .= preg_replace_callback('/<!--.*?-->/s', function ($m) {
                    return str_repeat("\n", substr_count($m[0], "\n"));
                }, $token[1]);
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

// ---- The grant form declares both of its axes -----------------------------------
// §5 listed this as undecidable because a rule here is "which files match", and the
// question is about a *shape* inside one file. That was a limit of the rule format, not
// of pattern matching — so it is written out longhand instead, which is what the three
// checks above this one already do.
//
// The defect (§4s) is that a browser posts only the ticked checkboxes, so an unticked
// box and a row or column that was never on the page are the same silence, and reading
// that silence as "remove it" is how the matrix saved over grants it had never shown.
// The fix is that both axes are declared by the form and the save only changes what it
// was told was on screen. So each name has to appear twice, in two different roles: as
// a hidden input, which is the declaring, and as a `$_POST` read, which is the acting
// on it. One without the other is the defect back — a form that declares an axis
// nothing reads, or a save that trusts an axis nothing drew.
$axisProblems = [];
$axes  = ['grants_accounts' => 'rows', 'grants_displays' => 'columns'];
$panel = codeWithoutComments(file_get_contents($root . '/admin_panel.php'));
foreach ($axes as $axis => $what) {
    if (strpos($panel, 'name="' . $axis . '[]"') === false) {
        $axisProblems[] = "admin_panel.php never declares $axis as a hidden input, so the "
                        . "save cannot know which $what were on the page";
    }
    if (strpos($panel, '$_POST[\'' . $axis . '\']') === false) {
        $axisProblems[] = "admin_panel.php never reads \$_POST['$axis'], so the axis it "
                        . "declares is not the one it saves by";
    }
}
foreach (phpFilesUnder($root, '', ['tools/']) as $rel) {
    if ($rel === 'admin_panel.php') { continue; }
    $other = codeWithoutComments(file_get_contents($root . '/' . $rel));
    foreach (array_keys($axes) as $axis) {
        if (strpos($other, $axis) !== false) {
            $axisProblems[] = "$rel names $axis, and the grant matrix has one page";
        }
    }
}
$checked++;
if (!$axisProblems) {
    echo "  ok   the grant matrix declares both axes and saves by the ones it declared (§4s)\n";
} else {
    echo "  FAIL the grant matrix's axes and its save do not agree (§4s)\n";
    foreach ($axisProblems as $problem) { echo "       $problem\n"; }
    $failures[] = 'the grant matrix axes';
}

// ---- The shared suite asks each engine only what that engine can answer ----------
// Invariant 32. `tools/selftest_layout.php` runs twice in CI — once on SQLite, once on
// the engine the shop actually uses — and the second run is the one that says the sign
// will work. A statement only SQLite understands does not fail that run politely: it
// throws, PHP dies, and every check after it goes unrun rather than unproven. That is
// how the MySQL leg sat at 593 of 1805 for a week while the branch it was red on looked
// like every other branch cut from a red base (§4ba).
//
// A denylist, and it says so — the same shape and the same limit as invariant 31. What
// it can decide is a token; what it cannot decide is a value a typed column will refuse
// (`'nonsense'` into a DATETIME, `'carousel'` into an ENUM), which is the other half of
// what §4ba fixed and is listed below as uncovered.
//
// It grew a second list the day after the first one shipped, and the reason is worth
// stating plainly: SQLite-only is not the only way to be un-runnable on the MySQL leg.
// A statement can be accepted by *both* engines somebody rehearses on and refused by
// the one CI and the shop run. `TEXT NOT NULL DEFAULT 'text'` is that statement —
// SQLite takes it, MariaDB has taken it since 10.2, MySQL answers 1101 — and it went
// to CI green on a local run and died there at check 1181 of 1823 (§4bb). So the
// second list is not "SQLite-only", it is "the MySQL leg refuses this", and a rehearsal
// on the wrong engine is exactly what it exists to survive. `DEFAULT NULL` is not in
// it: MySQL permits that on a TEXT column, which was checked against 5.7.44 rather
// than assumed, and a pattern that flagged it would be a false alarm on four fixture
// lines that are correct.
//
// The guard it looks for is on purpose the two that are readable at a glance: a PDO
// built as `sqlite::memory:`, which is a database this suite made for one check and no
// engine leg touches, or an explicit `!testIsMysql()`. Ten lines is the window, which is
// further than any of the four real uses needs.
$sqliteOnly = ['AUTOINCREMENT', 'RAISE(FAIL', 'PRAGMA ', 'sqlite_master'];
$sqliteGuards = ['sqlite::memory:', '!testIsMysql()'];
$suiteLines = explode("\n", codeWithoutComments(file_get_contents($root . '/tools/selftest_layout.php')));

// One guard question, asked by both lists below: is this line inside a stretch the
// MySQL leg never reaches? Ten lines is the window, which is further than any of the
// real uses needs.
$engineGuarded = function ($i) use ($suiteLines, $sqliteGuards) {
    for ($back = max(0, $i - 10); $back <= $i; $back++) {
        foreach ($sqliteGuards as $guard) {
            if (strpos($suiteLines[$back], $guard) !== false) { return true; }
        }
    }
    return false;
};

$engineProblems = [];
foreach ($suiteLines as $i => $line) {
    foreach ($sqliteOnly as $token) {
        if (strpos($line, $token) === false) { continue; }
        if (!$engineGuarded($i)) {
            $engineProblems[] = "selftest_layout.php line " . ($i + 1) . " uses $token with no "
                              . "sqlite::memory: or !testIsMysql() above it — on the MySQL leg "
                              . "that is a fatal, and every check after it stops running";
        }
    }
}
$checked++;
if (!$engineProblems) {
    echo "  ok   nothing SQLite-only runs unguarded in the suite both engines run (invariant 32)\n";
} else {
    echo "  FAIL the shared suite would die on the MySQL leg (invariant 32)\n";
    foreach ($engineProblems as $problem) { echo "       $problem\n"; }
    $failures[] = 'SQLite-only syntax in the shared suite';
}

// The second list: legal on both engines a person is likely to rehearse on, refused by
// the one that decides. `DEFAULT NULL` is deliberately excluded — MySQL allows it.
$mysqlRefuses = [
    '/\b(?:(?:TINY|MEDIUM|LONG)?(?:TEXT|BLOB)|JSON)\b[^,)]*\bDEFAULT\s+(?!NULL\b)/i'
        => "a default on a TEXT, BLOB or JSON column — MySQL answers 1101 and the run ends there, "
         . "while SQLite and MariaDB both accept it",
];
$refusedProblems = [];
foreach ($suiteLines as $i => $line) {
    foreach ($mysqlRefuses as $pattern => $why) {
        if (!preg_match($pattern, $line)) { continue; }
        if (!$engineGuarded($i)) {
            $refusedProblems[] = "selftest_layout.php line " . ($i + 1) . " has $why";
        }
    }
}
$checked++;
if (!$refusedProblems) {
    echo "  ok   nothing the MySQL leg refuses runs unguarded either (invariant 32)\n";
} else {
    echo "  FAIL the shared suite writes something MySQL will refuse (invariant 32)\n";
    foreach ($refusedProblems as $problem) { echo "       $problem\n"; }
    $failures[] = 'a statement MySQL refuses in the shared suite';
}

// ---- Convergence runs before anything that could hold a transaction --------------
// The §5 note on this one is right that the *position* is the invariant and not the
// call, and wrong that a pattern cannot decide it. DDL commits an open transaction in
// MySQL and says nothing about it, so what must be true is that the call comes before
// any transaction exists — and every transaction in this app is held by one of three
// use-case modules, reached through a store, so "before the first store or use case is
// so much as named" is both decidable and stricter than counting lines. api.php is why
// the line-number form would not have worked: its call is legitimately at line 128,
// after the upload-limit and CSRF gates, because those send a reply and stop.
$positions = [];
foreach (['admin_panel.php', 'api.php', 'builder.php', 'crud.php'] as $entry) {
    $code = codeWithoutComments(file_get_contents($root . '/' . $entry));
    $call = strpos($code, 'ensureSignageSchema($pdo)');
    if ($call === false) {
        $positions[] = "$entry does not converge at all, and it is an entry point";
        continue;
    }
    foreach (['beginTransaction', 'DisplayStore', 'LayoutStore', 'DisplayAdmin',
              'AccountAdmin', 'PasswordResetCompletion'] as $holder) {
        $at = strpos($code, $holder);
        if ($at !== false && $at < $call) {
            $positions[] = "$entry names $holder on line "
                         . (substr_count(substr($code, 0, $at), "\n") + 1)
                         . ', before it converges on line '
                         . (substr_count(substr($code, 0, $call), "\n") + 1);
        }
    }
}
$checked++;
if (!$positions) {
    echo "  ok   every entry point converges before a transaction could exist (invariant 21)\n";
} else {
    echo "  FAIL an entry point converges after something that can open a transaction (invariant 21)\n";
    foreach ($positions as $problem) { echo "       $problem\n"; }
    echo "       DDL commits an open transaction in MySQL silently, so a converge from\n";
    echo "       deeper in would commit half a publish and then report it failed. Anything\n";
    echo "       converging off a failure asks repairSchemaAfterFailure() instead.\n";
    $failures[] = 'convergence position in an entry point';
}

// ---- Nothing uses syntax or a function newer than the PHP floor -----------------
/**
 * Every use of PHP syntax or a library function newer than the floor.
 *
 * **This is the one check `php -l` cannot be.** The floor is 8.2 (#51, observed twice
 * on 2026-08-11), and the container these sessions run in has PHP 8.4 — so a construct
 * added in 8.3 or 8.4 lints clean here and is a **parse error on the live host**. A
 * parse error takes the whole file down, and a file a Screen loads going down is a
 * blank board in the shop rather than a message anybody reads. The gate that was
 * supposed to catch that has never been able to see it, and BUILD-REFERENCE recorded
 * the 8.4 container for a year without drawing the consequence.
 *
 * Token-based rather than grep-based, and that is not tidiness. The hand sweep that
 * cleared the tree on 2026-08-11 hit two false positives in a one-line grep — an HTML
 * `readonly` attribute in `admin_panel.php` and a JavaScript `.match(` in
 * `builder.php` — because the strings this looks for are ordinary English and ordinary
 * JavaScript. A rule that cries wolf twice on a clean tree is a rule somebody turns
 * off. Reading real tokens means a name inside a string, a comment, an HTML attribute
 * or a `->method()` call cannot be mistaken for the construct.
 *
 * **It is a denylist, and therefore incomplete by construction.** It knows the
 * constructs 8.3 and 8.4 added that somebody might plausibly reach for here; it cannot
 * know what 8.5 will add. That limit is printed on every run rather than left for a
 * reader to infer, because the failure mode of a denylist is silence, and silence here
 * reads exactly like a clean tree. What makes it worth having anyway is the direction
 * it fails in: it cannot promise the tree is clean, but every construct it does name is
 * one that would have reached the sign.
 *
 * @return array of ['line' => int, 'what' => string, 'since' => string]
 */
function aboveFloorUses($source)
{
    return aboveFloorInTokens(aboveFloorTokens($source));
}

/**
 * The significant tokens of some source, as [id, text, line] triples.
 *
 * Split from the recogniser below so that a **token list can be supplied by hand**, and
 * that is not a convenience. `private(set)` lexes as one token on 8.4 and as four on
 * 8.2, so the four-token branch is unreachable on the machine these sessions run on —
 * 21 of its mutants survived a sweep for exactly that reason, and it is the branch that
 * matters most, because CI is pinned to the floor and CI is where those four tokens
 * actually arrive. A recogniser that takes data can be handed the 8.2 lexing on an 8.4
 * runtime; one that takes source can only ever see its own version's.
 */
function aboveFloorTokens($source)
{
    // Significant tokens only, each carrying the line it starts on. Single-character
    // tokens arrive from token_get_all() without a line number at all, so the count is
    // carried forward by hand — a `)` on line 40 reported as line 1 is a finding
    // nobody can act on.
    $ts   = [];
    $line = 1;
    foreach (token_get_all($source) as $t) {
        if (is_array($t)) {
            $id = $t[0]; $text = $t[1]; $line = $t[2];
        } else {
            $id = null; $text = $t;
        }
        if ($id !== T_WHITESPACE && $id !== T_COMMENT
            && $id !== T_DOC_COMMENT && $id !== T_INLINE_HTML) {
            $ts[] = [$id, $text, $line];
        }
        $line += substr_count($text, "\n");
    }

    return $ts;
}

/**
 * Every above-floor construct in an already-tokenised file.
 *
 * @param array $ts [id, text, line] triples, from aboveFloorTokens() or built by hand
 * @return array of ['line' => int, 'what' => string, 'since' => string]
 */
function aboveFloorInTokens(array $ts)
{

    // Functions, not syntax, so these are a fatal at call time rather than a parse
    // error — a page that dies where it stood instead of never starting. Still a dead
    // sign, and still invisible to a lint on 8.4.
    $funcs = [
        'json_validate' => '8.3', 'mb_str_pad' => '8.3', 'str_increment' => '8.3',
        'str_decrement' => '8.3', 'stream_context_set_options' => '8.3',
        'array_find' => '8.4', 'array_find_key' => '8.4', 'array_any' => '8.4',
        'array_all' => '8.4', 'mb_trim' => '8.4', 'mb_ltrim' => '8.4',
        'mb_rtrim' => '8.4', 'mb_ucfirst' => '8.4', 'mb_lcfirst' => '8.4',
        'request_parse_body' => '8.4', 'bcdivmod' => '8.4', 'fpow' => '8.4',
        'grapheme_str_split' => '8.4', 'http_get_last_response_headers' => '8.4',
        'http_clear_last_response_headers' => '8.4',
    ];

    $out = [];
    $n   = count($ts);
    for ($i = 0; $i < $n; $i++) {
        list($id, $text, $at) = $ts[$i];
        $lower = strtolower($text);
        $next  = isset($ts[$i + 1]) ? $ts[$i + 1] : [null, '', $at];
        $prev  = $i > 0 ? $ts[$i - 1] : [null, '', $at];

        // A typed class constant (8.3): `const string N = 'x'`. Two names before the
        // `=` where an 8.2 constant has one. Stopping at `=` is what keeps
        // `const A = B|C` out of it, and stopping without one is what keeps
        // `use const Foo\BAR;` out — an import has no `=` and is not a declaration.
        if ($id === T_CONST) {
            $names = 0;
            $shape = false;
            for ($j = $i + 1; $j < $n; $j++) {
                $tok = $ts[$j];
                if ($tok[1] === '=') {
                    if ($names >= 2 || $shape) {
                        $out[] = ['line' => $at, 'since' => '8.3',
                                  'what' => 'a typed class constant'];
                    }
                    break;
                }
                if ($tok[1] === ';' || $tok[1] === ',') { break; }
                if ($tok[0] === T_STRING) { $names++; continue; }
                // `?string`, `A|B`, `array` and `static` are all type position.
                if ($tok[1] === '?' || $tok[1] === '|'
                    || $tok[0] === T_ARRAY || $tok[0] === T_STATIC
                    || $tok[0] === T_CALLABLE) { $shape = true; continue; }
                break;
            }
        }

        // The two attributes PHP itself added above the floor. Attributes as such are
        // 8.0 and fine; these two names are not, and an unknown attribute is a fatal
        // rather than something ignored.
        if ($id === T_ATTRIBUTE) {
            for ($j = $i + 1; $j < $n && $j <= $i + 2; $j++) {
                $name = ltrim($ts[$j][1], '\\');
                if ($name === 'Override') {
                    $out[] = ['line' => $at, 'since' => '8.3',
                              'what' => 'the #[\\Override] attribute'];
                }
                if ($name === 'Deprecated') {
                    $out[] = ['line' => $at, 'since' => '8.4',
                              'what' => 'the #[\\Deprecated] attribute'];
                }
            }
        }

        // Asymmetric visibility (8.4): `public private(set) string $n`.
        //
        // **Two lexings, and both are needed.** PHP 8.4 reads `private(set)` as one
        // token (T_PRIVATE_SET); 8.2 has no such token and would read four — T_PRIVATE,
        // `(`, `set`, `)`. This checker runs on both: 8.4 in the session container, 8.2
        // in CI, which pins the floor. Matching only the 8.4 shape would leave the
        // detector silently blind on the one machine whose version is the point, and it
        // would look like a pass. The constant is matched by *text* rather than by name
        // for the same reason — naming T_PRIVATE_SET here is a fatal on 8.2.
        $bare = strtolower(preg_replace('/\\s+/', '', $text));
        if ($bare === 'private(set)' || $bare === 'protected(set)') {
            $out[] = ['line' => $at, 'since' => '8.4',
                      'what' => 'asymmetric visibility (`' . $bare . '`)'];
        }
        if (($id === T_PRIVATE || $id === T_PROTECTED)
            && $next[1] === '('
            && isset($ts[$i + 2]) && strtolower($ts[$i + 2][1]) === 'set'
            && isset($ts[$i + 3]) && $ts[$i + 3][1] === ')') {
            $out[] = ['line' => $at, 'since' => '8.4',
                      'what' => 'asymmetric visibility (`' . $lower . '(set)`)'];
        }

        // A property hook (8.4): a property whose declaration opens a brace instead of
        // ending. `(` or T_FUNCTION on the way means this was a method or a promoted
        // constructor parameter, and `=` or `;` means an ordinary property.
        if ($id === T_PUBLIC || $id === T_PRIVATE || $id === T_PROTECTED || $id === T_VAR) {
            for ($j = $i + 1; $j < $n; $j++) {
                $tok = $ts[$j];
                if ($tok[0] === T_VARIABLE) {
                    if (isset($ts[$j + 1]) && $ts[$j + 1][1] === '{') {
                        $out[] = ['line' => $tok[2], 'since' => '8.4',
                                  'what' => 'a property hook'];
                    }
                    break;
                }
                if ($tok[1] === ';' || $tok[1] === '=' || $tok[1] === '('
                    || $tok[1] === '{' || $tok[0] === T_FUNCTION) { break; }
            }
        }

        // `new Foo()->bar()` (8.4). The 8.2 spelling wraps it: `(new Foo())->bar()`.
        // So the tell is a `(` *before* the `new`, and its absence is the flag. This is
        // the loosest of the five and the reason is worth stating: it decides on one
        // preceding token rather than on a parse, so `foo(new Bar())->baz()` reads as
        // wrapped. That form is legal on 8.2 anyway — chaining off a function call
        // always was — so the looseness costs a missed case that is not a defect,
        // rather than a false alarm on a clean tree.
        if ($id === T_NEW && $prev[1] !== '(') {
            $j = $i + 1;
            while ($j < $n && ($ts[$j][0] === T_STRING || $ts[$j][0] === T_VARIABLE
                   || $ts[$j][0] === T_NAME_QUALIFIED
                   || $ts[$j][0] === T_NAME_FULLY_QUALIFIED
                   || $ts[$j][0] === T_STATIC || $ts[$j][1] === '\\')) { $j++; }
            if ($j < $n && $ts[$j][1] === '(') {
                $depth = 0;
                for (; $j < $n; $j++) {
                    if ($ts[$j][1] === '(') { $depth++; }
                    elseif ($ts[$j][1] === ')') {
                        $depth--;
                        if ($depth === 0) { $j++; break; }
                    }
                }
                if ($j < $n && ($ts[$j][0] === T_OBJECT_OPERATOR
                    || $ts[$j][0] === T_NULLSAFE_OBJECT_OPERATOR
                    || $ts[$j][0] === T_DOUBLE_COLON)) {
                    $out[] = ['line' => $at, 'since' => '8.4',
                              'what' => '`new` chained without wrapping parentheses'];
                }
            }
        }

        // A function the floor does not have. `->name(` and `::name(` are somebody
        // else's method and not this, which is the whole reason for reading tokens.
        if ($id === T_STRING && isset($funcs[$lower]) && $next[1] === '('
            && $prev[0] !== T_OBJECT_OPERATOR
            && $prev[0] !== T_NULLSAFE_OBJECT_OPERATOR
            && $prev[0] !== T_DOUBLE_COLON
            && $prev[0] !== T_FUNCTION) {
            $out[] = ['line' => $at, 'since' => $funcs[$lower],
                      'what' => $text . '()'];
        }
    }

    return $out;
}

// The floor is read out of `lib/server_report.php` rather than written here again, and
// read rather than *loaded*: requiring that file pulls in five modules, and a static
// analyser that boots app code to learn a constant will one day boot a side effect. So
// the declaration is parsed, and failing to find it is a failure — a checker that
// quietly fell back to a hardcoded 8.2 would keep passing after somebody lowered the
// floor, which is the one moment it needs to speak up.
$floorSource = codeWithoutComments(file_get_contents($root . '/lib/server_report.php'));
$floor = '';
if (preg_match('/const\s+ASSUMED_PHP\s*=\s*\'([0-9.]+)\'/', $floorSource, $m)) {
    $floor = $m[1];
}
$checked++;
if ($floor !== '') {
    echo "  ok   the floor this checks against is read from ServerReport, not restated ($floor)\n";
} else {
    echo "  FAIL the ASSUMED_PHP declaration cannot be read, so there is no floor to check\n";
    echo "       Nothing below this line can mean anything until that declaration is found.\n";
    $failures[] = 'the PHP floor cannot be read';
}

// The tree itself. tools/ is included rather than skipped: CI pins the floor, so a tool
// that only runs above it is a red build, and the self-test is where a new construct is
// likeliest to be reached for casually.
$floorProblems = [];
foreach (phpFilesUnder($root, '') as $rel) {
    foreach (aboveFloorUses(file_get_contents($root . '/' . $rel)) as $use) {
        $floorProblems[] = $rel . ':' . $use['line'] . ' uses ' . $use['what']
                         . ', which needs PHP ' . $use['since'];
    }
}
$checked++;
if (!$floorProblems) {
    echo "  ok   no file uses syntax or a function newer than PHP "
       . "$floor (invariant 31)\n";
} else {
    echo "  FAIL a file uses PHP newer than the floor, and php -l here cannot see it "
       . "(invariant 31)\n";
    foreach ($floorProblems as $problem) { echo "       $problem\n"; }
    echo "       The floor is $floor (#51, observed twice). "
       . "This container is " . PHP_VERSION . ", so\n";
    echo "       this lints clean here and is a parse error on the live host — which is\n";
    echo "       a blank sign in the shop, not a message. Rewrite it, or lower the floor\n";
    echo "       deliberately with the owner's version in hand.\n";
    $failures[] = 'a file uses PHP newer than the floor';
}

// ---- And that detector, seen to fail --------------------------------------------
// Invariant 30: a check ships having been *seen* to go red. A denylist that matches
// nothing looks identical to a clean tree, so the constructs are put through it here as
// source strings — never as files, because a fixture file holding 8.4 syntax would be a
// parse error the moment anything included it, and the lexer reads a string happily
// without ever agreeing to run it.
//
// The negative half is not symmetry for its own sake. Every entry below is a shape that
// a plain grep for these names *does* hit, and two of them are the exact false positives
// the hand sweep produced on a clean tree.
// Each snippet is put on line 2 of a two-line file, so the reported **line** is asserted
// as well as the construct. A mutation sweep over this detector found the line arithmetic
// entirely uncovered — `$line = 1` could become `$line = 2` with every probe still green —
// and a construct reported against the wrong line is a finding nobody can act on, which
// is a sentence this file's own comments already contained. `what` and `since` are
// asserted for the same reason: a hit carrying the wrong version sends somebody to read
// up on the wrong release. Exactly one hit, too, so a detector that fires twice on one
// construct is a failure rather than a louder pass.
$floorProbes = [
    ['class A { const string N = "x"; }',            'typed class constant',  '8.3'],
    ['class A { #[\Override] function f() {} }',      '#[\Override]',          '8.3'],
    ['$ok = json_validate($raw);',                   'json_validate()',       '8.3'],
    ['$i = array_find($rows, $fn);',                 'array_find()',          '8.4'],
    ['class A { public string $n { get => "x"; } }', 'property hook',         '8.4'],
    ['class A { public private(set) int $n = 1; }',   'asymmetric visibility', '8.4'],
    ['$x = new Foo()->bar();',                       'new` chained',          '8.4'],
];
foreach ($floorProbes as $probe) {
    list($snippet, $needle, $since) = $probe;
    $checked++;
    $hits = aboveFloorUses("<?php\n" . $snippet . "\n");
    if (count($hits) === 1 && $hits[0]['line'] === 2
        && $hits[0]['since'] === $since
        && strpos($hits[0]['what'], $needle) !== false) {
        echo "  ok   above the floor, on the right line, as $since: $needle\n";
    } else {
        echo "  FAIL the floor check does not report $needle correctly\n";
        echo "       expected one hit on line 2 naming `$needle`, since $since; got "
           . count($hits) . "\n";
        foreach ($hits as $hit) {
            echo "       line " . $hit['line'] . ' — ' . $hit['what']
               . ', since ' . $hit['since'] . "\n";
        }
        $failures[] = 'the floor check misreports ' . $needle;
    }
}

// The tokeniser, asserted directly rather than through a finding.
//
// A sweep left `$line += substr_count($text, "\n")` standing, and the reason was not a
// missing probe: **no finding is anchored to a single-character token.** Every one of them
// reports the line of an array token, which arrives from `token_get_all()` carrying its
// own line, so the carry-forward could be deleted and every construct would still be
// reported on the right line. It is load-bearing only for the `)` and `]` entries in the
// list — which is to say, for the next detector somebody writes. That makes it worth an
// assertion here rather than a deletion, and the seam above is what allows one: the
// tokeniser is reachable on its own, so the token list can be read instead of inferred
// from a finding.
//
// The sibling mutant, `$line = 1` removed, stays alive and is not a gap: the first token
// of any source is an array token — `T_OPEN_TAG`, or `T_INLINE_HTML` for a file that opens
// with text — so the initial value is overwritten before it can be read. Unkillable
// because PHP's tokeniser guarantees it, which is the docblock being right.
$checked++;
$multi = aboveFloorTokens("<?php\n\$a = [\n    1,\n];\n");
$closer = null;
foreach ($multi as $tok) {
    if ($tok[1] === ']') { $closer = $tok; }
}
if ($closer !== null && $closer[2] === 4) {
    echo "  ok   a single-character token carries the line it is really on, not the last "
       . "one named\n";
} else {
    echo "  FAIL a single-character token is recorded on the wrong line\n";
    echo "       expected the `]` on line 4; got "
       . ($closer === null ? 'no `]` at all' : 'line ' . $closer[2]) . "\n";
    echo "       token_get_all() gives no line for these, so it is carried forward by\n";
    echo "       hand, and a construct reported on the wrong line cannot be acted on.\n";
    $failures[] = 'a single-character token records the wrong line';
}

// And the lexing this runtime never produces. On 8.4 `private(set)` is a single token, so
// the four-token branch is dead code here — 21 of its mutants survived a sweep, every one
// of them because the line cannot run on this machine. It is also the branch **CI**
// depends on, CI being the only runner pinned to the floor, so leaving it unexercised
// meant the detector could be broken there while every probe stayed green. Handing the
// recogniser a token list built by hand is what makes it reachable.
$checked++;
$eightTwoLexing = [
    [T_PUBLIC,   'public',  2],
    [T_PRIVATE,  'private', 2],
    [null,       '(',       2],
    [T_STRING,   'set',     2],
    [null,       ')',       2],
    [T_STRING,   'int',     2],
    [T_VARIABLE, '$n',      2],
    [null,       '=',       2],
    [T_LNUMBER,  '1',       2],
    [null,       ';',       2],
];
$hits = aboveFloorInTokens($eightTwoLexing);
if (count($hits) === 1 && $hits[0]['line'] === 2 && $hits[0]['since'] === '8.4'
    && strpos($hits[0]['what'], 'asymmetric visibility') !== false) {
    echo "  ok   and in the 8.2 lexing of it, which is the one CI sees and this cannot\n";
} else {
    echo "  FAIL the 8.2 lexing of private(set) goes uncaught\n";
    echo "       Four tokens on 8.2, one on 8.4. Matching only the shape this machine\n";
    echo "       produces leaves the check blind exactly where the floor is enforced.\n";
    $failures[] = 'the 8.2 lexing of asymmetric visibility';
}
$floorClean = [
    ['<?php $a = 1; ?><input readonly value="x">',
     'an HTML readonly attribute is markup, not a property hook'],
    ['<?php $s = "call json_validate($raw) one day";',
     'a function name inside a string is not a call'],
    ["<?php // json_validate() would need 8.3\n\$a = 1;\n",
     'and one in a comment is prose'],
    ['<?php const A = 1, B = 2;',
     'an untyped constant list is not a typed constant'],
    ['<?php use const Foo\BAR;',
     'and a const import is not a declaration at all'],
    ['<?php $x = (new Foo())->bar();',
     'the wrapped form of new is how 8.2 spells it'],
    ['<?php class A { public $n = 1; public static $m; }',
     'an ordinary property has no hook'],
    ['<?php class A { public function f($a) { return $a; } }',
     'and a method is not a property'],
    ['<?php $o->json_validate($raw); Foo::array_find($r, $f);',
     'somebody else\'s method is not the global function'],
    ['<?php $x = new Foo(); $y = $x->bar();',
     'a new and a later call on it are two statements, not a bare chain'],
    ['<?php class A { const N = self::M; }',
     'a constant whose value is another constant is still untyped'],
    ['<?php class A { protected static $n = []; }',
     'a static property with an array default has no hook'],
];
foreach ($floorClean as $probe) {
    list($source, $label) = $probe;
    $checked++;
    $hits = aboveFloorUses($source);
    if (!$hits) {
        echo "  ok   at the floor and left alone: $label\n";
    } else {
        echo "  FAIL the floor check cries wolf: $label\n";
        foreach ($hits as $hit) { echo "       flagged " . $hit['what'] . "\n"; }
        echo "       A rule that fails on a clean tree is a rule somebody turns off.\n";
        $failures[] = 'the floor check false-positives on ' . $label;
    }
}

// ---- The instrument itself -------------------------------------------------------
// Every rule above is read through codeWithoutComments(), so what that function drops
// decides what all thirty-two of them can see. It gained HTML comments in #50, and the
// decision it embodies — a comment holding PHP is code and stays — is worth an
// assertion rather than a paragraph, because both halves fail silently: dropping too
// little is the false positive #44 hit, and dropping too much blinds every rule to
// whatever a page hid inside a comment.
$commentProbes = [
    ["<?php \$a = 1; ?>\n<!-- no strtotime() here -->\n",
     'strtotime', false, 'an HTML comment is prose, and a rule does not match inside it'],
    ["<?php \$a = 1; ?>\n<!-- <?= strtotime('x') ?> -->\n",
     'strtotime', true, 'but one holding PHP is code, and that PHP still runs'],
    ["<?php // no strtotime() here\n\$a = 1;\n",
     'strtotime', false, 'and a PHP comment is dropped as it always was'],
];
foreach ($commentProbes as $probe) {
    list($source, $needle, $shouldMatch, $label) = $probe;
    $checked++;
    $found = strpos(codeWithoutComments($source), $needle) !== false;
    if ($found === $shouldMatch) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL $label\n";
        echo "       expected " . ($shouldMatch ? 'a match' : 'no match') . " for `$needle`\n";
        $failures[] = 'codeWithoutComments: ' . $label;
    }
}

// ---- What this does not cover ---------------------------------------------------
// The five §5 greps that used to be listed here are checked above as of #50. Four were
// mechanised outright; the fifth kept the half of itself that is a judgement. What is
// left below is deliberately shorter and deliberately not empty — a checker that
// quietly covers half of §5 reads like one that covers all of it, and that is the shape
// #50 was filed about, so this list going empty would need to be true rather than tidy.
echo "\nStill by eye — what running these greps cannot settle:\n";
foreach ([
    'ErrorPolicy::report — the caller SET is now checked, so a fourth cannot land '
        . 'unnoticed. Whether a new one can fire repeatedly on a condition the app '
        . 'expected, and therefore needs a window, is a reading of that call site '
        . '(invariant 20)',
    'schema.sql against lib/schema.php — covered instead by the MySQL self-test run, '
        . 'which asserts convergence has nothing left to do against a database built '
        . 'from that file (#48). A column missing from both is still invisible',
    'the rehearsal against a database that genuinely LAGS the repo — schema.sql '
        . 'produces one that is already converged, so the statements only have '
        . 'something to do on a copy of live data (§5, and a deploy-day step)',
    'anything a browser draws — interact.js is un-run by any suite (§4al), and a CSS '
        . 'rule that does not apply or a button that overlaps another at 1080p is '
        . 'invisible to all six of them',
    'whether a check can fail at all — `php tools/mutate.php <file>` answers that one '
        . 'file at a time, and is the thing #50 was filed about (§4aq). It is a tool to '
        . 'run, not a gate, because a full sweep is hours',
    'syntax above the floor that this does not know about (invariant 31) — the floor '
        . 'check is a denylist of what 8.3 and 8.4 added, so it cannot know what 8.5 '
        . 'will add, and it reads syntax rather than semantics: a method that only '
        . 'exists above the floor, or a changed default, passes it. What it does catch '
        . 'is proven every run by its own fixtures. The only complete answer is running '
        . 'the suite on ' . 'the version the shop runs, which CI does and this container cannot',
] as $note) {
    echo "  · $note\n";
}

echo "\n$checked consistency checks, " . count($failures) . " failed\n";
if ($failures) {
    foreach ($failures as $f) { echo "  FAILED: $f\n"; }
    exit(1);
}
exit(0);
