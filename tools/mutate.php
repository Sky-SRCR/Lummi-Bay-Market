<?php
// ============================================================
// MUTATION RUNNER — does the suite actually stand over this file?
// ============================================================
//   php tools/mutate.php lib/plain_text.php
//   php tools/mutate.php --list lib/grants.php
//   php tools/mutate.php --only=12 lib/grants.php
//
// Breaks one thing in a file, runs the self-test, and records whether anything
// noticed. A mutant the suite still passes is a line no test can fail on — which
// is decision #50's whole complaint, and until now the only way to find one was to
// break a line by hand, run the suite, and put the line back.
//
// #49 measured two files that way. It worked, it found real gaps (either backfill's
// WHERE clause could be deleted; the sanitiser's five other statements could each be
// removed with the suite green), and the numbers it produced are a record of an
// afternoon rather than something a later change re-runs. §4am says so and hands the
// automation to #50. This is it.
//
// ---- What a result means ------------------------------------------------------
//
// SURVIVED is the finding. It says: this line can be wrong and the suite will tell
// you it is fine. Either write a check that fails against the mutant, or — and this
// is a real answer, not a dodge — write down why the line cannot be observed, the way
// §4am does for `flock(LOCK_UN)`. What is not an answer is deleting the line because
// no test covers it; three of the survivors #49 found were load-bearing.
//
// KILLED is graded, because the grades are not the same claim:
//
//   assertion    a check failed. The suite stands over this line's *behaviour*.
//   diagnostic   only a PHP warning failed it — usually an undefined variable,
//                because the mutant deleted the line that set it. That is the
//                harness noticing the mutant, not a check knowing what the line
//                was for. Treat it as barely covered.
//   count        the count anchor failed and nothing else: the mutant changed how
//                many checks *ran*. Weaker still — it means a check disappeared
//                rather than failed.
//   fatal        the run died. Same caveat as diagnostic.
//   hang         the run had to be timed out. §4am records one of these on purpose:
//                making the schema-repair lock blocking is caught by the suite never
//                finishing, which is a red line of a sort.
//
// INVALID is the tool's own noise: a mutant that will not parse is not a defect
// anybody could have written, so it is not counted either way.
//
// ---- Why it is affordable now -------------------------------------------------
//
// The suite takes about ten seconds, and a mutant only needs one bit off it. With
// `SELFTEST_STOP_ON_FAIL` set (see tools/test_fixture.php) a run leaves on the first
// failure, so most mutants cost a fraction of that and only the survivors — the ones
// worth the wait — pay in full.
//
// One file at a time is the intended unit, and there is no `--all`. Every lib/ file
// at once is thousands of runs and hours of them, and a report nobody reads is the
// same as no report. Run it over what you changed.
//
// **The grade is the FIRST failure, not the worst one.** That is what makes a run cheap
// and it is a real limit on the grades: a mutant whose line is genuinely covered by an
// assertion is reported as `diagnostic` if any warning happens to fire earlier in the
// same run. Two of these were seen while writing this, both in runs launched four at a
// time against one temp directory, and neither reproduced on its own. So if a grade is
// what you are deciding on, run the one file by itself and re-check the mutant with
// `--only=N`. SURVIVED is not affected — nothing failed at all, in any order.
//
// It never touches the repo: the tree is copied once into a sandbox and the mutants
// are written there. The suite is run in the sandbox too, and every path in it is
// __DIR__-relative, so the copy is the whole isolation.
//
// CLI only, and never reachable from the browser (tools/.htaccess).

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$repo = dirname(__DIR__);

// ---- The sweep ledger, and why it is here rather than in a document ----------
//
// Three documents carried three different answers to "how many `lib/` modules have
// been swept" — six, eight and nine — and the true number was ten. That is #50's
// own complaint arriving in #50's bookkeeping: a count written into prose is right
// on the day and nothing ever disagrees with it afterwards. The root of all three
// was one sentence in §4aq naming `lib/layout_rules.php` as worth doing next, four
// paragraphs under a table reporting its 208 mutants. Both halves read fine alone.
//
// So the answer lives here, once, and `--swept` prints it. The documents cite the
// command.
//
// **It is a hand-maintained list and every run says so.** A sweep leaves nothing
// behind in the tree — no artifact, no timestamp — so nothing can derive this, the
// same way `check_invariants.php` prints that its floor check is a denylist. What
// the flag does check is the part that *can* be checked: that every module named
// here still exists under `lib/`, that the denominator is counted from `lib/` on
// disk rather than remembered, and that every write-up section cited is really in
// `BUILD-REFERENCE.md`. A citation left behind by a renumbering is rot this repo
// has shipped twice.
//
// Adding a row is part of writing the sweep up, next to the section itself.

$sweptLedger = [
    'lib/plain_text.php'      => ['§4am', '§4aq'],  // #49 by hand, then re-run by the tool
    'lib/schema.php'          => ['§4am'],          // #49 by hand; the MySQL-only statements ride #48
    'lib/markup.php'          => ['§4aq'],
    'lib/color.php'           => ['§4aq'],
    'lib/grants.php'          => ['§4aq'],
    'lib/store_clock.php'     => ['§4aq'],          // measured twice; the second run is the useful one
    'lib/display_request.php' => ['§4aq'],
    'lib/upload_limits.php'   => ['§4aq', '§4au'],  // and again when the browser pass widened it
    'lib/http_reply.php'      => ['§4aq'],
    'lib/layout_rules.php'    => ['§4aq'],          // 208 mutants — the one §4aq then called undone
];

// Worth doing next, in this order, because each is a module where a wrong answer
// empties a sign or hands somebody a Display they were never granted. The first
// three are §4aq's own list minus `layout_rules.php`; the fourth is here because
// the front door settles closed, suspended and locked-out before it reads the
// password, and a wrong answer there is an oracle rather than a bug (ADR-0008).
$sweptNext = [
    'lib/layout_store.php',
    'lib/displays.php',
    'lib/accounts.php',
    'lib/login_attempt.php',
];

/**
 * Print the ledger, check the checkable half of it, and return an exit code.
 *
 * Returns 1 on anything wrong so a caller in a script notices; the ledger being
 * stale is the failure this exists to make loud.
 */
function reportSweep($repo, array $ledger, array $next)
{
    $modules = glob($repo . '/lib/*.php');
    $total   = is_array($modules) ? count($modules) : 0;
    $doc     = @file_get_contents($repo . '/docs/BUILD-REFERENCE.md');
    $bad     = 0;

    echo "Mutation sweep — which lib/ modules have been seen to fail (invariant 30)\n\n";

    foreach ($ledger as $file => $sections) {
        $notes = [];
        if (!is_file($repo . '/' . $file)) {
            $notes[] = 'NOT IN THE TREE — renamed or removed';
            $bad++;
        }
        foreach ($sections as $s) {
            // The heading is `### 4aq.`; the citation is `§4aq`.
            $needle = '### ' . ltrim($s, '§') . '.';
            if ($doc === false || strpos($doc, $needle) === false) {
                $notes[] = 'no write-up at ' . $s;
                $bad++;
            }
        }
        printf("  %-26s %-12s %s\n", $file, implode(' ', $sections),
               $notes ? '<-- ' . implode('; ', $notes) : '');
    }

    $swept = count($ledger);
    echo "\n" . $swept . ' of ' . $total . " lib/ modules swept; " . ($total - $swept) . " not.\n";
    echo "Next, in order: " . implode(', ', $next) . "\n";
    echo "\nThe list of swept modules is maintained by hand — a sweep leaves no artifact,\n";
    echo "so this cannot be derived from the tree. The count and the citations are checked.\n";

    if ($bad > 0) {
        echo "\n" . $bad . " problem(s) above. Fix the ledger in tools/mutate.php.\n";
        return 1;
    }
    return 0;
}

// ---- Arguments ---------------------------------------------------------------

$targets  = [];
$listOnly = false;
$only     = null;
$suite    = 'tools/selftest_layout.php';
$timeout  = 180;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--swept') {
        exit(reportSweep($repo, $sweptLedger, $sweptNext));
    } elseif ($arg === '--list') {
        $listOnly = true;
    } elseif (strpos($arg, '--only=') === 0) {
        $only = (int)substr($arg, 7);
    } elseif (strpos($arg, '--suite=') === 0) {
        $suite = substr($arg, 8);
    } elseif (strpos($arg, '--timeout=') === 0) {
        $timeout = (int)substr($arg, 10);
    } elseif (strpos($arg, '--') === 0) {
        fwrite(STDERR, "unknown option: $arg\n");
        exit(2);
    } else {
        $targets[] = $arg;
    }
}

if (!$targets) {
    echo "usage: php tools/mutate.php [--list] [--only=N] <file.php> [file.php ...]\n";
    echo "       php tools/mutate.php --swept        which modules have been swept, and what is next\n";
    echo "       one file at a time is the intended unit; see the header.\n";
    exit(2);
}

foreach ($targets as $t) {
    if (!is_file($repo . '/' . $t)) {
        fwrite(STDERR, "not a file in the repo: $t\n");
        exit(2);
    }
}

// ---- Mutation operators ------------------------------------------------------

/**
 * Operators that swap one token for another.
 *
 * Every one of these is a defect somebody could type, which is the bar: a mutant
 * nobody would write teaches nothing about the suite. `===` to `==` is the shape
 * §4am found twice; `&&` to `||` is a guard that stops guarding; `<` to `<=` is the
 * off-by-one in a staleness comparison, which is the one this app cannot afford.
 *
 * Comment text never reaches here — token_get_all hands a comment back as one
 * T_COMMENT token, so an `&&` inside one is not an operator to this loop. The same
 * is true of a string: `true` inside quotes is T_CONSTANT_ENCAPSED_STRING.
 */
function tokenSwaps()
{
    return [
        '===' => '==',
        '!==' => '!=',
        '=='  => '!=',
        '!='  => '==',
        '<'   => '<=',
        '>'   => '>=',
        '<='  => '<',
        '>='  => '>',
        '&&'  => '||',
        '||'  => '&&',
        'and' => 'or',
        'or'  => 'and',
        // A flag set built with the wrong operator. `ENT_QUOTES & ENT_SUBSTITUTE` is
        // 0, and `htmlspecialchars($v, 0)` leaves both quote characters alone — which
        // is the defect lib/markup.php exists to prevent, written as one character.
        // Only this direction: a bare `&` in PHP is as likely to be a reference as a
        // bitwise and, and `|$ref` does not parse.
        '|'   => '&',
    ];
}

/**
 * The scoping predicate, taken away.
 *
 * This is the one operator that reaches inside a string, and it is here because the
 * costliest survivor #49 found was exactly this shape: either backfill's `WHERE
 * display_id IS NULL` could be deleted, which hands every element of every sign to the
 * drive-thru Display — every other Screen blank at once, layouts gone rather than
 * moved. A defect that large has to be something the tool can produce, and no operator
 * over PHP tokens can produce it, because to PHP the whole statement is one string.
 *
 * Two mutants per statement: the whole `WHERE` clause dropped, and each `AND` conjunct
 * dropped on its own. The second is the one that matters — a query keeping its `WHERE
 * id = ?` and losing its `AND display_id = ?` still returns a row, so it fails no test
 * that only asks whether something came back.
 *
 * Trailing clauses are kept. `DELETE … ORDER BY` is valid MySQL and dropping the sort
 * with the filter would make the mutant two changes rather than one.
 */
function sqlPredicateDrops($literal)
{
    $out = array();

    // Case-sensitive, and a statement keyword required alongside — for the reason
    // `check_invariants.php` gives about `NOW()`: every SQL keyword in this repo is upper
    // case, and an insensitive match reads English prose as a query. The first version of
    // this operator was insensitive and found the word "where" in the sentence
    // `HttpReply` prints about damaged text — *"a replacement character where the bad
    // bytes were"* — so it produced a mutant that mangled an admin's message and reported
    // it as an uncovered scoping predicate. A finding that is the tool's own grammar is
    // worse than no finding: it is a real-looking entry on a list somebody will work
    // through.
    if (!preg_match('/\sWHERE\s/', $literal)) { return $out; }
    if (!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/', $literal)) { return $out; }

    $quote = substr($literal, 0, 1);
    $body  = substr($literal, 1, -1);

    $split = preg_split('/(\sWHERE\s)/i', $body, 2, PREG_SPLIT_DELIM_CAPTURE);
    if (count($split) !== 3) { return $out; }
    list($before, , $after) = $split;

    $tail = '';
    if (preg_match('/\s(ORDER\s+BY|GROUP\s+BY|LIMIT|FOR\s+UPDATE|HAVING)\s/i', $after, $m, PREG_OFFSET_CAPTURE)) {
        $tail  = substr($after, $m[0][1]);
        $after = substr($after, 0, $m[0][1]);
    }

    $out[] = array('what' => 'SQL: WHERE clause dropped',
                   'text' => $quote . $before . $tail . $quote);

    $conjuncts = preg_split('/\sAND\s/i', $after);
    if (count($conjuncts) > 1) {
        foreach ($conjuncts as $n => $conjunct) {
            $kept = $conjuncts;
            unset($kept[$n]);
            $out[] = array('what' => 'SQL: dropped `' . trim($conjunct) . '` from the WHERE',
                           'text' => $quote . $before . ' WHERE ' . implode(' AND ', $kept) . $tail . $quote);
        }
    }
    return $out;
}

/** Flatten token_get_all into text/line pairs, so a single-char token has a line too. */
function flatTokens($source)
{
    $flat = [];
    $line = 1;
    foreach (token_get_all($source) as $t) {
        if (is_array($t)) {
            $flat[] = ['id' => $t[0], 'text' => $t[1], 'line' => $t[2]];
            $line   = $t[2] + substr_count($t[1], "\n");
        } else {
            $flat[] = ['id' => null, 'text' => $t, 'line' => $line];
        }
    }
    return $flat;
}

/**
 * The source with every comment blanked out, line numbering intact.
 *
 * The statement tests below ask what a line ends with and what the line above it
 * ended with, and a trailing `// why` makes both answers wrong — `$s = strip_tags($s);
 * // keep "<10" out` does not end in a semicolon, and the line under it then looks
 * like a continuation. Blanking rather than deleting keeps every line number equal to
 * the real file's, so a finding still points at the line a person will open.
 *
 * This is the same problem `check_invariants.php` solves with `codeWithoutComments()`,
 * and deliberately not the same code: that one is answering "does this file contain a
 * forbidden call", so it may collapse whatever it likes. This one has to preserve
 * position. Both work on tokens because both have to — a `//` inside a string is not
 * a comment, and a regex full of `#` characters is where that stops being academic.
 */
function commentFreeSource($source)
{
    $out = '';
    foreach (token_get_all($source) as $t) {
        if (is_array($t) && ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT)) {
            $out .= str_repeat("\n", substr_count($t[1], "\n"));
            continue;
        }
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

/** The token before $i that is not whitespace or a comment, or null. */
function prevMeaningful(array $flat, $i)
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $id = $flat[$j]['id'];
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) { continue; }
        return $flat[$j];
    }
    return null;
}

/** Rebuild the file with token $i replaced by $text. */
function sourceWithToken(array $flat, $i, $text)
{
    $out = '';
    foreach ($flat as $k => $tok) {
        $out .= ($k === $i) ? $text : $tok['text'];
    }
    return $out;
}

/**
 * Rebuild the file with tokens $from..$to gone, and their line breaks kept.
 *
 * The newlines stay so that every other mutant of the same file still points at the
 * line a person will open — a report whose numbers move depending on which mutant is
 * running is a report nobody can act on.
 */
function sourceWithoutRange(array $flat, $from, $to)
{
    $out = '';
    foreach ($flat as $k => $tok) {
        if ($k >= $from && $k <= $to) {
            $out .= str_repeat("\n", substr_count($tok['text'], "\n"));
            continue;
        }
        $out .= $tok['text'];
    }
    return $out;
}

/** The index of the token closing the group that $open opens, or null. */
function matchingClose(array $flat, $open, $openText, $closeText)
{
    $depth = 0;
    for ($j = $open; $j < count($flat); $j++) {
        if ($flat[$j]['text'] === $openText) { $depth++; }
        elseif ($flat[$j]['text'] === $closeText) {
            $depth--;
            if ($depth === 0) { return $j; }
        }
    }
    return null;
}

/** The index of the next token after $i that is not whitespace or a comment, or null. */
function nextMeaningfulIndex(array $flat, $i)
{
    for ($j = $i + 1; $j < count($flat); $j++) {
        $id = $flat[$j]['id'];
        if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) { continue; }
        return $j;
    }
    return null;
}

/**
 * A whole `if (…) { … }` taken out, braces and all.
 *
 * Without this the operator set had a blind spot big enough to swallow a file:
 * `lib/markup.php` produced **no mutants at all**. Every statement in it is a
 * `return`, which the deletion operator excludes on purpose, and its two guards are
 * multi-line blocks rather than the one-line form. A file the tool cannot mutate reads
 * from the outside exactly like a file with perfect coverage, which is #50's own
 * complaint pointed at the instrument instead of the suite.
 *
 * Only where nothing follows the block: deleting the `if` half of an `if/else` leaves
 * a dangling `else`, which is a parse error and so tool noise rather than a defect.
 */
function branchRemovals(array $flat, $source)
{
    $out = array();
    for ($i = 0; $i < count($flat); $i++) {
        if ($flat[$i]['id'] !== T_IF) { continue; }

        $prev = prevMeaningful($flat, $i);
        if ($prev && strtolower($prev['text']) === 'else') { continue; }

        $paren = nextMeaningfulIndex($flat, $i);
        if ($paren === null || $flat[$paren]['text'] !== '(') { continue; }
        $parenEnd = matchingClose($flat, $paren, '(', ')');
        if ($parenEnd === null) { continue; }

        $brace = nextMeaningfulIndex($flat, $parenEnd);
        if ($brace === null || $flat[$brace]['text'] !== '{') { continue; }
        $braceEnd = matchingClose($flat, $brace, '{', '}');
        if ($braceEnd === null) { continue; }

        $after = nextMeaningfulIndex($flat, $braceEnd);
        if ($after !== null && in_array(strtolower($flat[$after]['text']), array('else', 'elseif'), true)) {
            continue;
        }

        $condition = '';
        for ($k = $paren; $k <= $parenEnd; $k++) { $condition .= $flat[$k]['text']; }
        $condition = preg_replace('/\s+/', ' ', $condition);

        $out[] = array('line' => $flat[$i]['line'],
                       'what' => 'branch removed: if ' . $condition,
                       'source' => sourceWithoutRange($flat, $i, $braceEnd));
    }
    return $out;
}

/**
 * Does this line hold one whole statement, on its own?
 *
 * The deletion operator is the one that finds a line nothing stands over —
 * `setupInteract()` after a restore, a latch that costs nothing when called twice —
 * so it is worth being fussy about. A line only qualifies if it ends in `;`, its
 * brackets balance, its quotes pair, and the line above it ended a statement of its
 * own. That last test is what keeps a continuation line out: deleting the middle of
 * a chained call is a parse error, which is tool noise rather than a defect.
 */
function isWholeStatement(array $lines, $i)
{
    $line = rtrim($lines[$i]);
    $t    = trim($line);

    if ($t === '' || substr($t, -1) !== ';') { return false; }
    if (preg_match('/^(\*|\/\/|#|\/\*)/', $t)) { return false; }
    if (preg_match('/^(return|throw|break|continue|case|default|else|elseif|do|require|require_once|include|include_once|namespace|use|declare|const|global|static|abstract|final|public|private|protected|var|function|class|interface|trait)\b/', $t)) {
        return false;
    }
    if (substr_count($t, "'") % 2 !== 0 || substr_count($t, '"') % 2 !== 0) { return false; }
    foreach (array('(' => ')', '[' => ']', '{' => '}') as $open => $close) {
        if (substr_count($t, $open) !== substr_count($t, $close)) { return false; }
    }

    for ($j = $i - 1; $j >= 0; $j--) {
        $prev = trim($lines[$j]);
        if ($prev === '' || preg_match('/^(\*|\/\/|#|\/\*)/', $prev)) { continue; }
        return (bool)preg_match('/[;{}:]$/', $prev) || preg_match('/^<\?php/', $prev);
    }
    return true;
}

/** A guard clause written on one line — `if (!$ok) { return null; }` — which the repo prefers. */
function isOneLineGuard(array $lines, $i)
{
    $t = trim($lines[$i]);
    return (bool)preg_match('/^(if|elseif) \(.+\) \{[^{}]*\}$/', $t);
}

/**
 * Every mutant of one file, as [line, what, source].
 *
 * Deduplicated by the mutated source: two operators that produce the same file are
 * one mutant, and running it twice would report the same finding twice.
 */
function mutantsFor($source)
{
    $mutants = array();
    $seen    = array();

    $add = function ($line, $what, $mutated) use (&$mutants, &$seen, $source) {
        if ($mutated === $source) { return; }
        $key = md5($mutated);
        if (isset($seen[$key])) { return; }
        $seen[$key] = true;
        $mutants[]  = array('line' => $line, 'what' => $what, 'source' => $mutated);
    };

    $flat  = flatTokens($source);
    $swaps = tokenSwaps();

    foreach ($flat as $i => $tok) {
        $text  = $tok['text'];
        $lower = strtolower($text);

        // Matched on the token's *text*, not on a list of token ids. The first version
        // of this listed the ids it expected — and left out `T_BOOLEAN_AND` and
        // `T_BOOLEAN_OR`, so `&&`→`||` generated nothing at all for four modules'
        // worth of runs while the operator sat in the table above looking present. A
        // whole operator family missing is invisible from the report, which only ever
        // shows what was generated: it reads as a file with no connectives in it.
        // Text is safe here because none of the swaps can be anything else — `and` and
        // `or` are reserved words, and a string holding `&&` has its quotes in its
        // token text, so it does not match.
        if (isset($swaps[$lower])) {
            $add($tok['line'], $text . ' → ' . $swaps[$lower],
                 sourceWithToken($flat, $i, $swaps[$lower]));
            continue;
        }
        if ($text === '!') {
            $add($tok['line'], '! dropped', sourceWithToken($flat, $i, ''));
            continue;
        }

        if ($tok['id'] === T_STRING && ($lower === 'true' || $lower === 'false')) {
            $prev = prevMeaningful($flat, $i);
            if ($prev && in_array($prev['text'], array('->', '::', 'function'), true)) { continue; }
            $add($tok['line'], $lower . ' → ' . ($lower === 'true' ? 'false' : 'true'),
                 sourceWithToken($flat, $i, $lower === 'true' ? 'false' : 'true'));
            continue;
        }

        if ($tok['id'] === T_LNUMBER && preg_match('/^[0-9]+$/', $text)) {
            $add($tok['line'], $text . ' → ' . ((string)((int)$text + 1)),
                 sourceWithToken($flat, $i, (string)((int)$text + 1)));
            continue;
        }

        if ($tok['id'] === T_CONSTANT_ENCAPSED_STRING) {
            foreach (sqlPredicateDrops($text) as $drop) {
                $add($tok['line'], $drop['what'], sourceWithToken($flat, $i, $drop['text']));
            }
        }
    }

    foreach (branchRemovals($flat, $source) as $branch) {
        $add($branch['line'], $branch['what'], $branch['source']);
    }

    $lines = explode("\n", $source);
    $code  = explode("\n", commentFreeSource($source));
    foreach ($code as $i => $line) {
        if (!isset($lines[$i])) { break; }
        $whole = isWholeStatement($code, $i);
        $guard = isOneLineGuard($code, $i);
        if (!$whole && !$guard) { continue; }

        // The removed line is replaced rather than commented out, and the original
        // text is not carried into the replacement. The sanitiser's first statement is
        // a regex matching a `<br />` tag, and a question-mark-greater-than inside it
        // closes the PHP block when the line is put behind a `//` — the tokenizer ends
        // the block from inside a comment, which is the whole reason this note does not
        // spell the sequence out either. The obvious form of this operator therefore
        // graded that statement INVALID and stopped mutating the one file #49 measured
        // by hand. A block comment holding nothing of the file's own cannot do it. What
        // was removed is in the report, which is where a person reads it anyway.
        $copy = $lines;
        preg_match('/^[ \t]*/', $lines[$i], $indent);
        $copy[$i] = $indent[0] . '/* [mutant] statement removed */';
        $add($i + 1, ($guard ? 'guard removed: ' : 'statement removed: ') . trim($line),
             implode("\n", $copy));
    }

    usort($mutants, function ($a, $b) {
        if ($a['line'] === $b['line']) { return 0; }
        return $a['line'] < $b['line'] ? -1 : 1;
    });

    return $mutants;
}

// ---- The sandbox -------------------------------------------------------------

function copyTree($from, $to)
{
    if (!is_dir($to) && !@mkdir($to, 0700, true)) {
        fwrite(STDERR, "cannot create $to\n");
        exit(1);
    }
    $entries = scandir($from);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === '.git') { continue; }
        $src = $from . '/' . $entry;
        $dst = $to . '/' . $entry;
        if (is_link($src)) { continue; }
        if (is_dir($src)) { copyTree($src, $dst); continue; }
        copy($src, $dst);
    }
}

function removeTree($dir)
{
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') { continue; }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) { removeTree($path); } else { @unlink($path); }
    }
    @rmdir($dir);
}

/**
 * Run the suite in the sandbox and grade what came back.
 *
 * The grade comes off the output rather than the exit code, because every failing
 * run exits 1 and the five kinds of failure are not the same claim — see the header.
 */
function runSuite($sandbox, $suite, $timeout)
{
    $cmd = 'SELFTEST_STOP_ON_FAIL=1 timeout ' . (int)$timeout . ' '
         . escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($sandbox . '/' . $suite) . ' 2>&1';
    $out  = array();
    $code = 0;
    exec($cmd, $out, $code);
    $text = implode("\n", $out);

    if ($code === 124 || $code === 137) { return array('hang', $text); }
    if ($code === 0) { return array('survived', $text); }

    // Anchored to the start of a line, because the suite echoes the label of every
    // check it passes on the way and one of those labels is about a branding config
    // with a syntax error in it. A tool that greps its own subject's output for the
    // word "error" grades that run as its own noise — which is the first thing this
    // tool found, in itself.
    if (preg_match('/^KILLED by assertion/m', $text))      { return array('assertion', $text); }
    if (preg_match('/^KILLED by diagnostic/m', $text))     { return array('diagnostic', $text); }
    if (preg_match('/^KILLED by the count anchor/m', $text)) { return array('count', $text); }
    return array('fatal', $text);
}

/** Does this mutant parse? A mutant that cannot is the tool's noise, not a defect. */
function mutantParses($path)
{
    $cmd = escapeshellcmd(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1';
    exec($cmd, $out, $code);
    return $code === 0;
}

// ---- Run ---------------------------------------------------------------------

$sandbox = sys_get_temp_dir() . '/lbm-mutate-' . getmypid();
$tidy    = function () use ($sandbox) { removeTree($sandbox); };

if (!$listOnly) {
    echo "Copying the tree into $sandbox\n";
    copyTree($repo, $sandbox);
    register_shutdown_function($tidy);

    // Verify the instrument before measuring through it. A sandbox whose suite is
    // already failing grades every mutant "killed" and reports perfect coverage,
    // which is the mistake this tool exists to catch in other people's tests.
    echo "Baseline: ";
    list($grade, $text) = runSuite($sandbox, $suite, $timeout);
    if ($grade !== 'survived') {
        echo "FAILED — the unmutated suite does not pass in the sandbox.\n";
        echo "Every mutant would be graded killed. Fix the suite first.\n\n";
        echo $text . "\n";
        exit(1);
    }
    echo "clean.\n\n";
}

$grand = array('assertion' => 0, 'diagnostic' => 0, 'count' => 0, 'fatal' => 0,
               'hang' => 0, 'survived' => 0, 'invalid' => 0);
$survivors = array();

foreach ($targets as $target) {
    $source  = file_get_contents($repo . '/' . $target);
    $mutants = mutantsFor($source);

    echo "$target — " . count($mutants) . " mutants\n";

    foreach ($mutants as $n => $mutant) {
        $num = $n + 1;
        if ($only !== null && $num !== $only) { continue; }

        if ($listOnly) {
            printf("  %4d  %s:%d  %s\n", $num, $target, $mutant['line'], $mutant['what']);
            continue;
        }

        file_put_contents($sandbox . '/' . $target, $mutant['source']);
        if (mutantParses($sandbox . '/' . $target)) {
            list($grade, $text) = runSuite($sandbox, $suite, $timeout);
        } else {
            $grade = 'invalid';
        }
        file_put_contents($sandbox . '/' . $target, $source);

        $grand[$grade]++;
        if ($grade === 'survived') {
            $survivors[] = array('target' => $target, 'num' => $num,
                                 'line' => $mutant['line'], 'what' => $mutant['what']);
        }
        printf("  %4d  %-10s %s:%d  %s\n", $num, strtoupper($grade), $target,
               $mutant['line'], $mutant['what']);
    }
    echo "\n";
}

if ($listOnly) { exit(0); }

$killed = $grand['assertion'] + $grand['diagnostic'] + $grand['count']
        + $grand['fatal'] + $grand['hang'];
$total  = $killed + $grand['survived'];

echo "─────────────────────────────────────────────\n";
printf("%d mutants, %d killed, %d survived", $total, $killed, $grand['survived']);
if ($grand['invalid']) { printf(" (%d invalid, not counted)", $grand['invalid']); }
echo "\n";
printf("killed by: assertion %d, diagnostic %d, count anchor %d, fatal %d, hang %d\n",
       $grand['assertion'], $grand['diagnostic'], $grand['count'],
       $grand['fatal'], $grand['hang']);

if ($grand['diagnostic'] + $grand['count'] + $grand['fatal'] + $grand['hang'] > 0) {
    echo "\nOnly the assertion column is a check knowing what a line was for. The other\n";
    echo "four are the harness noticing something moved.\n";
}

if ($survivors) {
    echo "\nSurvived — a line the suite cannot fail on:\n";
    foreach ($survivors as $s) {
        printf("  %s:%d  (#%d)  %s\n", $s['target'], $s['line'], $s['num'], $s['what']);
    }
    echo "\nEach one is a check to write or a reason to write down. It is not a line to\n";
    echo "delete: three of #49's survivors were load-bearing (§4am).\n";
    exit(1);
}

echo "\nNothing survived.\n";
exit(0);
