<?php
require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/login_attempt.php';

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

// Which sentence a refused sign-in gets — and, crucially, the order the questions
// are asked in — lives in lib/login_attempt.php. This page used to decide it here,
// and asked "is the password right?" before "is this account still usable?", so a
// suspended account answered one thing for every wrong guess and something else
// for the right one. That is a password oracle, and the fix is an ordering, which
// is exactly the kind of thing that needs somewhere it can be read and tested.
//
// What is left here is the two things a page does: start a session, or print a
// sentence.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfOk()) {
        // Login CSRF is real and is not about protecting this account: it signs a
        // visitor into somebody *else's* account, quietly, and then everything they
        // do afterwards is done in that account's name. Every other POST in the app
        // was already covered; this one was not.
        //
        // It refuses **softly**, which is the whole design of it. verifyCsrf() ends
        // the request with a 403 and the word "security", and on the front door that
        // is the wrong answer to the commonest cause by far: a browser that is not
        // keeping the session cookie has no token to send and never will, so a hard
        // failure there is #38's invisible loop again with a frightening sentence
        // on it. So the page says what is actually likely to be wrong, keeps the
        // username typed, and lets them try again.
        //
        // It also happens before the account is looked at, so a request with no
        // token accrues no failed attempt and cannot be used to lock somebody out.
        $error = 'Your sign-in form had expired, so nothing was sent. Please try again — '
               . 'and if this keeps happening, this browser is not keeping cookies for '
               . 'this site, which sign-in needs.';
    } else {
        $outcome = (new LoginAttempt(new AccountStore($pdo)))
            ->attempt($_POST['username'] ?? '', $_POST['password'] ?? '');

        if ($outcome->isOk()) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = $outcome->accountId();
            $_SESSION['username'] = $outcome->username();
            $_SESSION['role']     = $outcome->role();
            header('Location: builder.php');
            exit;
        }

        $error = $outcome->message();
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
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken()) ?>">
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
