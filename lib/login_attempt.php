<?php
// ============================================================
// SIGNING IN — one decision, and a refusal that never depends on the password
// ============================================================
// The login page used to decide in this order: locked out, then wrong password,
// then closed, then suspended, then in. Read from the outside, that order is an
// oracle. A guesser working through passwords on a suspended account got
// "Incorrect username or password" for every wrong one and *a different sentence*
// for the right one — so the app answered the only question the guesser had, out
// loud, on the account where the answer was supposed to be worthless. Passwords
// are reused; a password confirmed on a retired account is a password to try
// somewhere else.
//
// The rule that fixes it is not "say less". It is:
//
//   **Every question that does not depend on the password is answered before the
//   password is read at all.**
//
// Closed, suspended and locked-out are properties of the account. They are
// settled first, and the sentence they produce is then the same whatever was
// typed into the password box — which is what makes it useless to guess against.
// Only when none of them applies does the password get verified, and its two
// outcomes are the generic refusal and being let in.
//
// The cost, stated rather than hidden: somebody who knows a username can now
// learn that it is suspended or closed without knowing the password. ADR-0001
// already accepted a weaker version of that trade (only real accounts ever lock
// out), and this one names accounts that cannot sign in under any password. See
// docs/adr/0008-a-sign-in-refusal-never-depends-on-the-password.md for the
// alternatives that were rejected.
//
// **This module holds no PDO.** Not an oversight — it is the structure of the
// rule. Every statement is `AccountStore`'s, so the file that decides what to say
// cannot quietly grow a query that says something else, and the thing under test
// is the decision rather than a database. `login.php` is left with three lines:
// hand over what was typed, and either start a session or print the sentence.

require_once __DIR__ . '/accounts.php';

/**
 * What happened, in a form a page can act on without re-deriving it.
 *
 * The `kind` exists so the page never matches on wording, and so a test can tell
 * "refused as suspended" from "refused as a wrong password" — which is the whole
 * distinction this module was written to get right, and one that is invisible if
 * the only thing an outcome carries is a sentence.
 */
class LoginOutcome
{
    const OK         = 'ok';
    const INCOMPLETE = 'incomplete';   // the form was not filled in
    const REFUSED    = 'refused';      // no such account, or the wrong password
    const LOCKED     = 'locked';       // too many failures, waiting it out
    const SUSPENDED  = 'suspended';    // is_active is off
    const CLOSED     = 'closed';       // closed_at is set — permanent

    private $kind;
    private $message;
    private $account;

    private function __construct($kind, $message, $account)
    {
        $this->kind    = $kind;
        $this->message = $message;
        $this->account = $account;
    }

    public static function ok(array $account)
    {
        return new self(self::OK, '', $account);
    }

    public static function refused($kind, $message)
    {
        return new self($kind, $message, null);
    }

    public function kind()    { return $this->kind; }
    public function isOk()    { return $this->kind === self::OK; }
    public function message() { return $this->message; }

    public function accountId()
    {
        return $this->account ? intval($this->account['id']) : 0;
    }

    public function username()
    {
        return $this->account ? (string)$this->account['username'] : '';
    }

    /**
     * Normalised the same way `syncSessionAccount()` normalises it on every later
     * request. The session used to be handed the column verbatim, so a row saying
     * anything other than exactly `admin` or `basic` meant one thing for the rest
     * of that first request and another from the second onwards.
     */
    public function role()
    {
        if (!$this->account) { return ''; }
        return ($this->account['role'] === 'admin') ? 'admin' : 'basic';
    }
}

class LoginAttempt
{
    /** ADR-0001: five failures inside the window, and the window is the lockout. */
    const MAX_ATTEMPTS   = 5;
    const WINDOW_SECONDS = 900;

    const INCOMPLETE_MESSAGE = 'Please enter both your username and password.';

    /** The one sentence for an unknown username and for a wrong password alike. */
    const REFUSED_MESSAGE = 'Incorrect username or password.';

    const SUSPENDED_MESSAGE = 'Your account has been deactivated. Please contact your manager.';

    /**
     * Deliberately not the suspended sentence. Closing is permanent (invariant
     * 14) — telling a retired employee to ask a manager to switch the account
     * back on sends them to ask for the one thing this app will not do.
     */
    const CLOSED_MESSAGE = 'This account has been closed and cannot be used again. '
                         . 'If you still work here, ask an admin to set you up a new one.';

    private $accounts;

    public function __construct(AccountStore $accounts)
    {
        $this->accounts = $accounts;
    }

    /**
     * Decide one sign-in.
     *
     * `$now` is a Unix timestamp, taken as a parameter so the lockout's clock can
     * be moved in a test — there is no other way to reach "fifteen minutes later"
     * in a suite that runs in under a second.
     */
    public function attempt($username, $password, $now = null)
    {
        $now      = ($now === null) ? time() : intval($now);
        $username = trim((string)$username);
        $password = (string)$password;

        if ($username === '' || $password === '') {
            return LoginOutcome::refused(LoginOutcome::INCOMPLETE, self::INCOMPLETE_MESSAGE);
        }

        $account = $this->accounts->findForSignIn($username);
        if (!$account) {
            // No such account — and also what a database that cannot be read
            // answers. Both are the same sentence as a wrong password, and both
            // write nothing: only a real account accrues failures (ADR-0001).
            return LoginOutcome::refused(LoginOutcome::REFUSED, self::REFUSED_MESSAGE);
        }
        $accountId = intval($account['id']);

        // ---- Everything the password has no part in, settled first -----------
        //
        // Nothing above this point and nothing in the three branches below reads
        // `$password`. That is the property worth protecting when this code is
        // changed: a state check moved below `password_verify()` puts the oracle
        // straight back, and it will look like a tidy-up.

        // Closed is asked before suspended because closing clears `is_active` as
        // well (lib/accounts.php), so every closed account is also a suspended
        // one and the other order would never reach this branch at all.
        if ($this->accounts->isClosed($accountId)) {
            return LoginOutcome::refused(LoginOutcome::CLOSED, self::CLOSED_MESSAGE);
        }

        if (intval($account['is_active']) !== 1) {
            return LoginOutcome::refused(LoginOutcome::SUSPENDED, self::SUSPENDED_MESSAGE);
        }

        // Both of these are ahead of the lockout on purpose: "please wait 15
        // minutes" is advice that comes true, and for an account nobody can ever
        // sign in to it would not.
        $lockedUntil = $this->stamp($account, 'locked_until');
        if ($lockedUntil !== null && $lockedUntil > $now) {
            return LoginOutcome::refused(LoginOutcome::LOCKED, self::lockedMessage($lockedUntil - $now));
        }

        // ---- And only now the password ---------------------------------------
        if (!password_verify($password, (string)$account['password_hash'])) {
            return $this->countFailure($account, $now);
        }

        // The one recovery path that is not the reset page. Its failure is
        // swallowed inside the store, because a sign-in that worked must not be
        // turned into a refusal by a counter that could not be cleared.
        $this->accounts->clearLoginLockout($accountId);
        return LoginOutcome::ok($account);
    }

    // ---- Internals ----------------------------------------------------------

    /**
     * Record the failure and say which refusal it was.
     *
     * The write's own success is not consulted. On a database where the runtime
     * `ALTER` never applied there is nowhere to count, and signing in without a
     * brute-force counter beats nobody signing in at all — but a *different*
     * sentence for "we could not count that" would be one more thing the guesser
     * could read.
     */
    private function countFailure(array $account, $now)
    {
        $lockedUntil = $this->stamp($account, 'locked_until');
        $lastFailed  = $this->stamp($account, 'last_failed_at');

        // A fresh five if the last lockout has run out, or if the last failure is
        // older than the window. One window governs both (ADR-0001).
        $lockoutExpired = $lockedUntil !== null && $lockedUntil <= $now;
        $agedOut        = $lastFailed  !== null && ($now - $lastFailed) > self::WINDOW_SECONDS;
        $attempts       = (($lockoutExpired || $agedOut) ? 0 : intval($account['failed_attempts'])) + 1;

        $stamp = date('Y-m-d H:i:s', $now);

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->accounts->registerFailedLogin(
                intval($account['id']), $attempts, $stamp, date('Y-m-d H:i:s', $now + self::WINDOW_SECONDS));
            return LoginOutcome::refused(LoginOutcome::LOCKED, self::lockedMessage(self::WINDOW_SECONDS));
        }

        $this->accounts->registerFailedLogin(intval($account['id']), $attempts, $stamp, null);
        return LoginOutcome::refused(LoginOutcome::REFUSED, self::REFUSED_MESSAGE);
    }

    /** A stored datetime as a timestamp, or null when there is nothing readable. */
    private function stamp(array $account, $key)
    {
        if (!isset($account[$key]) || $account[$key] === null || $account[$key] === '') { return null; }
        $when = strtotime((string)$account[$key]);
        return ($when === false) ? null : $when;
    }

    /** Rounded up, and never "0 minute(s)" for the last few seconds of a lockout. */
    private static function lockedMessage($seconds)
    {
        $minutes = (int)ceil(max(1, intval($seconds)) / 60);
        return 'Too many failed attempts. Please wait ' . $minutes . ' minute(s) before trying again.';
    }
}
