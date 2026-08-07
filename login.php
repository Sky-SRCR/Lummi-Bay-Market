<?php
require_once 'auth.php';
require_once 'db_connect.php';

// Already logged in → go straight to builder
if (isLoggedIn()) {
    header('Location: builder.php');
    exit;
}

// Store branding is loaded by db_connect.php and read through Brand:: — the colours
// go into the <style> block below, where escaping is not what makes a value safe.

$error = '';

// Rate limiting: account-keyed brute-force lockout, backed by the `users`
// table (a session counter was bypassable by dropping the cookie). A single
// window governs both the age-out of stale failures and the lockout length.
$maxAttempts   = LOGIN_LOCKOUT_MAX;     // 5 failures
$lockoutWindow = LOGIN_LOCKOUT_WINDOW;  // 900s = 15 min

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ensureLockoutColumns($pdo); // idempotent; kept off the public viewer path

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } else {
        // ensureLockoutColumns() above swallows its failures by design, so this
        // read cannot assume the three columns exist. If the ALTER could not apply
        // — a database user without ALTER, a hosting restriction, a full disk —
        // the unguarded version raised "unknown column" with nothing catching it,
        // and nobody could sign in at all, on any account, with no message and
        // nothing in a log. Signing in without the brute-force counters is worse
        // than the alternative of nobody signing in at all, so fall back and carry
        // on: clearLockout()/registerFailedLogin() swallow their own failures the
        // same way.
        try {
            $stmt = $pdo->prepare(
                "SELECT id, username, password_hash, role, is_active,
                        failed_attempts, last_failed_at, locked_until
                 FROM users WHERE username = ? LIMIT 1"
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();
        } catch (Throwable $e) {
            $stmt = $pdo->prepare(
                "SELECT id, username, password_hash, role, is_active
                 FROM users WHERE username = ? LIMIT 1"
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if ($user) {
                $user['failed_attempts'] = 0;
                $user['last_failed_at']  = null;
                $user['locked_until']    = null;
            }
        }
        $now  = time();

        if ($user && $user['locked_until'] !== null && strtotime($user['locked_until']) > $now) {
            // Lockout is absolute: a correct password still waits it out.
            $remaining = strtotime($user['locked_until']) - $now;
            $error = 'Too many failed attempts. Please wait ' . ceil($remaining / 60) . ' minute(s) before trying again.';
        } elseif (!$user || !password_verify($password, $user['password_hash'])) {
            // One generic message for both unknown user and wrong password.
            $error = 'Incorrect username or password.';

            // Only real accounts accrue failed-attempt state.
            if ($user) {
                // Fresh 5 if the previous lockout has expired, or if it has
                // been longer than the window since the last failure.
                $lockoutExpired = $user['locked_until'] !== null && strtotime($user['locked_until']) <= $now;
                $agedOut        = $user['last_failed_at'] !== null && ($now - strtotime($user['last_failed_at'])) > $lockoutWindow;
                $attempts       = (($lockoutExpired || $agedOut) ? 0 : (int)$user['failed_attempts']) + 1;

                if ($attempts >= $maxAttempts) {
                    $lockedUntil = date('Y-m-d H:i:s', $now + $lockoutWindow);
                    $pdo->prepare(
                        "UPDATE users SET failed_attempts = ?, last_failed_at = ?, locked_until = ? WHERE id = ?"
                    )->execute([$attempts, date('Y-m-d H:i:s', $now), $lockedUntil, $user['id']]);
                    $error = 'Too many failed attempts. Please wait ' . ceil($lockoutWindow / 60) . ' minute(s) before trying again.';
                } else {
                    $pdo->prepare(
                        "UPDATE users SET failed_attempts = ?, last_failed_at = ?, locked_until = NULL WHERE id = ?"
                    )->execute([$attempts, date('Y-m-d H:i:s', $now), $user['id']]);
                }
            }
        } elseif (accountIsClosed($pdo, (int)$user['id'])) {
            // Checked before the deactivated branch because closing also clears
            // is_active — without this, someone whose account was retired would be
            // told to contact a manager about getting it switched back on, which is
            // not a thing that can happen (lib/accounts.php).
            $error = 'This account has been closed and cannot be used again. '
                   . 'If you still work here, ask an admin to set you up a new one.';
        } elseif (!$user['is_active']) {
            $error = 'Your account has been deactivated. Please contact your manager.';
        } else {
            // Successful login — clear lockout state and start the session.
            clearLockout($pdo, (int)$user['id']);
            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];
            header('Location: builder.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= Markup::text(SITE_NAME) ?></title>
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
        input:focus { outline: none; border-color: <?= Brand::accent() ?>; }
        .btn {
            width: 100%; padding: 13px;
            background: <?= Brand::accent() ?>; color: #fff;
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
    <?php if (Brand::logo()): ?>
        <div style="text-align:center; margin-bottom:16px;">
            <img src="<?= Markup::text(Brand::logo()) ?>" alt="<?= Markup::text(SITE_NAME) ?>"
                 style="max-height:60px; max-width:180px; object-fit:contain;">
        </div>
    <?php endif; ?>
    <h1><?= Markup::text(SITE_NAME) ?></h1>
    <p class="subtitle">Sign in to your account</p>
    <?php if ($error): ?>
        <div class="error"><?= Markup::text($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <label for="username">Username</label>
        <input type="text" id="username" name="username"
               value="<?= Markup::text($_POST['username'] ?? '') ?>"
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
