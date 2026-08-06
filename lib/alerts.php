<?php
// ============================================================
// ALERTS — one email per problem per hour, to admins only
// ============================================================
// A log nobody reads is a log. The failures this exists for — the database gone,
// a fatal on the Viewer — are exactly the ones where nothing else will tell
// anyone: the sign is in a shop, the log is on a server with no shell, and the
// symptom a member of staff reports is "the board looks funny".
//
// Two design constraints, both from the failure being alerted:
//
//   **The rate limiter cannot live in the database.** The commonest reason to send
//   an alert is that the database is unreachable, and a limiter that needs a query
//   would fail open, and failing open means one email per Screen per poll — four
//   Screens polling every 30 seconds is 11,520 emails a day, from a machine whose
//   sending reputation the store's real mail depends on. It is a file, and if
//   there is no writable directory this refuses to send at all. Silence is the
//   safe direction here; a mail bomb is not recoverable by an admin, and the log
//   still has the entry.
//
//   **The recipient list cannot come from the database either**, for the same
//   reason. So the addresses are cached to disk from `AccountStore::adminEmails()`
//   whenever an admin opens the admin panel — a working moment, by definition —
//   and read back from that cache when things are not working.
//
// "Per problem" means per *place*: the key an `ErrorPolicy` failure hands over is
// its kind plus its file and line, so two different bugs in the same hour are two
// emails, and the same bug hit 3,000 times is one.
//
// Reads no tables. Writes no tables. Never throws.

class AlertMailer
{
    /** One per problem per hour. */
    const WINDOW = 3600;

    const RECIPIENTS_FILE = 'alert-recipients.txt';

    private $dir;
    private $siteName;

    /**
     * @param string $stateDir  Writable directory, or '' for "cannot rate-limit".
     * @param string $siteName  For the subject line.
     */
    public function __construct($stateDir, $siteName = '')
    {
        $this->dir      = rtrim((string)$stateDir, '/\\');
        $this->siteName = (string)$siteName !== '' ? (string)$siteName : 'Display System';
    }

    /**
     * Cache the addresses to alert. Called from a page that is working, with the
     * list read from the database there; a no-op when nothing has changed, because
     * this is called on every admin panel load and a write per page view to say
     * the same thing is a write per page view.
     */
    public function remember(array $emails)
    {
        try {
            if ($this->dir === '') { return false; }
            $clean = [];
            foreach ($emails as $email) {
                $email = trim((string)$email);
                if ($email !== '' && strpos($email, '@') !== false && strpos($email, "\n") === false) {
                    $clean[$email] = true;
                }
            }
            if (!$clean) { return false; }   // never blank a working list

            $body = implode("\n", array_keys($clean)) . "\n";
            $path = $this->dir . '/' . self::RECIPIENTS_FILE;
            if (@is_file($path) && @file_get_contents($path) === $body) { return true; }
            return @file_put_contents($path, $body, LOCK_EX) !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Who would be told. Empty means no alert can be sent. */
    public function recipients()
    {
        try {
            if ($this->dir === '') { return []; }
            $path = $this->dir . '/' . self::RECIPIENTS_FILE;
            if (!@is_file($path)) { return []; }
            $raw = @file_get_contents($path);
            if ($raw === false) { return []; }
            $out = [];
            foreach (explode("\n", $raw) as $line) {
                $line = trim($line);
                if ($line !== '' && strpos($line, '@') !== false) { $out[] = $line; }
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Send, unless this problem has already been sent inside the window.
     * Returns true only when a message was actually handed to the mailer.
     */
    public function notify($key, $subject, $body)
    {
        try {
            if ($this->dir === '') { return false; }

            $to = $this->recipients();
            if (!$to) { return false; }

            $stamp = $this->stampFile($key);
            if ($this->sentRecently($stamp)) { return false; }

            // Stamped *before* sending, not after. mail() on a host with a slow
            // relay can take seconds, and every Screen polling through the same
            // outage would queue up behind an unwritten stamp.
            if (@file_put_contents($stamp, gmdate('c') . "\n", LOCK_EX) === false) {
                return false;   // cannot remember → must not send
            }

            $headers = 'From: ' . $this->fromName() . ' <' . $this->fromAddress() . '>' . "\r\n"
                     . 'Reply-To: ' . $this->fromAddress() . "\r\n"
                     . 'Auto-Submitted: auto-generated' . "\r\n"
                     . 'X-Mailer: PHP/' . phpversion();

            $text = $this->siteName . " reported a problem.\n\n"
                  . $body . "\n"
                  . "-- \nThis is an automatic message. At most one is sent per problem per hour.\n";

            $this->deliver(implode(', ', $to), '[' . $this->siteName . '] ' . $subject, $text, $headers);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * The one line that actually sends. Separate so the self-test can subclass it
     * and assert on what would have gone out — the rules worth testing here are
     * who gets told and how often, and neither of them is `mail()`.
     *
     * Suppressed: a host with no MTA emits a warning, and a warning raised while
     * handling a failure is how a failure becomes two.
     */
    protected function deliver($to, $subject, $body, $headers)
    {
        return @mail($to, $subject, $body, $headers);
    }

    /** When this problem was last sent, as a unix time, or 0. For the readout. */
    public function lastSent($key)
    {
        if ($this->dir === '') { return 0; }
        $stamp = $this->stampFile($key);
        if (!@is_file($stamp)) { return 0; }
        $at = @filemtime($stamp);
        return $at === false ? 0 : $at;
    }

    // ---- Internals ----------------------------------------------------------

    private function sentRecently($stamp)
    {
        // Checked before statting: the usual case is a problem that has never
        // happened, and statting a path that is not there raises a warning — from
        // inside the machinery that exists to handle warnings.
        if (!@is_file($stamp)) { return false; }
        $at = @filemtime($stamp);
        if ($at === false) { return false; }
        // A stamp from the future — a clock corrected backwards — would otherwise
        // hold the alert off until the clock caught up.
        return $at <= time() && (time() - $at) < self::WINDOW;
    }

    /** The key is arbitrary text from a failure; the filename must not be. */
    private function stampFile($key)
    {
        return $this->dir . '/alert-' . substr(sha1((string)$key), 0, 16) . '.stamp';
    }

    private function fromAddress()
    {
        return defined('MAIL_FROM') ? MAIL_FROM : 'noreply@localhost';
    }

    private function fromName()
    {
        return defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : $this->siteName;
    }
}
