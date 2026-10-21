<?php
// One-time setup for a second admin account, "andu". Same booking manager, same database and settings
// as Alexandra's. Works only until andu has a password. Sits behind the preview password.
define('PB', 1);
require __DIR__ . '/lib/app.php';

const NEW_USER = 'andu';
header('Cache-Control: no-store');
$done = admin_user_hash(NEW_USER) !== '';
$err = ''; $ok = false;

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = (string)($_POST['password'] ?? ''); $p2 = (string)($_POST['password2'] ?? '');
    if (strlen($p) < 10) $err = 'Use at least 10 characters.';
    elseif ($p !== $p2) $err = 'The two passwords are different.';
    elseif (setting('admin_hash', '') !== '' && password_verify($p, setting('admin_hash'))) $err = 'Choose a different password from Alexandra\'s.';
    else {
        set_admin_user_hash(NEW_USER, password_hash($p, PASSWORD_DEFAULT));
        $ok = true; $done = true;
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Add admin · Perfect Beauty</title>
<style>
body{margin:0;background:#F7F5F8;color:#1F1A24;font:16px/1.55 system-ui,-apple-system,sans-serif;padding:32px 16px}
main{max-width:460px;margin:0 auto;display:grid;gap:16px}
h1{font:30px/1.15 Georgia,serif;margin:0}
form,.card{background:#fff;border:1px solid #E4DEE6;border-radius:16px;padding:20px;display:grid;gap:12px}
label{font-size:14px;font-weight:600;display:grid;gap:6px}
input{font:inherit;padding:12px;border:1px solid #E4DEE6;border-radius:10px}
button,a.btn{font:inherit;font-weight:600;border:0;border-radius:999px;padding:13px;background:#8A2F55;color:#fff;text-align:center;text-decoration:none}
.err{background:#FBE3E3;padding:10px 12px;border-radius:10px}
.muted{color:#6B6372;font-size:14px;margin:0}
</style></head><body><main>
<h1>Admin account: <?= $h(NEW_USER) ?></h1>
<?php if ($ok): ?>
  <div class="card"><p><b>Done.</b> Your password is set.</p>
  <p class="muted">Sign in at /admin/ with it. You'll see the same bookings and settings as Alexandra.</p>
  <a class="btn" href="/admin/">Open the booking manager</a></div>
<?php elseif ($done): ?>
  <div class="card"><p>This account already has a password.</p><a class="btn" href="/admin/">Open the booking manager</a></div>
<?php else: ?>
  <form method="post">
    <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
    <input type="text" name="username" value="<?= $h(NEW_USER) ?>" autocomplete="username" hidden>
    <label for="pw">Password (at least 10 characters)<input id="pw" name="password" type="password" autocomplete="new-password" required minlength="10"></label>
    <label for="pw2">Type it again<input id="pw2" name="password2" type="password" autocomplete="new-password" required minlength="10"></label>
    <button type="submit">Create my password</button>
    <p class="muted">Same booking manager and database as Alexandra's. Her password keeps working.</p>
  </form>
<?php endif; ?>
</main></body></html>
