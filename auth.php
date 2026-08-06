<?php
// Session authentication helpers — include at the top of every protected page.
// AUTH_NO_SESSION lets the one public entry point — api.php's get_layout, polled
// every 30 seconds by every Screen — include this file for its helpers without
// opening a session it never reads. A framed Screen returns no cookie, so each
// poll was minting a fresh session file: thousands a day, per Screen, reaped by
// nothing.
require_once __DIR__ . '/lib/request_scheme.php';
if (!defined('AUTH_NO_SESSION') && session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie: unreadable to page scripts (HttpOnly), not sent on
    // cross-site requests (SameSite=Lax), and Secure **when the request arrived over
    // TLS**. Secure asserted on a plain-HTTP install is not a stricter setting; it is
    // a browser instruction to discard the cookie, and the sign-in that follows loops
    // back to this page forever with nothing to read. RequestScheme owns that
    // decision, the two PHP forms it has to be expressed in, and the reasoning.
    RequestScheme::applyToSession($_SERVER);
    session_start();
}
require_once __DIR__ . '/config.php';

function requireLogin(string $redirect = 'login.php'): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . $redirect);
        exit;
    }
    // Mint the token here rather than waiting for a page to render a form. An
    // authenticated session with no token used to be routine — login lands every
    // admin on the Builder's Display picker, which exits before it renders one —
    // and in that state csrfOk() has nothing to compare against.
    csrfToken();
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: builder.php');
        exit;
    }
}

/**
 * Re-read the signed-in account and bring the session into line with it.
 *
 * Returns false when the session must end: the account has been deleted or
 * deactivated. Otherwise it refreshes the cached role and returns true.
 *
 * Until this existed, `role` was frozen at login and the app never read the
 * requesting account's row again — so demoting an admin, unticking Active, or
 * deleting the account outright left that browser with full admin over every
 * sign for as long as the tab stayed open, while the panel reported success.
 * The grant half of ADR-0005 was already re-read on every request; this is the
 * role half, which is the one that carries the power.
 *
 * Fails closed. A users read that throws means the database is unusable, and
 * signing in again is impossible in that state anyway.
 */
function syncSessionAccount(PDO $pdo): bool {
    if (empty($_SESSION['user_id'])) { return false; }
    try {
        $stmt = $pdo->prepare("SELECT role, is_active FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([intval($_SESSION['user_id'])]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
    if (!$row || intval($row['is_active']) !== 1) { return false; }
    // Closing an account also clears is_active, so the line above already ends the
    // session — this is the belt for a row edited by hand, and the one place that
    // states the rule in the vocabulary of closure rather than of suspension.
    if (accountIsClosed($pdo, intval($_SESSION['user_id']))) { return false; }
    $_SESSION['role'] = ($row['role'] === 'admin') ? 'admin' : 'basic';
    return true;
}

/** Drop the session entirely — used when the account behind it is gone. */
function endSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * The page-script form: sign the browser out and send it to the login page if
 * the account behind the session no longer exists or is no longer active.
 * Call it after db_connect.php and before requireAdmin(), so a demotion that
 * happened while the tab was open is honoured on this request.
 */
function requireCurrentAccount(PDO $pdo, string $redirect = 'login.php'): void {
    requireLogin($redirect);
    if (!syncSessionAccount($pdo)) {
        endSession();
        header('Location: ' . $redirect);
        exit;
    }
}

function isAdmin(): bool {
    return !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin';
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id']  ?? 0,
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role']     ?? 'basic',
    ];
}

// ── CSRF helpers ────────────────────────────────────────────
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Does this POST carry the session's CSRF token?
 *
 * Fails closed when the session has no token. `hash_equals('', '')` is **true**,
 * so the old check accepted a request with no token at all whenever the session
 * had not yet minted one — and that state was reachable on every login, because
 * login.php lands on the Builder's Display picker, which exits before the line
 * that creates the token. In that window every POST endpoint in the app was
 * unprotected, with SameSite=Lax the only thing standing in the way, and that is
 * the browser's mitigation rather than ours.
 */
function csrfOk(): bool {
    $session = $_SESSION['csrf_token'] ?? '';
    $sent    = $_POST['csrf_token']    ?? '';
    if (!is_string($session) || !is_string($sent) || $session === '') { return false; }
    return hash_equals($session, $sent);
}

function verifyCsrf(): void {
    if (!csrfOk()) {
        http_response_code(403);
        die('Security token mismatch. Please go back and try again.');
    }
}

// ── One sentence carried across a redirect ──────────────────
// A page that answers a POST by rendering leaves the POST in the browser's history,
// so F5 sends it again — and for a form that rewrites a whole table of state, that
// is a second write nobody asked for, against a page that has since changed. The
// answer is to redirect and let the browser GET the result (post/redirect/get), which
// needs somewhere to leave the message the redirect would otherwise throw away.
//
// The session is that somewhere, and it is read exactly once: takeFlashMessage()
// removes what it returns, so reloading the page it redirected to shows the page
// without the sentence rather than repeating it over a state that may have moved on.

/** Leave one sentence for the page this request is about to redirect to. */
function flashMessage(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type === 'error' ? 'error' : 'success'];
}

/**
 * Take the sentence left by the last redirect, or null. Clears it either way.
 *
 * @return array|null ['message' => string, 'type' => 'success'|'error']
 */
function takeFlashMessage() {
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) { return null; }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $message = isset($flash['message']) ? (string)$flash['message'] : '';
    if ($message === '') { return null; }
    return [
        'message' => $message,
        'type'    => (isset($flash['type']) && $flash['type'] === 'error') ? 'error' : 'success',
    ];
}

// ── Login-lockout helpers (account-keyed brute-force protection) ──
// Failed-login state lives in three columns on `users`. A single window
// governs BOTH how long failures stay "recent" (age-out) and how long a
// tripped lockout lasts. See docs/adr/0001-account-keyed-login-lockout.md.
//
// The two numbers, the arithmetic over them and every sentence the login page can
// print are `LoginGate::MAX_ATTEMPTS` / `WINDOW_SECONDS` in lib/login_gate.php —
// one place, so that what the page says and what it writes down cannot disagree
// (ADR-0008). What stays here is the pair of database chores either side of that
// decision: adding the columns, and clearing them.
require_once __DIR__ . '/lib/login_gate.php';

// Idempotently add the lockout columns. Called only from the pre-auth
// pages (login / reset) — deliberately NOT from db_connect.php, so the
// public viewer poll never runs migrations.
function ensureLockoutColumns(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE users ADD COLUMN failed_attempts INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN last_failed_at DATETIME NULL"); }            catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME NULL"); }              catch (Exception $e) {}
}

// Wipe all lockout state for one account. Called on a successful login
// and on a completed password reset (the two recovery paths).
//
// The statement lives in lib/accounts.php, which checks the three columns exist
// first. This used to assume them, so on a database where ensureLockoutColumns()
// could not apply — no ALTER privilege, a full disk — it threw "unknown column" at
// the end of a *successful* sign-in, and nobody could get in on any account. The
// comment in login.php has always said this helper swallows its own failures; now
// it does.
function clearLockout(PDO $pdo, int $userId): void {
    $store = new AccountStore($pdo);
    $store->clearLoginLockout($userId);
}

// ── Closed accounts ─────────────────────────────────────────
// A closed account is one that has been retired permanently: the row stays so its
// id number can never be handed to somebody else, and it can never sign in again.
// The rule and every statement behind it live in lib/accounts.php; this is the
// one-line form the pre-auth pages and the session sync call.
//
// Answers false when the column does not exist, which is right rather than
// convenient: a database without it has never closed an account, because there was
// nowhere to record one.
require_once __DIR__ . '/lib/accounts.php';

// Built per call rather than cached in a static: a cached store would hold the
// first PDO it ever saw, and a helper that silently answers about the wrong
// database is the kind of bug that only shows up somewhere it cannot be traced.
// This runs once per request.
function accountIsClosed(PDO $pdo, int $accountId): bool {
    $store = new AccountStore($pdo);
    return $store->isClosed($accountId);
}

// ── Signage text sanitiser ──────────────────────────────────
// toPlainText() moved to lib/plain_text.php so the layout store can use it
// without including this file (which starts a session). Included here so every
// existing caller keeps calling toPlainText() unchanged.
require_once __DIR__ . '/lib/plain_text.php';
?>
