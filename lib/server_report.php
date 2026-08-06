<?php
// ============================================================
// SERVER REPORT — what is this machine, and did the schema converge?
// ============================================================
// This project was written for years to a PHP version nobody ever checked. The old
// rule in CLAUDE.md — "PHP 7.1-compatible syntax, the live server's version is
// unverified" — shaped every file in the repo, and the one real violation it ever
// caught was not a syntax feature at all but a library signature that changed in 7.3
// (auth.php's session cookie parameters).
//
// The rule has since been replaced by a declared floor of 8.2, which was a decision
// rather than a measurement: the host is still unverified and there is no shell on
// it. So this screen matters more now, not less. It is the only place the real
// version can appear, and the only place that reads the session cookie flags back
// off the live cookie — which is the thing that breaks, silently, if the floor
// turns out to be wrong.
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

class ServerReport
{
    /**
     * The oldest PHP this code is written to run on — the declared floor, not a
     * measurement of the live host. Below it the repo's syntax may not parse and
     * auth.php's session cookie call sets nothing at all, so this is the number
     * the report holds the machine against.
     */
    const ASSUMED_PHP = '8.2';
    const ASSUMED_PHP_ID = 80200;

    private $pdo;
    private $facts = null;

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

        // The note carries a consequence or it is empty. Meeting the floor is the
        // expected case and needs no sentence; falling below it is the one reading
        // on this screen that says the repo's own premise is wrong.
        $out['PHP version'] = [PHP_VERSION, PHP_VERSION_ID >= self::ASSUMED_PHP_ID
            ? ''
            : 'OLDER than the ' . self::ASSUMED_PHP . ' this code declares as its '
              . 'minimum. Two consequences, in this order: the session cookie call in '
              . 'auth.php sets nothing at all below 7.3, so read the Session cookie row '
              . 'below before trusting sign-in; and the repo is now allowed to use '
              . 'syntax this server cannot parse, which is a blank page rather than a '
              . 'wrong answer. Tell the developer this number.'];

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
            (!empty($cookie['httponly']) && !empty($cookie['secure'])) ? ''
                : 'One of the protections on the sign-in cookie did not apply.'];

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
     * Before 7.3 there was no samesite key: the attribute was appended to the path
     * instead, so that is where it would have to be read back from.
     *
     * `auth.php` no longer writes it that way — the 8.2 floor means the options
     * array is the only form it uses. This is kept because it is now a *diagnostic
     * for a violated floor* rather than a reader for a supported one: on such a
     * host the samesite key is absent and this at least distinguishes "the old
     * form set it" from "nothing set it", which is the question somebody reading
     * this screen after a surprise host downgrade actually has.
     */
    private function sameSiteFromPath($cookie)
    {
        $path = isset($cookie['path']) ? (string)$cookie['path'] : '';
        if (preg_match('/SameSite\s*=\s*(\w+)/i', $path, $m)) { return $m[1]; }
        return 'not set';
    }
}
