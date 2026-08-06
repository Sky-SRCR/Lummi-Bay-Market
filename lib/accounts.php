<?php
// ============================================================
// ACCOUNTS — closing one, and never reusing its number
// ============================================================
// Deleting a user used to mean `DELETE FROM users`. The row went, and with it the
// only thing that made its id number meaningful — so MySQL was free to hand that
// same number to the next account created. Anything still pointing at it would
// then be pointing at a different person: a grant row that outlived its cascade, a
// held edit lock, a publish record, a session on a machine in the back office.
// Each of those was defended one at a time, by remembering to. This removes the
// thing they were defending against.
//
// An account is **closed** instead. The row stays, permanently:
//
//   - it cannot sign in, and an open session on it ends on the next request;
//   - it holds no grants and no edit lock — both are surrendered on closing;
//   - it is out of the user list and out of the grant matrix;
//   - its username and email stay taken, so a former employee's name can never be
//     re-registered by somebody else and quietly inherit their history;
//   - "published by Kayla" still says Kayla.
//
// There is no reopening. That is the point of the decision, not an omission: a
// number that can come back into service is a number that can be reused, and this
// app has no undo anywhere to catch the case where it comes back wrong.
//
// `closed_at` is the whole mechanism, and it is deliberately not `is_active`.
// Inactive is a manager suspending somebody for a fortnight and un-suspending them
// after; closed is final. Collapsing the two would make "reactivate" the button
// that undoes the thing that must not be undone.

require_once __DIR__ . '/grants.php';
require_once __DIR__ . '/displays.php';

class AccountResult
{
    private $ok;
    private $message;

    private function __construct($ok, $message)
    {
        $this->ok      = $ok;
        $this->message = $message;
    }

    public static function ok($message)     { return new self(true,  $message); }
    public static function failed($message) { return new self(false, $message); }

    public function isOk()    { return $this->ok; }
    public function message() { return $this->message; }
}

/**
 * Every statement about whether an account is closed.
 *
 * Not a gatekeeper for all of `users` — creating accounts, changing a role and
 * resetting a password are still written by `admin_panel.php`, and sign-in is
 * still `login.php`'s. What lives here is the closure concept and the reads that
 * depend on it, so that the five files which have an opinion about a user row
 * cannot disagree about what a closed one means.
 */
class AccountStore
{
    private $pdo;

    /** Cached per request: every reader below asks, and the answer cannot change. */
    private $hasColumn = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Add `closed_at` to a database that predates it.
     *
     * Called from the authenticated admin panel, like the rest of convergence, and
     * from nowhere on the public path (invariant 7). Idempotent and silent, per
     * the pattern in lib/schema.php.
     */
    public function ensureSchema()
    {
        try {
            $this->pdo->exec("ALTER TABLE users ADD COLUMN closed_at DATETIME NULL DEFAULT NULL");
            $this->hasColumn = null;
        } catch (Throwable $e) {
            // Already applied, or cannot be. Either way every reader here copes.
        }
    }

    /**
     * Is this account closed?
     *
     * **False when the column does not exist**, and that is the correct answer
     * rather than a shrug: a database without the column has never closed an
     * account, because there was nowhere to record it. login.php reasons the same
     * way about the lockout columns — signing in without a counter that has
     * nothing to count beats nobody signing in at all.
     */
    public function isClosed($accountId)
    {
        if (!$this->columnExists()) { return false; }
        try {
            $stmt = $this->pdo->prepare("SELECT closed_at FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([intval($accountId)]);
            $row = $stmt->fetch();
            return $row ? ($row['closed_at'] !== null && $row['closed_at'] !== '') : false;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The accounts still in service — the user list, and the grant matrix. */
    public function open()
    {
        return $this->listWhere($this->columnExists() ? "closed_at IS NULL" : "1=1");
    }

    /**
     * The closed ones, newest first. Shown to an admin on purpose: without them on
     * screen, "username already exists" for a name nobody can see is a dead end
     * with no way out of it.
     */
    public function closed()
    {
        if (!$this->columnExists()) { return []; }
        return $this->listWhere("closed_at IS NOT NULL", "closed_at DESC");
    }

    /**
     * id => username for **every** account, closed included.
     *
     * The reason closing beats deleting, in one method: a publish record and a
     * held lock both name an account by id, and they must keep printing a name
     * after that person leaves.
     */
    public function names()
    {
        $out = [];
        try {
            foreach ($this->pdo->query("SELECT id, username FROM users")->fetchAll() as $row) {
                $out[intval($row['id'])] = $row['username'];
            }
        } catch (Throwable $e) {
            // Nothing to add; callers fall back to printing the id.
        }
        return $out;
    }

    /** How many admins can still sign in. The guard against closing the last one. */
    public function openAdminCount()
    {
        try {
            $sql = "SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1"
                 . ($this->columnExists() ? " AND closed_at IS NULL" : "");
            return intval($this->pdo->query($sql)->fetchColumn());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Mark one account closed and shut it out. Returns false if it wrote nothing —
     * an id that does not exist, or one already closed.
     */
    public function markClosed($accountId)
    {
        if (!$this->columnExists()) { return false; }
        try {
            // is_active goes to 0 as well, so every existing check that already
            // asks "may this account sign in" refuses a closed one even where it
            // has not been taught the new column.
            $stmt = $this->pdo->prepare(
                "UPDATE users SET closed_at = ?, is_active = 0
                  WHERE id = ? AND closed_at IS NULL"
            );
            $stmt->execute([gmdate('Y-m-d H:i:s'), intval($accountId)]);
            return $stmt->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** The account holding this username or email, closed or not, or null. */
    public function findByNameOrEmail($username, $email)
    {
        try {
            $stmt = $this->pdo->prepare(
                "SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1"
            );
            $stmt->execute([(string)$username, (string)$email]);
            $row = $stmt->fetch();
            return $row ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    // ---- Internals ----------------------------------------------------------

    private function listWhere($where, $order = "role DESC, username ASC")
    {
        try {
            return $this->pdo->query("SELECT * FROM users WHERE " . $where . " ORDER BY " . $order)
                             ->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Asked once per request. A `SELECT … LIMIT 0` rather than information_schema,
     * because this has to give the same answer on the SQLite fixture as on MySQL.
     */
    private function columnExists()
    {
        if ($this->hasColumn !== null) { return $this->hasColumn; }
        try {
            $this->pdo->query("SELECT closed_at FROM users LIMIT 0");
            $this->hasColumn = true;
        } catch (Throwable $e) {
            $this->hasColumn = false;
        }
        return $this->hasColumn;
    }
}

/**
 * Closing an account is three writes across three tables, and none of them is
 * useful without the others — an account marked closed that still holds a grant is
 * exactly the stale pointer this whole change exists to prevent.
 *
 * So this holds the transaction, the way DisplayAdmin does for a Display, and
 * writes no SQL of its own.
 */
class AccountAdmin
{
    private $pdo;
    private $accounts;
    private $grants;
    private $displays;

    public function __construct(PDO $pdo, AccountStore $accounts, GrantStore $grants, DisplayStore $displays)
    {
        $this->pdo      = $pdo;
        $this->accounts = $accounts;
        $this->grants   = $grants;
        $this->displays = $displays;
    }

    /**
     * Close one account, on behalf of the admin doing it.
     *
     * Refuses three things, in this order, because each one is worse than the next
     * to discover afterwards: closing yourself, closing the last admin who can
     * still sign in, and closing something that is not an open account.
     */
    public function close($accountId, $actingAccountId)
    {
        $accountId = intval($accountId);

        if ($accountId === intval($actingAccountId)) {
            return AccountResult::failed('You cannot close your own account.');
        }
        if ($accountId <= 0) {
            return AccountResult::failed('No account was named.');
        }
        if ($this->accounts->isClosed($accountId)) {
            return AccountResult::failed('That account is already closed.');
        }

        // The last admin standing. Closing cannot be undone, so getting this wrong
        // means nobody can administer the app again without a database console.
        $names = $this->accounts->names();
        $isLastAdmin = $this->isOpenAdmin($accountId) && $this->accounts->openAdminCount() <= 1;
        if ($isLastAdmin) {
            return AccountResult::failed(
                'That is the only admin who can still sign in. Make somebody else an admin first — '
                . 'closing an account cannot be undone.');
        }

        $name = isset($names[$accountId]) ? $names[$accountId] : ('account ' . $accountId);

        try {
            $this->pdo->beginTransaction();

            // Access first. A grant outliving its account is the pointer that used
            // to hand somebody else's sign to whoever inherited the id number.
            $this->grants->revokeAllForAccount($accountId);

            // And any Display they were holding, which would otherwise stay locked
            // for a full idle window under a name no banner would print.
            $this->displays->releaseLocksHeldBy($accountId);

            if (!$this->accounts->markClosed($accountId)) {
                $this->pdo->rollBack();
                return AccountResult::failed(
                    'That account could not be closed, so nothing was changed.');
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            return AccountResult::failed('That account could not be closed, so nothing was changed.');
        }

        return AccountResult::ok(
            '"' . $name . '" is closed. They can no longer sign in and hold no display access. '
            . 'The name stays reserved, and anything they published still says who published it.');
    }

    private function isOpenAdmin($accountId)
    {
        foreach ($this->accounts->open() as $row) {
            if (intval($row['id']) === intval($accountId)) {
                return $row['role'] === 'admin' && intval($row['is_active']) === 1;
            }
        }
        return false;
    }

    /** A rollback that throws would replace the real failure with a confusing one. */
    private function rollBackQuietly()
    {
        try {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
        } catch (Throwable $e) {}
    }
}
