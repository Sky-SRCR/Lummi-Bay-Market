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
// Pure, and takes the directory rather than reading `__DIR__`, for the reason
// `SiteChrome::pick()` takes the stored value and `ServerReport::phpVersionNote()` takes
// the version id (§4o): the interesting cases are all directories this machine is
// not sitting in, so a rule that could only be exercised against the real one could
// only ever be tested with the one answer it happens to give.

class InstallPaths
{
    /** Only these characters may reach a filename this builds. */
    const SAFE_FOLDER = '/^[A-Za-z0-9._-]+$/';

    /**
     * The credentials files to try, in order, for an app installed at `$appDir`.
     *
     * Always at least one entry — the shared file — so a deployment that has never
     * heard of this feature is unaffected.
     *
     * @param string $appDir the directory holding db_connect.php (`__DIR__` of it)
     * @return array absolute paths, most specific first
     */
    public static function credentialsCandidates($appDir)
    {
        $private = dirname($appDir, 2) . '/private';
        $shared  = $private . '/db_credentials.php';

        $folder = self::installName($appDir);
        if ($folder === '') { return [$shared]; }

        return [$private . '/db_credentials_' . $folder . '.php', $shared];
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
