<?php
// ============================================================
// SERVER REPORT — what is this machine, and did the schema converge?
// ============================================================
// This project has been written to a PHP version nobody ever checked. The rule in
// CLAUDE.md — "PHP 7.1-compatible syntax, the live server's version is unverified"
// — has shaped every file in the repo, and the one real violation it ever caught
// was not a syntax feature at all but a library signature that changed in 7.3
// (auth.php's session cookie parameters). A rule that costs this much should not
// rest on a guess, and there is no shell on the live host: the only place the
// answer can appear is a page an admin can open.
//
// The second half is the same problem wearing different clothes. Invariant 10 says
// the live database is behind the repo and every schema change is an idempotent
// statement — which is safe, but silent. `schemaTry()` swallows the failure of a
// statement that could not apply by design, so a column that never landed is
// invisible until something that needs it misbehaves. The lockout columns were in
// exactly that state for months. So this reports, per column, whether it is
// actually there.
//
// Deliberately narrow: it reads `information_schema` and PHP's own configuration,
// and it does not read a single row of application data. That is why it can name
// `users`, `displays` and `canvas_elements` without being the second writer the
// module rules forbid — it asks the catalogue what columns exist, never the table
// what it contains.
//
// Nothing here decides anything. It reports, the admin panel renders, and no code
// path branches on the answers.

class ServerReport
{
    /**
     * The oldest PHP this code is written to run on. Not a check that the server
     * is modern — a check that the rule the repo has been obeying still matches
     * the machine it was obeyed for.
     */
    const ASSUMED_PHP = '7.1';

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Runtime facts, as label => [value, note]. `note` is empty when there is
     * nothing to say and carries the consequence when there is.
     */
    public function runtime()
    {
        $out = [];

        $out['PHP version'] = [PHP_VERSION, PHP_VERSION_ID >= 70300
            ? 'Newer than the ' . self::ASSUMED_PHP . ' this code is written for, so the '
              . 'session cookie is hardened by the modern call. Worth telling the '
              . 'developer: the repo is still avoiding features this server has.'
            : 'At or below 7.2. The ' . self::ASSUMED_PHP . ' rule in the repo is right, '
              . 'and the pre-7.3 session cookie form is the one in use.'];

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

        // A public page printing a stack trace gives away the absolute path to the
        // webroot, which is the first thing anyone probing the site wants.
        $out['Errors shown to visitors'] = [$this->onOff('display_errors'),
            $this->flagOn('display_errors')
                ? 'On. A PHP error on the Viewer prints server paths onto the sign '
                  . 'and into anyone\'s browser. It should be off.'
                : ''];

        $log = ini_get('error_log');
        $out['Errors written to a log'] = [$this->onOff('log_errors') . ($log ? ' → ' . $log : ''),
            $this->flagOn('log_errors') ? '' : 'Off. Nothing that goes wrong on the '
                . 'server leaves any record at all.'];

        // These three are what stops a stolen or cross-site request riding the
        // session. auth.php sets them; this says whether they took.
        $cookie = session_get_cookie_params();
        $out['Session cookie'] = [
            'HttpOnly ' . $this->yesNo(!empty($cookie['httponly']))
            . ', Secure ' . $this->yesNo(!empty($cookie['secure']))
            . ', SameSite ' . (isset($cookie['samesite']) && $cookie['samesite'] !== ''
                                 ? $cookie['samesite']
                                 : $this->sameSiteFromPath($cookie)),
            (!empty($cookie['httponly']) && !empty($cookie['secure'])) ? ''
                : 'One of the protections on the sign-in cookie did not apply.'];

        $out['Largest file that can be uploaded'] = [
            ini_get('upload_max_filesize') . ' (whole request: ' . ini_get('post_max_size') . ')',
            ''];

        return $out;
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
            ['password_resets', 'attempts',        'A reset code gets unlimited guesses.'],
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

    /**
     * Ask the catalogue, not the table. `information_schema` carries no
     * application data, which is what keeps this file outside the one-writer rule
     * for `users`, `displays` and `canvas_elements`.
     */
    private function columnExists($table, $column)
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return intval($stmt->fetchColumn()) > 0;
        } catch (Throwable $e) {
            // No information_schema means this is not MySQL — the self-test's
            // SQLite fixture, for one. Fall back to reading the table's shape.
            try {
                $this->pdo->query("SELECT " . $column . " FROM " . $table . " LIMIT 0");
                return true;
            } catch (Throwable $e2) {
                return false;
            }
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

    private function flagOn($setting)
    {
        $v = strtolower(trim((string)ini_get($setting)));
        return ($v !== '' && $v !== '0' && $v !== 'off' && $v !== 'false');
    }

    private function onOff($setting)
    {
        return $this->flagOn($setting) ? 'On' : 'Off';
    }

    private function yesNo($ok)
    {
        return $ok ? 'yes' : 'NO';
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
