<?php
// ============================================================
// WHICH INSTALL IS THIS, AND WHOSE CREDENTIALS DOES IT USE?
// ============================================================
// One account on this host can hold more than one copy of this app. The live sign
// runs from `public_html/lbm/`; a rehearsal copy runs from `public_html/lbm-test/`
// against a duplicate of the database. Both are the same tree — the whole point of a
// rehearsal is that it is the code you are about to deploy, not a variant of it.
//
// That is exactly what made the old credentials lookup dangerous. It was
//
//     dirname(__DIR__, 2) . '/private/db_credentials.php'
//
// which walks up out of the webroot and reads one shared file. Two folders at the
// same depth walk up to the *same* place, so an unmodified copy in `lbm-test/`
// connected to the **live** database and said nothing about it. Everything then
// behaves: signing in converges schema on the live tables (an `ALTER` on the table
// every sign's layout lives in, while the Screens are polling it), and pressing
// Publish overwrites a real sign. There is no undo anywhere in this app, so the
// first sign that a "test" install was never isolated is a customer reading the
// wrong prices.
//
// The fix is to let the folder name decide, and to look for the specific file
// *before* the shared one:
//
//     /home/ACCOUNT/private/db_credentials_lbm-test.php     <- if it exists
//     /home/ACCOUNT/private/db_credentials.php              <- otherwise
//
// Three things that matters for:
//
// - **No tracked file diverges.** The rehearsal copy is byte-for-byte the tree that
//   will go live. A `db_connect.php` edited by hand in one folder is a difference
//   that survives the next upload only if somebody remembers it, and reverts
//   silently when they do not — and the thing it would revert to is "point the test
//   folder at the live database".
// - **Credentials stay outside the webroot.** The alternative — a local override
//   file beside the app — puts a password inside a directory the web server serves.
//   PHP normally executes it and emits nothing, so it reads as safe, right up until
//   a configuration change stops PHP running and Apache hands the file over as text.
// - **Adding an install is adding one file**, in a directory that is not in the repo
//   and cannot be reached by a browser. Nothing in the tree changes at all.
//
// The live install needs no `db_credentials_lbm.php`, and creating one is optional
// there for ever: absent the specific file, this answers the shared one, which is
// what every deployment before this change already used.
//
// Two later corrections, both of them the same mistake in different coordinates —
// a fact about one deployment written down as a rule about all of them.
//
// **The depth was assumed.** `dirname($appDir, 2) . '/private'` is not a rule, it is
// a guess that the app sits two levels below the account: true of `public_html/lbm/`
// and false of anywhere else a web server can be pointed. A cPanel subdomain served
// from `~/lbm.srcresort.com/` is one level down, so it resolved to `/home1/private`
// — a directory *above* the account, which nobody but the host can create. The app
// found no credentials, fell through to the placeholders in `db_connect.php`, and
// could not connect at all; `ErrorPolicy::stateDir()` counted the same two levels
// and put the error log inside the webroot instead. Neither of those is a wrong
// answer about *which* credentials to use, which is what this file was written to
// get right — they are the file not being able to look in the right place. So the
// depth is searched now, nearest ancestor first, and the live install's answer is
// the one it has always had.
//
// **And the name was not unique.** The install is named by its own folder, and one
// account can hold two folders with one name: `public_html/lbm/` and
// `lbm.srcresort.com/lbm/` are two installs both called `lbm`. A
// `db_credentials_lbm.php` written to isolate the second would have been picked up
// by the first as well — the live sign quietly changing database, in the act of
// isolating something else. That is this file's own defect, one level up from where
// it was fixed. So the parent folder qualifies the name and is tried first:
// `db_credentials_lbm.srcresort.com-lbm.php` before `db_credentials_lbm.php`.
//
// Pure, and takes the directory rather than reading `__DIR__`, for the reason
// `SiteChrome::pick()` takes the stored value and `ServerReport::phpVersionNote()` takes
// the version id (§4o): the interesting cases are all directories this machine is
// not sitting in, so a rule that could only be exercised against the real one could
// only ever be tested with the one answer it happens to give.
//
// **Three classes of mutant this file's checks cannot kill** (invariant 30), written
// down rather than left as a gap in a `--swept` line:
//
// - **`===` to `==`, everywhere it appears here.** Both sides are always strings — out
//   of `dirname()`, `basename()` or a literal — and PHP compares two strings the same
//   way either way. There is no argument that separates them.
// - **Removing an `$out = []`.** Appending to an unset variable creates the array, and
//   every path through both builders adds at least one entry, so nothing ever reads it
//   unset.
// - **The `$dir === ''` guard in `privateDirCandidates()`.** Unobservable *today*:
//   `dirname('')` is `''`, which is its own parent, so an empty argument already stops
//   at the root. It stays because `dirname('.')` is `'.'` — if an empty path ever
//   normalised that way instead, that guard is what keeps this from answering
//   `./private` and walking a relative path.
//
// The two that *were* worth a check are gone: `PRIVATE_LEVELS` could be raised, and the
// loop condition could be `<=`, with nothing failing, because the bound was only ever
// stated by paths that ran out before reaching it.

class InstallPaths
{
    /** Only these characters may reach a filename this builds. */
    const SAFE_FOLDER = '/^[A-Za-z0-9._-]+$/';

    /** How far above the app to look for the private directory. */
    const PRIVATE_LEVELS = 3;

    /**
     * The private directories to look in, for an app installed at `$appDir` —
     * nearest ancestor first.
     *
     * Searched rather than counted, for the reason in the header: two levels up is
     * where `public_html/lbm/`'s private directory is, and a statement about one
     * install's layout. The live install's answer does not change — it looks in
     * `public_html/private` first, which is not there, and then in `~/private`,
     * which is.
     *
     * **`$appDir` itself is never a candidate.** A `private/` beside the app is
     * inside whatever the web server is serving, and the header's second bullet is
     * why that is the one place credentials must not be. It reads as safe because
     * PHP executes the file and emits nothing — right up until a configuration
     * change stops PHP running and Apache hands it over as text.
     *
     * The walk stops after three levels because above an account is the host's, and
     * a file there is not one this account could have put there. It is a bound on a
     * pointless search rather than a protection: the search ends at the first file
     * that exists, so nothing above the one an admin created is ever consulted.
     *
     * Pure, like everything else here, and for the same reason — the interesting
     * layouts are all ones this machine is not sitting in.
     *
     * @param string $appDir the directory holding db_connect.php (`__DIR__` of it)
     * @return array absolute paths, nearest first
     */
    public static function privateDirCandidates($appDir)
    {
        $dir = rtrim((string)$appDir, '/\\');
        if ($dir === '') { $dir = '/'; }

        $out = [];
        for ($i = 0; $i < self::PRIVATE_LEVELS; $i++) {
            $parent = dirname($dir);
            if ($parent === $dir) {
                // The filesystem root, which is its own parent. Worth one candidate
                // if that is somehow where the app is, and nothing beyond it.
                if ($i === 0) { $out[] = '/private'; }
                break;
            }
            $out[] = ($parent === '/' ? '' : $parent) . '/private';
            $dir = $parent;
        }
        return $out;
    }

    /**
     * The names this install may go by, most specific first.
     *
     * Two of them, because a folder name is not unique on an account — see the
     * header. The parent folder qualifies the bare one and is tried before it, so an
     * admin who needs two same-named installs kept apart has a filename that can only
     * mean one of them, and an admin who does not is unaffected: the qualified file is
     * one nobody has to create.
     *
     * **Not the hostname**, which is what a person naturally reaches for and would be
     * wrong twice over. A request's `Host` header is chosen by whoever is making the
     * request, so it is not a fact about the install at all; and it names a domain,
     * while the thing being told apart is a directory. Two subdomains can serve one
     * folder and one subdomain can serve several. The folder is what the web server
     * decided, which is the same reason `installName()` refuses to build a path out
     * of anything else.
     *
     * @param string $appDir the directory holding db_connect.php (`__DIR__` of it)
     * @return array zero, one or two names, most specific first
     */
    public static function installNames($appDir)
    {
        $folder = self::installName($appDir);
        if ($folder === '') { return []; }

        $parent = self::installName(dirname(rtrim((string)$appDir, '/\\')));
        if ($parent === '') { return [$folder]; }

        return [$parent . '-' . $folder, $folder];
    }

    /**
     * The credentials files to try, in order, for an app installed at `$appDir`.
     *
     * Always at least one entry — the shared file, in the nearest private directory —
     * so a deployment that has never heard of any of this is unaffected.
     *
     * **Every specific name comes before any shared file**, across all the
     * directories, and that order is the safety property rather than a tidy way to
     * build a list. Sorted the other way — nearest directory first, specific before
     * shared within each — a shared `db_credentials.php` one directory closer would
     * beat the `db_credentials_lbm-test.php` written to keep the rehearsal copy off
     * the live database. The rehearsal would then behave perfectly while publishing
     * to a real sign, which is the failure at the top of this file, reintroduced by
     * the fix for a different one.
     *
     * @param string $appDir the directory holding db_connect.php (`__DIR__` of it)
     * @return array absolute paths, most specific first
     */
    public static function credentialsCandidates($appDir)
    {
        $dirs = self::privateDirCandidates($appDir);

        $out = [];
        foreach (self::installNames($appDir) as $name) {
            foreach ($dirs as $dir) { $out[] = $dir . '/db_credentials_' . $name . '.php'; }
        }
        foreach ($dirs as $dir) { $out[] = $dir . '/db_credentials.php'; }
        return $out;
    }

    /**
     * The name this install goes by: its own folder, or '' when that cannot be used.
     *
     * A folder name is not user input — the web server decides where the app lives —
     * so this is not sanitising a request. It is refusing to *build a path* out of
     * anything surprising, which is the same discipline `Color::read()` applies to a
     * value that is about to become CSS: a rule that answers "no" for a shape it did
     * not expect cannot be talked into answering something worse. `.` and `..` are
     * refused by name, because both match the character class and neither is a name.
     */
    public static function installName($appDir)
    {
        $folder = basename(rtrim((string)$appDir, '/\\'));
        if ($folder === '' || $folder === '.' || $folder === '..') { return ''; }
        if (!preg_match(self::SAFE_FOLDER, $folder)) { return ''; }
        return $folder;
    }

    /**
     * The first candidate that is really there, or '' if none is.
     *
     * '' rather than the shared path, so the caller can tell "found nothing" from
     * "found the shared one" — `db_connect.php` prints a different, actionable
     * sentence for each and neither is a stack trace on a sign.
     */
    public static function credentialsFile($appDir)
    {
        foreach (self::credentialsCandidates($appDir) as $path) {
            if (is_file($path)) { return $path; }
        }
        return '';
    }
}
