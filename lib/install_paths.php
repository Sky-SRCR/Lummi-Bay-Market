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
// The live install needs no `db_credentials_lbm.php`: absent the specific file, this
// answers the shared one, which is what every deployment before this change already
// used.
//
// ── And then: whose is the shared file? ──────────────────────────────────────
//
// Everything above answers *which file*, and for a long time that was taken to be the
// whole question. It is not. Falling through to the shared file is a **guess**, and the
// guess is only harmless while every install on the account belongs to the same person.
// Between `lbm/` and `lbm-test/` that is true, and the cost of a wrong guess is a
// rehearsal copy publishing over the store's own sign — bad, documented
// (docs/DEPLOY-SKIP.md §E), and caught by a check a human runs. Put a third install on
// the account that is somebody *else's* and the same guess reaches somebody else's
// database, and a check a human has to remember to run stops being a control at all.
//
// So the shared file says who it is for, in one line, in a file that is not in this
// repo and cannot be reached by a browser:
//
//     define('CREDENTIALS_FOR', 'lbm');
//
// An install that is not that one gets a refusal naming the file to fix and the line to
// add, rather than a connection to a database it was never given. Refusing costs a page
// somebody is looking at; guessing costs a sign, and there is no undo anywhere in this
// app — so between the two this refuses, which is the same trade `Color::read()` makes
// and the same one `Markup` makes.
//
// **Undeclared is refused, not waved through**, and that is the whole of the change: a
// rule that only engages once somebody remembers to configure it protects exactly the
// installs whose owner did not need protecting. What keeps that from being a window in
// which the live sign is dark is the *order of the deploy* rather than a softer rule —
// the `CREDENTIALS_FOR` line is an unused constant to every version of this app that
// came before it, so it goes on the server first and the tree goes up afterwards.
//
// The claim is compared to the folder name and nothing else. Not to `DB_NAME` — an
// install pointed at the wrong database by a typo inside its *own* file is a different
// mistake with a different fix, and a rule that conflated them would refuse the person
// who did everything right. Not case-insensitively, either: these are directory names
// on Linux, where `LBM` and `lbm` are two folders.
//
// Pure, and takes the directory rather than reading `__DIR__`, for the reason
// `Brand::pick()` takes the stored value and `ServerReport::phpVersionNote()` takes
// the version id (§4o): the interesting cases are all directories this machine is
// not sitting in, so a rule that could only be exercised against the real one could
// only ever be tested with the one answer it happens to give.

class InstallPaths
{
    /** Only these characters may reach a filename this builds. */
    const SAFE_FOLDER = '/^[A-Za-z0-9._-]+$/';

    /**
     * The constant the shared credentials file uses to name the install it is for.
     *
     * Spelled once, here, and reached through `InstallPaths::CLAIM` everywhere else —
     * `tools/check_invariants.php` holds the tree to that. A second spelling is not a
     * duplicate string, it is a second answer to "what is the line called", and the
     * failure it produces is an install refusing for a line the admin has already added.
     */
    const CLAIM = 'CREDENTIALS_FOR';

    /**
     * What a person in front of a browser is told when this refuses.
     *
     * Deliberately path-free. `ErrorPolicy::fail()` never shows its `$detail` to a
     * visitor, and this is why: the detail names a directory outside the webroot, and
     * the page that would print it is `login.php`, which anybody can reach. The paths
     * and the exact line to add go to the error log and to the admin alert, which are
     * the two channels that already have a reader with a right to them.
     */
    const REFUSAL_SENTENCE =
        'This installation has not been told which database belongs to it, so it has '
      . 'refused to connect rather than guess at one. Nothing is broken and nothing is '
      . 'lost. The error log names the file to fix and the line to add.';

    /**
     * The credentials files to try, in order, for an app installed at `$appDir`.
     *
     * Always at least one entry — the shared file — so this answers "which file" for
     * every install, including one that has none of its own. Whether that install may
     * *use* the shared one is a separate question with a separate answer,
     * `sharedClaimRefusal()`, and separating them is deliberate: resolution is a fact
     * about the disk and the claim is a fact about the file's contents, and a function
     * that mixed them could not be asked either one on its own.
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
     * The one file every install on the account can reach, named without asking which
     * install is asking. Always the last candidate, and never the first.
     *
     * A method rather than a second `dirname()` at the two call sites: `db_connect.php`
     * has to recognise the file it just loaded and `ServerReport` has to say which one
     * it was, and a path built twice is a path that can be built two ways.
     */
    public static function sharedCredentialsFile($appDir)
    {
        return dirname($appDir, 2) . '/private/db_credentials.php';
    }

    /**
     * Whether an install may use the shared credentials file: '' if it may, and the
     * sentence saying why not if it may not.
     *
     * `$claim` is whatever the file declared — the value of `CREDENTIALS_FOR`, or null
     * when it declared nothing. Passed in rather than read here, because a constant is
     * process-wide and cannot be un-defined: a rule that read it directly could be
     * exercised with exactly one value per test run, which is one more than none and
     * five fewer than this needs.
     *
     * Every answer but the empty string is a refusal, including the shapes nobody meant
     * to write — `true`, `0`, an array left over from a copy-paste. A claim that is not
     * a name does not name this install.
     *
     * The sentence is the whole deliverable. It goes to the log and to an admin's inbox
     * at the moment a deploy has gone wrong, so it names the file, the line and the
     * alternative, and it is written for somebody who has never read this class.
     */
    public static function sharedClaimRefusal($appDir, $claim)
    {
        $shared = self::sharedCredentialsFile($appDir);
        $folder = self::installName($appDir);

        // No usable name means no claim could ever match and no per-install file could
        // ever be built for it — so this is first, and it asks for a rename rather than
        // for a line that would have nothing to match.
        if ($folder === '') {
            return 'This install cannot say what it is called: it runs from a folder ('
                 . basename(rtrim((string)$appDir, '/\\'))
                 . ') whose name is not usable as one, so it cannot be matched against '
                 . 'the ' . self::CLAIM . ' line in ' . $shared . '. Rename the folder '
                 . 'using letters, digits, dot, dash or underscore.';
        }

        $candidates = self::credentialsCandidates($appDir);
        $own        = $candidates[0];

        if (!is_string($claim) || $claim === '') {
            return 'The credentials in ' . $shared . ' do not say which install they '
                 . 'belong to, so this install (' . $folder . ') will not use them. '
                 . 'Every copy of this app on this account reaches that same file, and '
                 . 'using it without being named in it is how a second install publishes '
                 . 'over the first one\'s signs. Either add   define(\'' . self::CLAIM
                 . '\', \'' . $folder . '\');   to that file, if that database really is '
                 . 'this install\'s, or create ' . $own . ' holding this install\'s own.';
        }

        if ($claim !== $folder) {
            return 'The credentials in ' . $shared . ' belong to the install named '
                 . $claim . ', and this one is ' . $folder . '. Create ' . $own
                 . ' holding this install\'s own database — or, if that database really '
                 . 'is this install\'s, change ' . self::CLAIM . ' in that file to '
                 . $folder . '.';
        }

        return '';
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
