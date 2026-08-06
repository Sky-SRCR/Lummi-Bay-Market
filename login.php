<?php
require_once 'auth.php';
require_once 'db_connect.php';

// Already logged in → go straight to builder
if (isLoggedIn()) {
    header('Location: builder.php');
    exit;
}

// Load store branding (defaults if config not yet set)
if (!defined('BRAND_NAV_BG') && file_exists(__DIR__ . '/branding_config.php')) {
    require_once __DIR__ . '/branding_config.php';
}
if (!defined('BRAND_LOGO'))       define('BRAND_LOGO',       '');
if (!defined('BRAND_NAV_BG'))     define('BRAND_NAV_BG',     '#1a252f');
if (!defined('BRAND_NAV_BORDER')) define('BRAND_NAV_BORDER', '#0d1b24');
if (!defined('BRAND_ACCENT'))     define('BRAND_ACCENT',     '#3498db');
if (!defined('BRAND_TEXT'))       define('BRAND_TEXT',       '#ffffff');

$error = '';

/**
 * The one `users` read sign-in makes. Every rule applied to what comes back lives in
 * lib/login_gate.php; this only fetches it.
 *
 * ensureLockoutColumns() swallows its failures by design, so this read cannot assume
 * the three columns exist. If the ALTER could not apply — a database user without
 * ALTER, a hosting restriction, a full disk — the unguarded version raised "unknown
 * column" with nothing catching it, and nobody could sign in at all, on any account,
 * with no message and nothing in a log. Signing in without the brute-force counters
 * is worse than the alternative of nobody signing in at all, so fall back and carry
 * on: clearLockout() and AccountStore::recordLoginFailure() both check the columns
 * exist before writing them.
 */
function loginRow(PDO $pdo, $username)
{
    try {
        $stmt = $pdo->prepare(
            "SELECT id, username, password_hash, role, is_active,
                    failed_attempts, last_failed_at, locked_until
             FROM users WHERE username = ? LIMIT 1"
        );
        $stmt->execute([$username]);
        return $stmt->fetch();
    } catch (Throwable $e) {
        $stmt = $pdo->prepare(
            "SELECT id, username, password_hash, role, is_active
             FROM users WHERE username = ? LIMIT 1"
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row) {
            $row['failed_attempts'] = 0;
            $row['last_failed_at']  = null;
            $row['locked_until']    = null;
        }
        return $row;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureLockoutColumns($pdo); // idempotent; kept off the public viewer path

    $accounts = new AccountStore($pdo);
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    // Nothing typed is nothing to look up. Which sentence an empty form gets is still
    // the gate's to decide — this only declines to query the database for nothing.
    $row      = $username === '' ? null : loginRow($pdo, $username);
    $account  = LoginGate::accountFacts($row, $row ? $accounts->isClosed($row['id']) : false);

    // Whether this attempt succeeds, is refused, is locked out or cannot be
    // remembered at all is one decision, made in one place, so that no sentence this
    // page prints can depend on something a stranger must not learn (ADR-0008).
    $outcome = LoginGate::decide(
        [
            'username'       => $username,
            'password'       => $password,
            // A POST with no session cookie has nowhere to keep a sign-in, so the
            // redirect below would come straight back here — the invisible loop
            // decision #38 is about. Asked here rather than after the password on
            // purpose: see lib/login_gate.php.
            'session_cookie' => isset($_COOKIE[session_name()]),
        ],
        $account,
        function () use ($row, $password) {
            return $row && password_verify($password, (string)$row['password_hash']);
        },
        time()
    );

    if ($outcome->isSignedIn()) {
        clearLockout($pdo, intval($row['id']));
        session_regenerate_id(true);
        $_SESSION['user_id']  = $row['id'];
        $_SESSION['username'] = $row['username'];
        $_SESSION['role']     = $row['role'];
        header('Location: builder.php');
        exit;
    }

    $error   = $outcome->message();
    $failure = $outcome->failureRecord();
    if ($failure !== null) {
        $accounts->recordLoginFailure(
            $account['id'],
            $failure['failed_attempts'],
            date('Y-m-d H:i:s', $failure['last_failed_at']),
            $failure['locked_until'] === null ? null : date('Y-m-d H:i:s', $failure['locked_until'])
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= htmlspecialchars(SITE_NAME) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: #1a252f;
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .card {
            background: #fff;
            border-radius: 10px;
            padding: 40px 36px;
            width: 360px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.3);
        }
        h1 { font-size: 22px; color: #2c3e50; margin-bottom: 6px; }
        .subtitle { font-size: 13px; color: #7f8c8d; margin-bottom: 28px; }
        label { display: block; font-weight: 600; font-size: 14px; color: #555; margin-bottom: 6px; }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 12px 14px;
            border: 1px solid #ccc; border-radius: 6px;
            font-size: 16px; margin-bottom: 18px;
            transition: border-color .2s;
        }
        input:focus { outline: none; border-color: <?= htmlspecialchars(BRAND_ACCENT) ?>; }
        .btn {
            width: 100%; padding: 13px;
            background: <?= htmlspecialchars(BRAND_ACCENT) ?>; color: #fff;
            border: none; border-radius: 6px;
            font-size: 16px; font-weight: bold; cursor: pointer;
        }
        .btn:hover { filter: brightness(0.88); }
        .error {
            background: #fdecea; color: #c0392b;
            border: 1px solid #e74c3c;
            border-radius: 5px; padding: 10px 14px;
            margin-bottom: 18px; font-size: 14px;
        }
        .forgot { text-align: center; margin-top: 18px; font-size: 13px; }
        .forgot a { color: #3498db; text-decoration: none; }
        .forgot a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <?php if (BRAND_LOGO): ?>
        <div style="text-align:center; margin-bottom:16px;">
            <img src="<?= htmlspecialchars(BRAND_LOGO) ?>" alt="<?= htmlspecialchars(SITE_NAME) ?>"
                 style="max-height:60px; max-width:180px; object-fit:contain;">
        </div>
    <?php endif; ?>
    <h1><?= htmlspecialchars(SITE_NAME) ?></h1>
    <p class="subtitle">Sign in to your account</p>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <label for="username">Username</label>
        <input type="text" id="username" name="username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               autocomplete="username" autofocus required>
        <label for="password">Password</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
        <button type="submit" class="btn">Sign In</button>
    </form>
    <div class="forgot">
        <a href="reset_password.php">Forgot your password or username?</a>
    </div>
</div>
</body>
</html>
