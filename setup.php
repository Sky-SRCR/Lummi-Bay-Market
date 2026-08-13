<?php
// ============================================================
// FIRST-TIME SETUP – Create the initial admin account.
// This page only works when ZERO users exist in the database,
// and deletes itself as soon as that stops being true.
// ============================================================
require_once 'db_connect.php';
require_once 'config.php';
require_once __DIR__ . '/lib/brands.php';
require_once __DIR__ . '/lib/brand_styles.php';
require_once __DIR__ . '/lib/brand_admin.php';

/**
 * Delete this file, and answer whether it is really gone.
 *
 * "Delete setup.php afterwards" is a job nobody is assigned, so it does not get
 * done: this file sat on the live server for months, printing that instruction to
 * anyone who asked for it. It is harmless while `users` has a row in it, and a
 * public "make yourself an admin" form the moment the app is pointed at an empty or
 * freshly restored database — which happens by accident rather than on purpose.
 *
 * So it removes itself, at both moments it becomes dead weight: at the end of a
 * successful setup, and otherwise on the first request that finds it already
 * disabled. Deleting the file the running request came from is safe here — the
 * script is compiled before this line and the inode outlives the unlink — and the
 * next request 404s in Apache without reaching PHP.
 *
 * The answer is read back from the filesystem rather than taken from unlink(),
 * because "it is still there" is the only outcome that needs acting on, and a
 * write that fails while reporting success is the defect this codebase has spent
 * its whole history unpicking. A host that forbids it is a condition to *expect*
 * and not to alert on — an alert would reach an admin every hour forever, and be
 * triggered by any passing bot. The page says so instead.
 */
function removeSelf(): bool {
    @unlink(__FILE__);
    clearstatcache(true, __FILE__);
    return !file_exists(__FILE__);
}

// If any user already exists, this page can do nothing — so it goes.
$count = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
if ($count > 0) {
    die(removeSelf()
        ? 'Setup is complete. This page has deleted itself and will not answer again.'
        : 'Setup is complete. This page is disabled. It could not delete itself — '
          . 'please delete setup.php from your server.');
}

$error   = '';
$success = '';
$removed = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username  = trim($_POST['username'] ?? '');
    $email     = trim($_POST['email']    ?? '');
    $password  = $_POST['password']      ?? '';
    $confirm   = $_POST['confirm']       ?? '';
    $brandName = BrandStore::cleanName($_POST['brand'] ?? '');

    if (!$username || !$email || !$password || $brandName === '') {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!BrandStore::isValidName($brandName)) {
        $error = 'That brand name cannot be used — ' . BrandStore::NAME_MAX
               . ' characters or fewer, and no control characters.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->execute([$username, $email, $hash]);

        // The first Brand. Every Display wears one and `displays.brand_id` is NOT
        // NULL, so an installation with none is one where no sign can be created at
        // all (ADR-0011). schema.sql seeds a generically-named Brand so the column
        // has something to point at from the first moment; what this does is give it
        // the venue's real name, because "Store Brand" on the Display Branding tab
        // reads like something the install left half-done.
        //
        // Never fatal. The account is what this page exists to create and it is
        // already made; a database that cannot take this — one restored from before
        // Brands existed, say — gets its first Brand from convergence on the first
        // signed-in page load instead, named after SITE_NAME. Failing the whole setup
        // over a label would strand somebody with no way in.
        $brandNote = '';
        try {
            $brands = new BrandStore($pdo);
            $admin  = new BrandAdmin($pdo, $brands, new BrandStyles($pdo), new DisplayStore($pdo));
            $existing = $brands->all();
            $res = count($existing) === 1
                ? $admin->updateDetails($existing[0], [
                      'name'    => $brandName,
                      'bg_type' => $existing[0]->backgroundType(),
                      'bg_val'  => $existing[0]->backgroundValue(),
                  ])
                : $admin->create(['name' => $brandName]);
            if (!$res->isOk()) {
                $brandNote = ' The brand "' . $brandName . '" could not be saved ('
                           . $res->message() . ') — set it in Admin Panel → Display Branding.';
            }
        } catch (Throwable $e) {
            $brandNote = ' The brand could not be created yet; it will appear as soon as you '
                       . 'sign in, and you can rename it in Admin Panel → Display Branding.';
        }

        // The account exists, so this page's work is done. Only now is it safe to go.
        $removed = removeSelf();
        $success = 'Admin account created! You can now sign in.'
                 . ($removed
                     ? ' This setup page has deleted itself.'
                     : ' Please delete setup.php from your server — it could not delete itself.')
                 . $brandNote;
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
        <label>Venue / brand name</label>
        <input type="text" name="brand" value="<?= Markup::text($_POST['brand'] ?? '') ?>"
               placeholder="Salmon House" maxlength="<?= intval(BrandStore::NAME_MAX) ?>" required>
        <p style="font-size:12px; color:#7f8c8d; margin:-10px 0 16px;">
            Every display wears a brand — its typography, palette and logo. You can add more
            later, one per venue.
        </p>
        <button type="submit" class="btn">Create Admin Account</button>
    </form>
    <?php endif; ?>

    <?php if (!$removed): ?>
    <div class="warn">&#9888; This page deletes itself once the first admin account exists. If it is
    still here after that, the server did not allow it — delete <strong>setup.php</strong> by hand.</div>
    <?php endif; ?>
</div>
</body>
</html>
