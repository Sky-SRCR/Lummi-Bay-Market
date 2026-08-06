<?php
// ============================================================
// LOGIN GATE — what a sign-in attempt is allowed to learn
// ============================================================
// The account-keyed lockout (ADR-0001) was careful about one leak and blind to a
// worse one. It says the same sentence for an unknown username and a wrong password
// — but the checks for a **suspended** and a **closed** account sat *after*
// `password_verify()`, so reaching them was itself the answer:
//
//   wrong password on a suspended account  → "Incorrect username or password."
//   right password on a suspended account  → "Your account has been deactivated."
//
// A guesser working through a list stops at the second sentence knowing the password
// is correct. They cannot sign in here — and that is not the point. People reuse
// passwords, and the store's staff are the people whose passwords this page would be
// confirming, on the account most likely to be probed: the one whose owner has left,
// is not watching for anything odd, and whose password nobody is going to change.
//
// So the sentence no longer depends on the password. Everything that is not a
// completed sign-in says one thing (`REFUSAL`), and that sentence is safe to show a
// stranger because it also tells a real member of staff what to do:
//
//   "Incorrect username or password. If you are sure they are right, your account
//    may have been switched off — ask your manager to check."
//
// **The two alternatives, and why not.** Moving the state checks *before* the
// password turns a password oracle into a username oracle: type a name, learn that
// it exists and has been suspended. That is a smaller leak than this one but a
// bigger one than none, and it contradicts `reset_password.php`, which has always
// answered a suspended account exactly as it answers a stranger. Keeping the two
// sentences and emailing the person instead is the textbook answer and the wrong
// shape for this host: an email per attempt is what the alert rate limit exists to
// stop (§4m), and the mail allowance would be spent by the first bot to arrive.
//
// **What is deliberately still visible**, because a person locked out has to be told
// to stop trying: the wait message is shown only for an account that could otherwise
// sign in, so five deliberate wrong guesses still distinguish "real and usable" from
// "suspended, closed or unknown". That is existence, not a credential, and ADR-0001
// already accepted it. What five guesses can no longer distinguish is a right
// password from a wrong one.
//
// The gate also answers the second half of decision #38 before it looks at anything
// else: a POST that arrives without this site's session cookie cannot complete a
// sign-in, whatever the password is, because there is no session for the redirect to
// land in. Saying so is the difference between a sentence and a form that reappears
// forever (see `lib/request_scheme.php` for how it got that way). It is checked
// ahead of the password on purpose — a message that only appears once the password
// is right would be the very oracle this module exists to close.
//
// Nothing is required here: this file must be includable from a page that has not
// started a session (see BUILD-REFERENCE.md §1).

/**
 * One sign-in attempt's answer: what to say, and what to write down about it.
 *
 * `failureRecord()` is the lockout arithmetic's result rather than a statement —
 * the gate decides what the three ADR-0001 columns should now hold, and
 * `AccountStore` is the only thing that writes them.
 */
class LoginOutcome
{
    const SIGNED_IN = 'signed_in';
    const REFUSED   = 'refused';     // wrong password, unknown, suspended or closed
    const LOCKED    = 'locked';      // too many failures; a correct password waits too
    const NO_COOKIE = 'no_cookie';   // the browser is not keeping the session cookie
    const NO_FIELDS = 'no_fields';   // the form came back empty

    private $kind;
    private $message;
    private $failure;

    private function __construct($kind, $message, $failure = null)
    {
        $this->kind    = $kind;
        $this->message = $message;
        $this->failure = $failure;
    }

    public static function signedIn()
    {
        return new self(self::SIGNED_IN, '');
    }

    public static function refused($message, $failure = null)
    {
        return new self(self::REFUSED, $message, $failure);
    }

    public static function locked($message, $failure = null)
    {
        return new self(self::LOCKED, $message, $failure);
    }

    public static function noCookie($message)
    {
        return new self(self::NO_COOKIE, $message);
    }

    public static function noFields($message)
    {
        return new self(self::NO_FIELDS, $message);
    }

    public function kind()       { return $this->kind; }
    public function message()    { return $this->message; }
    public function isSignedIn() { return $this->kind === self::SIGNED_IN; }

    /**
     * ['failed_attempts' => int, 'last_failed_at' => int, 'locked_until' => int|null]
     * or null when this attempt is not one worth counting.
     */
    public function failureRecord() { return $this->failure; }
}

class LoginGate
{
    /** ADR-0001's two numbers. One window governs both age-out and lockout length. */
    const MAX_ATTEMPTS   = 5;
    const WINDOW_SECONDS = 900;   // 15 minutes

    /** The one sentence every refusal gets, whatever the real reason was. */
    const REFUSAL = 'Incorrect username or password. If you are sure they are right, '
                  . 'your account may have been switched off — ask your manager to check.';

    const MISSING_FIELDS = 'Please enter both your username and password.';

    /**
     * Not "cookies are disabled" — the browser may simply not have had one yet, and
     * this response carries one, so trying again is the fix in that case and the
     * sentence has to work for both.
     */
    const NO_COOKIE = 'This browser did not send back the sign-in cookie, so signing in '
                    . 'cannot finish. Reload this page and try again — if it keeps happening, '
                    . 'allow cookies for this site.';

    /**
     * Normalise a `users` row into the facts the decision is made from, or null.
     *
     * Times become integers here so the decision itself is arithmetic a test can
     * drive without a database or a clock. `$closed` is asked separately because the
     * question belongs to `AccountStore` (a database that predates `closed_at` has
     * never closed an account) and this module reads no SQL.
     */
    public static function accountFacts($row, $closed)
    {
        if (!is_array($row) || !isset($row['id'])) { return null; }
        return [
            'id'              => intval($row['id']),
            'is_active'       => !empty($row['is_active']),
            'closed'          => (bool)$closed,
            'failed_attempts' => intval($row['failed_attempts'] ?? 0),
            'last_failed_at'  => self::toTime($row['last_failed_at'] ?? null),
            'locked_until'    => self::toTime($row['locked_until'] ?? null),
        ];
    }

    /**
     * Decide one attempt.
     *
     * @param array    $request  ['username' => string, 'password' => string,
     *                            'session_cookie' => bool]
     * @param array|null $account  accountFacts(), or null for a username nobody has
     * @param callable $verifyPassword  answers the one question this module must not
     *                                  ask itself: does the password match the stored
     *                                  hash? Called **at most once**, and called for
     *                                  every existing account — including one that
     *                                  may not sign in, so that a suspended account
     *                                  costs the same hundred milliseconds as a live
     *                                  one. Skipping it there would replace the
     *                                  message oracle with a timing one.
     * @param int      $now
     */
    public static function decide(array $request, $account, callable $verifyPassword, $now)
    {
        $username = trim((string)($request['username'] ?? ''));
        $password = (string)($request['password'] ?? '');
        $now      = intval($now);

        if ($username === '' || $password === '') {
            return LoginOutcome::noFields(self::MISSING_FIELDS);
        }

        // Before the password, always: a sign-in that cannot be remembered is not a
        // sign-in, and this is the one refusal that must not wait on being right.
        if (empty($request['session_cookie'])) {
            return LoginOutcome::noCookie(self::NO_COOKIE);
        }

        $exists = is_array($account);
        $mayUse = $exists && $account['is_active'] && !$account['closed'];

        // Lockout is absolute: a correct password still waits it out. Checked before
        // the hash so that hammering a locked account stays cheap for this server —
        // and only for an account that could sign in, so a suspended one is never
        // told to wait for something that would not help.
        if ($mayUse && $account['locked_until'] !== null && $account['locked_until'] > $now) {
            return LoginOutcome::locked(self::waitMessage($account['locked_until'] - $now));
        }

        $passwordOk = $exists ? (bool)call_user_func($verifyPassword) : false;

        if ($mayUse && $passwordOk) {
            return LoginOutcome::signedIn();
        }

        // One sentence for four different truths. Only an account that could have
        // signed in accrues failures: there is nothing to brute-force on one that
        // cannot, and counting there would leak its state through the wait message.
        if (!$mayUse) {
            return LoginOutcome::refused(self::REFUSAL);
        }

        $failure = self::nextFailure($account, $now);
        return $failure['locked_until'] === null
            ? LoginOutcome::refused(self::REFUSAL, $failure)
            : LoginOutcome::locked(self::waitMessage($failure['locked_until'] - $now), $failure);
    }

    /**
     * What the three lockout columns should hold after one more failure.
     *
     * A fresh five either when the previous lockout has expired or when the last
     * failure is older than the window — otherwise an account could be walked to
     * four failures a day for a year and locked out by the 1,825th.
     */
    public static function nextFailure(array $account, $now)
    {
        $now            = intval($now);
        $lockoutExpired = $account['locked_until'] !== null && $account['locked_until'] <= $now;
        $agedOut        = $account['last_failed_at'] !== null
                          && ($now - $account['last_failed_at']) > self::WINDOW_SECONDS;
        $attempts       = (($lockoutExpired || $agedOut) ? 0 : intval($account['failed_attempts'])) + 1;

        return [
            'failed_attempts' => $attempts,
            'last_failed_at'  => $now,
            'locked_until'    => $attempts >= self::MAX_ATTEMPTS ? $now + self::WINDOW_SECONDS : null,
        ];
    }

    /** Rounded up, so "1 minute" is never a wait that is still refused at 59 seconds. */
    public static function waitMessage($seconds)
    {
        $minutes = max(1, (int)ceil(intval($seconds) / 60));
        return 'Too many failed attempts. Please wait ' . $minutes
             . ' minute(s) before trying again.';
    }

    /** A stored DATETIME as a timestamp, or null. Unparseable reads as absent. */
    private static function toTime($value)
    {
        if ($value === null || $value === '') { return null; }
        if (is_int($value)) { return $value; }
        $time = strtotime((string)$value);
        return $time === false ? null : $time;
    }
}
