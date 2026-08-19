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
        'name'   => '`brands` has one writer',
        'regex'  => '/(INTO|UPDATE|FROM|JOIN|TABLE)\s+`?brands`?\b/i',
        'in'     => '',
        // lib/brands.php owns the table. lib/schema.php creates it and seeds the first
        // Brand, exactly as it does for `displays`. BrandAdmin composes the two tables a
        // Brand spans and writes no SQL itself, so it is deliberately *not* here — if it
        // appears, it has started reaching past BrandStore.
        'expect' => ['lib/brands.php', 'lib/schema.php',
                     'tools/rehearse_phase1.php', 'tools/selftest_layout.php',
                     'tools/test_fixture.php'],
        'why'    => 'a Brand is what several signs read their typography, palette and logo '
                  . 'from, so a second writer is a venue repainted by a page that did not '
                  . 'know it was the one deciding (ADR-0011, invariant 35)',
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
        // The §4bi rule, and the PHP half of what `tools/page_constants.js` does for
        // the node suites. Everything in this list is a value the *machine* supplies,
        // which means it has exactly one value on any machine that runs the tests and
        // whatever value the shop's server happens to hold everywhere else. That is not
        // a reason to ban it — this app reports on its own host, so somebody has to read
        // it — it is a reason for the list to be short, named, and to grow only on
        // purpose. A new one appearing in a module nobody was watching is a sentence,
        // a limit or a branch that the suite will assert about this container for as
        // long as it exists, in green.
        //
        // The four here each pair the read with a seam beside it that takes the value
        // instead: phpVersionNote(), phpZoneNoteFor(), uploadCeilingNoteFor(),
        // smallestOf(), requestNameFor(). Adding a file to this list without one is
        // adding a branch no arm of selftest_installed.php can reach.
        //
        // alerts.php is here for one `phpversion()` in an X-Mailer header — a string in
        // an email, with no branch behind it. Listed rather than excused: the point of
        // the rule is that the list is the whole set.
        'name'   => 'every read of the machine is one somebody named',
        // `ATTR_SERVER_VERSION` and `ATTR_DRIVER_NAME` are here because the engine's
        // version is a fact about the machine in exactly the sense this rule is about,
        // and the first draft of the rule missed them — which is how the MySQL row came
        // to print a number with a hardcoded `''` beside it while the PHP row above had
        // three bands and a declared floor. No-ops today, since only this module reads
        // them; the point of a set is that it is the whole set.
        'regex'  => '/ini_get\s*\(|\$_SERVER|\$_ENV|getenv\s*\(|PHP_VERSION|PHP_SAPI'
                  . '|php_uname\s*\(|phpversion\s*\(|session_get_cookie_params\s*\('
                  . '|ATTR_SERVER_VERSION|ATTR_DRIVER_NAME/',
        'in'     => 'lib',
        // displays.php is the fifth, and adding the two PDO attributes above is what
        // found it: `limitPublishLockWait()` skips a MySQL-only `SET SESSION` on any other
        // engine, which makes it a branch chosen by the machine **in the publish path** —
        // the highest-consequence code here. It is the one read on this list that was
        // already covered the right way before the rule existed: the suite asserts
        // `checkSame(testIsMysql(), …)`, so the check states that the answer depends on
        // the engine and is true in both arms rather than pinning either.
        'expect' => ['lib/alerts.php', 'lib/displays.php', 'lib/error_policy.php',
                     'lib/server_report.php', 'lib/upload_limits.php'],
        'why'    => 'a value taken from the host is one the suite can only ever see this '
                  . 'container\'s copy of, so the branch behind it is asserted in the one '
                  . 'configuration no shop is running (§4bi)',
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
        // save, not as values it paints with. lib/site_chrome.php is the only *reader*.
        //
        // A page other than these is a page that has gone back to interpolating
        // whatever the file holds into its own stylesheet. The self-test is listed
        // because it pins a value to prove a save leaves the other seven alone; the
        // rule is about pages, and there is nowhere else for a test of it to live.
        // `selftest_installed.php` is the other direction entirely: it *defines* the
        // names, so that the suite runs as a shop that has set the app up rather than
        // as a fresh checkout, and it paints nothing. A file that only writes them is
        // not a second reader, and the two names it uses are checked against
        // `BrandingConfig::DEFAULTS` on every run rather than typed and trusted.
        'expect' => ['admin_panel.php', 'branding_config.php', 'lib/site_chrome.php',
                     'lib/branding.php', 'tools/selftest_layout.php',
                     'tools/selftest_installed.php'],
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
//   a colour      SiteChrome::navBg() and its three siblings, SiteChrome::styleVariables()
//                 which prints all thirteen roles at once, and Color::read() itself —
//                 each answers `#rrggbb` (or nothing) because Color::read() decided
//                 (§4ai). The one case in this app where escaping would have been the
//                 wrong tool: they land in a <style> block or a style attribute, neither
//                 of which has a delimiter an entity could close. Escaping stops a value
//                 ending the attribute and does not stop it ending the *declaration*.
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
// `SiteChrome::styleVariables()` prints every chrome role at once, and every value in it
// has been through `Color::read()` inside `pick()`. Safe for the reason the four
// accessors beside it are — a `<style>` block has no delimiter escaping could
// neutralise, so being *a colour* is the only property that helps — and
// `selftest_layout` asserts the shape of every line it emits, which is what makes this
// entry a fact rather than a promise. The four accessors stay listed because the sign-in
// page still interpolates them one at a time: it never wears a theme, so it has no
// `:root` block to draw from.
$SAFE_STATIC = ['Markup::text', 'Markup::jsInAttr', 'HttpReply::jsValue',
                'SiteChrome::navBg', 'SiteChrome::navBorder', 'SiteChrome::accent', 'SiteChrome::text',
                'SiteChrome::styleVariables',
                // `themeColor()` is what a theme's own colour resolves through, and like
                // `pick()` underneath it it answers `#rrggbb` for every input — a value
                // `Color::read()` refuses becomes the colour the store default paints.
                // Reached directly by the theme list and the theme form, which draw a
                // swatch per role and cannot go through a thirteen-way switch of named
                // accessors. `pick()` itself is not listed: nothing echoes it, and an
                // allowance nothing uses is a line that would be believed later.
                'SiteChrome::themeColor',
                // The validator itself, which the paragraph above already names as the
                // reason the four accessors are safe. Its answer set is `#rrggbb` and the
                // empty string, and an empty declaration in a `style` attribute is one a
                // browser drops. Listed because a swatch drawn from a colour somebody
                // stored is exactly the case: escaping it would stop the value ending the
                // attribute and not stop it ending the declaration.
                'Color::read'];

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

// ---- No canvas colour resolves through a Workspace Theme ------------------------
// Decision 11 of the v2 roadmap, and the one decision written down *as* a check rather
// than as a convention: "`#builder-canvas` and everything drawn on it belong to the
// Brand. Enforced by a check, not by convention."
//
// Both halves are tempting, which is why it needs enforcing. What the canvas shows is
// what the sign shows, so a theme colour reaching a block would make the Builder a
// preview of something no Screen renders — and it would be invisible, because the person
// who set the theme is the person looking at the canvas. The other half is the mirror
// image: the selection outline and the resize handles *are* themable (decision 10),
// being drawn over the canvas and reaching no Screen, and a rule that refused every role
// anywhere near the canvas would have made that role undrawable.
//
// So two things are checked, and the second is the one a reviewer would not think of: no
// role but `--selection` appears in a rule that paints the canvas, **and** `--selection`
// appears nowhere else. A role that may only be used in one place is a fact only if the
// other places are checked too.
require_once $root . '/lib/site_chrome.php';

// The selectors that draw on the canvas. A list, and its limit is stated at the bottom of
// this file: a canvas rule written under a brand-new selector is invisible to it. The
// count assertion below is what stops the list going stale in silence — a renamed
// selector makes this rule check nothing, and checking nothing reads as a pass.
$canvasSelectors = ['#builder-canvas', '.editable-block', '.rh', '.section-block',
                    '.section-label', '.text-inner', '.hidden-badge', '.clip-badge',
                    '.lock-icon', '.carousel-preview', '.marquee-preview'];

$themeVars = [];
foreach (array_keys(SiteChrome::ROLES) as $_role) { $themeVars[SiteChrome::varName($_role)] = $_role; }
unset($_role);
$overlayVar = SiteChrome::varName('selection');

$canvasProblems = [];
$canvasRules = $overlayUses = 0;
preg_match_all('/<style>(.*?)<\/style>/s', file_get_contents($root . '/builder.php'), $blocks);
foreach ($blocks[1] as $block) {
    // Comments out first, and this is not tidiness: the text before a rule's `{` includes
    // whatever comment sits above it, and the comments above these very rules explain
    // which selectors are the selection outline and the handles. So a comment could
    // satisfy the `.selected` test for a rule that is not one — the check agreeing with
    // its own documentation, which is #50's complaint in a new costume. Found by
    // hand-mutating the selector list and reading what the failure said.
    $block = preg_replace('!/\*.*?\*/!s', ' ', $block);
    preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $block, $rules, PREG_SET_ORDER);
    foreach ($rules as $rule) {
        $selector = trim(preg_replace('/\s+/', ' ', $rule[1]));
        $onCanvas = false;
        foreach ($canvasSelectors as $frag) {
            if (strpos($selector, $frag) !== false) { $onCanvas = true; break; }
        }
        if ($onCanvas) { $canvasRules++; }
        preg_match_all('/var\((--[a-z0-9-]+)\)/i', $rule[2], $used);
        foreach ($used[1] as $var) {
            if (!isset($themeVars[$var])) { continue; }
            if ($var === $overlayVar) {
                $overlayUses++;
                if (!$onCanvas || (strpos($selector, '.selected') === false
                                   && strpos($selector, '.rh') === false)) {
                    $canvasProblems[] = "`$var` is the canvas-overlay role and is used by `$selector`, "
                                      . 'which is not the selection outline or a resize handle';
                }
                continue;
            }
            if ($onCanvas) {
                $canvasProblems[] = "`$selector` paints the canvas and reaches the theme role "
                                  . "`{$themeVars[$var]}` through `$var`";
            }
        }
    }
}
if ($canvasRules < 8) {
    $canvasProblems[] = "only $canvasRules rules in builder.php matched the canvas selector list, "
                      . 'so this rule is checking almost nothing — the list has gone stale';
}
if ($overlayUses < 1) {
    $canvasProblems[] = "`$overlayVar` is used nowhere, so the one themable thing on the canvas "
                      . 'is not actually drawn from the theme';
}
$checked++;
if (!$canvasProblems) {
    echo "  ok   no canvas colour resolves through a theme, and the overlay role is used "
       . "nowhere else (decision 11)\n";
} else {
    echo "  FAIL the canvas and the Workspace Theme have met (decision 11)\n";
    foreach ($canvasProblems as $problem) { echo "       $problem\n"; }
    echo "       What the canvas shows is what the sign shows, so its colours are the\n";
    echo "       Brand's. The selection outline and the resize handles are the exception\n";
    echo "       and the only one: they are drawn over a block and reach no Screen.\n";
    $failures[] = 'a theme role on the canvas';
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
foreach (['admin_panel.php', 'api.php', 'builder.php', 'crud.php', 'help.php'] as $entry) {
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

// ---- No parameter is implicitly nullable ----------------------------------------
/**
 * Every `Type $x = null` that should be `?Type $x = null` (invariant 33).
 *
 * The sibling of invariant 31 and the opposite direction. That one refuses syntax the
 * shop's PHP cannot *parse* — a blank sign. This one refuses syntax that parses
 * everywhere and is **deprecated from 8.4**, whose cost is a line in the error log on
 * every request that compiles the file. `SiteChrome::wear()` was one, and it is called
 * on every signed-in page load; `ServerReport::__construct()` was another, and
 * `admin_panel.php` builds one every time the panel is opened.
 *
 * Three things could not see it, which is why it is a check and not a convention:
 *
 *   · `php -l` is clean on both spellings, on every version.
 *   · The deprecation is emitted when a file is **compiled**, not when it is parsed —
 *     so it fires at `require` time, before any error handler the self-test installs
 *     exists, and the suite's "no PHP diagnostics during the run" check never sees it.
 *   · This container's `error_reporting` is 22527, which excludes `E_DEPRECATED`
 *     (`E_ALL` is 30719 here). So the suite runs green on PHP 8.4 while the notice is
 *     being emitted. `ErrorPolicy::install()` does set `E_ALL` — on a real request,
 *     which is the one place nobody is watching a console.
 *
 * So the rule has two halves and they overlap on purpose. `tools/check_deprecations.php`
 * is the other: it compiles every file in a child process with `E_ALL` set and fails on
 * whatever the engine says, which is the instrument that *found* these rather than a rule
 * written afterwards, and it answers for the version running it — so it reports what 8.5
 * deprecates without anybody teaching it. This half knows one shape and knows it on 8.2,
 * where the engine says nothing at all. Both, because CI answers on a push and this
 * answers before one.
 *
 * Only parameter lists are examined, and that is the whole difficulty. A scan that
 * looks at every `$x = null` in the file reports `private static $bytes = null;` —
 * `UploadLimit` has exactly that, and so does `ServerReport` — because the token before
 * the variable is `static` either way. So this walks to a `function`/`fn`, finds its
 * parameter list, and only reads variables at depth 1 inside it.
 *
 * @return array of ['line' => int, 'what' => string]
 */
function implicitNullableUses($source)
{
    $ts = array_values(array_filter(token_get_all($source), function ($t) {
        return !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }));

    // A parameter type is these tokens and nothing else.
    //
    // `static` is absent because it is not legal as a parameter type — and **that is all
    // it is**. It would be easy to write that excluding it is what stops a static property
    // reading as one, and a mutation check says otherwise: putting `T_STATIC` back into
    // this list changes nothing, because the parameter-list walk below never reaches a
    // property or a `static $x = null` in the first place. The scoping is load-bearing and
    // this line is not, which is worth saying rather than letting the comment take credit
    // (invariant 30 — the mutant survived and the answer was to fix the sentence).
    $typeTokens = [T_STRING, T_ARRAY, T_CALLABLE];
    if (defined('T_NAME_QUALIFIED'))       { $typeTokens[] = T_NAME_QUALIFIED; }
    if (defined('T_NAME_FULLY_QUALIFIED')) { $typeTokens[] = T_NAME_FULLY_QUALIFIED; }

    $isType = function ($t) use ($typeTokens) {
        if (is_array($t)) { return in_array($t[0], $typeTokens, true); }
        return $t === '\\' || $t === '|' || $t === '&';
    };

    $out   = [];
    $count = count($ts);
    for ($i = 0; $i < $count; $i++) {
        $t = $ts[$i];
        if (!is_array($t) || ($t[0] !== T_FUNCTION && !(defined('T_FN') && $t[0] === T_FN))) {
            continue;
        }
        // Walk to this function's own '('. A name or `&` may sit between.
        $open = $i + 1;
        while ($open < $count && $ts[$open] !== '(') { $open++; }
        if ($open >= $count) { continue; }

        $depth = 0;
        for ($j = $open; $j < $count; $j++) {
            if ($ts[$j] === '(') { $depth++; continue; }
            if ($ts[$j] === ')') { $depth--; if ($depth === 0) { $i = $j; break; } continue; }
            if ($depth !== 1 || !is_array($ts[$j]) || $ts[$j][0] !== T_VARIABLE) { continue; }

            // Does it default to null?
            if (!isset($ts[$j + 1], $ts[$j + 2]) || $ts[$j + 1] !== '='
                || !is_array($ts[$j + 2]) || $ts[$j + 2][0] !== T_STRING
                || strcasecmp($ts[$j + 2][1], 'null') !== 0) { continue; }

            // Collect the type immediately before it.
            $type = [];
            for ($k = $j - 1; $k >= $open; $k--) {
                if ($ts[$k] === '?') { $type[] = '?'; continue; }
                if ($isType($ts[$k])) { $type[] = is_array($ts[$k]) ? $ts[$k][1] : $ts[$k]; continue; }
                break;
            }
            if (!$type) { continue; }                                  // untyped: legal forever
            $spelled = implode('', array_reverse($type));
            if (strpos($spelled, '?') !== false) { continue; }          // already explicit
            if (preg_match('/\bnull\b/i', $spelled)) { continue; }      // union already holds null

            $out[] = ['line' => $ts[$j][2], 'what' => $spelled . ' ' . $ts[$j][1] . ' = null'];
        }
    }
    return $out;
}

$implicit = [];
// phpFilesUnder() already skips this file, which is what lets the probes below spell
// the deprecated form out in full without the sweep above reporting them. This loop
// carried a second `continue` for the same file until the merge with main, where the
// same rule had been written without one: a guard that cannot fire, in a checker whose
// own invariant 30 is that a line ships having been seen to matter.
foreach (phpFilesUnder($root, '', []) as $rel) {
    foreach (implicitNullableUses(file_get_contents($root . '/' . $rel)) as $hit) {
        $implicit[] = $rel . ':' . $hit['line'] . '  ' . $hit['what'];
    }
}
$checked++;
if (!$implicit) {
    echo "  ok   no parameter is implicitly nullable, so nothing logs a deprecation on 8.4 (invariant 33)\n";
} else {
    echo "  FAIL a parameter is implicitly nullable, which 8.4 deprecates (invariant 33)\n";
    foreach ($implicit as $where) { echo "       $where\n"; }
    echo "       Write it `?Type \$x = null`. Understood back to 7.1, so it costs nothing\n";
    echo "       below the floor. `php -l` is clean either way and the deprecation fires\n";
    echo "       when the file is compiled — before any handler the suite installs — so\n";
    echo "       this and tools/check_deprecations.php are the only two things here\n";
    echo "       that can see it.\n";
    $failures[] = 'an implicitly nullable parameter (invariant 33)';
}

// ---- And that detector, seen to fail --------------------------------------------
// Invariant 30, and the negative half carries the weight: every `no` below is a shape a
// scan of `$x = null` really does hit, and the first two are live code in this repo.
$nullableProbes = [
    ['function f(array $x = null) {}',                     1, 'array $x = null',
     'a built-in type defaulting to null is the deprecated form'],
    ['function f(WorkspaceTheme $t = null) {}',            1, 'WorkspaceTheme $t = null',
     'and so is a class type — the one SiteChrome::wear() had'],
    ['class A { function f(int $n = null) {} }',            1, 'int $n = null',
     'inside a class body as well as at top level'],
    ['function f(?Foo $a = null, Bar $b = null) {}',        1, 'Bar $b = null',
     'and only the offending one of two, so a mixed signature reports once'],
    ['function f(?array $x = null) {}',                     0, '',
     'the explicit form is what this exists to leave alone'],
    ['function f($x = null) {}',                            0, '',
     'an untyped parameter has always been legal and is not touched'],
    ['function f(array|null $x = null) {}',                 0, '',
     'a union already naming null is explicit enough for 8.4'],
    ['function f(array $x = []) {}',                        0, '',
     'a default that is not null is not the deprecation'],
    ['class A { private static $bytes = null; }',           0, '',
     'a static property is not a parameter — the false positive a naive scan gives'],
    ['function f() { static $c = null; return $c; }',       0, '',
     'and neither is a static variable, which reads identically to one'],
    ['$x = null;',                                          0, '',
     'nor a plain assignment, which is most of what the pattern would hit'],
];
foreach ($nullableProbes as $probe) {
    list($snippet, $expected, $needle, $label) = $probe;
    $checked++;
    $hits = implicitNullableUses("<?php\n" . $snippet . "\n");
    $ok = count($hits) === $expected
          && ($expected === 0 || ($hits[0]['line'] === 2 && $hits[0]['what'] === $needle));
    if ($ok) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL the implicit-nullable detector is wrong about: $label\n";
        echo "       expected $expected hit(s)"
           . ($expected ? " on line 2 reading `$needle`" : '') . ', got ' . count($hits) . "\n";
        foreach ($hits as $hit) { echo "         line {$hit['line']}: {$hit['what']}\n"; }
        $failures[] = "implicit-nullable detector: $label";
    }
}

// ---- No test writes a value the engine the shop runs would refuse ----------------
/**
 * The column types `schema.sql` declares, as `[table][column] => type` (invariant 32).
 *
 * Read from that file rather than from `lib/schema.php` on purpose: schema.sql is what
 * builds the MySQL fixture the suite runs against, so it is the file whose answer the
 * engine will actually give. Only the four kinds of type that can *refuse* a literal
 * are read — an ENUM, a fixed-width string, and a date — because the rule below is
 * about a write MySQL rejects, not about type-checking the suite.
 *
 * @return array
 */
function schemaColumnTypes($sql)
{
    $types = [];
    if (!preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+`?(\w+)`?\s*\((.*?)\n\)\s*ENGINE/is',
                        $sql, $tables, PREG_SET_ORDER)) {
        return $types;
    }
    foreach ($tables as $table) {
        $types[$table[1]] = [];
        foreach (explode("\n", $table[2]) as $line) {
            // The lookahead is not decoration: `\b` after a closing paren is not a word
            // boundary — a space follows a non-word character — so the ENUM and VARCHAR
            // halves of this pattern matched nothing at all while the date half worked,
            // which is a rule reading green over the two column kinds that broke CI.
            if (preg_match('/^\s*`?(\w+)`?\s+(ENUM\([^)]*\)|VARCHAR\(\d+\)|CHAR\(\d+\)|DATETIME|TIMESTAMP|DATE)(?=[\s,]|$)/i',
                           $line, $col)) {
                $types[$table[1]][$col[1]] = strtoupper(substr($col[2], 0, 5)) === 'ENUM('
                                           ? $col[2] : strtoupper($col[2]);
            }
        }
    }
    return $types;
}

/**
 * Why MySQL would refuse this literal for this column, or '' if it would not.
 *
 * The three refusals are the three that actually happened, and the fourth is the one
 * that would have happened next:
 *
 *   · a value that is not an ENUM member — error 1265, and lettercase does **not**
 *     make one: MySQL matches a member through the column's case-insensitive
 *     collation, which is why the suite's `role = 'Admin'` write has always been fine
 *     on both engines and is not what this looks for;
 *   · a string longer than the column is wide — error 1406. A colour nobody can read
 *     has to *fit* `VARCHAR(7)` before anybody can be shown the wrong thing by it;
 *   · anything that is not a date in a date column — error 1292;
 *   · the zero date, which is a date and still refused, by NO_ZERO_DATE.
 *
 * SQLite has none of these limits: it stores every one of them and the check that
 * reads it back passes, which is the whole reason this is a gate here rather than
 * something CI can be relied on to say. CI *does* say it — as a fatal, in the middle
 * of a run, which also takes down the rehearsal step underneath.
 */
function valueRefusedByColumn($columns, $column, $value)
{
    if (!isset($columns[$column])) { return ''; }
    $type = $columns[$column];

    if (strtoupper(substr($type, 0, 5)) === 'ENUM(') {
        preg_match_all("/'([^']*)'/", $type, $members);
        foreach ($members[1] as $member) {
            if (strcasecmp($member, $value) === 0) { return ''; }
        }
        return $type . " has no member '" . $value . "'";
    }
    if (preg_match('/^(?:VAR)?CHAR\((\d+)\)$/', $type, $width)) {
        return strlen($value) > intval($width[1])
             ? $type . ' cannot hold ' . strlen($value) . " characters ('" . $value . "')"
             : '';
    }
    if ($type === 'DATETIME' || $type === 'TIMESTAMP' || $type === 'DATE') {
        if (strpos($value, '0000-00-00') === 0) {
            return $type . " refuses the zero date, which NO_ZERO_DATE has covered since 5.7";
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}/', $value) ? '' : $type . " will not read '" . $value . "'";
    }
    return '';
}

/**
 * Every literal write in this source the engine the shop runs would refuse.
 *
 * Comments are dropped first, for the reason every other rule in this file drops them:
 * the write-ups quote the statements they are about.
 *
 * @return array of ['line' => int, 'what' => string]
 */
function refusedLiteralWrites($source, $types)
{
    $out = [];
    foreach (explode("\n", codeWithoutComments($source)) as $i => $line) {
        $n = $i + 1;
        if (preg_match_all('/UPDATE\s+(\w+)\s+SET\s+(.*?)(?:\s+WHERE|"|$)/i', $line, $sets, PREG_SET_ORDER)) {
            foreach ($sets as $set) {
                if (!isset($types[$set[1]])) { continue; }
                preg_match_all("/(\w+)\s*=\s*'([^']*)'/", $set[2], $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $why = valueRefusedByColumn($types[$set[1]], $pair[1], $pair[2]);
                    if ($why !== '') { $out[] = ['line' => $n, 'what' => $set[1] . '.' . $pair[1] . ': ' . $why]; }
                }
            }
        }
        if (preg_match_all('/INSERT\s+INTO\s+(\w+)\s*\(([^)]*)\)\s*VALUES\s*\(([^)]*)\)/i',
                           $line, $ins, PREG_SET_ORDER)) {
            foreach ($ins as $one) {
                if (!isset($types[$one[1]])) { continue; }
                $columns = array_map(function ($c) { return trim(trim($c), '`'); }, explode(',', $one[2]));
                $values  = array_map('trim', explode(',', $one[3]));
                if (count($columns) !== count($values)) { continue; }
                foreach ($columns as $at => $column) {
                    $value = $values[$at];
                    if (strlen($value) < 2 || $value[0] !== "'" || substr($value, -1) !== "'") { continue; }
                    $why = valueRefusedByColumn($types[$one[1]], $column, substr($value, 1, -1));
                    if ($why !== '') { $out[] = ['line' => $n, 'what' => $one[1] . '.' . $column . ': ' . $why]; }
                }
            }
        }
    }
    return $out;
}

// The rule, over every tool that can be pointed at the MySQL fixture. Which connection
// a given statement is on is not something this can read — the suite holds several, two
// of them deliberately SQLite-only — so it asks the narrower question that needs no such
// answer: would the engine the shop runs accept this literal at all? For eight days the
// answer was no in four places, and nothing local could say so, because SQLite stores
// every one of them and the check that reads it back passes (§4bk).
$writeTypes = schemaColumnTypes(file_get_contents($root . '/schema.sql'));
$checked++;
if (isset($writeTypes['brands']['bg_type']) && count($writeTypes) >= 8) {
    echo "  ok   schema.sql's column types were read, so the rule below has something to check against\n";
} else {
    echo "  FAIL schema.sql's column types were not read — " . count($writeTypes) . " tables\n";
    $failures[] = 'schemaColumnTypes read schema.sql';
}

$refusedWrites = [];
foreach (glob($root . '/tools/*.php') as $tool) {
    if (basename($tool) === basename(__FILE__)) { continue; }
    foreach (refusedLiteralWrites(file_get_contents($tool), $writeTypes) as $hit) {
        $refusedWrites[] = 'tools/' . basename($tool) . ':' . $hit['line'] . '  ' . $hit['what'];
    }
}
$checked++;
if (!$refusedWrites) {
    echo "  ok   no tool writes a literal the engine the shop runs would refuse\n";
} else {
    echo "  FAIL a tool writes a literal MySQL refuses — a fatal mid-run, and the rehearsal under it never runs\n";
    foreach ($refusedWrites as $one) { echo "       $one\n"; }
    $failures[] = 'literal writes MySQL refuses: ' . count($refusedWrites);
}

// ---- And that detector, seen to fail --------------------------------------------
// Invariant 30. The first four are the four writes §4bk removed, in the order CI would
// have hit them; the negative half is where the care is, because the two easy ways to
// write this rule both break real lines in the suite — an ENUM match that respects
// lettercase would condemn `role = 'Admin'`, which MySQL has always accepted, and a
// length rule that did not read the declared width would condemn every colour.
$writeProbes = [
    ['$p->exec("UPDATE brands SET bg_type = \'nonsense\' WHERE id = 1");', 1,
     'the ENUM write that ended the MySQL leg is refused'],
    ['$p->exec("UPDATE users SET role = \'root\' WHERE id = 1");', 1,
     'and a word an ENUM never offered, on the table main\'s arm died on'],
    ['$p->exec("UPDATE users SET role = \'Admin\' WHERE id = 1");', 0,
     'but a member in another lettercase is left alone — MySQL folds it, and always has'],
    ['$p->exec("UPDATE block_styles SET font_color = \'linear-gradient(to right, #ffffff 0%, #000000 100%)\' WHERE id = 1");', 1,
     'and a value past a VARCHAR(50), which is the same refusal a wider column still makes'],
    ['$p->exec("UPDATE displays SET last_published_at = \'nonsense\' WHERE id = 1");', 1,
     'and so is the stamp that is not a date — the one before it on main'],
    ['$p->exec("UPDATE workspace_themes SET nav_bg = \'darkblue\' WHERE id = 1");', 1,
     'and eight characters in a VARCHAR(7), which no database client could have stored either'],
    ['$p->exec("UPDATE displays SET last_published_at = \'0000-00-00 00:00:00\' WHERE id = 1");', 1,
     'and the zero date, which is a date and still refused'],
    ['$p->exec("INSERT INTO brands (name, bg_type) VALUES (\'Salmon House\', \'nonsense\');");', 1,
     'an INSERT is read the same way as an UPDATE, by column position'],
    ['$p->exec("UPDATE brands SET bg_type = \'image\' WHERE id = 1");', 0,
     'a member of the ENUM is left alone'],
    ['$p->exec("UPDATE brands SET bg_type = \'Image\' WHERE id = 1");', 0,
     'and so is one in another lettercase, because MySQL matches a member case-insensitively'],
    ['$p->exec("UPDATE workspace_themes SET nav_bg = \'gold\' WHERE id = 1");', 0,
     'a colour that will not read but fits the column is exactly the state a check wants'],
    ['$p->exec("UPDATE displays SET last_published_at = \'2026-08-19 12:00:00\' WHERE id = 1");', 0,
     'a real stamp is not a refusal'],
    ['$p->exec("UPDATE nothing_declared SET bg_type = \'nonsense\' WHERE id = 1");', 0,
     'and a table schema.sql does not declare is not this rule\'s business'],
    ['// UPDATE brands SET bg_type = \'nonsense\' — what §4bk removed', 0,
     'a write-up quoting the statement it is about is prose, not a write'],
];
foreach ($writeProbes as $probe) {
    list($snippet, $expected, $label) = $probe;
    $checked++;
    $hits = refusedLiteralWrites("<?php\n" . $snippet . "\n", $writeTypes);
    if (count($hits) === $expected) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL the refused-write detector is wrong about: $label\n";
        echo "       expected $expected hit(s), got " . count($hits) . "\n";
        foreach ($hits as $hit) { echo "         line {$hit['line']}: {$hit['what']}\n"; }
        $failures[] = "refused-write detector: $label";
    }
}

// ---- Nor SQLite dialect on a connection that may be MySQL ------------------------
/**
 * SQLite-only SQL handed to a connection the MySQL leg can also be holding.
 *
 * The other half of invariant 32, and the half that cost the run once the literals
 * were fixed: a value MySQL refuses is one statement failing, but a `CREATE TABLE`
 * in the wrong dialect is a *fatal* — it throws where no check is looking, the suite
 * ends without reporting, and the rehearsal step under it never starts (§4bl).
 *
 * Which connection a statement is on *is* readable here, unlike for the literals
 * above, because the suite names them: a handle assigned from `newSqliteTestDb()` or
 * from `new PDO('sqlite:…')` is SQLite for the life of the file, and one assigned
 * from `newTestDb()` is whichever engine the run was started against. So the rule is
 * narrow on purpose — it fires only on a handle of the second kind, which leaves a
 * test that genuinely needs SQLite free to say so and be believed.
 *
 * Its blind spot is the same narrowness read the other way: a handle this cannot
 * classify is left alone, so the `$pdo` parameter the two fixture helpers take is
 * invisible here. That is where both dialects are *supposed* to be written out, side
 * by side, which is why it is a reasonable place to be blind — but it means adding a
 * third such helper is not something this gate will check for you.
 *
 * @return array of ['line' => int, 'what' => string]
 */
function sqliteOnlyOnPortableHandle($source)
{
    $code = codeWithoutComments($source);

    // Which handles are pinned to SQLite, and which are whatever the run chose.
    $sqliteOnly = [];
    $portable   = [];
    if (preg_match_all('/\$(\w+)\s*=\s*(newSqliteTestDb\(|new\s+PDO\s*\(\s*[\'"]sqlite:)/',
                       $code, $pins, PREG_SET_ORDER)) {
        foreach ($pins as $pin) { $sqliteOnly[$pin[1]] = true; }
    }
    if (preg_match_all('/\$(\w+)\s*=\s*newTestDb\(/', $code, $both, PREG_SET_ORDER)) {
        foreach ($both as $one) { $portable[$one[1]] = true; }
    }

    // Constructs MySQL has no spelling for at all, so a statement carrying one is in
    // the wrong dialect however the rest of it is written. `TEXT … DEFAULT` is here
    // for a different reason than the others: it is valid SQLite and MySQL rejects
    // *the default*, which is the same fatal by a subtler route.
    $sqliteisms = [
        'AUTOINCREMENT'                       => 'AUTOINCREMENT is AUTO_INCREMENT on MySQL',
        'RAISE('                              => 'RAISE() is SQLite\'s only way to abort a trigger',
        'CREATE TRIGGER'                      => 'a SQLite trigger body is not a MySQL one',
        'sqlite_master'                       => 'sqlite_master is SQLite\'s catalogue',
        'PRAGMA '                             => 'PRAGMA is a SQLite statement',
        'INSERT OR REPLACE'                   => 'INSERT OR REPLACE is REPLACE INTO on MySQL',
    ];

    // The receiver a statement is sent through, which is what decides whether it can
    // reach MySQL. A heredoc or a statement split over lines keeps the receiver on the
    // first line, so the handle in scope is carried forward until the next one appears.
    $out     = [];
    $holding = '';
    foreach (explode("\n", $code) as $i => $line) {
        if (preg_match('/\$(\w+)\s*->\s*(?:exec|query|prepare)\s*\(/', $line, $call)) {
            $holding = $call[1];
        }
        foreach ($sqliteisms as $needle => $why) {
            if (stripos($line, $needle) === false) { continue; }
            if ($holding === '' || !isset($portable[$holding])) { continue; }
            $out[] = ['line' => $i + 1, 'what' => '$' . $holding . ': ' . $why];
        }
        if (preg_match('/\bTEXT\b[^,)]*\bDEFAULT\b/i', $line)
            && $holding !== '' && isset($portable[$holding])) {
            $out[] = ['line' => $i + 1,
                      'what' => '$' . $holding . ': MySQL allows no DEFAULT on a TEXT column'];
        }
    }
    return $out;
}

$dialectHits = [];
foreach (glob($root . '/tools/*.php') as $tool) {
    if (basename($tool) === basename(__FILE__)) { continue; }
    foreach (sqliteOnlyOnPortableHandle(file_get_contents($tool)) as $hit) {
        $dialectHits[] = 'tools/' . basename($tool) . ':' . $hit['line'] . '  ' . $hit['what'];
    }
}
$checked++;
if (!$dialectHits) {
    echo "  ok   no tool sends SQLite-only SQL down a connection the MySQL leg may be holding
";
} else {
    echo "  FAIL SQLite dialect on a portable handle — a fatal on the MySQL leg, not a failed check
";
    foreach ($dialectHits as $one) { echo "       $one
"; }
    $failures[] = 'SQLite dialect on a portable handle: ' . count($dialectHits);
}

// And that one seen to fail as well. The first probe is the statement that ended the
// run at check 1383; the negative half is the whole reason the rule reads the handle at
// all, since the same text is correct on a connection that is pinned to SQLite.
$dialectProbes = [
    ['$midPdo = newTestDb();' . "\n" . '$midPdo->exec("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)");',
     1, 'the CREATE TABLE that ended the MySQL leg at check 1383 is refused'],
    ['$racePdo = newTestDb();' . "\n" . '$racePdo->exec("CREATE TRIGGER seed_race BEFORE INSERT ON displays BEGIN");',
     1, 'and so is a trigger, which MySQL spells nothing like'],
    ['$midPdo = newTestDb();' . "\n" . '$midPdo->exec("CREATE TABLE t (type TEXT NOT NULL DEFAULT \'text\')");',
     1, 'and a DEFAULT on a TEXT column, which is valid here and rejected there'],
    ['$racePdo = newSqliteTestDb();' . "\n" . '$racePdo->exec("CREATE TRIGGER seed_race BEFORE INSERT ON displays BEGIN");',
     0, 'a handle that says it is SQLite is believed, which is what makes the rule usable'],
    ['$bare = new PDO(\'sqlite::memory:\');' . "\n" . '$bare->exec("CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT)");',
     0, 'including one opened directly rather than through the fixture'],
    ['$midPdo = newTestDb();' . "\n" . '$midPdo->exec("CREATE TABLE t (id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY)");',
     0, 'and the portable spelling is not mistaken for the SQLite one'],
    ['// CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT) — what §4bl removed',
     0, 'a write-up quoting the statement it is about is prose, not a statement'],
];
foreach ($dialectProbes as $probe) {
    list($snippet, $expected, $label) = $probe;
    $checked++;
    $hits = sqliteOnlyOnPortableHandle("<?php\n" . $snippet . "\n");
    if (count($hits) === $expected) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL the SQLite-dialect detector is wrong about: $label\n";
        echo "       expected $expected hit(s), got " . count($hits) . "\n";
        foreach ($hits as $hit) { echo "         line {$hit['line']}: {$hit['what']}\n"; }
        $failures[] = "SQLite-dialect detector: $label";
    }
}

// ---- The instrument itself -------------------------------------------------------
// Every rule above is read through codeWithoutComments(), so what that function drops
// decides what all thirty-two of them can see. Invariant 33, above, is the one that does
// not use it — token_get_all() hands it comments as tokens and it drops them itself,
// which is what lets it read a parameter list rather than a pattern. It gained HTML
// comments in
// #50, and the decision it embodies — a comment holding PHP is code and stays — is
// worth an assertion rather than a paragraph, because both halves fail silently:
// dropping too little is the false positive #44 hit, and dropping too much blinds
// every rule to whatever a page hid inside a comment.
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

// ---- Every gate in tools/ is a step in the workflow that runs it ------------------
/**
 * Suites and gates that CI does not run, and steps that name a file that is not there.
 *
 * The hole this closes was found by the merge that produced it. The node job's comment
 * said "six suites" while its step list ran eight, and the count had already been
 * corrected twice — each time by a merge, because two branches each adding a suite is
 * two clean diffs and no conflict. `selftest_builder_readonly.js` does assert there are
 * eight, but it counts `selftest_*.js` **files**: a ninth added without a step here
 * passed every gate in the repo and never ran, which is a suite in the same state as a
 * check that cannot fail (invariant 30) — it costs its minutes and answers nothing.
 *
 * Both directions, because they fail differently. A suite with no step is **silent**:
 * green everywhere, running nowhere. A step naming a file that is not there is loud —
 * CI goes red — but it goes red on a push, after the review, and the answer to it is
 * one line in this file away from whoever renamed the suite.
 *
 * Comments are stripped from the workflow first, and that is the check inside the check:
 * this file's own YAML discusses `tools/check_deprecations.php` and `mutate.php` in
 * prose, and a scan that read those as steps would report a clean CI for a suite nobody
 * runs. A step is a `run:` line. Naming one in a sentence is not running it.
 *
 * The exemptions carry their reasons and are held to existing, so the list cannot rot
 * into a name nobody has run since a rename. A gate is anything that asserts; `mutate.php`
 * is deliberately not one — it takes minutes rather than seconds and is a tool to point at
 * what you changed (§4aq) — and a fixture is not a gate at all.
 *
 * @return array ['unrun' => [...], 'missing' => [...], 'stale' => [...]]
 */
function toolsNotRunByCi(array $toolFiles, $workflow, array $notGates)
{
    // A `#` comment in YAML is a line whose first non-space character is `#`. Trailing
    // comments cannot appear on a `run:` line here without becoming part of the shell
    // command, so line-leading is the whole rule.
    $lines = [];
    foreach (explode("\n", $workflow) as $line) {
        if (preg_match('/^\s*#/', $line)) { continue; }
        $lines[] = $line;
    }
    $steps = [];
    if (preg_match_all('~\b(?:php|node)\s+tools/([A-Za-z0-9_.-]+\.(?:php|js))~',
                       implode("\n", $lines), $m)) {
        $steps = array_unique($m[1]);
    }

    $unrun = [];
    foreach ($toolFiles as $file) {
        if (in_array($file, array_keys($notGates), true)) { continue; }
        if (!in_array($file, $steps, true))              { $unrun[] = $file; }
    }
    $missing = [];
    foreach ($steps as $step) {
        if (!in_array($step, $toolFiles, true)) { $missing[] = $step; }
    }
    $stale = [];
    foreach (array_keys($notGates) as $exempt) {
        if (!in_array($exempt, $toolFiles, true)) { $stale[] = $exempt; }
    }
    sort($unrun);
    sort($missing);
    sort($stale);
    return ['unrun' => $unrun, 'missing' => $missing, 'stale' => $stale];
}

// Not a gate, and why. Each of these is asked to exist, so a rename cannot leave a name
// here that means nothing.
$notGates = [
    'mutate.php'         => 'minutes rather than seconds — a tool to point at what you changed (§4aq)',
    'audit_colors.php'   => 'reports what is stored, and asserts nothing about it',
    'test_fixture.php'   => 'the fixture every PHP suite requires, not a suite',
    'page_constants.js'  => 'what the server puts on a page, read by the node suites',
];
$toolFiles = [];
foreach (scandir($root . '/tools') as $entry) {
    if (!is_file($root . '/tools/' . $entry)) { continue; }
    $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
    if ($ext === 'php' || $ext === 'js') { $toolFiles[] = $entry; }
}
$ciFile = '.github/workflows/php-lint.yml';
$ci     = toolsNotRunByCi($toolFiles, (string) file_get_contents($root . '/' . $ciFile), $notGates);
$checked++;
if (!$ci['unrun'] && !$ci['missing'] && !$ci['stale']) {
    echo '  ok   every gate in tools/ is a step in ' . $ciFile
       . ', and every step names a file that is there' . "\n";
} else {
    echo "  FAIL tools/ and $ciFile disagree about what runs\n";
    foreach ($ci['unrun'] as $f) {
        echo "       + tools/$f asserts something and no step runs it — green here, running nowhere\n";
    }
    foreach ($ci['missing'] as $f) {
        echo "       - a step runs tools/$f, which does not exist\n";
    }
    foreach ($ci['stale'] as $f) {
        echo "       ? tools/$f is on the not-a-gate list and is not there either\n";
    }
    echo "       Add the step, or add the file to \$notGates above with the reason it is\n";
    echo "       not a gate. A suite CI does not run costs its minutes and answers nothing.\n";
    $failures[] = 'a gate in tools/ that CI does not run, or a step with no file';
}

// ---- And that detector, seen to fail --------------------------------------------
// Invariant 30. The first probe is the hole itself, and the fourth is the one that made
// stripping comments worth doing rather than assuming: this workflow talks about tools it
// does not run, in prose, a few lines above the steps that run other ones.
$ciYaml = "jobs:\n  a:\n    steps:\n      - run: php tools/selftest_layout.php\n"
        . "      - run: node tools/selftest_viewer.js\n";
$ciProbes = [
    [['selftest_layout.php', 'selftest_viewer.js', 'selftest_ghost.js'], $ciYaml, [],
     ['selftest_ghost.js'], [], [],
     'a suite with no step in the workflow is reported — the hole this was written for'],
    [['selftest_layout.php', 'selftest_viewer.js'], $ciYaml, [],
     [], [], [],
     'and a tools/ directory the workflow covers exactly is clean'],
    [['selftest_layout.php'], $ciYaml, [],
     [], ['selftest_viewer.js'], [],
     'a step naming a file that is not there is reported too, which is a rename half done'],
    [['selftest_layout.php', 'selftest_viewer.js', 'selftest_ghost.js'],
     $ciYaml . "      # node tools/selftest_ghost.js is what this used to run\n", [],
     ['selftest_ghost.js'], [], [],
     'and a comment naming a suite does not count as running it'],
    [['selftest_layout.php', 'selftest_viewer.js', 'mutate.php'], $ciYaml,
     ['mutate.php' => 'minutes rather than seconds'],
     [], [], [],
     'a file the list says is not a gate is left alone'],
    [['selftest_layout.php', 'selftest_viewer.js'], $ciYaml,
     ['gone.php' => 'renamed away three merges ago'],
     [], [], ['gone.php'],
     'but an exemption for a file that no longer exists is reported, so the list cannot rot'],
    [['selftest_layout.php', 'rehearse_phase1.php'],
     "jobs:\n  a:\n    steps:\n      - run: php tools/selftest_layout.php\n"
     . "      - run: |\n          mysql -e \"CREATE DATABASE x;\"\n"
     . "          php tools/rehearse_phase1.php \\\n            --host=127.0.0.1 --confirm-copy\n",
     [], [], [], [],
     'a multi-line run block counts, which is the shape the rehearsal is written in'],
];
foreach ($ciProbes as $probe) {
    list($files, $yaml, $exempt, $wantUnrun, $wantMissing, $wantStale, $label) = $probe;
    $checked++;
    $got = toolsNotRunByCi($files, $yaml, $exempt);
    if ($got['unrun'] === $wantUnrun && $got['missing'] === $wantMissing
        && $got['stale'] === $wantStale) {
        echo "  ok   $label\n";
    } else {
        echo "  FAIL the CI-coverage detector is wrong about: $label\n";
        echo '       expected unrun ' . json_encode($wantUnrun)
           . ', missing ' . json_encode($wantMissing)
           . ', stale ' . json_encode($wantStale) . "\n";
        echo '       got      unrun ' . json_encode($got['unrun'])
           . ', missing ' . json_encode($got['missing'])
           . ', stale ' . json_encode($got['stale']) . "\n";
        $failures[] = "CI-coverage detector: $label";
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
        . 'invisible to all seven of them',
    'a canvas rule under a selector the theme check has never heard of (decision 11) — '
        . 'that check classifies a rule by a LIST of canvas selectors, so a new class '
        . 'drawn inside #builder-canvas is chrome as far as it knows. What stops the '
        . 'list rotting silently is its own count assertion: if a rename made it match '
        . 'almost nothing, it fails rather than passing. A genuinely new selector still '
        . 'has to be added by whoever writes it',
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

// ---- And that every one of them ran ---------------------------------------------
// Both branches that met here added this independently, which is its own small argument
// for it. Not a count of rules: a count of what actually *ran*, so a rule that stops
// being reached is the same failure as one that was deleted. §4bi found this file
// without one — delete the rule that keeps `json_encode` in a single module, one of the
// most load-bearing lines in the repo, and this printed "60 consistency checks, 0
// failed" and exited 0. A checker whose own coverage can shrink silently is the failure
// it exists to prevent, wearing its own uniform. §4bh found the same hole in four of the
// eight node suites; this was the third place it was hiding, and `selftest_layout.php`
// has had the same anchor since #48 (`reportChecks()`).
//
// The ways it goes wrong are not theoretical: a `continue` landing above a block, a
// probe list losing an entry to a merge — this merge dropped 494 duplicate lines and
// with them a whole probe list, twice — or a detector guarded by a `defined()` that is
// false on the host. Each reads as one fewer `ok` line in three hundred, against a total
// nobody knows. Update the number when a check is added on purpose. That is the point:
// it makes adding one deliberate and losing one loud.
//
// It counts itself, which is main's half of this and the better one: an anchor that
// prints nothing when it passes is a check whose own presence is unreadable.
//
// 98 reconciled the merge that deleted 553 duplicated lines, and that is the only reason
// the deletion was trustworthy: 94 before it, plus the three probes main's copy had that
// this one did not, plus one for the anchor now counting itself. A duplicate detector
// removed by hand is exactly the edit that takes a rule with it, and this number is what
// noticed it had not. 106 is that plus the CI-coverage rule and its seven probes.
$expectedChecks = 106;
$checked++;
if ($checked === $expectedChecks) {
    echo "  ok   this checker ran every check it is supposed to ($checked)\n";
} else {
    echo "  FAIL this checker did not run every check it is supposed to\n";
    echo "       expected $expectedChecks, ran $checked\n";
    $failures[] = 'this checker ran every check it is supposed to — expected '
                . $expectedChecks . ', ran ' . $checked;
}

echo "\n$checked consistency checks, " . count($failures) . " failed\n";
if ($failures) {
    foreach ($failures as $f) { echo "  FAILED: $f\n"; }
    exit(1);
}
exit(0);
