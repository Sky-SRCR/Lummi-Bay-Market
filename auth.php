<?php
// Session authentication helpers — include at the top of every protected page.
if (session_status() === PHP_SESSION_NONE) {
    // Harden the session cookie: unreadable to page scripts (HttpOnly),
    // HTTPS-only (Secure), and not sent on cross-site requests (SameSite=Lax).
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
require_once __DIR__ . '/config.php';

function requireLogin(string $redirect = 'login.php'): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: ' . $redirect);
        exit;
    }
}

function requireAdmin(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: builder.php');
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

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Security token mismatch. Please go back and try again.');
    }
}

// ── Login-lockout helpers (account-keyed brute-force protection) ──
// Failed-login state lives in three columns on `users`. A single window
// governs BOTH how long failures stay "recent" (age-out) and how long a
// tripped lockout lasts. See docs/adr/0001-account-keyed-login-lockout.md.
const LOGIN_LOCKOUT_MAX    = 5;    // failed attempts before lockout
const LOGIN_LOCKOUT_WINDOW = 900;  // 15 minutes, in seconds

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
function clearLockout(PDO $pdo, int $userId): void {
    $pdo->prepare(
        "UPDATE users SET failed_attempts = 0, last_failed_at = NULL, locked_until = NULL WHERE id = ?"
    )->execute([$userId]);
}

// ── Signage text sanitiser ──────────────────────────────────
// Text-block content is plain text only (see docs/adr/0002). This strips
// any markup a browser could execute before it is stored, while keeping
// intended line breaks. Rendering then uses textContent, so stored text is
// always shown literally — belt and suspenders against stored XSS.
function toPlainText(string $s): string {
    $s = preg_replace('#<\s*br\s*/?>#i', "\n", $s);
    $s = preg_replace('#</\s*(div|p|li|h[1-6])\s*>#i', "\n", $s);
    $s = strip_tags($s);
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = preg_replace("/[ \t]+\n/", "\n", $s);   // trailing spaces per line
    $s = preg_replace("/\n{3,}/", "\n\n", $s);   // collapse blank-line runs
    return trim($s);
}
?>
