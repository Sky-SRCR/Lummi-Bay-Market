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
     * Spend one guess. True only if the passcode was right, in which case the
     * token is consumed and the caller may change the password.
     *
     * False covers every other case on purpose — see the header. It also covers
     * an account id of 0, which is what the reset page holds after someone typed
     * a username that matches nobody: there is no token, so the guess finds
     * nothing, and the page says exactly what it says to a real account.
     */
    public function redeem($accountId, $passcode)
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
                return false;
            }

            $stmt = $this->pdo->prepare(
                "SELECT id, passcode, attempts FROM password_resets
                  WHERE user_id = ? AND used = 0 AND expires_at > ?
                  ORDER BY id DESC LIMIT 1"
            );
            $stmt->execute([$accountId, $now]);
            $row = $stmt->fetch();
            if (!$row) { return false; }

            // Length-independent, so the comparison cannot be timed a digit at a
            // time. Both sides are cast: a passcode arrives from a form as a
            // string but a stored CHAR(6) can come back as one too, and
            // hash_equals() refuses anything else outright.
            if (!hash_equals((string)$row['passcode'], (string)$passcode)) {
                if (intval($row['attempts']) >= self::MAX_GUESSES) {
                    $this->discard($accountId);
                }
                return false;
            }

            // Right code. Consume it in the same breath, guarded on `used = 0`,
            // so two browsers submitting the same correct code cannot both be
            // told to go ahead and change the password.
            $consume = $this->pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ? AND used = 0");
            $consume->execute([intval($row['id'])]);
            return $consume->rowCount() === 1;
        } catch (Throwable $e) {
            // A database this broken cannot be reasoned about, and a reset is
            // the one flow where guessing in the caller's favour hands over an
            // account. Refuse.
            return false;
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
