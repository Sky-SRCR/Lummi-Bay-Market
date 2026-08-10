<?php
require_once 'auth.php';
require_once 'db_connect.php';
require_once __DIR__ . '/lib/password_resets.php';

if (isLoggedIn()) { header('Location: builder.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') { verifyCsrf(); }

// The one schema statement this page issues, at the top, where nothing has opened a
// transaction — invariant 21. The lockout columns are deliberately NOT added here
// any more: login.php adds them on any sign-in attempt, and the reset's clear copes
// with their absence, so this page no longer pays three ALTERs for a column it only
// reads on the way out.
$tokens = new ResetTokenStore($pdo);
$tokens->ensureSchema();

// Holds the transaction the last step needs; writes no SQL itself.
$completion = new PasswordResetCompletion($pdo, $tokens, new AccountStore($pdo));

$step    = intval($_SESSION['reset_step'] ?? 1);
$message = '';
$msgType = 'info';

// The one thing step 2 ever says when it will not let you through.
//
// One sentence for every refusal — wrong code, expired code, no such account,
// guesses used up — because a stranger typing usernames into step 1 must not be
// able to tell those apart. The count that used to be in here ("3 attempt(s)
// remaining") was safe only while the counter lived in the visitor's own session
// and therefore said the same thing to everybody; now that the budget is real and
// shared, showing it would answer "does this account exist?" out loud.
const RESET_CODE_REFUSED =
    'That code is incorrect or has expired. A code allows 5 tries — use "Start over" below to request a new one.';

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

        if ($user) {
            // Real, active account: invalidate old tokens, issue a fresh
            // passcode, and email it (best effort). Issuing also resets the
            // guess budget, because it is a property of the code, not of the
            // account — that is the whole point of the new column.
            $passcode = $tokens->issue($user['id']);

            // No passcode means the write failed, and an email reading "Your
            // code is:" followed by nothing is worse than no email at all — the
            // screen already tells everyone to check their inbox, so someone who
            // gets no message does the same thing either way: start over.
            if ($passcode !== '') {
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
                @mail($to, $subject, $body, $headers);
            }

            $_SESSION['reset_user_id'] = $user['id'];
        } else {
            // Unknown or inactive account: no token, no email. We still
            // advance to the code screen below so the response is identical
            // either way — this is what prevents account enumeration.
            unset($_SESSION['reset_user_id']);
        }

        // Always advance to the code-entry screen with the same message,
        // regardless of whether the account exists.
        $_SESSION['reset_step'] = 2;
        $step    = 2;
        $message = 'If an account matches, a 6-digit code has been sent to the email on file. Enter it below.';
        $msgType = 'success';
    }
}

// ---- STEP 2: Verify passcode and update password ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_reset'])) {
    $passcode = trim($_POST['passcode'] ?? '');
    $newPass  = $_POST['new_password']  ?? '';
    $confirm  = $_POST['confirm']       ?? '';
    // May be 0 for an unknown/inactive account that reached this screen. We do
    // NOT bounce back to step 1 (that would reveal the account doesn't exist);
    // there is no token for id 0, so the completion refuses in the same words it
    // uses for a real account with a wrong code.
    $userId   = intval($_SESSION['reset_user_id'] ?? 0);

    // Nothing about the guess budget is read or written here any more. It lives
    // on the token row, where clearing a cookie cannot reach it, and the page
    // only learns yes or no (lib/password_resets.php).

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
        // One call, and it either changed the password or changed nothing at all
        // (lib/password_resets.php). The page's only job is which of the three
        // answers to print.
        $outcome = $completion->complete($userId, $passcode, $newPass);

        if ($outcome->isRefused()) {
            // Wrong code, expired code, no such account, or the five guesses are
            // gone — one answer for all four, deliberately (RESET_CODE_REFUSED).
            $message = RESET_CODE_REFUSED;
            $msgType = 'error';
            $step = 2;
        } elseif (!$outcome->isOk()) {
            // The code was right; the database would not take the change. Saying
            // "that code is incorrect" here would send somebody round the loop
            // again with a code that was never the problem — and the guess it just
            // spent is real, so the loop is four tries long.
            $message = 'Your code was accepted, but the password could not be changed just now. '
                     . 'Nothing was altered — please try again in a moment, or ask an admin.';
            $msgType = 'error';
            $step = 2;
        } else {
            unset($_SESSION['reset_user_id'], $_SESSION['reset_step']);
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
    <title>Reset Password — <?= Markup::text(SITE_NAME) ?></title>
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
            <div class="msg <?= Markup::text($msgType) ?>"><?= Markup::text($message) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
            <label>Username or Email Address</label>
            <input type="text" name="identifier" autofocus required>
            <button type="submit" name="find_user" class="btn">Send Reset Code</button>
        </form>

    <?php else: ?>
        <h1>Enter Your Reset Code</h1>
        <p class="sub">Check your email for a 6-digit code. It expires in 30 minutes.</p>
        <?php if ($message): ?>
            <div class="msg <?= Markup::text($msgType) ?>"><?= Markup::text($message) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= Markup::text(csrfToken()) ?>">
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
