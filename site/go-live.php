<?php
// One-time: removes the preview password so the website is public. Reachable only through the preview password.
define('PB', 1);
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/stripe.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$ht = __DIR__ . '/.htaccess';
$src = is_file($ht) ? file_get_contents($ht) : '';
$locked = str_contains($src, 'Perfect Beauty preview lock');
$done = false; $err = '';

if ($locked && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    copy($ht, $ht . '.before-live');
    $new = preg_replace('/# --- Perfect Beauty preview lock.*?# --- end preview lock ---\R?/s', '', $src);
    if ($new !== null && file_put_contents($ht, $new) !== false) {
        @unlink(__DIR__ . '/.htpasswd');
        $done = true; $locked = false;
    } else $err = 'The website could not change its settings file. Nothing was changed.';
}

// Things to check before the public can book.
$warn = [];
$depositsOn = setting('deposit_mode', 'off') !== 'off';
if ($depositsOn && stripe_mode() === 'test') $warn[] = 'Stripe is in <b>test mode</b> but deposits are switched on, so real customers won\'t be able to pay. Put the live Stripe key in, or set Deposit to "No deposit" in Settings, before going live.';
if ($depositsOn && stripe_mode() === 'live' && setting('stripe_webhook_secret', '') === '') $warn[] = 'The live Stripe webhook signing secret isn\'t saved yet (Settings → Deposits and payments).';
if (setting('smtp_pass', '') === '') $warn[] = 'The bookings mailbox password isn\'t saved, so emails go out through the website server and are more likely to land in spam.';
if (setting('notify_email', '') === '') $warn[] = 'No email address for new booking alerts is set.';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Go live · Perfect Beauty</title>
<style>
body{margin:0;background:#F7F5F8;color:#1F1A24;font:16px/1.55 system-ui,-apple-system,sans-serif;padding:32px 16px}
main{max-width:480px;margin:0 auto;display:grid;gap:16px}
h1{font:32px/1.15 Georgia,serif;margin:0}
.card{background:#fff;border:1px solid #E4DEE6;border-radius:16px;padding:20px;display:grid;gap:12px}
.warn{background:#FBF0DC;color:#5a3b06;border-radius:12px;padding:12px 14px}
.ok{background:#E3F1EA;color:#1f4d36;border-radius:12px;padding:12px 14px}
button,a.btn{font:inherit;font-weight:600;border:0;border-radius:999px;padding:14px;background:#8A2F55;color:#fff;text-align:center;text-decoration:none;cursor:pointer}
label{display:flex;gap:10px;align-items:flex-start;font-size:15px}
input{width:20px;height:20px;accent-color:#8A2F55;margin-top:2px}
.muted{color:#6B6372;font-size:14px;margin:0}
</style></head><body><main>
<h1>Go live</h1>
<?php if ($done): ?>
  <div class="card"><div class="ok"><b>Perfect Beauty is live.</b> Anyone can now open perfect-beauty.uk and book.</div>
  <p class="muted">Google usually starts listing a new site within a few days to a couple of weeks.</p>
  <a class="btn" href="/">Open the website</a></div>
<?php elseif (!$locked): ?>
  <div class="card"><div class="ok">The website is already public. There's no preview password.</div><a class="btn" href="/">Open the website</a></div>
<?php else: ?>
  <div class="card">
    <p style="margin:0">This removes the preview password, so anyone can visit the website and book.</p>
    <?php if ($err): ?><div class="warn"><?= $h($err) ?></div><?php endif; ?>
    <?php foreach ($warn as $w): ?><div class="warn"><?= $w ?></div><?php endforeach; ?>
    <?php if (!$warn): ?><div class="ok">Payments, emails and alerts are set up.</div><?php endif; ?>
    <form method="post" style="display:grid;gap:12px">
      <label><input type="checkbox" name="confirm" value="yes" required> I've checked the prices, opening hours and photos, and I'm ready for customers to book.</label>
      <button type="submit">Go live now</button>
    </form>
  </div>
<?php endif; ?>
</main></body></html>
