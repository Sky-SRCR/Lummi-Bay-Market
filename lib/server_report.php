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
// **The answer came from elsewhere first, and this card was not what reported it.** A
// branch recorded 8.2 here and raised the rule to match; that was withdrawn, because this
// card ships with the multi-display build and #46's probe found that build undeployed —
// `lib/` answered 404 live — so this screen cannot have been the thing that reported it,
// and Cloudflare hides the version from the response headers too (§4k). The store owner
// then stated it directly (**PHP 8.2, 2026-08-10**), and on **2026-08-11** it was observed
// twice in cPanel: 8.2.33 on the runtime card, and `ea-php82` pinned explicitly to the
// domain against a system default of 8.3. A person and a configuration screen, both dated,
// neither of them a reading this code took — which is why it is recorded that way.
//
// **This card runs in `lbm-test/` and not yet on the sign.** That distinction is the whole
// of its remaining job: `public_html/lbm/` is still the single-sign app, so the version,
// the three clocks, the upload ceiling and the converged columns are readable for the
// rehearsal install and for nothing else. Nothing else here observes any of them, and the
// floor in `ASSUMED_PHP` is load-bearing rather than cautious — so what this card
// contradicts is a host upgraded, downgraded or moved to a different account, and it is
// worth opening after any hosting change rather than only after a deploy.
//
// It is also the answer to "which database am I actually looking at", which is why the
// isolation guarantee in DEPLOY-SKIP §E points here: two installs that walk up to the same
// `private/` directory behave identically while reading different databases, and this card
// is the only page that names the one it found.
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
require_once __DIR__ . '/install_paths.php';
require_once __DIR__ . '/store_clock.php';

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

    // `?array` rather than `array … = null`: from PHP 8.4 the implicit form is
    // deprecated and every request that builds this report emits a notice into the
    // error log. The explicit form is understood back to 7.1, so this costs nothing
    // below the floor and is one fewer thing between here and the next version.
    // It is invariant 33 now (§4bj), which is what stops the next one being found by
    // compiling the tree by hand — and the sentence above is the reason it is a rule.
    public function __construct(PDO $pdo, ?array $server = null)
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

        $out['MySQL version'] = [$this->mysqlVersion(),
            self::mysqlVersionNote($this->driverName(), $this->mysqlVersion())];

        // Which install this is, and which database it is talking to. Together these
        // are the only place a person can *see* that a rehearsal copy is isolated,
        // and they exist because the alternative is finding out by publishing.
        //
        // Two folders of this app on one account walk up to the same `private/`
        // directory, so a copy that has not been given its own credentials file
        // connects to the live database and behaves perfectly while doing it
        // (lib/install_paths.php). Nothing downstream can notice: the schema
        // converges, the Displays are all there, a publish succeeds. So the check is
        // a fact on a screen — open this card on the copy, read the name, and if it
        // is the live database you have found that out before overwriting a sign
        // rather than after.
        //
        // The name is not a credential. The host, the user and the password are not
        // reported, and a database name is already in `HANDOFF.md`.
        $out['This install'] = [InstallPaths::installName(dirname(__DIR__)), ''];

        $dbName = defined('DB_NAME') ? (string)DB_NAME : '';
        $out['Database'] = [$dbName !== '' ? $dbName : 'unknown',
            $dbName === '' ? 'No DB_NAME is defined, so this cannot say which database '
                             . 'the app is using.' : ''];

        // Three clocks, because that is how many there were (#44) and because the
        // whole reason this row existed was that the mismatch was otherwise invisible.
        //
        // The one that answers the question people ask goes first: which zone the
        // times on these screens are in. It is a setting now, on this same tab, so the
        // note names the stored value it could not use rather than leaving a person to
        // wonder why the dropdown and the clock disagree (#21).
        $out[StoreClock::LABEL] = [
            StoreClock::zone() . ' — ' . StoreClock::labelForEpoch(time(), 'D j M Y, g:ia'),
            $this->storeZoneNoteFor(defined(StoreClock::SETTING) ? constant(StoreClock::SETTING) : null)];

        // PHP's process zone. No longer what any time on any page is drawn in —
        // `config.php` points it at the store and `StoreClock::label()` converts
        // explicitly either way — so an unset `date.timezone` is a fact about the host
        // and not a defect any more. Which is exactly why it stays on the card and why
        // the note changed: it used to say times may be hours out, and saying that now
        // would send somebody after a problem the setting above has already answered.
        $out['PHP time zone'] = [date_default_timezone_get(),
            self::phpZoneNoteFor(ini_get('date.timezone'))];

        // MySQL's, and the reason it is worth a row of its own: it is where
        // `created_at` comes from, PHP cannot convert what it did not write, and until
        // `db_connect.php` set it there was no screen anywhere that showed it. The note
        // is seamed rather than spelled here, because the value is read off the
        // connection and its two forms are the two engines (§4bl) — and the question
        // it answers is whether the stamps are in UTC, not whether the `SET` ran.
        $dbZone = $this->databaseTimeZone();
        $out['Database time zone'] = [$dbZone, self::dbZoneNoteFor($dbZone)];

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
        $out['Largest file that can be uploaded'] = [
            UploadLimit::describe(),
            self::uploadCeilingNoteFor(UploadLimit::bytes(),
                                       ini_get('upload_max_filesize'),
                                       ini_get('post_max_size'))];

        return $out;
    }

    /**
     * What an unset `date.timezone` means, given that the app no longer depends on it.
     *
     * Takes the ini value for the reason `phpVersionNote()` takes the version id: the
     * case this exists for is the one no test process can be in. `date.timezone` is
     * PHP_INI_ALL in name only — a host that has no such line in its php.ini answers
     * `''`, and `php -d date.timezone=` does not reproduce that, it is rejected at
     * startup and replaced with UTC. So the branch that speaks was unreachable while
     * it was spelled inline, and the branch that stays silent was the only one any
     * machine here could produce.
     *
     * Silent when the host has a zone, and that is not a check that cannot fail: the
     * point of the sentence is that an unset zone is *harmless now*, and saying so
     * every time would train an admin past the rows above that are not.
     */
    public static function phpZoneNoteFor($iniValue)
    {
        if ((string)$iniValue !== '') { return ''; }
        return 'Not set in the server configuration. Harmless — the app sets its own '
             . 'from the setting above on every page, which is what stopped this '
             . 'from being the reason a time was wrong.';
    }

    /**
     * Whether the host or the app is the thing refusing a large file, and which
     * settings to go and change.
     *
     * Same seam and the same reason as `UploadLimit::smallestOf()`, whose docblock
     * says it out loud: `upload_max_filesize` and `post_max_size` are PHP_INI_PERDIR,
     * so a running process cannot be moved between the two cases. Reading them inline
     * meant this sentence had exactly one form on any given machine — here, the one
     * that names 2M, forever.
     *
     * The two ini values are quoted verbatim rather than in bytes on purpose: they are
     * what somebody has to find and edit, and `8388608` is not what is written in the
     * file they will open.
     */
    public static function uploadCeilingNoteFor($effective, $uploadMax, $postMax)
    {
        if ($effective >= UploadLimit::APP_MAX_BYTES) { return ''; }
        return 'This server, not the app, is the limit (upload_max_filesize '
             . $uploadMax . ', post_max_size ' . $postMax . '). A video for a sign '
             . 'may not fit.';
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
            ['block_styles',    'brand_id',        'Brand Standards are not keyed to a brand, so every display shares one set and Display Branding can only edit the first. Do not create a second brand.'],
            ['displays',        'brand_id',        'No display knows which brand it wears, so its branded blocks fall back to their own stored typography. Do not publish.'],
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

    /** Which engine is answering, so a note about MySQL is not printed about SQLite. */
    private function driverName()
    {
        try {
            return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        } catch (Throwable $e) {
            return 'unknown';
        }
    }

    /**
     * The database floor the SQL in this repo is written to.
     *
     * 5.7 because that is what the host **is** — `5.7.23-23`, read off this very card on
     * 2026-08-11 and recorded in `HANDOFF.md`. Which is the whole reason this note exists
     * now and did not before: the version sat in that table for eight days beside two
     * rows that each settled a standing question, and this one produced nothing. The row
     * printed a number and said `''`, hardcoded, while the row above it had three bands
     * of commentary and a declared floor.
     */
    const ASSUMED_MYSQL = '5.7';

    /**
     * What this server's database engine means, given the SQL this repo actually sends.
     *
     * Takes the driver and the version rather than reading either, for the reason
     * `phpVersionNote()` takes the version id: one process talks to one engine, and the
     * bands that matter are the ones no test database is. The driver is a parameter and
     * not an afterthought — the self-test fixture is **SQLite**, whose
     * `ATTR_SERVER_VERSION` is something like `3.45.1`. Parsed as a MySQL version that is
     * far below the floor, so a note written without the driver would have fired on every
     * SQLite run in the project and said the shop's engine was ancient.
     *
     * Silent at and above the floor, for the reason the PHP note is: a sentence an admin
     * reads every time is one they learn to skip.
     *
     * The band below the floor names consequences rather than the number, because the
     * number is already in the row beside it, and because a failed schema statement here
     * is **logged and emailed, never thrown** (#9) — so an engine that refuses one of
     * these does not announce itself. The page carries on and dies later at the first
     * query against what was never created.
     */
    public static function mysqlVersionNote($driver, $version)
    {
        if ($driver !== 'mysql') { return ''; }

        // MariaDB reports 10.x, which is numerically above the floor and tells you
        // nothing, because it is a different product. No mechanism is invented here: the
        // honest statement is that nothing in this project has ever run on it.
        //
        // `!== false` and not `!= false`: `stripos` answers 0 for a match at the start of
        // the string, and 0 is falsy. No version string PDO hands back begins with the
        // word — MariaDB reports `10.6.16-MariaDB`, and some builds `5.5.5-10.6.16-MariaDB`
        // — so the loose form would work here by luck rather than by being right. The
        // suite pins the strict form with a string starting at position 0, which is the
        // only way to tell the two spellings apart (invariant 30).
        if (stripos((string)$version, 'mariadb') !== false) {
            return 'This is MariaDB, not the MySQL ' . self::ASSUMED_MYSQL . ' the SQL in '
                 . 'this app was written against and observed on. Nothing here has run on '
                 . 'it. Rehearse against a copy before publishing — tools/rehearse_phase1.php.';
        }

        // A comparable integer, not a meaningful number. The two multipliers survive
        // mutation on purpose and it is worth saying why rather than leaving it to be
        // rediscovered: any base in which the major version outranks the minor gives the
        // same answer at the one threshold below, so `10001` and `101` are the same
        // function as `10000` and `100` for every version string a MySQL host produces.
        // What the encoding has to get right is the ordering, and that is what the
        // boundary checks at 5.6 / 5.7 / 8.0 assert.
        if (!preg_match('/^(\d+)\.(\d+)/', (string)$version, $m)) { return ''; }
        $id = intval($m[1]) * 10000 + intval($m[2]) * 100;

        if ($id >= 50700) { return ''; }

        // Deliberately no SQL keyword in this sentence. Two reasons, and the second is
        // the one that bit: an admin reading this card does not need the identifier, and
        // `check_invariants.php` holds the whole repo to one place that may name the
        // database's own clock — a rule it enforces over string literals, which unlike
        // comments it cannot drop. The first draft of this note failed that check.
        return 'Older than the MySQL ' . self::ASSUMED_MYSQL . ' this app\'s SQL is '
             . 'written for. Two things it needs stop being safe below that: a date column '
             . 'that defaults to the time the row was made, and the unique index on a '
             . '255-character utf8mb4 email address, which is 1020 bytes and needs '
             . 'innodb_large_prefix — off by default before 5.7.7. A schema statement this '
             . 'server refuses is logged and emailed, never thrown, so it will not announce '
             . 'itself. Tell the developer before the next deploy.';
    }

    /**
     * What the store time zone row has to say, if anything.
     *
     * A stored value this app will not use is the only thing worth a sentence here,
     * and it can only arrive by hand-editing `branding_config.php` — the form offers a
     * list. Both halves are said: what is stored, and what is being used instead, so
     * "the setting says one thing and the clock says another" is answered on the same
     * screen rather than being the question somebody arrives with (#21).
     *
     * Public and pure for the same reason `phpVersionNote()` is: the case it exists for
     * cannot be reached through the constant, because a `define()` cannot be undone and
     * a running installation is not in that state.
     */
    public function storeZoneNoteFor($stored)
    {
        $bad = StoreClock::unreadable($stored);
        if ($bad === '') { return ''; }
        return 'The stored setting is "' . $bad . '", which is not a time zone this '
             . 'server knows — a fixed offset or an abbreviation will not do, because '
             . 'neither says when daylight saving starts. Times are being shown in '
             . StoreClock::DEFAULT_ZONE . ' instead. Pick one from the list on this '
             . 'page to fix it.';
    }

    /**
     * The zone this connection is actually in.
     *
     * `@@session.time_zone` is `SYSTEM` on a host where nothing set it, and `SYSTEM` is
     * not an answer — so the system zone is asked for as well, which is the value the
     * name is standing in for. Configuration, not application data: this module reads
     * no rows, which is what keeps it outside the one-writer rules.
     */
    private function databaseTimeZone()
    {
        try {
            $zone = (string)$this->pdo->query("SELECT @@session.time_zone")->fetchColumn();
            if (strcasecmp($zone, 'SYSTEM') === 0) {
                $system = (string)$this->pdo->query("SELECT @@system_time_zone")->fetchColumn();
                return $system !== '' ? 'SYSTEM (' . $system . ')' : 'SYSTEM';
            }
            return $zone !== '' ? $zone : 'unknown';
        } catch (Throwable $e) {
            // Neither variable exists on SQLite, which has no session zone at all —
            // every stamp it produces is UTC by definition. The self-test's fixture
            // lands here, and so would any engine that is not MySQL.
            return 'not applicable';
        }
    }

    /**
     * What to say about the zone the database writes `created_at` in, if anything.
     *
     * A seam beside `databaseTimeZone()` for the reason every other readout on this
     * card has one: the value is read off the machine, so spelled inline it has one
     * form on whatever ran the suite and the other form on nothing — and here the
     * two forms are the two engines, which is why the suite could only ever assert
     * whichever one it happened to be running against (§4bl).
     *
     * `SYSTEM (UTC)` is the case that made this more than tidiness. It means the
     * `SET time_zone` in `db_connect.php` did not take *and* the host's own zone is
     * already UTC, so the stamps are in UTC regardless — and the note below says
     * they may read hours out, which is the sentence somebody acts on. A protection
     * that turns out not to have been needed is not a problem to report.
     */
    public static function dbZoneNoteFor($zone)
    {
        if (self::isUtcOffset($zone)) { return ''; }
        return 'The app asks the database for UTC on every connection and this one '
             . 'is not, so this host refused it. Dates recorded by the database '
             . 'itself — when an account was created — may read a few hours out. '
             . 'Nothing a sign shows is affected.';
    }

    /**
     * Is this zone a zero offset — i.e. are the database's own stamps in UTC?
     *
     * `+00:00` is what is asked for; `UTC` and `+0:00` are the same instant written
     * differently and a host that normalises the value is not a host that refused it.
     * `not applicable` is the non-MySQL case and is not a failure either.
     *
     * `SYSTEM (x)` is unwrapped and asked again, because the name is standing in for
     * `x` and the question is about the instant, not about which statement set it.
     * Only what this predicate already recognises counts as zero — an abbreviation
     * like `GMT` is left warning rather than added on the strength of what it looks
     * like, since a wrong entry here is silence about stamps that really are out.
     */
    private static function isUtcOffset($zone)
    {
        $zone = trim((string)$zone);
        if ($zone === 'not applicable') { return true; }
        if (strcasecmp($zone, 'UTC') === 0) { return true; }
        if (preg_match('/^SYSTEM \((.+)\)$/i', $zone, $m) === 1) {
            return self::isUtcOffset($m[1]);
        }
        return preg_match('/^[+-]0?0:00$/', $zone) === 1;
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
