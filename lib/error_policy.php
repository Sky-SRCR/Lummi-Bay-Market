<?php
// ============================================================
// ERROR POLICY — what the app does when something goes wrong
// ============================================================
// Until now: nothing. No `error_reporting`, no `display_errors`, no `log_errors`
// anywhere in the repo, so whatever the hosting account's php.ini happened to say
// was the policy. On a shared host that is usually "print it", which means a PHP
// warning or an uncaught PDOException prints the absolute path to the webroot —
// onto the sign in the shop, in 2vw grey text, until somebody notices.
//
// The .htaccess route was considered and rejected. `php_flag display_errors Off`
// only works under mod_php, and this app has already been bitten once by assuming
// a mechanism was in force when it silently was not (the session cookie flags, see
// auth.php). A policy set in PHP travels with the deploy and is true on every
// SAPI, and — the part that decided it — it can be *read back*, which is what the
// Settings screen does.
//
// Three things live here, and they are one concern:
//
//   1. Report everything, show nothing, write it down.
//   2. Catch what escapes — warnings, uncaught exceptions, fatals — so that no
//      failure reaches a visitor as PHP's own output.
//   3. Say the right last thing. This is the part that needs the modes: the same
//      failure has to become a JSON error for an endpoint the Builder is polling,
//      a plain sentence for somebody signed in, and a self-retrying kiosk notice on
//      a TV that nobody is standing next to. Getting that last one wrong is the
//      whole defect: a Screen showing a stack trace is showing it to customers.
//
// Nothing here throws. A failure inside the failure handler produces a white page
// with no log line, which is strictly worse than the thing it was handling, so
// every filesystem call is suppressed and every handler is total.

require_once __DIR__ . '/alerts.php';

class ErrorPolicy
{
    /** A browser page somebody is signed in to and looking at. */
    const PAGE   = 'page';
    /** The Viewer, on a Screen, with nobody in front of it. */
    const SCREEN = 'screen';
    /** A JSON endpoint. Its caller is a script, and the script is watching. */
    const API    = 'api';

    /** Rotate rather than grow forever: a shared host has a disk quota. */
    const MAX_LOG_BYTES = 2097152;   // 2 MB

    const LOG_NAME = 'lbm-error.log';

    /** How often the app is willing to repeat itself about the same problem. */
    const REPORT_WINDOW = 3600;

    private static $installed = false;
    private static $mode      = self::PAGE;
    private static $sentence  = '';
    private static $dir       = null;   // null = not resolved yet, '' = nowhere to write
    private static $logFile   = '';
    private static $alerts    = null;
    private static $spent     = false;  // a last-resort answer has already gone out

    // ---- Installing ---------------------------------------------------------

    /**
     * Turn the policy on and declare what kind of page this is. Calling it again
     * with a different mode re-aims the last-resort output without re-registering
     * anything, which is how an entry point corrects the default.
     */
    public static function install($mode = self::PAGE)
    {
        self::$mode = self::knownMode($mode);
        if (self::$installed) { return; }
        self::$installed = true;

        // Report everything to the handler; show none of it; write it down.
        error_reporting(E_ALL);
        @ini_set('display_errors',         '0');
        @ini_set('display_startup_errors', '0');
        @ini_set('log_errors',             '1');
        @ini_set('html_errors',            '0');

        set_error_handler(['ErrorPolicy', 'handleError']);
        set_exception_handler(['ErrorPolicy', 'handleException']);
        register_shutdown_function(['ErrorPolicy', 'handleShutdown']);
    }

    /**
     * For `db_connect.php`, which every entry point includes. It gives the whole
     * app the policy without overriding a mode an entry point already declared —
     * a Screen that has said it is a Screen must not be quietly demoted back to a
     * page by an include that runs afterwards.
     */
    public static function installDefault()
    {
        if (!self::$installed) { self::install(self::PAGE); }
    }

    /**
     * Override the one sentence a visitor is told. See api.php's public poll.
     * Passing '' restores the per-mode defaults.
     */
    public static function sayOnFailure($sentence)
    {
        self::$sentence = (string)$sentence;
    }

    public static function useAlerts(AlertMailer $mailer)
    {
        self::$alerts = $mailer;
    }

    public static function mode()      { return self::$mode; }
    public static function installed() { return self::$installed; }

    // ---- Handlers -----------------------------------------------------------

    /**
     * Warnings and notices. Logged and swallowed — never promoted to exceptions.
     *
     * Promoting is the fashionable choice and it is the wrong one here. This app
     * is full of deliberate `@`-suppressed calls and empty catches around writes
     * that are allowed to fail (schemaTry, the reset email, every filesystem call
     * in this very file). Turning a notice into a throw would convert working code
     * into fatal errors on the first deploy, on a system with no staging.
     */
    public static function handleError($severity, $message, $file = '', $line = 0)
    {
        // `@` sets error_reporting to a reduced mask for the duration of the call.
        // Respecting it is the difference between an honest log and 40,000 lines
        // of "filemtime(): stat failed" from a rate limiter doing its job.
        if (!(error_reporting() & $severity)) { return true; }

        self::log(self::severityName($severity) . ': ' . $message, $file, $line);
        return true;   // handled; PHP prints and logs nothing further
    }

    /** An exception nobody caught. The request is over; say something useful. */
    public static function handleException($e)
    {
        $where = '';
        $kind  = 'exception';
        try {
            $kind  = get_class($e);
            $where = $e->getFile() . ':' . $e->getLine();
            self::log('UNCAUGHT ' . $kind . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
        } catch (Throwable $ignored) {
        }
        self::alert($kind . '|' . $where, 'Uncaught ' . $kind, self::describe($e));
        self::emit(self::sentence());
    }

    /**
     * A fatal — the one class of failure that reaches neither handler above.
     * Runs on every request; on all but a handful it finds nothing and returns.
     */
    public static function handleShutdown()
    {
        $last = error_get_last();
        if (!$last || !self::isFatal($last['type'])) { return; }

        $file = isset($last['file']) ? $last['file'] : '';
        $line = isset($last['line']) ? $last['line'] : 0;
        self::log('FATAL: ' . $last['message'], $file, $line);
        self::alert('fatal|' . $file . ':' . $line, 'Fatal error',
            $last['message'] . "\n\n" . $file . ':' . $line);
        self::emit(self::sentence());
    }

    /**
     * Give up deliberately. `db_connect.php`'s one caller: there is no database,
     * so there is no page to draw, and the only question left is what the person
     * or Screen in front of it sees.
     *
     * $detail goes to the log and the alert. It never goes to the visitor.
     */
    public static function fail($key, $detail, $subject = 'Failure')
    {
        self::log('FAILED (' . $key . '): ' . $detail);
        self::alert($key, $subject, $detail);
        self::emit(self::sentence());
        exit;
    }

    /**
     * Something went wrong, was handled, and the app carried on — but an admin
     * should still hear about it.
     *
     * `$every` throttles the *log as well as the email*, which is the difference
     * between this and the handlers above. Those run on a request that is ending;
     * this one is for a problem that recurs on its own schedule. A schema statement
     * the database keeps refusing is retried on every signed-in page load, and on
     * the Viewer's self-heal path every 30 seconds per Screen — thousands of
     * identical lines a day, in a 2 MB file that rotates, burying everything worth
     * reading. Pass 0 (the default) for a problem a person had to cause.
     *
     * Returns true when something was actually written or sent.
     */
    public static function report($key, $detail, $subject = 'Problem', $every = 0)
    {
        if ($every > 0 && !self::firstInWindow($key, $every)) { return false; }
        self::log('REPORT (' . $key . '): ' . $detail);
        self::alert($key, $subject, $detail);
        return true;
    }

    // ---- The last thing a visitor sees --------------------------------------

    /**
     * The whole of the mode decision, as a pure function of its arguments, so it
     * can be read and tested without a failing server. Returns markup; prints
     * nothing; knows nothing about the request.
     *
     * $partial is true when output has already started. It cannot be undone, so
     * the notice becomes an overlay laid over whatever got out rather than a
     * document that would arrive after a half-drawn page.
     */
    public static function noticeFor($mode, $sentence, $partial = false)
    {
        $mode = self::knownMode($mode);
        $text = htmlspecialchars((string)$sentence, ENT_QUOTES, 'UTF-8');

        if ($mode === self::API) {
            $json = json_encode(['status' => 'error', 'message' => (string)$sentence]);
            // A sentence that will not encode (invalid UTF-8 reaching this far) must
            // still leave the caller a reply it can parse — the Viewer's poll shows
            // `message` on the sign, and a zero-byte 200 leaves it stale for good.
            return $json === false
                ? '{"status":"error","message":"Temporarily unavailable."}'
                : $json;
        }

        // A Screen has nobody to reload it. Both shapes below re-check on the same
        // 30-second cadence the Viewer already polls on, so a database that comes
        // back puts the sign back without anybody driving to the store. That is
        // the single most important line in this file.
        if ($mode === self::SCREEN) {
            $css = 'position:fixed;inset:0;top:0;left:0;right:0;bottom:0;z-index:2147483647;'
                 . 'background:#111;display:flex;align-items:center;justify-content:center;'
                 . 'font-family:Arial,Helvetica,sans-serif;color:#8a8a8a;font-size:2.2vw;'
                 . 'letter-spacing:0.04em;text-align:center;padding:0 6vw;';
            if ($partial) {
                return '<div style="' . $css . '">' . $text . '</div>'
                     . '<script>setTimeout(function(){location.reload();},30000);</script>';
            }
            return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
                 . "<meta charset=\"UTF-8\">\n"
                 . "<meta http-equiv=\"refresh\" content=\"30\">\n"
                 . "<title>Display</title>\n</head>\n"
                 . '<body style="margin:0;background:#111;">'
                 . '<div style="' . $css . '">' . $text . '</div>'
                 . "</body>\n</html>";
        }

        $css = 'position:fixed;inset:0;top:0;left:0;right:0;bottom:0;z-index:2147483647;'
             . 'background:#f4f4f4;display:flex;align-items:center;justify-content:center;'
             . 'font-family:Arial,Helvetica,sans-serif;color:#333;font-size:17px;'
             . 'line-height:1.6;text-align:center;padding:0 24px;';
        if ($partial) {
            return '<div style="' . $css . '">' . $text . '</div>';
        }
        return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n"
             . "<meta charset=\"UTF-8\">\n<title>Something went wrong</title>\n</head>\n"
             . '<body style="margin:0;background:#f4f4f4;">'
             . '<div style="' . $css . '">' . $text . '</div>'
             . "</body>\n</html>";
    }

    /**
     * The sentence for a mode — this request's by default. Never carries a file
     * path, a class name, an SQL fragment or a value from the request.
     */
    public static function sentence($mode = null)
    {
        if (self::$sentence !== '') { return self::$sentence; }
        $mode = $mode === null ? self::$mode : self::knownMode($mode);
        if ($mode === self::SCREEN) {
            return 'This sign is temporarily unavailable.';
        }
        if ($mode === self::API) {
            return 'Temporarily unavailable. Please try again in a moment.';
        }
        return 'Something went wrong at our end. It has been written to the error log. '
             . 'Please try again in a moment.';
    }

    // ---- The log ------------------------------------------------------------

    /**
     * Where the log and the alert rate-limiter live. Resolved once, lazily, so
     * that a private-directory path defined in the credentials file — which
     * db_connect.php loads *after* installing the policy — is still honoured.
     *
     * Returns '' when there is nowhere writable. Everything downstream treats
     * that as "do less", never as an error worth raising.
     */
    public static function stateDir()
    {
        if (self::$dir !== null) { return self::$dir; }
        self::$dir = '';

        $app = dirname(__DIR__);
        $candidates = [];
        if (defined('LBM_LOG_DIR')) { $candidates[] = rtrim(LBM_LOG_DIR, '/\\'); }
        // Beside the database credentials, outside the webroot. Only attempted if
        // that private directory is really there — on an install using the
        // fallback placeholders in db_connect.php it is not, and creating a
        // `private/` directory the admin never asked for would be a surprise.
        $private = dirname($app, 2) . '/private';
        if (@is_dir($private)) { $candidates[] = $private . '/logs'; }
        // Last resort: inside the app, where it is reachable by URL unless we say
        // otherwise — so we say otherwise, in the same breath as creating it.
        $candidates[] = $app . '/logs';

        foreach ($candidates as $dir) {
            if ($dir === '') { continue; }
            if (!@is_dir($dir) && !@mkdir($dir, 0750, true) && !@is_dir($dir)) { continue; }
            if (!@is_writable($dir)) { continue; }
            self::guard($dir);
            self::$dir = $dir;
            break;
        }
        return self::$dir;
    }

    /**
     * A path inside the state directory for something other than the log — a lock,
     * a stamp — that has to be remembered across requests.
     *
     * Returns '' when there is nowhere writable, and every caller must read that as
     * "carry on without coordinating", never as "do not do the work". The thing
     * these files coordinate is a schema repair, and an install with no writable
     * directory needs that repair more than it needs the coordination.
     */
    public static function stateFile($name)
    {
        $dir  = self::stateDir();
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$name);
        return ($dir === '' || $name === '') ? '' : $dir . '/' . $name;
    }

    public static function logFile()
    {
        if (self::$logFile !== '') { return self::$logFile; }
        $dir = self::stateDir();
        if ($dir === '') { return ''; }
        self::$logFile = $dir . '/' . self::LOG_NAME;
        // From here PHP's own logging lands in the same file as ours, so a failure
        // early enough to beat the handlers is still in one place.
        @ini_set('error_log', self::$logFile);
        return self::$logFile;
    }

    /** For the self-test, and for an admin who has moved the directory. */
    public static function useLogFile($path)
    {
        self::$logFile = (string)$path;
        self::$dir     = $path === '' ? '' : dirname($path);
    }

    public static function log($message, $file = '', $line = 0)
    {
        try {
            $path = self::logFile();
            if ($path === '') { return false; }

            // PHP caches the result of a stat per path, and this function stats the
            // same path on every call — so the second entry of a request would be
            // sized against what the file was before the first one was appended, and
            // a request that logs in a loop would never see the file cross the limit
            // at all. The same is true across requests, which is the larger half of
            // it: every page load is a separate process appending to this one file,
            // so a cached answer is one this process took before anybody else's entry
            // landed. Ask the filesystem again; it is one syscall on the rare path.
            clearstatcache(true, $path);

            // `is_file` first: statting a path that is not there is a warning, and a
            // warning raised inside the thing that writes warnings down is a loop
            // waiting for somebody to remove one `@`.
            if (@is_file($path) && @filesize($path) > self::MAX_LOG_BYTES) {
                // One generation kept. Two would need a policy for the third.
                @rename($path, $path . '.1');
            }

            $entry = "[" . gmdate('Y-m-d H:i:s') . " UTC] "
                   . str_pad(self::$mode, 6) . ' '
                   . self::whichRequest() . ' — '
                   . str_replace(["\r", "\n"], ' ', (string)$message)
                   . ($file !== '' ? '  at ' . $file . ':' . intval($line) : '')
                   . "\n";
            return @file_put_contents($path, $entry, FILE_APPEND | LOCK_EX) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** What the log says a request was. No query string: it can carry a passcode. */
    public static function whichRequest()
    {
        return self::requestNameFor(PHP_SAPI, isset($_SERVER['SCRIPT_NAME'])
                                                ? $_SERVER['SCRIPT_NAME'] : '');
    }

    /**
     * The same answer, over a SAPI and a script name handed in.
     *
     * Seamed for the reason `ServerReport::phpVersionNote()` is — one process is only
     * ever one of these — and it found something while being seamed. The `'cli'`
     * fallback here was written for the command line and was never once reached
     * there: PHP sets `$_SERVER['SCRIPT_NAME']` on the CLI too, to the script's path,
     * and under `php -r` it sets it to the literal string **"Standard input code"**.
     * So every tool in this repo that produced a JSON reply tagged its log line
     * `json-reply|…|selftest_layout.php`, and every arm of `selftest_installed.php`
     * tagged theirs `json-reply|…|Standard input code` — a phrase from PHP's own
     * internals, in a log a person reads to find out which page broke. The SAPI is what
     * actually answers the question the fallback was asking.
     *
     * One SAPI name, not a list of the CLI-ish ones: `phpdbg` and the rest are real, and
     * a clause for a SAPI this app is never run under is a line no check here could ever
     * observe (invariant 30). It falls through to the page branch and names the script,
     * which is not wrong — just not special-cased on speculation.
     *
     * The empty case stays: a web SAPI with no `SCRIPT_NAME` is a host doing something
     * unusual, and a bare `|` at the end of a log line says less than a word does.
     */
    public static function requestNameFor($sapi, $scriptName)
    {
        if ($sapi === 'cli') { return 'cli'; }
        $script = basename((string)$scriptName);
        return $script === '' ? 'cli' : $script;
    }

    // ---- Reading the policy back --------------------------------------------

    /**
     * What the policy is actually doing, in the shape ServerReport uses so the
     * Settings tab can print both from one loop.
     *
     * Worth a screen of its own because every part of this can fail silently by
     * design: an unwritable directory means no log, and no cached recipients
     * means no alert — and both look exactly like "nothing has gone wrong".
     */
    public static function status()
    {
        $out = [];

        $showing = self::flagOn('display_errors');
        $out['Errors shown to visitors'] = [
            $showing ? 'On' : 'Off',
            $showing
                ? 'On, which this app sets off on every request — something is '
                . 'overriding it. A PHP error will print server paths onto the sign.'
                : ''];

        $path = self::logFile();
        if ($path === '') {
            $out['Error log'] = ['Nowhere to write',
                'No writable directory was found, so nothing that goes wrong is being '
                . 'recorded and no alert can be sent. Give the app a writable "logs" '
                . 'folder, or set LBM_LOG_DIR in the private credentials file.'];
        } else {
            // Same reason as the rotation check above: this page has very likely
            // logged something itself on the way here, and a readout that reports
            // the size from before its own entry is a readout that is wrong.
            @clearstatcache(true, $path);
            $size = @filesize($path);
            $when = @filemtime($path);
            $out['Error log'] = [$path,
                @is_writable($path) || @is_writable(dirname($path)) ? ''
                    : 'The file is there but cannot be written to.'];
            $out['Last logged'] = [
                $when ? gmdate('j M Y, H:i', $when) . ' UTC'
                        . ($size ? '  (' . number_format(round($size / 1024)) . ' KB)' : '')
                      : 'Nothing logged yet',
                ''];
        }

        $to = self::$alerts === null ? [] : self::$alerts->recipients();
        $out['Alerts go to'] = [
            $to ? implode(', ', $to) : 'Nobody',
            $to ? 'At most one email per problem per hour.'
                : 'No admin has an email address on file, so a failure that takes the '
                . 'signs down will be logged but nobody will be told.'];

        return $out;
    }

    // ---- Internals ----------------------------------------------------------

    private static function emit($sentence)
    {
        // Once. A fatal inside the shutdown handler must not print a second notice
        // underneath the first.
        if (self::$spent) { return; }
        self::$spent = true;

        $partial = headers_sent();
        if (!$partial) {
            // Discard the half-built page. What is in the buffer is by definition
            // the output of a request that did not finish.
            while (ob_get_level() > 0) { @ob_end_clean(); }
            @http_response_code(500);
            if (self::$mode === self::API) { @header('Content-Type: application/json'); }
        }
        echo self::noticeFor(self::$mode, $sentence, $partial);
    }

    /**
     * True the first time this key is seen in the window, false while still inside
     * it. `AlertMailer` keeps a stamp of its own, and this deliberately does not
     * reuse it: that one is written only when there is somebody to email, so on a
     * site where no admin has an address on file it would never be written and the
     * throttle would never engage — which is the exact case where the log is the
     * only record and the last thing that should be flooded.
     *
     * Stamped before reporting, not after, so a slow mail relay cannot let a second
     * request through behind the first. A stamp that cannot be written means the
     * report is dropped, matching `AlertMailer`: not being able to remember having
     * said something is the one state in which saying it again is unbounded.
     *
     * Public because a repeated *attempt to fix* something needs the same restraint
     * as a repeated report of it, and this is where the state directory is decided.
     * `lib/schema.php` uses it to stop every Screen retrying a schema repair that
     * cannot succeed on every 30-second poll.
     */
    public static function firstInWindow($key, $seconds)
    {
        try {
            $dir = self::stateDir();
            if ($dir === '') { return true; }   // nothing is being written anyway

            $stamp = $dir . '/report-' . substr(sha1((string)$key), 0, 16) . '.stamp';
            // Checked before statting: the usual case is a problem that has never
            // happened, and statting a path that is not there raises a warning from
            // inside the machinery that exists to handle warnings.
            if (@is_file($stamp)) {
                $at = @filemtime($stamp);
                // A stamp from the future — a clock corrected backwards — must not
                // hold the report off until the clock catches up.
                if ($at !== false && $at <= time() && (time() - $at) < $seconds) {
                    return false;
                }
            }
            return @file_put_contents($stamp, gmdate('c') . "\n", LOCK_EX) !== false;
        } catch (Throwable $e) {
            return true;
        }
    }

    private static function alert($key, $subject, $detail)
    {
        try {
            if (self::$alerts === null) { return; }
            self::$alerts->notify(
                $key,
                $subject,
                $detail . "\n\n"
                . 'Page: '  . self::whichRequest() . "\n"
                . 'Mode: '  . self::$mode . "\n"
                . 'Time: '  . gmdate('Y-m-d H:i:s') . " UTC\n"
            );
        } catch (Throwable $e) {
            // An alerter that throws is a failure inside a failure. Log and stop.
            self::log('alerting failed: ' . $e->getMessage());
        }
    }

    private static function describe($e)
    {
        try {
            return get_class($e) . ': ' . $e->getMessage() . "\n\n"
                 . $e->getFile() . ':' . $e->getLine() . "\n\n"
                 . $e->getTraceAsString();
        } catch (Throwable $ignored) {
            return 'An exception that could not be described.';
        }
    }

    /**
     * Written the moment the directory is created, not left to the deployer. A log
     * of server errors inside the webroot is a readable list of what is broken and
     * where the files are; both syntaxes, matching lib/.htaccess.
     */
    private static function guard($dir)
    {
        $htaccess = $dir . '/.htaccess';
        if (@file_exists($htaccess)) { return; }
        @file_put_contents($htaccess,
            "# Error log. Never served — it names server paths and failed queries.\n"
          . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
          . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n");
        @file_put_contents($dir . '/index.html', "");
    }

    /** `ini_get` answers "off" as the string '0', 'Off' or '' depending on how it was set. */
    private static function flagOn($setting)
    {
        $v = strtolower(trim((string)ini_get($setting)));
        return ($v !== '' && $v !== '0' && $v !== 'off' && $v !== 'false');
    }

    private static function knownMode($mode)
    {
        return ($mode === self::SCREEN || $mode === self::API) ? $mode : self::PAGE;
    }

    private static function isFatal($type)
    {
        return in_array($type, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    }

    private static function severityName($severity)
    {
        $names = [
            E_ERROR             => 'ERROR',
            E_WARNING           => 'WARNING',
            E_PARSE             => 'PARSE',
            E_NOTICE            => 'NOTICE',
            E_CORE_ERROR        => 'CORE ERROR',
            E_CORE_WARNING      => 'CORE WARNING',
            E_COMPILE_ERROR     => 'COMPILE ERROR',
            E_COMPILE_WARNING   => 'COMPILE WARNING',
            E_USER_ERROR        => 'USER ERROR',
            E_USER_WARNING      => 'USER WARNING',
            E_USER_NOTICE       => 'USER NOTICE',
            E_RECOVERABLE_ERROR => 'RECOVERABLE',
            E_DEPRECATED        => 'DEPRECATED',
            E_USER_DEPRECATED   => 'USER DEPRECATED',
        ];
        return isset($names[$severity]) ? $names[$severity] : 'ERROR ' . intval($severity);
    }
}
