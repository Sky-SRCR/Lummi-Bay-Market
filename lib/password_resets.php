<?php
// ============================================================
// PASSWORD RESET TOKENS
// ============================================================
// One emailed 6-digit passcode per account, and the guess budget that protects
// it. Every statement against `password_resets` lives here.
//
// The rule this module exists to enforce: **a live passcode may be guessed five
// times in total, ever — not five times per browser.** The limiter it replaces
// counted in `$_SESSION`, which is state the guesser owns. Clearing a cookie
// bought five more guesses, so forty cookie jars bought two hundred, all of them
// tested against the one live code; a six-digit code falls to that in an evening.
// This is the same defect ADR-0001 rewrote the login lockout to avoid, still
// standing in the other door — so the budget is counted where the passcode is,
// on the token row, and nothing the browser sends can reset it.
//
// The budget is spent by the UPDATE that increments it, before the passcode is
// even looked at. That ordering is deliberate: read-then-write would let two
// simultaneous guesses both see "four spent" and both spend the fifth.
//
// One thing this module deliberately does not do is say *why* it refused. A
// caller cannot distinguish "wrong code" from "no such account" from "budget
// exhausted", because the reset page must answer all three identically — the
// moment those responses differ, the page tells a stranger which usernames are
// real. Hence a plain boolean.
//
// The end of the flow — code consumed, password changed, lockout released — lives
// in PasswordResetCompletion at the bottom of this file. It is here rather than on
// the page because those writes have to be one thing that either happens or does
// not, and a page cannot hold a transaction over SQL it writes itself.

require_once __DIR__ . '/accounts.php';

class ResetTokenStore
{
    /** Guesses allowed against one issued passcode, across every browser. */
    const MAX_GUESSES = 5;

    /** How long an issued passcode stays usable, in seconds. */
    const LIFETIME = 1800;   // 30 minutes

    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Add the guess counter to a database that predates it.
     *
     * Called from reset_password.php and nowhere else, the same way the login
     * lockout columns are added by the pre-auth pages (ADR-0001): the public
     * Viewer poll must never run a migration, and this table is only ever
     * touched by someone standing at the reset form.
     *
     * A live database that already has the column fails this statement
     * harmlessly, which is the whole convention (lib/schema.php).
     */
    public function ensureSchema()
    {
        try {
            $this->pdo->exec("ALTER TABLE password_resets ADD COLUMN attempts INT NOT NULL DEFAULT 0");
        } catch (Throwable $e) {
            // Already applied. Nothing else here can be safely repaired at
            // runtime — a missing table means the install never ran schema.sql.
        }
    }

    /**
     * Issue a fresh passcode for one account and return it, or '' if the write
     * failed. Any earlier code for that account stops working immediately: two
     * live codes would be two live guess budgets.
     *
     * The caller emails it. This module never sees an address.
     */
    public function issue($accountId)
    {
        $accountId = intval($accountId);
        $passcode  = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        try {
            $this->discard($accountId);
            $this->pdo->prepare(
                "INSERT INTO password_resets (user_id, passcode, expires_at, attempts)
                 VALUES (?, ?, ?, 0)"
            )->execute([$accountId, $passcode, gmdate('Y-m-d H:i:s', time() + self::LIFETIME)]);
        } catch (Throwable $e) {
            return '';
        }
        return $passcode;
    }

    /** Destroy every reset token for one account. Safe to call for an id of 0. */
    public function discard($accountId)
    {
        try {
            $this->pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")
                      ->execute([intval($accountId)]);
        } catch (Throwable $e) {
            // Nothing a caller can do about it, and reporting the difference
            // would leak whether the account had a token in the first place.
        }
    }

    /**
     * Spend one guess and consume the token if the passcode was right.
     *
     * Kept as the one-call form for anything that only needs yes or no. The reset
     * page needs the two halves apart, because they have to land on opposite sides
     * of a transaction boundary — see verify() and PasswordResetCompletion.
     */
    public function redeem($accountId, $passcode)
    {
        $tokenId = $this->verify($accountId, $passcode);
        if ($tokenId <= 0) { return false; }
        try {
            return $this->consume($tokenId);
        } catch (Throwable $e) {
            // consume() reports to a caller holding a transaction; this form has
            // promised a plain boolean since it was the only form there was.
            return false;
        }
    }

    /**
     * Spend one guess and say which token the passcode matched, or 0.
     *
     * **Never call this inside a transaction.** The guess it spends has to outlive
     * a rollback: if the caller's transaction fails and takes the increment with it,
     * the five-guess budget resets on every failed write and the limiter this module
     * exists to be is gone. That is why the reset flow spends the guess out here,
     * before it opens the transaction that changes the password.
     *
     * 0 covers every refusal on purpose — see the header. It also covers an account
     * id of 0, which is what the reset page holds after someone typed a username
     * that matches nobody: there is no token, so the guess finds nothing, and the
     * page says exactly what it says to a real account.
     */
    public function verify($accountId, $passcode)
    {
        $accountId = intval($accountId);
        $now       = gmdate('Y-m-d H:i:s');

        try {
            // Spend first, decide second. An UPDATE takes the row's write lock,
            // so of two simultaneous guesses one waits for the other and the
            // budget is spent twice — where a SELECT … then UPDATE would have
            // let both read the same remaining count and both proceed.
            //
            // Unqualified by design: issue() leaves at most one live row per
            // account, and if a hand edit ever left two, spending a guess on
            // both is the direction that fails safe.
            $spend = $this->pdo->prepare(
                "UPDATE password_resets
                    SET attempts = attempts + 1
                  WHERE user_id = ? AND used = 0 AND expires_at > ? AND attempts < " . self::MAX_GUESSES
            );
            $spend->execute([$accountId, $now]);

            if ($spend->rowCount() < 1) {
                // No live token with budget left: never issued, expired, already
                // used, or guessed out. Nothing here is worth keeping.
                $this->discard($accountId);
                return 0;
            }

            $stmt = $this->pdo->prepare(
                "SELECT id, passcode, attempts FROM password_resets
                  WHERE user_id = ? AND used = 0 AND expires_at > ?
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$accountId, $now]);
            $row = $stmt->fetch();
            if (!$row) { return 0; }

            // Length-independent, so the comparison cannot be timed a digit at a
            // time. Both sides are cast: a passcode arrives from a form as a
            // string but a stored CHAR(6) can come back as one too, and
            // hash_equals() refuses anything else outright.
            if (!hash_equals((string)$row['passcode'], (string)$passcode)) {
                if (intval($row['attempts']) >= self::MAX_GUESSES) {
                    $this->discard($accountId);
                }
                return 0;
            }

            return intval($row['id']);
        } catch (Throwable $e) {
            // A database this broken cannot be reasoned about, and a reset is
            // the one flow where guessing in the caller's favour hands over an
            // account. Refuse.
            return 0;
        }
    }

    /**
     * Spend the token itself, so it can never be presented twice.
     *
     * Guarded on `used = 0` and answering how many rows that moved, which is what
     * makes two browsers holding the same correct code safe: exactly one of them
     * gets `true` and may go on to change the password.
     *
     * **This one lets its exception out**, unlike everything else here, because its
     * caller runs it inside the transaction that changes the password — and a
     * consume that failed silently would leave a code alive next to a password that
     * had already been changed with it.
     */
    public function consume($tokenId)
    {
        $stmt = $this->pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
        $stmt->execute([intval($tokenId)]);
        return $stmt->rowCount() === 1;
    }

    /** Is this token still unspent? Only PasswordResetCompletion's tests ask. */
    public function isSpent($tokenId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT used FROM password_resets WHERE id = ? LIMIT 1");
            $stmt->execute([intval($tokenId)]);
            $row = $stmt->fetch();
            return $row ? intval($row['used']) === 1 : true;
        } catch (Throwable $e) {
            return true;
        }
    }

    /** Guesses already spent against this account's live token. Tests and tools. */
    public function attemptsSpent($accountId)
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT attempts FROM password_resets
                  WHERE user_id = ? AND used = 0 AND expires_at > ?
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([intval($accountId), gmdate('Y-m-d H:i:s')]);
            $n = $stmt->fetchColumn();
            return ($n === false || $n === null) ? 0 : intval($n);
        } catch (Throwable $e) {
            return 0;
        }
    }
}

/**
 * What happened at the end of a reset. Three answers, not two, because two of them
 * must look identical to the visitor and the third must not.
 *
 *   refused — wrong code, expired code, no such account, guesses gone. One
 *             sentence covers all four, or the page tells a stranger which
 *             usernames are real.
 *   failed  — the code was right and the database would not take the change.
 *             Nothing was written. Saying "that code is incorrect" here would send
 *             somebody round the same loop with a code that was never the problem.
 */
class ResetOutcome
{
    private $kind;

    private function __construct($kind) { $this->kind = $kind; }

    public static function ok()      { return new self('ok'); }
    public static function refused() { return new self('refused'); }
    public static function failed()  { return new self('failed'); }

    public function isOk()      { return $this->kind === 'ok'; }
    public function isRefused() { return $this->kind === 'refused'; }
    public function kind()      { return $this->kind; }
}

/**
 * The last step of a password reset, as one thing that either happens or does not.
 *
 * It used to be three writes in a row on a page, with nothing tying them together:
 * consume the code, change the password, clear the login lockout. Any failure after
 * the first left the code spent and the password unchanged — and the visitor, told
 * their reset had failed, would request another code and never learn that the first
 * one had been used up doing nothing. The lockout clear was worse: it ran a runtime
 * `ALTER TABLE` and then assumed the columns, so on a database where that ALTER had
 * never applied it threw *after* the password had already changed. The person was
 * told the reset failed while holding a password that worked.
 *
 * Two rules decide the shape, and they pull in opposite directions:
 *
 *   - **The guess must survive a rollback.** Spending one of the five tries is not
 *     part of the change being made; it is the price of having asked. So verify()
 *     is called before the transaction opens. Inside it, a failed write would undo
 *     the increment and hand the guesser their budget back, five at a time, forever.
 *   - **The consume must not.** Marking the code used and changing the password are
 *     one act. Either both stand or neither does.
 *
 * And one that follows from invariant 21: no schema work in here. DDL commits an
 * open transaction in MySQL without saying so, which would commit half of this and
 * then report that it failed. The column this table needed is added at the top of
 * the entry point, before anything opens a transaction; the lockout columns are not
 * added at all any more — AccountStore::clearLoginLockout() copes with their absence
 * instead, which is what they needed all along.
 *
 * Writes no SQL of its own. The transaction is the only thing it owns.
 */
class PasswordResetCompletion
{
    private $pdo;
    private $tokens;
    private $accounts;

    public function __construct(PDO $pdo, ResetTokenStore $tokens, AccountStore $accounts)
    {
        $this->pdo      = $pdo;
        $this->tokens   = $tokens;
        $this->accounts = $accounts;
    }

    /**
     * Redeem a passcode and set a new password, or change nothing at all.
     *
     * Takes the password in the clear and hashes it here, so that "a plain password
     * never reaches a column" is a property of this module rather than a habit of
     * whichever page calls it. Length and confirmation are the page's business.
     */
    public function complete($accountId, $passcode, $newPassword)
    {
        $accountId = intval($accountId);

        // Nothing here can be trusted inside somebody else's transaction: the guess
        // would be rolled back with it, and the rollback below would end a
        // transaction this method did not start. The reset page holds none — this is
        // for whoever calls it next.
        if ($this->pdo->inTransaction()) { return ResetOutcome::failed(); }

        // Before the transaction, deliberately — see the class note. An account id
        // of 0 (a username that matches nobody) finds no token and is refused here,
        // in the same words as a wrong code.
        $tokenId = $this->tokens->verify($accountId, $passcode);
        if ($tokenId <= 0) { return ResetOutcome::refused(); }

        if ($accountId <= 0 || (string)$newPassword === '') {
            // A token cannot exist for account 0, so this is unreachable by the
            // reset page; it is here so that a future caller cannot blank a
            // password with a valid code.
            return ResetOutcome::failed();
        }

        try {
            $this->pdo->beginTransaction();

            // The code first: if two browsers hold the same correct passcode, this
            // is what makes exactly one of them the winner, and the loser must not
            // have changed a password on the way to finding out.
            if (!$this->tokens->consume($tokenId)) {
                $this->pdo->rollBack();
                return ResetOutcome::refused();
            }

            if (!$this->accounts->setPassword($accountId, password_hash((string)$newPassword, PASSWORD_DEFAULT))) {
                $this->pdo->rollBack();
                return ResetOutcome::failed();
            }

            // A completed reset is a recovery path, so it releases any login
            // lockout — and answers true on a database that has no lockout columns,
            // where there was never anything to release.
            $this->accounts->clearLoginLockout($accountId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            return ResetOutcome::failed();
        }

        return ResetOutcome::ok();
    }

    /** A rollback that throws would replace the real failure with a confusing one. */
    private function rollBackQuietly()
    {
        try {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        } catch (Throwable $e) {}
    }
}
