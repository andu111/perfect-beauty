<?php
// One-time setup for the booking manager: sets the admin password and opens /admin to the
// phone app (it keeps its own login). Works only until a password exists.
define('PB', 1);
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/push.php';

header('Cache-Control: no-store');
$done = setting('admin_hash', '') !== '';
$err = ''; $ok = false;

if (!$done && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p = (string)($_POST['password'] ?? ''); $p2 = (string)($_POST['password2'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));
    if (strlen($p) < 10) $err = 'Use at least 10 characters.';
    elseif ($p !== $p2) $err = 'The two passwords are different.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'That email address doesn\'t look right.';
    else {
        set_setting('admin_hash', password_hash($p, PASSWORD_DEFAULT));
        if ($email !== '') set_setting('notify_email', $email);
        vapid_keys();
        // Let the admin app skip the preview password (it has its own login), so it works as a home-screen app.
        @file_put_contents(__DIR__ . '/admin/.htaccess', "# Created by setup-admin.php. The admin app has its own login.\nRequire all granted\n");
        $ok = true; $done = true;
    }
}

$checks = [
    'Database' => class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true),
    'Data folder writable' => is_dir(PB_DATA) && is_writable(PB_DATA),
    'Encryption (OpenSSL)' => function_exists('openssl_pkey_derive'),
    'Outgoing connections (cURL)' => function_exists('curl_init'),
];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Set up bookings · Perfect Beauty</title>
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
ul{margin:0;padding-left:18px;font-size:14px}
</style></head><body><main>
<h1>Set up the booking manager</h1>
<div class="card"><p class="muted">Server check</p><ul>
<?php foreach ($checks as $k => $v): ?><li><?= $h($k) ?>: <?= $v ? 'OK' : '<b>missing</b>' ?></li><?php endforeach; ?>
</ul></div>
<?php if ($ok): ?>
  <div class="card"><p><b>Done.</b> The admin password is set.</p>
  <p class="muted">Open the booking manager on Alexandra's iPhone in Safari, sign in, then tap Share → Add to Home Screen.</p>
  <a class="btn" href="/admin/">Open the booking manager</a></div>
<?php elseif ($done): ?>
  <div class="card"><p>Setup is already complete.</p><a class="btn" href="/admin/">Open the booking manager</a></div>
<?php else: ?>
  <form method="post">
    <?php if ($err): ?><div class="err"><?= $h($err) ?></div><?php endif; ?>
    <label for="email">Email for new booking alerts<input id="email" name="email" type="email" autocomplete="email" value="<?= $h($_POST['email'] ?? '') ?>"></label>
    <label for="pw">Admin password (at least 10 characters)<input id="pw" name="password" type="password" autocomplete="new-password" required minlength="10"></label>
    <label for="pw2">Type it again<input id="pw2" name="password2" type="password" autocomplete="new-password" required minlength="10"></label>
    <button type="submit">Create admin password</button>
    <p class="muted">Alexandra signs in with this password on her phone. She can change it later in Settings.</p>
  </form>
<?php endif; ?>
</main></body></html>
