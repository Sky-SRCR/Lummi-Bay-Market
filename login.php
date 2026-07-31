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

// Rate limiting: 5 failed attempts → 5-minute lockout
$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['login_last_fail'] = $_SESSION['login_last_fail'] ?? 0;

$lockoutDuration = 300; // 5 minutes in seconds
$maxAttempts     = 5;
$lockedOut       = ($_SESSION['login_attempts'] >= $maxAttempts)
                   && (time() - $_SESSION['login_last_fail'] < $lockoutDuration);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($lockedOut) {
        $remaining = $lockoutDuration - (time() - $_SESSION['login_last_fail']);
        $error = 'Too many failed attempts. Please wait ' . ceil($remaining / 60) . ' minute(s) before trying again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter both your username and password.';
        } else {
            $stmt = $pdo->prepare("SELECT id, username, password_hash, role, is_active FROM users WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $_SESSION['login_attempts']++;
                $_SESSION['login_last_fail'] = time();
                $error = 'Incorrect username or password.';
            } elseif (!$user['is_active']) {
                $error = 'Your account has been deactivated. Please contact your manager.';
            } else {
                // Successful login — clear rate limit state
                unset($_SESSION['login_attempts'], $_SESSION['login_last_fail']);
                session_regenerate_id(true);
                $_SESSION['user_id']  = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];
                header('Location: builder.php');
                exit;
            }
        }
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
