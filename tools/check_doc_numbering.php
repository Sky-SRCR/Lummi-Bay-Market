<?php
// ============================================================
// CONSISTENCY CHECK — the numbering the documents run on
// ============================================================
//   php tools/check_doc_numbering.php
//
// BUILD-REFERENCE.md numbers its write-ups §4a, §4b, … and its invariants 1, 2, …
// reviewed-decisions.md points at those letters from its "Written up" column, and
// CLAUDE.md and the ADRs cite them in prose. Nothing checked any of it.
//
// The way that breaks is specific, and it is not carelessness. Several branches
// cut from the same base each take the next free letter — four of them wanted
// §4u at once — and git merges two `### 4u.` headings with no conflict marker,
// because they are not the same lines in the same place. Nothing is overwritten
// and nothing is reported. What lands is a document with two §4u sections in it
// and a decision table pointing at both, from a merge that said it succeeded.
//
// A conflict a person has to notice is a conflict that gets merged. So:
//
//   · every section letter appears exactly once
//   · every invariant number appears exactly once, in an unbroken run from 1
//   · every §-reference anywhere in the docs resolves to exactly one section
//
// The third is what catches the *aftermath* rather than the collision: renumber a
// section to settle a clash and every citation of the old letter is now pointing
// at nothing, which is the silent half of doing the right thing.
//
// Exits 1 on any failure. CLI only; reads files and nothing else.

$root = dirname(__DIR__);
$ref  = $root . '/docs/BUILD-REFERENCE.md';

$checks = 0;
$fails  = array();

function check($ok, $label)
{
    global $checks, $fails;
    $checks++;
    if ($ok) {
        echo "  ok   " . $label . "\n";
    } else {
        $fails[] = $label;
        echo "  FAIL " . $label . "\n";
    }
}

function section($title) { echo "\n" . $title . "\n"; }

if (!is_file($ref)) {
    echo "cannot read " . $ref . "\n";
    exit(1);
}
$text  = file_get_contents($ref);
$lines = explode("\n", $text);

// ── The section letters ──────────────────────────────────────

section('Every write-up has a number of its own');

// `## 4b. Some title` or `### 4u. Some title` — the phase number and the letter
// within it. Both depths count: the file settled into `###` at §4h and the seven
// before it are `##`, and a check that knew about only one of those would have
// reported five real citations as dangling.
//
// One letter *or two*: phase 4 ran past z, so §4aa follows §4z. A single-letter
// pattern did not fail on those, which is worse than failing — it did not see them
// at all, so nine write-ups were unchecked for duplication, every citation of them
// was unchecked for dangling, and the advice below offered `§4{`. The one job this
// file has is to be right about the next free letter.
/**
 * The letter after this one: z → aa, az → ba, zz → aaa.
 *
 * `chr(ord($last) + 1)` was right until §4z and then answered `§4{`, which is the
 * character after z in ASCII and not a section anybody would write. Spelled out as
 * a carry so the answer stays a letter however far phase 4 runs.
 */
function nextLetter($letters)
{
    $chars = array_reverse(str_split($letters));
    $carry = true;
    foreach ($chars as $i => $c) {
        if (!$carry) { break; }
        if ($c === 'z') { $chars[$i] = 'a'; }
        else            { $chars[$i] = chr(ord($c) + 1); $carry = false; }
    }
    if ($carry) { $chars[] = 'a'; }
    return implode('', array_reverse($chars));
}

$sections = array();          // "4u" => how many times it appears
foreach ($lines as $line) {
    if (preg_match('/^#{2,4}\s+(\d+)([a-z]{1,2})\.\s/', $line, $m)) {
        $key = $m[1] . $m[2];
        $sections[$key] = isset($sections[$key]) ? $sections[$key] + 1 : 1;
    }
}

check(count($sections) > 0, 'the file still has numbered write-ups to check');

$dupes = array();
foreach ($sections as $key => $count) {
    if ($count > 1) { $dupes[] = '§' . $key . ' appears ' . $count . ' times'; }
}
check(empty($dupes),
      'no two write-ups claim the same number'
      . (empty($dupes) ? '' : ' — ' . implode('; ', $dupes)));

// ── The invariants ───────────────────────────────────────────

section('Every invariant has a number of its own');

// The numbered list under "## 2. Invariants", up to the next top-level heading.
$inInvariants = false;
$invariants   = array();
foreach ($lines as $line) {
    if (preg_match('/^##\s+\d+\.\s/', $line)) {
        $inInvariants = (stripos($line, 'invariant') !== false);
        continue;
    }
    if ($inInvariants && preg_match('/^(\d+)\.\s+\*\*/', $line, $m)) {
        $invariants[] = (int) $m[1];
    }
}

check(count($invariants) > 0, 'the invariants list was found and is not empty');

$seen  = array();
$twice = array();
foreach ($invariants as $n) {
    if (isset($seen[$n])) { $twice[$n] = true; }
    $seen[$n] = true;
}
check(empty($twice),
      'no two invariants claim the same number'
      . (empty($twice) ? '' : ' — ' . implode(', ', array_keys($twice))));

// A gap means a number was renumbered or dropped, which is the other way a merge
// of two branches that both extended this list goes wrong.
$expected = range(1, count($invariants));
$actual   = $invariants;
sort($actual);
check($actual === $expected,
      'and they run unbroken from 1 to ' . count($invariants)
      . ($actual === $expected ? '' : ' — got ' . implode(',', $actual)));

// ── The references to both ───────────────────────────────────

section('Every reference points at something that exists');

// Anything citing a section: `§4j`, `see §4o`, `(§4n)`. Checked across the docs
// that do the citing, not only the file being cited.
$citers = array_filter(array_merge(
    glob($root . '/docs/*.md'),
    glob($root . '/docs/adr/*.md'),
    array($root . '/CLAUDE.md', $root . '/README.md', $root . '/HANDOFF.md')
), 'is_file');

$dangling = array();
foreach ($citers as $file) {
    $body = file_get_contents($file);
    if (!preg_match_all('/§(\d+[a-z]{1,2})\b/', $body, $m)) { continue; }
    foreach (array_unique($m[1]) as $cited) {
        if (!isset($sections[$cited])) {
            $dangling[] = '§' . $cited . ' in ' . basename($file);
        } elseif ($sections[$cited] > 1) {
            $dangling[] = '§' . $cited . ' in ' . basename($file) . ' is ambiguous';
        }
    }
}
check(empty($dangling),
      'no citation points at a missing or duplicated write-up'
      . (empty($dangling) ? '' : ' — ' . implode('; ', $dangling)));

// ── ─────────────────────────────────────────────────────────

// Anchored, same as `check_invariants.php` and for the same reason (§4bi): every check
// in this file is a whole *class* of numbering fault, so one going missing is a class
// nobody is watching any more — and the run would still print a clean line and exit 0.
$expectedChecks = 6;
if ($checks !== $expectedChecks) {
    $fails[] = 'this checker ran every check it is supposed to — expected '
             . $expectedChecks . ', ran ' . $checks;
}

echo "\n" . $checks . " checks, " . count($fails) . " failed\n";
foreach ($fails as $f) { echo "  FAILED: " . $f . "\n"; }

// Say what the next free letter is when everything passes. The collision this
// file exists for happened because four branches each had to guess it.
if (empty($fails)) {
    $phase4 = array();
    foreach (array_keys($sections) as $key) {
        if (substr($key, 0, 1) === '4') { $phase4[] = substr($key, 1); }
    }
    sort($phase4);
    if (!empty($phase4)) {
        // Order by length first, so 'z' sorts before 'aa' rather than after it.
        usort($phase4, function ($a, $b) {
            return strlen($a) === strlen($b) ? strcmp($a, $b) : strlen($a) - strlen($b);
        });
        $last = end($phase4);
        echo "\nphase 4 runs to §4" . $last . "; the next free letter is §4"
             . nextLetter($last) . "\n";
    }
}

exit(empty($fails) ? 0 : 1);
