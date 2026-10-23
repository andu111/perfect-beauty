<?php
// Customer page: see a booking's status and cancel it. Reached from the link in their emails.
define('PB', 1);
require __DIR__ . '/lib/app.php';
require __DIR__ . '/lib/availability.php';
require __DIR__ . '/lib/notify.php';
require __DIR__ . '/lib/stripe.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? $_POST['t'] ?? ''));
$q = db()->prepare('SELECT * FROM bookings WHERE token=?');
$q->execute([$token]);
$b = strlen($token) === 32 ? $q->fetch() : false;

$cancelHours = (int)setting('cancel_hours', 24);
$canCancel = false; $msg = ''; $payUrl = null;
if ($b) {
    // Back from the payment page, or still waiting: ask Stripe where things are.
    if ($b['deposit_status'] === 'awaiting' && $b['stripe_session'] && stripe_ready()) {
        try { $b = stripe_sync($b); } catch (Throwable $e) { /* show the page anyway */ }
    }
    if (isset($_GET['paid']) && $b['status'] === 'pending') $msg = 'Thank you. Your deposit is held and your request has been sent to the studio.';
    if (isset($_GET['paid']) && $b['status'] === 'awaiting_payment') $msg = 'We\'re confirming your payment. Refresh this page in a moment.';
    // Pay again (payment page closed or timed out) if the time is still free.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'pay' && in_array($b['status'], ['awaiting_payment', 'expired'], true) && (int)$b['deposit'] > 0) {
        $pdo = db(); $pdo->exec('BEGIN IMMEDIATE');
        $b = reload_booking((int)$b['id']);
        $free = $b['status'] === 'awaiting_payment' || !clashes(substr($b['start_at'], 0, 10), substr($b['start_at'], 11, 5), (int)$b['duration'], (int)$b['id']);
        if ($free) $pdo->prepare("UPDATE bookings SET status='awaiting_payment', deposit_status='awaiting', hold_until=? WHERE id=?")
            ->execute([now()->modify('+' . PB_HOLD_MINUTES . ' minutes')->format('Y-m-d H:i:s'), $b['id']]);
        $pdo->exec('COMMIT');
        if ($free) {
            try { header('Location: ' . stripe_checkout(reload_booking((int)$b['id'])), true, 303); exit; }
            catch (Throwable $e) { $msg = 'Online payment isn\'t working right now. Please try again shortly or call the studio.'; }
        } else $msg = 'Sorry, that time has been booked by someone else. Please choose another time.';
        $b = reload_booking((int)$b['id']);
    }
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $b['start_at'], tz());
    $canCancel = in_array($b['status'], ['pending', 'accepted'], true) && $start > now()->modify("+$cancelHours hours");
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'cancel') {
        if ($canCancel) {
            db()->prepare("UPDATE bookings SET status='cancelled', reason='Cancelled by client online', decided_at=? WHERE id=?")
                ->execute([now()->format('Y-m-d H:i:s'), $b['id']]);
            $payErr = deposit_release(reload_booking((int)$b['id']), 'Cancelled by the client online');
            $b = reload_booking((int)$b['id']);
            notify_customer_cancelled($b);
            $msg = 'Your appointment has been cancelled.' . (in_array($b['deposit_status'], ['released', 'refunded'], true)
                ? ' Your ' . money((int)$b['deposit']) . ' deposit has been returned to your card.' : '')
                . ($payErr ? ' We couldn\'t return your deposit automatically; the studio will refund it.' : '');
            $canCancel = false;
        } else {
            $msg = 'This booking can no longer be cancelled online. Please contact the studio.';
        }
    }
}
$labels = ['pending' => 'Waiting for confirmation', 'accepted' => 'Confirmed', 'declined' => 'Not available', 'cancelled' => 'Cancelled',
           'awaiting_payment' => 'Deposit not paid yet', 'expired' => 'Not booked: deposit not paid'];
$depText = ['authorized' => 'held on your card', 'captured' => 'paid', 'released' => 'released', 'refunded' => 'refunded', 'awaiting' => 'to pay now', 'unpaid' => 'not paid', 'failed' => 'not taken'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
<title>Your booking · Perfect Beauty</title>
<style>
:root{--bg:#F7F5F8;--surface:#fff;--ink:#1F1A24;--muted:#6B6372;--line:#E4DEE6;--berry:#8A2F55;--berry-ink:#fff;--soft:#F3E4EB;--ok:#2F6B4F}
@media (prefers-color-scheme:dark){:root{--bg:#141117;--surface:#1D1922;--ink:#F1ECF2;--muted:#A79FAD;--line:#2F2935;--berry:#E07FA6;--berry-ink:#1A0F15;--soft:#33202A;--ok:#7FC8A2;color-scheme:dark}}
*{box-sizing:border-box} [hidden]{display:none!important}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;padding:32px max(16px,env(safe-area-inset-right)) 48px max(16px,env(safe-area-inset-left))}
main{max-width:520px;margin:0 auto;display:grid;gap:18px}
.logo{font:22px Georgia,serif;text-decoration:none;color:var(--ink)} .logo i{color:var(--berry)}
.card{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:20px;display:grid;gap:10px}
.status{display:inline-block;justify-self:start;font-size:13px;font-weight:600;padding:5px 12px;border-radius:999px;background:var(--soft);color:var(--berry)}
.status.accepted{color:var(--ok)}
h1{font:28px/1.15 Georgia,serif;margin:0}
.row{display:flex;justify-content:space-between;gap:12px;border-top:1px solid var(--line);padding-top:10px}
.row span:first-child{color:var(--muted)}
button,.btn{font:inherit;font-weight:600;border:0;border-radius:999px;padding:12px 20px;background:var(--berry);color:var(--berry-ink);cursor:pointer;text-decoration:none;text-align:center}
.ghost{background:transparent;color:var(--ink);box-shadow:inset 0 0 0 1px var(--line)}
.msg{background:var(--soft);padding:12px 14px;border-radius:12px}
.muted{color:var(--muted);font-size:14px}
</style>
</head>
<body>
<main>
  <a class="logo" href="/">Perfect <i>Beauty</i></a>
<?php if (!$b): ?>
  <div class="card"><h1>Booking not found</h1><p class="muted">The link may be incomplete. Please use the link from your email, or contact the studio.</p><a class="btn" href="/">Back to the website</a></div>
<?php else: ?>
  <?php if ($msg): ?><div class="msg"><?= $h($msg) ?></div><?php endif; ?>
  <div class="card">
    <span class="status <?= $h($b['status']) ?>"><?= $h($labels[$b['status']] ?? $b['status']) ?></span>
    <h1><?= $h($b['service_name']) ?></h1>
    <div class="row"><span>When</span><span><?= $h(nice_when($b['start_at'])) ?></span></div>
    <div class="row"><span>Length</span><span><?= $h(dur_text((int)$b['duration'])) ?></span></div>
    <div class="row"><span>Price</span><span><?= $h(money((int)$b['price'])) ?></span></div>
    <?php if ((int)$b['deposit'] > 0): ?>
    <div class="row"><span>Deposit</span><span><?= $h(money((int)$b['deposit']) . ' · ' . ($depText[$b['deposit_status']] ?? '')) ?></span></div>
    <?php if (in_array($b['deposit_status'], ['authorized', 'captured'], true)): ?><div class="row"><span>To pay in the studio</span><span><?= $h(money((int)$b['price'] - (int)$b['deposit'])) ?></span></div><?php endif; ?>
    <?php endif; ?>
    <div class="row"><span>Reference</span><span><?= $h($b['ref']) ?></span></div>
    <?php if ($b['status'] === 'declined' && $b['reason']): ?><p class="muted"><?= $h($b['reason']) ?></p><?php endif; ?>
    <?php if ($b['status'] === 'pending'): ?><p class="muted">We'll email you as soon as your appointment is confirmed.<?= $b['deposit_status'] === 'authorized' ? ' Your deposit is only taken if it is confirmed; otherwise the hold is released.' : '' ?></p><?php endif; ?>
  </div>
  <?php if (in_array($b['status'], ['awaiting_payment', 'expired'], true) && (int)$b['deposit'] > 0): ?>
  <form method="post" class="card">
    <input type="hidden" name="t" value="<?= $h($token) ?>"><input type="hidden" name="do" value="pay">
    <p class="muted" style="margin:0"><?= $b['status'] === 'expired' ? 'Your time was released because the deposit wasn\'t paid. If it\'s still free, you can pay now to request it.' : 'Your time is held for a few minutes. Pay the deposit to send your request to the studio.' ?></p>
    <button type="submit">Pay <?= $h(money((int)$b['deposit'])) ?> deposit</button>
  </form>
  <?php endif; ?>
  <?php if ($canCancel): ?>
  <form method="post" class="card" id="cancelForm">
    <input type="hidden" name="t" value="<?= $h($token) ?>">
    <input type="hidden" name="do" value="cancel">
    <p class="muted" style="margin:0">Can't make it? Cancel here up to <?= $cancelHours ?> hours before your appointment, so someone else can have the time.</p>
    <button type="button" class="ghost" id="ask">Cancel appointment</button>
    <div id="confirm" hidden style="display:grid;gap:8px"><p style="margin:0">Cancel this appointment?</p><button type="submit">Yes, cancel it</button><button type="button" class="ghost" id="keep">Keep it</button></div>
  </form>
  <script>
    const a=document.getElementById('ask'),c=document.getElementById('confirm');
    a.onclick=()=>{a.hidden=true;c.hidden=false};document.getElementById('keep').onclick=()=>{a.hidden=false;c.hidden=true};
  </script>
  <?php endif; ?>
  <?php if (in_array($b['status'], ['declined', 'cancelled', 'expired'], true)): ?><a class="btn" href="/#book">Book another time</a><?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
