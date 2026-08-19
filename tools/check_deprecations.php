<?php
/**
 * Compile every PHP file in the tree and fail on anything the engine says about it.
 *
 * The gate `php -l` cannot be. A deprecation is emitted when a file is **compiled**,
 * not when it is parsed, and lint stops at the parse — so both spellings of an
 * implicitly nullable parameter lint clean on every version, while one of them writes a
 * line into the error log on every request that touches the file. Five of those were in
 * this tree, one of them in `ServerReport`, which `admin_panel.php` builds every time the
 * panel is opened.
 *
 * The self-tests do not close it either, and that is the part worth being explicit
 * about, because the workflow file claimed for four days that they did. They `require`
 * the files, so the notice **is** emitted — and emitting a notice is not failing a step.
 * The process exits 0. On a runner whose `error_reporting` excludes `E_DEPRECATED` the
 * line is not even printed, which is this container: 22527 against an `E_ALL` of 30719.
 * Reintroducing the exact parameter above left `php -l`, `check_invariants.php` and both
 * self-test steps green.
 *
 * `opcache_compile_file()` is what makes this possible at all: it compiles without
 * executing, so a page that would open a session, connect to the database or redirect
 * can be checked the same way as a `lib/` module. One child process per file, because a
 * second file declaring the same class as the first is a fatal in one process and
 * nothing at all in two.
 *
 * Its relationship to invariant 33 is worth keeping straight — they overlap on purpose
 * and neither replaces the other. Invariant 33 is a **pattern** in
 * `check_invariants.php`: it knows one shape, and it knows it on every version,
 * including the 8.2 the shop runs, where the engine says nothing. This is the
 * **engine's own answer**, on whatever version is running it — so it reports what 8.5
 * deprecates without anybody teaching it, and reports nothing on 8.2 today.
 *
 * Not a silent pass when it cannot answer: without opcache there is no way to compile
 * without running, so it says so and exits 1 rather than sweeping nothing and printing
 * a green line.
 */

$root = dirname(__DIR__);

// Two ways this cannot answer at all, and they are told apart on purpose: a sweep that
// reports the wrong reason sends whoever reads it to fix the wrong thing. Some shared
// hosts disable `proc_open` and have opcache; this container has both.
if (!function_exists('proc_open')) {
    fwrite(STDERR, "FAIL proc_open is disabled here, and one child process per file is how\n"
                 . "     this works — a second file declaring the same class as the first is\n"
                 . "     a fatal in one process and nothing at all in two.\n");
    exit(1);
}

// Then ask a child, once, rather than asking this process. `function_exists()` here is
// the wrong process to ask: the children get their own `-d` flags, and it is their
// answer that decides whether the sweep below means anything.
if (childErrors($root . '/tools/check_deprecations.php') === null) {
    fwrite(STDERR, "FAIL opcache is not loaded, so nothing here can compile a file without\n"
                 . "     also running it. Install it, or run this where it is available —\n"
                 . "     an empty sweep would print the same green line as a clean tree.\n");
    exit(1);
}

$flagged = 0;
$swept   = 0;
foreach (phpFiles($root) as $rel) {
    $swept++;
    $errors = childErrors($root . '/' . $rel);
    if ($errors === null || $errors === '') { continue; }
    $flagged++;
    echo "  FAIL $rel\n";
    foreach (explode("\n", trim($errors)) as $line) { echo '       ' . trim($line) . "\n"; }
}

echo "\n$swept files compiled on PHP " . PHP_VERSION . ", $flagged with something to say\n";
if ($flagged) {
    echo "  An implicitly nullable parameter is written `?Type \$x = null` — understood\n";
    echo "  back to 7.1, so it costs nothing below the floor. Anything else here is the\n";
    echo "  engine telling you what it will stop supporting; fix it or write down why not.\n";
    exit(1);
}
exit(0);

/**
 * Everything the engine says while compiling one file, or null if it could not compile
 * without running it.
 *
 * `E_ALL` and `display_errors` are set on the child rather than assumed: the whole point
 * is that the default mask on this container hides the class this exists to find.
 */
function childErrors($path)
{
    $php  = PHP_BINARY;
    $args = [
        $php,
        '-d', 'opcache.enable_cli=1',
        '-d', 'error_reporting=' . E_ALL,
        '-d', 'display_errors=stderr',
        '-d', 'log_errors=0',
        '-r', 'if (!function_exists("opcache_compile_file")) { exit(3); } '
            . 'opcache_compile_file($argv[1]);',
        $path,
    ];

    $pipes = [];
    $proc  = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    // Not `null`: that answer is reserved for "the child has no opcache", and the caller
    // prints a different sentence for each. A spawn that fails after the probe succeeded
    // is a machine problem, not a missing extension.
    if (!is_resource($proc)) {
        fwrite(STDERR, "FAIL could not start a child process for $path\n");
        exit(1);
    }
    stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code === 3) { return null; }
    return $stderr;
}

/** Every .php file under $root, vendor and .git excluded, in a stable order. */
function phpFiles($root)
{
    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
        $rel = str_replace($root . '/', '', $file->getPathname());
        if (strpos($rel, 'vendor/') === 0 || strpos($rel, '.git/') === 0) { continue; }
        $found[] = $rel;
    }
    sort($found);
    return $found;
}
