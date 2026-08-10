<?php
// ============================================================
// SERVER REPORT — what is this machine, and did the schema converge?
// ============================================================
// This project spent its whole life written to a PHP version nobody had checked. The
// rule in CLAUDE.md read "PHP 7.1-compatible syntax, the live server's version is
// unverified", it shaped every file in the repo, and the one real violation it ever
// caught was not a syntax feature at all but a library signature that changed in 7.3
// (auth.php's session cookie parameters). A rule that costs this much should not rest
// on a guess, and there is no shell on the live host: the only place the answer could
// appear from inside the app is a page an admin can open. This one.
//
// **It has not answered yet, and the answer came from elsewhere.** A branch recorded
// 8.2 here and raised the rule to match; that was withdrawn, because this card ships
// with the multi-display build and #46's probe found that build undeployed — `lib/`
// answers 404 live — so this screen cannot have been the thing that reported it, and
// Cloudflare hides the version from the response headers too (§4k). The store owner
// then stated it directly: **PHP 8.2, 2026-08-10**. That is a person, dated, not a
// reading this code took, which is why it is recorded that way.
//
// So this card's job changed. It is no longer where the answer will come from — it is
// what confirms that answer the moment the build is deployed, and what contradicts it
// if the host is upgraded, downgraded or moved to a different account. That is the
// only place such a change becomes visible: nothing else here observes the version,
// and the floor in `ASSUMED_PHP` is now load-bearing rather than cautious.
//
// The second half is the same problem wearing different clothes. Invariant 10 says
// the live database is behind the repo and every schema change is an idempotent
// statement — which is safe, but silent. `schemaTry()` swallows the failure of a
// statement that could not apply by design, so a column that never landed is
// invisible until something that needs it misbehaves. The lockout columns were in
// exactly that state for months. So this reports, per column, whether it is
// actually there.
//
// Deliberately narrow: it reads the database *catalogue* and PHP's own
// configuration, and it does not read a single row of application data. That is
// why it can name `users`, `displays` and `canvas_elements` without being the
// second writer the module rules forbid — it asks what columns exist, never the
// table what it contains.
//
// It does not ask `information_schema` itself. `lib/schema.php` owns that read,
// because convergence has to ask the same question before it alters anything and
// two files with their own catalogue query could disagree about what "the column
// is there" means. This one asks `readSchemaFacts()` and falls back to reading a
// table's shape only when the catalogue cannot be read at all — which is the
// self-test's SQLite fixture, and any host that hides the catalogue.
//
// Nothing here decides anything. It reports, the admin panel renders, and no code
// path branches on the answers.

require_once __DIR__ . '/upload_limits.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/request_scheme.php';

class ServerReport
{
    /**
     * The PHP this code is written to run on. Not a check that the server is modern —
     * a check that the rule the repo obeys can still be obeyed on the machine it is
     * obeyed for.
     *
     * A declared floor, resting on the owner's statement of 8.2 (§4k), not on
     * anything this code has measured. Which is why the note below exists: with a
     * floor declared, being wrong is a parse error rather than merely wasteful
     * syntax, and a parse error in a file a Screen loads is a blank sign in a shop.
     * Lowering it again is one line for as long as no file uses a later construct.
     */
    const ASSUMED_PHP = '8.2';

    private $pdo;
    private $facts = null;

    /**
     * The request being reported on. Taken as a parameter, defaulting to the real
     * one, because the session cookie's `Secure` flag is now a property of the
     * request rather than of the machine — and a report that can only describe the
     * request it happens to be running inside cannot be checked against the other
     * one.
     */
    private $server;

    public function __construct(PDO $pdo, array $server = null)
    {
        $this->pdo    = $pdo;
        $this->server = ($server === null) ? $_SERVER : $server;
    }

    /**
     * Runtime facts, as label => [value, note]. `note` is empty when there is
     * nothing to say and carries the consequence when there is.
     */
    public function runtime()
    {
        $out = [];

        $out['PHP version'] = [PHP_VERSION, self::phpVersionNote(PHP_VERSION_ID)];

        $out['MySQL version'] = [$this->mysqlVersion(), ''];

        // The store is in Washington; a server left on UTC prints every "editing
        // since" time seven or eight hours out. The lock's own arithmetic is UTC
        // throughout and is not affected — this is about what a person reads.
        $tz = date_default_timezone_get();
        $out['Server time zone'] = [$tz . ' — ' . date('D j M Y, g:ia'),
            ini_get('date.timezone') === ''
                ? 'Not set in the server configuration, so PHP is falling back to '
                  . 'a default. Times shown next to an edit lock may be hours out.'
                : ''];

        // What happens when something goes wrong is no longer a property of the
        // server: lib/error_policy.php sets it in code on every request, and
        // reports itself through ErrorPolicy::status(). Reading the same two ini
        // flags here as well would be a second opinion about a setting this module
        // does not own — and one that could only ever agree with itself.

        // These three are what stops a stolen or cross-site request riding the
        // session. auth.php sets them; this says whether they took.
        $cookie = session_get_cookie_params();
        $out['Session cookie'] = [
            'HttpOnly ' . $this->yesNo(!empty($cookie['httponly']))
            . ', Secure ' . $this->yesNo(!empty($cookie['secure']))
            . ', SameSite ' . (isset($cookie['samesite']) && $cookie['samesite'] !== ''
                                 ? $cookie['samesite']
                                 : $this->sameSiteFromPath($cookie)),
            $this->cookieNote($cookie)];

        // The effective number, not the three ini values it comes from: the
        // arithmetic belongs to lib/upload_limits.php, which is also what the
        // Builder enforces in the file picker and what every refusal message
        // quotes. Printing the raw settings here as well invited reading the
        // largest of them as the answer, when the smallest is.
        $effective = UploadLimit::bytes();
        $out['Largest file that can be uploaded'] = [
            UploadLimit::describe(),
            $effective < UploadLimit::APP_MAX_BYTES
                ? 'This server, not the app, is the limit (upload_max_filesize '
                  . ini_get('upload_max_filesize') . ', post_max_size '
                  . ini_get('post_max_size') . '). A video for a sign may not fit.'
                : ''];

        return $out;
    }

    /**
     * What this server's PHP version means, given the rule the repo obeys.
     *
     * ASSUMED_PHP is the floor the repo is written to, so a server at or above it has
     * nothing wrong with it and is told nothing. The two bands below it each fire on
     * something real, and they are separate because what to do about them differs:
     *
     * - Below the floor, syntax this repo is now allowed to use may not parse. That
     *   is a deploy-blocking fact and the reason the floor is reported at all — the
     *   host was stated to be 8.2 by a person (§4k), and this is what notices if that
     *   stops being true. Not moot just because a parse error would take the page
     *   with it: PHP parses per file, so a construct in a file this path never
     *   includes leaves this card rendering and able to say so, where the first file
     *   to break would otherwise be found by a blank sign in the shop.
     * - Below 7.3 the version stops being a rule and becomes behaviour.
     *   `session_set_cookie_params()`'s array form does not exist there, so
     *   `auth.php` branches to the string form; the note says which one is in use,
     *   because that is a branch an admin would otherwise have to read the source to
     *   know about. The Session cookie row reads the three flags back off the live
     *   cookie separately, which is the check that the branch worked.
     *
     * Deliberately says nothing on the expected case. A note an admin reads every
     * time is a note they learn to skip, and skipping it is how the two bands that
     * matter get missed. That is also what keeps this from being a check that cannot
     * fail (#50): it is silent on the common case on purpose, not for want of
     * anything to test.
     *
     * Takes the version id rather than reading PHP_VERSION_ID, for the reason
     * `UploadLimit::smallestOf()` takes its ini values: two of the three cases are
     * unreachable on whatever machine happens to run the test.
     */
    public static function phpVersionNote($versionId)
    {
        if ($versionId >= 80200) {
            return '';
        }
        if ($versionId >= 70300) {
            return 'Older than the ' . self::ASSUMED_PHP . ' this code is written for. '
                 . 'The sign-in cookie is still hardened by the modern call, but syntax '
                 . 'this repo is allowed to use may not parse here. Tell the developer '
                 . 'before the next deploy.';
        }
        return 'Far older than the ' . self::ASSUMED_PHP . ' this code is written for, '
             . 'and below 7.3, so the pre-7.3 session cookie form is the one in use. '
             . 'Check the Session cookie row below reports all three flags, and tell '
             . 'the developer before the next deploy.';
    }

    /**
     * Every column this app adds at runtime, and whether it is really there.
     *
     * The list is written out rather than derived, because the point is to state
     * what the code expects — a derived list would agree with the database by
     * construction and prove nothing.
     */
    public function convergence()
    {
        $expected = [
            ['users',           'failed_attempts', 'Login lockout cannot count failures.'],
            ['users',           'last_failed_at',  'Login lockout cannot age failures out.'],
            ['users',           'locked_until',    'Login lockout cannot hold anyone out.'],
            ['users',           'closed_at',       'No account can be closed. A departing employee can only be suspended, and their id can still come back into service.'],
            ['password_resets', 'attempts',        'A reset code gets unlimited guesses.'],
            ['assets',          'auto_pooled',     'The Library tidy-up falls back to the "Auto: " label prefix.'],
            ['displays',        'lock_taken_at',   'A read-only Builder cannot say since when.'],
            ['canvas_elements', 'display_id',      'Nothing is scoped to a Display. Do not publish.'],
        ];

        $out = [];
        foreach ($expected as $row) {
            $there = $this->columnExists($row[0], $row[1]);
            $out[] = [
                'table'  => $row[0],
                'column' => $row[1],
                'ok'     => $there,
                'note'   => $there ? '' : $row[2],
            ];
        }
        return $out;
    }

    /** True when every runtime-added column landed. */
    public function isConverged()
    {
        foreach ($this->convergence() as $row) {
            if (!$row['ok']) { return false; }
        }
        return true;
    }

    // ---- Internals ----------------------------------------------------------

    /** The catalogue, read once per report rather than once per column. */
    private function facts()
    {
        if ($this->facts === null) { $this->facts = readSchemaFacts($this->pdo); }
        return $this->facts;
    }

    /**
     * Ask the catalogue, not the table. It carries no application data, which is
     * what keeps this file outside the one-writer rule for `users`, `displays` and
     * `canvas_elements`.
     *
     * The catalogue is trusted only for a table it actually covers. A `false` for
     * a table the read never looked at would be a confident wrong answer — and
     * this report exists to be trusted — so anything the facts do not cover is
     * settled by reading the table's shape instead.
     */
    private function columnExists($table, $column)
    {
        $facts = $this->facts();
        if ($facts->hasTable($table) === true) {
            return $facts->hasColumn($table, $column) === true;
        }

        // No catalogue (not MySQL — the self-test's SQLite fixture, for one), or a
        // table outside the catalogue read. Fall back to reading the shape.
        try {
            $this->pdo->query("SELECT " . $column . " FROM " . $table . " LIMIT 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function mysqlVersion()
    {
        try {
            $v = $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
            return $v ? (string)$v : 'unknown';
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    private function yesNo($ok)
    {
        return $ok ? 'yes' : 'NO';
    }

    /**
     * What to say about the sign-in cookie, if anything.
     *
     * `Secure` off is not a fault by itself, and reporting it as one is how a
     * true row gets ignored: on a page reached over plain HTTP the flag is off
     * *because* setting it would throw the cookie away and turn sign-in into a
     * blank form nobody could get past (lib/request_scheme.php). The note that
     * belongs there is the one that says how to get the protection — reach the
     * site over https — rather than one that reads like a broken installation.
     */
    private function cookieNote(array $cookie)
    {
        if (empty($cookie['httponly'])) {
            return 'HttpOnly did not apply, so a script on the page could read the sign-in cookie.';
        }
        if (!RequestScheme::isSecure($this->server)) {
            return 'You opened this page over plain HTTP, so the cookie is deliberately not '
                 . 'marked Secure — marking it on a plain-HTTP request hands the browser a '
                 . 'cookie it throws away, and sign-in becomes a blank form with no error in '
                 . 'it. Reach the site over https:// and this row reads Secure yes.';
        }
        if (empty($cookie['secure'])) {
            return 'This page came over HTTPS and the cookie is not marked Secure, so it can '
                 . 'be sent again over a plain-HTTP link to this site.';
        }
        return '';
    }

    /**
     * Before 7.3 there was no samesite key: the attribute is appended to the path
     * instead (auth.php does exactly that), so that is where it has to be read
     * back from. Reporting "not set" for a server where it *is* set would send
     * somebody chasing a problem that does not exist.
     */
    private function sameSiteFromPath($cookie)
    {
        $path = isset($cookie['path']) ? (string)$cookie['path'] : '';
        if (preg_match('/SameSite\s*=\s*(\w+)/i', $path, $m)) { return $m[1]; }
        return 'not set';
    }
}
