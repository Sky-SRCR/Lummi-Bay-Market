<?php
// ============================================================
// FIRST-TIME SETUP – Create the initial admin account.
// This page only works when ZERO users exist in the database.
// DELETE or rename this file once your first admin is created.
// ============================================================
require_once 'db_connect.php';
require_once 'config.php';

// If any user already exists, lock this page down
$count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($count > 0) {
    die('Setup is complete. This page is disabled. Please delete setup.php from your server.');
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['confirm']       ?? '';

    if (!$username || !$email || !$password) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([$username, $email, $hash]);
        $success = 'Admin account created! You can now sign in. Please delete setup.php from your server.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>First-Time Setup — <?= Markup::text(SITE_NAME) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #1a252f; display: flex; align-items: center; justify-content: center; min-height: 100vh; font-family: sans-serif; }
        .card { background: #fff; border-radius: 10px; padding: 36px; width: 380px; box-shadow: 0 8px 32px rgba(0,0,0,.3); }
        h1 { font-size: 20px; color: #2c3e50; margin-bottom: 4px; }
        .sub { color: #7f8c8d; font-size: 13px; margin-bottom: 24px; }
        label { display: block; font-weight: 600; font-size: 13px; color: #555; margin-bottom: 5px; }
        input { width: 100%; padding: 10px 12px; border: 1px solid #ccc; border-radius: 5px; font-size: 15px; margin-bottom: 16px; }
        .btn { width: 100%; padding: 12px; background: #27ae60; color: #fff; border: none; border-radius: 5px; font-size: 15px; font-weight: bold; cursor: pointer; }
        .btn:hover { background: #219a52; }
        .msg { padding: 10px 14px; border-radius: 5px; margin-bottom: 16px; font-size: 13px; }
        .error   { background: #fdecea; color: #c0392b; border: 1px solid #e74c3c; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .warn { background: #fff3cd; color: #856404; border: 1px solid #ffc107; border-radius: 5px; padding: 10px 14px; font-size: 12px; margin-top: 16px; }
    </style>
</head>
<body>
<div class="card">
    <h1>First-Time Setup</h1>
    <p class="sub">Create your administrator account to get started.</p>

    <?php if ($error):   ?><div class="msg error"><?= Markup::text($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="msg success"><?= Markup::text($success) ?> <a href="login.php">Go to Sign In →</a></div><?php endif; ?>

    <?php if (!$success): ?>
    <form method="POST">
        <label>Username</label>
        <input type="text" name="username" value="<?= Markup::text($_POST['username'] ?? '') ?>" required autofocus>
        <label>Email Address</label>
        <input type="email" name="email" value="<?= Markup::text($_POST['email'] ?? '') ?>" required>
        <label>Password (min 8 characters)</label>
        <input type="password" name="password" required>
        <label>Confirm Password</label>
        <input type="password" name="confirm" required>
        <button type="submit" class="btn">Create Admin Account</button>
    </form>
    <?php endif; ?>

    <div class="warn">&#9888; Delete <strong>setup.php</strong> from your server after creating your first admin account.</div>
</div>
</body>
</html>
