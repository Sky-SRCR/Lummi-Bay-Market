<?php
// ============================================================
// GRANTS — who may edit which Display
// ============================================================
// A grant is permission for one account to edit one Display (CONTEXT.md). It is a
// single flag, not a level: what the account may do once inside that Display comes
// from its role, and publishing is part of editing (ADR-0005). Admins hold every
// Display without a grant.
//
// Two things live here and nowhere else:
//   GrantStore — every statement that touches `display_permissions`.
//   Actor      — who is asking, and which Displays they may reach. Built once per
//                request from the session's account plus that account's grants.
//
// `Actor` is where the two axes meet, and it is the reason there is exactly one
// answer to "may this account open that sign": the resolution seam that accepts a
// write and the picker that offers the choice ask the same object the same
// question, rather than each rebuilding the rule out of a role and a list. (The
// admin panel needs neither — it is admin-only, and administers grants rather than
// being subject to them.)
//
// Nothing here reads $_SESSION or $_GET — the adapter passes currentUser() in.

require_once __DIR__ . '/displays.php';

class GrantStore
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    // ---- Reads --------------------------------------------------------------

    /** The Display ids this account has been granted. Ints, ascending. */
    public function displayIdsFor($accountId)
    {
        $out = [];
        foreach ($this->pairs() as $pair) {
            if ($pair['user_id'] === intval($accountId)) { $out[] = $pair['display_id']; }
        }
        return $out;
    }

    /** accountId => [displayId, …] — the grant matrix, by row. */
    public function displayIdsByAccount()
    {
        $out = [];
        foreach ($this->pairs() as $pair) {
            $out[$pair['user_id']][] = $pair['display_id'];
        }
        return $out;
    }

    /** displayId => [accountId, …] — the same grants, for "who may edit this sign". */
    public function accountIdsByDisplay()
    {
        $out = [];
        foreach ($this->pairs() as $pair) {
            $out[$pair['display_id']][] = $pair['user_id'];
        }
        return $out;
    }

    // ---- Writes -------------------------------------------------------------
    // Called by DisplayAdmin, which holds the transaction. Each one is a single
    // statement so that a partial matrix save cannot leave a half-granted account.

    /**
     * Add a grant. Already granted is success, not an error: the caller asked for
     * a state, and re-inserting would only move the row's date for no reason.
     */
    public function grant($displayId, $accountId)
    {
        if ($this->exists($displayId, $accountId)) { return; }
        $this->pdo->prepare(
            "INSERT INTO display_permissions (display_id, user_id) VALUES (?, ?)"
        )->execute([intval($displayId), intval($accountId)]);
    }

    public function revoke($displayId, $accountId)
    {
        $this->pdo->prepare(
            "DELETE FROM display_permissions WHERE display_id = ? AND user_id = ?"
        )->execute([intval($displayId), intval($accountId)]);
    }

    /**
     * Every grant this account holds, gone.
     *
     * Called when the account is deleted. The `ON DELETE CASCADE` should do this,
     * but it is added by schemaTry() and may never have applied on a database
     * behind the repo — and a grant row left pointing at a deleted account is one
     * id reuse away from handing someone access nobody gave them.
     *
     * @return int grants removed
     */
    public function revokeAllForAccount($accountId)
    {
        $stmt = $this->pdo->prepare("DELETE FROM display_permissions WHERE user_id = ?");
        $stmt->execute([intval($accountId)]);
        return $stmt->rowCount();
    }

    /** Every grant on this Display, gone — it is being destroyed. @return int removed */
    public function revokeAllForDisplay(Display $display)
    {
        $stmt = $this->pdo->prepare("DELETE FROM display_permissions WHERE display_id = ?");
        $stmt->execute([$display->id()]);
        return $stmt->rowCount();
    }

    // ---- Internals ----------------------------------------------------------

    private function exists($displayId, $accountId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM display_permissions WHERE display_id = ? AND user_id = ? LIMIT 1"
        );
        $stmt->execute([intval($displayId), intval($accountId)]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Every grant, as [display_id, user_id] ints.
     *
     * There are a handful of accounts and a handful of Displays, so reading the
     * whole table and grouping it in PHP is cheaper than the queries it replaces —
     * and it means one code path is responsible for the shape of a grant.
     *
     * A read that fails yields no grants, which refuses access rather than
     * allowing it. That is the safe direction and it is deliberate: the table is
     * created by the same convergence run that every authenticated request makes,
     * so the only way here is a genuinely broken database, and a `basic` account
     * that cannot open a sign is a support call while the opposite is a silent
     * hole. Admins are unaffected — they never consult grants.
     */
    private function pairs()
    {
        try {
            $rows = $this->pdo
                ->query("SELECT display_id, user_id FROM display_permissions ORDER BY display_id ASC, user_id ASC")
                ->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = ['display_id' => intval($row['display_id']), 'user_id' => intval($row['user_id'])];
        }
        return $out;
    }
}

/**
 * The account behind a request, and what it may reach.
 *
 * Immutable, and cheap to pass around: the grants are read once when it is built.
 * Every "may they?" question in the app is a method here, so the seam that
 * enforces access and the picker that offers choices cannot disagree.
 */
class Actor
{
    /**
     * What an account holding no sign is told when it tries to write something
     * shared.
     *
     * One sentence in one place, because two doors refuse for this reason — the
     * Library's add form and the API's image upload — and one refusal met in two
     * wordings reads as two different problems. It names what did not happen and who
     * to ask, because there is nothing the person at the keyboard can do about it
     * themselves.
     */
    const NO_SIGN_REFUSAL = 'No display has been assigned to you yet, so there is no sign'
                          . ' for this to go on. Ask an admin which display is yours —'
                          . ' nothing was saved.';

    private $id;
    private $username;
    private $isAdmin;
    private $grantedIds;

    private function __construct($id, $username, $isAdmin, array $grantedIds)
    {
        $this->id         = intval($id);
        $this->username   = (string)$username;
        $this->isAdmin    = (bool)$isAdmin;
        $this->grantedIds = $grantedIds;
    }

    /**
     * The signed-in account, with its grants.
     *
     * @param array      $user   currentUser() — id, username, role
     * @param GrantStore $grants consulted only for a `basic` account: an admin
     *                           holds every Display by role (ADR-0005), so asking
     *                           would be a query whose answer cannot matter.
     */
    public static function signedIn(array $user, GrantStore $grants)
    {
        $isAdmin = isset($user['role']) && $user['role'] === 'admin';
        $id      = isset($user['id']) ? intval($user['id']) : 0;
        return new self(
            $id,
            isset($user['username']) ? $user['username'] : '',
            $isAdmin,
            $isAdmin ? [] : $grants->displayIdsFor($id)
        );
    }

    /** An actor with a known grant set — for the self-tests and for callers holding one already. */
    public static function withGrants($id, $username, $isAdmin, array $grantedDisplayIds)
    {
        $ids = [];
        foreach ($grantedDisplayIds as $displayId) { $ids[] = intval($displayId); }
        return new self($id, $username, $isAdmin, $ids);
    }

    public function id()       { return $this->id; }
    public function username() { return $this->username; }
    public function isAdmin()  { return $this->isAdmin; }

    /**
     * Is this Display theirs to edit at all? The grant axis on its own.
     *
     * Separate from mayOpen() so a refusal can say *why*: "not assigned to you" and
     * "turned off" send someone to different people for help.
     */
    public function mayEdit(Display $display)
    {
        if ($this->isAdmin) { return true; }
        return in_array($display->id(), $this->grantedIds, true);
    }

    /**
     * May they open this Display in the Builder right now?
     *
     * Both axes: the grant says whether the sign is theirs, and the role decides
     * whether a *retired* sign is workable — a Display out of service stays
     * editable by admins (CONTEXT.md) and is nobody else's job meanwhile.
     *
     * This is the single predicate. Anything that offers a Display, and anything
     * that accepts a write for one, asks this.
     */
    public function mayOpen(Display $display)
    {
        if (!$this->mayEdit($display)) { return false; }
        return $display->isActive() || $this->isAdmin;
    }

    /**
     * The subset of these Displays this account may open, in the order given.
     *
     * The Builder's picker is this list, and the editing entry rule is this list
     * having exactly one member — which is why they can never disagree about what
     * an account holds.
     *
     * @param Display[] $displays
     * @return Display[]
     */
    public function openable(array $displays)
    {
        $out = [];
        foreach ($displays as $display) {
            if ($this->mayOpen($display)) { $out[] = $display; }
        }
        return $out;
    }

    /**
     * The subset that is theirs by grant, retired or not.
     *
     * What openable() would return if nothing were turned off — the material for
     * telling "you have not been given a sign yet" apart from "your sign is off".
     *
     * @param Display[] $displays
     * @return Display[]
     */
    public function granted(array $displays)
    {
        $out = [];
        foreach ($displays as $display) {
            if ($this->mayEdit($display)) { $out[] = $display; }
        }
        return $out;
    }

    /**
     * Has a sign been assigned to this account at all? (#33)
     *
     * The question the shared writes ask — the Asset Library and the image upload —
     * because until the answer is yes, there is nothing an entry could be put on. A
     * `basic` account with no grant could fill the library every sign draws from, and
     * drop files into `uploads/`, having just been told by the Builder that there was
     * nothing here for it to edit.
     *
     * **The grant axis on its own, deliberately not `openable()`.** This is "is there
     * a sign this account is here to work on", which a Display switched off for the
     * afternoon does not change: somebody holding one retired sign cannot open it, and
     * can perfectly well be getting next week's promo into the library ready for it
     * coming back. Gating on `openable()` would also make turning a Display off take
     * away a second thing, on another page, with nothing saying so. The refusal's
     * wording rests on the same choice — it says *no display has been assigned to
     * you*, which is true of everyone this returns false for and would be a lie to the
     * account whose one sign is merely off.
     *
     * It takes the Display list rather than reading `grantedIds` alone, because a
     * grant row is a permission only while the Display it names is still there. The
     * `ON DELETE CASCADE` should mean the two can never differ; invariant 10 says
     * assume nothing about a database behind the repo, which is the same reason
     * `revokeAllForDisplay()` exists.
     *
     * Admins are true whatever the list holds, including empty. They hold every
     * Display by role (ADR-0005), and the one case where that differs from "the list
     * is not empty" is a fresh install with no Displays yet — where the admin is the
     * person about to add the first one, and refusing them the library on the way in
     * would be this rule aimed at nobody.
     *
     * @param Display[] $displays every Display in the installation
     */
    public function holdsASign(array $displays)
    {
        if ($this->isAdmin) { return true; }
        return $this->granted($displays) !== [];
    }
}
