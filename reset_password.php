<?php
require_once 'auth.php';
require_once 'db_connect.php';

if (isLoggedIn()) { header('Location: builder.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') { verifyCsrf(); }

$step    = intval($_SESSION['reset_step'] ?? 1);
$message = '';
$msgType = 'info';

// ---- STEP 1: Look up user and send passcode ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['find_user'])) {
    $identifier = trim($_POST['identifier'] ?? '');
    if ($identifier === '') {
        $message = 'Please enter your username or email address.';
        $msgType = 'error';
    } else {
        $stmt = $pdo->prepare(
            "SELECT id, username, email FROM users WHERE (username = ? OR email = ?) AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user) {
            // Intentionally vague to prevent user enumeration
            $message = 'If that account exists, a reset code has been sent to the email on file.';
            $msgType = 'success';
        } else {
            // Invalidate old tokens for this user
            $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);

            // Generate 6-digit passcode
            $passcode  = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresAt = date('Y-m-d H:i:s', time() + 1800); // 30 minutes

            $pdo->prepare("INSERT INTO password_resets (user_id, passcode, expires_at) VALUES (?, ?, ?)")
                ->execute([$user['id'], $passcode, $expiresAt]);

            // Send email
            $to      = $user['email'];
            $subject = SITE_NAME . ' – Password Reset Code';
            $body    = "Hello {$user['username']},\r\n\r\n"
                     . "Your password reset code is:\r\n\r\n"
                     . "    {$passcode}\r\n\r\n"
                     . "This code expires in 30 minutes.\r\n\r\n"
                     . "If you did not request a password reset, you can ignore this email.\r\n\r\n"
                     . "— " . SITE_NAME;
            $headers = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n"
                     . "Reply-To: " . MAIL_FROM . "\r\n"
                     . "X-Mailer: PHP/" . phpversion();

            $sent = mail($to, $subject, $body, $headers);

            if (!$sent) {
                $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$user['id']]);
                $message = 'Could not send the reset email. Please try again later or contact your manager.';
                $msgType = 'error';
            } else {
                $_SESSION['reset_user_id'] = $user['id'];
                $_SESSION['reset_step']    = 2;
                $step    = 2;
                $message = 'A 6-digit code has been sent to the email on file. Enter it below.';
                $msgType = 'success';
            }
        }
    }
}

// ---- STEP 2: Verify passcode and update password ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reset'])) {
    $passcode = trim($_POST['passcode'] ?? '');
    $newPass  = $_POST['new_password']  ?? '';
    $confirm  = $_POST['confirm']       ?? '';
    $userId   = intval($_SESSION['reset_user_id'] ?? 0);

    if (!$userId) {
        $_SESSION['reset_step'] = 1;
        header('Location: reset_password.php');
        exit;
    }

    $_SESSION['reset_attempts'] = ($_SESSION['reset_attempts'] ?? 0);

    // After 5 wrong guesses, invalidate the token and restart
    if ($_SESSION['reset_attempts'] >= 5) {
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ?")->execute([$userId]);
        unset($_SESSION['reset_user_id'], $_SESSION['reset_step'], $_SESSION['reset_attempts']);
        header('Location: reset_password.php?restart=1');
        exit;
    }

    if ($passcode === '' || $newPass === '') {
        $message = 'Please fill in all fields.';
        $msgType = 'error';
        $step = 2;
    } elseif (strlen($newPass) < 8) {
        $message = 'Password must be at least 8 characters.';
        $msgType = 'error';
        $step = 2;
    } elseif ($newPass !== $confirm) {
        $message = 'Passwords do not match.';
        $msgType = 'error';
        $step = 2;
    } else {
        $stmt = $pdo->prepare(
            "SELECT id FROM password_resets
             WHERE user_id = ? AND passcode = ? AND used = 0 AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$userId, $passcode]);
        $reset = $stmt->fetch();

        if (!$reset) {
            $_SESSION['reset_attempts']++;
            $attemptsLeft = 5 - $_SESSION['reset_attempts'];
            $message = $attemptsLeft > 0
                ? 'That code is incorrect or has expired. ' . $attemptsLeft . ' attempt(s) remaining.'
                : 'Too many incorrect attempts. Please request a new code.';
            $msgType = 'error';
            $step = 2;
        } else {
            // Mark token used + update password
            $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $userId]);

            unset($_SESSION['reset_user_id'], $_SESSION['reset_step'], $_SESSION['reset_attempts']);
            session_regenerate_id(true);
            header('Location: login.php?reset=1');
            exit;
        }
    }
}

// Cancel / restart
if (isset($_GET['restart'])) {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_step']);
    header('Location: reset_password.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password — <?= htmlspecialchars(SITE_NAME) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #1a252f; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: sans-serif; }
        .card { background: #fff; border-radius: 10px; padding: 36px; width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,.3); }
        h1 { font-size: 20px; color: #2c3e50; margin-bottom: 4px; }
        .sub { color: #7f8c8d; font-size: 13px; margin-bottom: 22px; }
        label { display: block; font-weight: 600; font-size: 13px; color: #555; margin-bottom: 5px; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 5px; font-size: 15px; margin-bottom: 16px; }
        .btn { width: 100%; padding: 12px; background: #3498db; color: #fff; border: none; border-radius: 5px; font-size: 15px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #2980b9; }
        .msg { padding: 10px 14px; border-radius: 5px; margin-bottom: 16px; font-size: 13px; }
        .error   { background: #fdecea; color: #c0392b; border: 1px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .links { margin-top: 16px; text-align: center; font-size: 13px; }
        .links a { color: #3498db; text-decoration: none; margin: 0 6px; }
        .passcode-input { font-size: 28px; letter-spacing: 12px; text-align: center; font-weight: bold; }
    </style>
</head>
<body>
<div class="card">
    <?php if ($step === 1): ?>
        <h1>Forgot Password</h1>
        <p class="sub">Enter your username or email address and we'll send you a reset code.</p>
        <?php if ($message): ?>
            <div class="msg <?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <label>Username or Email Address</label>
            <input type="text" name="identifier" autofocus required>
            <button type="submit" name="find_user" class="btn">Send Reset Code</button>
        </form>

    <?php else: ?>
        <h1>Enter Your Reset Code</h1>
        <p class="sub">Check your email for a 6-digit code. It expires in 30 minutes.</p>
        <?php if ($message): ?>
            <div class="msg <?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
            <label>6-Digit Code</label>
            <input type="text" name="passcode" class="passcode-input" maxlength="6"
                   inputmode="numeric" pattern="[0-9]{6}" autocomplete="one-time-code" autofocus required>
            <label>New Password (min 8 characters)</label>
            <input type="password" name="new_password" autocomplete="new-password" required>
            <label>Confirm New Password</label>
            <input type="password" name="confirm" autocomplete="new-password" required>
            <button type="submit" name="do_reset" class="btn">Reset Password</button>
        </form>
        <div class="links"><a href="reset_password.php?restart=1">Start over</a></div>
    <?php endif; ?>

    <div class="links"><a href="login.php">← Back to Sign In</a></div>
</div>
</body>
</html>
