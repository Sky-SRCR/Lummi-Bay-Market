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
 * Not a gatekeeper for all of `users` — creating an account and setting somebody's
 * password from the admin panel are still written there, and sign-in is still
 * `login.php`'s. What lives here is the closure concept and the reads that depend on
 * it, so that the five files which have an opinion about a user row cannot disagree
 * about what a closed one means — plus the three writes that have to happen inside
 * somebody else's transaction, because a page cannot hold a transaction over SQL it
 * writes itself:
 *
 *   setPassword, clearLoginLockout — a password reset (PasswordResetCompletion)
 *   updateProfile                  — editing an account (AccountAdmin), where a
 *                                    change of role also changes what grants mean
 *
 * Those three are the only methods here that let an exception out. Everything else
 * answers a question, and a question is better answered "no" than not at all.
 */
class AccountStore
{
    private $pdo;

    /** Cached per request: every reader below asks, and the answer cannot change. */
    private $hasColumn = null;

    /** Likewise for the login-lockout columns, which are added at runtime too. */
    private $hasLockoutColumns = null;

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

    /**
     * The addresses an automatic alert goes to: admins who can still sign in and
     * have somewhere to be written to.
     *
     * Read here, but used when the database is unreachable — which is why the
     * caller caches the answer to disk rather than asking at the moment of
     * failure. See lib/alerts.php.
     */
    public function adminEmails()
    {
        $out = [];
        try {
            $sql = "SELECT email FROM users
                     WHERE role = 'admin' AND is_active = 1
                       AND email IS NOT NULL AND email <> ''"
                 . ($this->columnExists() ? " AND closed_at IS NULL" : "");
            foreach ($this->pdo->query($sql)->fetchAll() as $row) {
                $out[] = $row['email'];
            }
        } catch (Throwable $e) {
            // No list is a real answer: the alerter sends nothing rather than
            // guessing an address.
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

    /**
     * Store a new password hash for one account. True if the row is now holding it.
     *
     * **This one lets its exception out**, alone among the writes here, because its
     * caller holds a transaction that has to roll back rather than carry on — see
     * PasswordResetCompletion in lib/password_resets.php. Everything else in this
     * class answers a question, and a question is better answered "no" than not at
     * all; this one is half of a change that must not half-happen.
     *
     * The hash is the caller's to make. Nothing here ever sees a plain password.
     */
    public function setPassword($accountId, $passwordHash)
    {
        $accountId = intval($accountId);

        // Asked before writing rather than inferred afterwards from `rowCount()`,
        // because that number does not mean the same thing on both engines: MySQL
        // reports rows it *changed*, so a hash identical to the stored one comes
        // back as zero and reads exactly like "no such account". Reporting a reset
        // as failed when it succeeded is the defect this method is part of closing,
        // and a number that cannot tell those apart is no basis for the answer. The
        // caller holds a transaction, so nothing can remove the row in between.
        if (!$this->rowExists($accountId)) { return false; }

        $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                  ->execute([(string)$passwordHash, $accountId]);
        return true;
    }

    /**
     * Change what an admin may change about an account: its role, whether it may
     * sign in, and its email address.
     *
     * **Lets its exceptions out**, like setPassword() and for the same reason: the
     * caller holds a transaction that also clears the account's grants, and a
     * duplicate email has to abandon the whole change rather than leave the role
     * moved and the grants gone. AccountAdmin turns the throw into a sentence.
     *
     * The role is the one field with reach beyond this row — an admin holds every
     * Display by role (ADR-0005) — which is why this is not a method anybody may
     * call on its own from a page.
     *
     * @return bool false when the id names no account
     */
    public function updateProfile($accountId, $role, $isActive, $email)
    {
        $accountId = intval($accountId);
        // Asked first for the same reason setPassword() does: an UPDATE that changes
        // nothing because the values already match reports zero rows on MySQL, which
        // is indistinguishable from an id that does not exist.
        if (!$this->rowExists($accountId)) { return false; }

        $this->pdo->prepare("UPDATE users SET role = ?, is_active = ?, email = ? WHERE id = ?")
                  ->execute([
                      $role === 'admin' ? 'admin' : 'basic',
                      $isActive ? 1 : 0,
                      (string)$email,
                      $accountId,
                  ]);
        return true;
    }

    /** The role this account holds now — what a change of role is measured against. */
    public function roleOf($accountId)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([intval($accountId)]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            return '';
        }
        if (!$row) { return ''; }
        return $row['role'] === 'admin' ? 'admin' : 'basic';
    }

    /**
     * Wipe the login lockout for one account: a completed reset and a successful
     * sign-in are the two recovery paths (ADR-0001).
     *
     * **True when the three columns do not exist**, for the same reason isClosed()
     * answers false: a database without them has never locked anybody out, so there
     * is nothing to clear and nothing has gone wrong. The version this replaces
     * assumed the columns and threw "unknown column" if the runtime ALTER had never
     * applied — at the end of a successful sign-in, and in the middle of a password
     * reset, which are the two worst moments in the app to raise an exception.
     */
    public function clearLoginLockout($accountId)
    {
        if (!$this->lockoutColumnsExist()) { return true; }
        $this->pdo->prepare(
            "UPDATE users SET failed_attempts = 0, last_failed_at = NULL, locked_until = NULL
              WHERE id = ?"
        )->execute([intval($accountId)]);
        return true;
    }

    /**
     * Write down one failed sign-in: the counter, when it happened, and the lockout
     * it may have tripped. `LoginGate` decides all three; this only stores them.
     *
     * **False when the three columns do not exist**, the mirror of clearLoginLockout()
     * and for the same reason: a database where the runtime ALTER never applied has
     * nowhere to count. login.php used to write this itself, unguarded, and the file's
     * own comment claimed the write "swallows its failures the same way" as its
     * neighbours — it did not. On such a database every *wrong password* raised
     * "unknown column" instead of printing "Incorrect username or password", which is
     * a 500 on the one page that exists to be typed into by strangers.
     */
    public function recordLoginFailure($accountId, $attempts, $failedAt, $lockedUntil)
    {
        if (!$this->lockoutColumnsExist()) { return false; }
        try {
            $this->pdo->prepare(
                "UPDATE users SET failed_attempts = ?, last_failed_at = ?, locked_until = ?
                  WHERE id = ?"
            )->execute([
                intval($attempts),
                (string)$failedAt,
                $lockedUntil === null ? null : (string)$lockedUntil,
                intval($accountId),
            ]);
        } catch (Throwable $e) {
            return false;
        }
        return true;
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

    /** Same question, same technique, about the three columns ADR-0001 added. */
    private function lockoutColumnsExist()
    {
        if ($this->hasLockoutColumns !== null) { return $this->hasLockoutColumns; }
        try {
            $this->pdo->query("SELECT failed_attempts, last_failed_at, locked_until FROM users LIMIT 0");
            $this->hasLockoutColumns = true;
        } catch (Throwable $e) {
            $this->hasLockoutColumns = false;
        }
        return $this->hasLockoutColumns;
    }

    /** Does this account number exist at all? The two writes that must not guess ask. */
    private function rowExists($accountId)
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([intval($accountId)]);
        return $stmt->fetchColumn() !== false;
    }
}

/**
 * The two things an admin does to an account, each of which spans three tables.
 *
 *   close(id, actor)                   → the account is retired for good
 *   edit(id, role, active, email, actor) → role, sign-in, email
 *
 * Neither is useful in halves. Closing writes `closed_at`, revokes the grants and
 * frees the edit lock, and an account marked closed that still holds a grant is
 * exactly the stale pointer that change exists to prevent. Editing writes the role
 * and then has to make the other two tables agree with it: a promotion leaves grant
 * rows nothing will ever show again, and a demotion leaves a lock the account can no
 * longer reach to release.
 *
 * So this holds the transaction, the way DisplayAdmin does for a Display, and writes
 * no SQL of its own.
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

    /**
     * Edit one account: its role, whether it may sign in, and its email.
     *
     * Three writes, one transaction, because a change of role changes what the other
     * two tables mean:
     *
     * **Promoting to admin clears the individual grants.** An admin holds every
     * Display by role (ADR-0005), so grants stop being shown for that account — and
     * the rows stayed behind, invisible on the one screen that administers them and
     * therefore impossible to take away. Demote the person a month later and the
     * access they had in March came back, silently, decided by a table nobody could
     * see. Clearing them makes the matrix the whole truth about who was given what.
     *
     * **Demoting to basic, or suspending, frees the edit lock.** A demoted account
     * keeps no grants — there were none to keep, by the rule above — so from the next
     * request it may open nothing, which includes the Display it is holding open right
     * now. A suspended one cannot sign in at all. Neither could release that lock even
     * deliberately: releasing goes through the same seam that now refuses it. The sign
     * would sit locked for a full idle window under a name that can no longer reach it.
     *
     * The two are one condition on purpose — "does this account end up unable to reach
     * what it is holding" — rather than two branches that have to be kept in step. A
     * lock outliving its holder's access is also caught on read, by
     * LockState::isHeld: this frees it at the moment it happens, that covers the paths
     * nobody enumerated.
     *
     * @param int    $accountId       the account being edited
     * @param string $role            'admin' or 'basic'
     * @param bool   $isActive        may it sign in
     * @param string $email           validated by the caller — this checks uniqueness
     *                                only in the sense that the database does
     * @param int    $actingAccountId the admin doing it, who may not demote themselves
     */
    public function edit($accountId, $role, $isActive, $email, $actingAccountId)
    {
        $accountId = intval($accountId);
        $role      = ($role === 'admin') ? 'admin' : 'basic';
        $isActive  = (bool)$isActive;

        if ($accountId <= 0) {
            return AccountResult::failed('No account was named.');
        }
        // The oldest guard on this form, and still the important one: an admin who
        // demotes or deactivates themselves is locked out of the screen that would
        // undo it, and at a one-admin store nobody can undo it at all.
        if ($accountId === intval($actingAccountId) && ($role !== 'admin' || !$isActive)) {
            return AccountResult::failed('You cannot demote or deactivate your own account.');
        }

        $was = $this->accounts->roleOf($accountId);
        if ($was === '') {
            return AccountResult::failed('That account no longer exists.');
        }
        // Closing is permanent (invariant 14), and this form is the one place that
        // could look like an undo of it: `is_active` back to 1 on a closed account.
        // Only reachable by a hand-built POST, because the panel renders no edit form
        // for a closed account — and sign-in would still refuse it, because it asks
        // about `closed_at` before `is_active`. Refused here anyway rather than left
        // resting on the order of two checks in another file.
        if ($this->accounts->isClosed($accountId)) {
            return AccountResult::failed(
                'That account is closed, and closing cannot be undone. Nothing was changed.');
        }
        $promoted = ($was !== 'admin' && $role === 'admin');
        $demoted  = ($was === 'admin' && $role !== 'admin');

        $clearedGrants = 0;
        $freedLocks    = 0;

        try {
            $this->pdo->beginTransaction();

            if (!$this->accounts->updateProfile($accountId, $role, $isActive, $email)) {
                $this->pdo->rollBack();
                return AccountResult::failed('That account no longer exists.');
            }
            if ($promoted) {
                $clearedGrants = $this->grants->revokeAllForAccount($accountId);
            }
            // Demotion and suspension are the same fact from two directions: this
            // account can no longer reach what it is holding. A demoted admin holds no
            // grants and so holds no Displays; a suspended account cannot sign in at
            // all. Either way its locks are leftovers, and it cannot release them
            // itself — releasing goes through the seam that has just started refusing
            // it. Asked as "does it end up inactive" rather than "was it just
            // deactivated", because an account that was already inactive holds no
            // locks and frees nothing.
            if ($demoted || !$isActive) {
                $freedLocks = $this->displays->releaseLocksHeldBy($accountId);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->rollBackQuietly();
            // The one expected failure is an email another account already holds. It
            // is reported as the whole change failing because that is what happened:
            // nothing was written, including the role.
            return AccountResult::failed(
                'That account could not be updated — the email address may already be in use. '
                . 'Nothing was changed.');
        }

        $note = '';
        if ($promoted) {
            $note = ' They now hold every display by role, so the individual displays they had been '
                  . 'given were cleared'
                  . ($clearedGrants > 0 ? ' (' . $clearedGrants . ')' : '')
                  . ' — if you make them a basic user again you will need to give those back.';
        } elseif ($demoted) {
            $note = ' They hold no displays now: assign the ones they should have in "Who can edit '
                  . 'which display" below.';
        }
        if (!$isActive) {
            $note .= ' They cannot sign in until "Active" is ticked again.';
        }
        // One sentence for both reasons, because it is one fact: what they were holding
        // is free, and their own page is about to say so. Counted rather than assumed —
        // "a display was released" and "nobody had one open" are different things for an
        // admin to have just done, and only the row knows which.
        if ($freedLocks > 0) {
            $note .= ' ' . $freedLocks . ($freedLocks === 1
                        ? ' display they had open has been released'
                        : ' displays they had open have been released')
                   . ', and their builder says so within a minute. Nothing they had not published'
                   . ' reached a screen.';
        }
        return AccountResult::ok('User updated.' . $note);
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
