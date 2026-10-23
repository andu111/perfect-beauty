<?php
// Admin API for the studio's booking manager. Everything except login needs the admin cookie.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/availability.php';
require __DIR__ . '/../lib/notify.php';
require __DIR__ . '/../lib/stripe.php';
require __DIR__ . '/../lib/portfolio.php';

const COOKIE = 'pb_admin';
const SESSION_DAYS = 180;

$action = (string)($_GET['a'] ?? '');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
// Custom header blocks cross-site form posts (browsers can't add it without our permission).
if ($isPost && ($_SERVER['HTTP_X_PB'] ?? '') !== '1') fail('Bad request.', 400);
$in = $isPost ? body() : [];

/** Signed-in account name, or null. Sessions from before accounts existed belong to Alexandra. */
function admin_user(): ?string {
    $t = $_COOKIE[COOKIE] ?? '';
    if (strlen($t) !== 64) return null;
    $q = db()->prepare('SELECT COALESCE(user, ?) FROM admin_tokens WHERE hash=? AND expires_at>?');
    $q->execute([PB_OWNER, hash('sha256', $t), time()]);
    $u = $q->fetchColumn();
    return $u === false ? null : (string)$u;
}
function set_cookie(string $v, int $exp): void {
    setcookie(COOKIE, $v, ['expires' => $exp, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true, 'samesite' => 'Strict']);
}
function load_booking(int $id): array {
    $q = db()->prepare('SELECT * FROM bookings WHERE id=?'); $q->execute([$id]);
    return $q->fetch() ?: fail('Booking not found.', 404);
}

/* ---------- login ---------- */
if ($action === 'login') {
    if (!$isPost) fail('Use POST.', 405);
    $accounts = admin_accounts();
    if (!admin_accounts()) fail('The admin password has not been set up yet.', 403);
    if (!rate_ok('login', 8, 900, false)) fail('Too many attempts. Wait 15 minutes and try again.', 429);
    // One password box: the password picks the account (Alexandra's or andu's).
    $pw = (string)($in['password'] ?? ''); $who = null;
    $u = strtolower(trim((string)($in['user'] ?? '')));
    if ($u !== '') $accounts = isset($accounts[$u]) ? [$u => $accounts[$u]] : [];   // username given: only that account
    foreach ($accounts as $name => $hash) if (password_verify($pw, $hash)) { $who = $name; break; }
    if ($who === null) { rate_ok('login', 8, 900); fail('That password is not right.', 401); }
    $t = bin2hex(random_bytes(32)); $exp = time() + SESSION_DAYS * 86400;
    db()->prepare('INSERT INTO admin_tokens(hash,expires_at,created_at,ua,user) VALUES(?,?,?,?,?)')
        ->execute([hash('sha256', $t), $exp, time(), mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200), $who]);
    db()->prepare('DELETE FROM admin_tokens WHERE expires_at<?')->execute([time()]);
    set_cookie($t, $exp);
    json_out(['ok' => true]);
}
$ME = admin_user();
if ($ME === null) fail('Please sign in.', 401, ['code' => 'auth']);

switch ($action) {

case 'logout':
    db()->prepare('DELETE FROM admin_tokens WHERE hash=?')->execute([hash('sha256', $_COOKIE[COOKIE] ?? '')]);
    set_cookie('', time() - 3600);
    json_out(['ok' => true]);

case 'me':
    json_out(['ok' => true, 'vapid' => vapid_keys()['public'], 'pending' => pending_count(),
        'devices' => (int)db()->query('SELECT COUNT(*) FROM push_subs WHERE enabled=1')->fetchColumn(),
        'device_hashes' => array_map(fn($e) => hash('sha256', $e), db()->query('SELECT endpoint FROM push_subs')->fetchAll(PDO::FETCH_COLUMN)),
        'notify_email' => setting('notify_email', ''), 'user' => $ME]);

case 'bookings': {
    $scope = $_GET['scope'] ?? 'pending';
    $today = now()->format('Y-m-d H:i');
    if ($scope === 'pending') {
        $q = db()->query("SELECT * FROM bookings WHERE status='pending' ORDER BY start_at");
    } elseif ($scope === 'day' && valid_date($_GET['date'] ?? '')) {
        $d = $_GET['date'];
        $q = db()->prepare("SELECT * FROM bookings WHERE start_at>=? AND start_at<? AND status IN ('pending','accepted','cancelled','awaiting_payment') ORDER BY start_at");
        $q->execute(["$d 00:00", "$d 24:00"]);
    } elseif ($scope === 'upcoming') {
        $q = db()->prepare("SELECT * FROM bookings WHERE status='accepted' AND start_at>=? ORDER BY start_at LIMIT 200");
        $q->execute([$today]);
    } else {
        $q = db()->prepare("SELECT * FROM bookings WHERE start_at<? OR status IN ('declined','cancelled') ORDER BY start_at DESC LIMIT 100");
        $q->execute([$today]);
    }
    json_out(['ok' => true, 'bookings' => array_map('booking_public', $q->fetchAll()), 'pending' => pending_count()]);
}

case 'counts': {
    // Bookings per day for the diary strip.
    $from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : now()->format('Y-m-d');
    $q = db()->prepare("SELECT substr(start_at,1,10) d, SUM(status='accepted') a, SUM(status='pending') p FROM bookings
        WHERE start_at>=? AND start_at<date(?, '+31 days') GROUP BY d");
    $q->execute(["$from 00:00", $from]);
    json_out(['ok' => true, 'days' => $q->fetchAll()]);
}

case 'decide': {
    if (!$isPost) fail('Use POST.', 405);
    $b = load_booking((int)($in['id'] ?? 0));
    $to = $in['status'] ?? '';
    $allowed = ['pending' => ['accepted', 'declined'], 'accepted' => ['cancelled']];
    if (!in_array($to, $allowed[$b['status']] ?? [], true)) fail('This booking has already been ' . $b['status'] . '.', 409);
    $reason = trim(mb_substr((string)($in['reason'] ?? ''), 0, 300));
    db()->prepare('UPDATE bookings SET status=?, reason=?, decided_at=? WHERE id=?')
        ->execute([$to, $reason ?: null, now()->format('Y-m-d H:i:s'), $b['id']]);
    $b = load_booking($b['id']);
    // Deposit: take it on Accept, give it back on Decline or Cancel.
    $payErr = $to === 'accepted' ? deposit_capture($b) : deposit_release($b, $to === 'declined' ? 'Declined by the studio' : 'Cancelled by the studio');
    $b = load_booking($b['id']);
    $mailErr = (($in['email'] ?? true) !== false) ? notify_decision($b) : null;
    json_out(['ok' => true, 'booking' => booking_public($b), 'pending' => pending_count(), 'email_error' => $mailErr, 'payment_error' => $payErr]);
}

case 'create': {
    // Bookings she takes by phone or in person. Confirmed straight away.
    if (!$isPost) fail('Use POST.', 405);
    $q = db()->prepare('SELECT * FROM services WHERE id=?'); $q->execute([(int)($in['service'] ?? 0)]);
    $svc = $q->fetch() ?: fail('Choose a treatment.');
    $date = (string)($in['date'] ?? ''); $time = (string)($in['time'] ?? '');
    if (!valid_date($date) || !valid_hm($time)) fail('Choose a day and time.');
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') fail('Enter the client\'s name.');
    $dur = (int)($in['duration'] ?? 0) ?: (int)$svc['duration'];
    if (clashes($date, $time, $dur) && empty($in['force'])) fail('That overlaps another booking or time off.', 409, ['code' => 'clash']);
    $b = ['ref' => strtoupper(substr(bin2hex(random_bytes(4)), 0, 6)), 'token' => bin2hex(random_bytes(16)),
        'service_id' => (int)$svc['id'], 'service_name' => $svc['name'], 'price' => isset($in['price']) ? (int)round($in['price'] * 100) : (int)$svc['price'],
        'duration' => $dur, 'start_at' => "$date $time", 'end_at' => end_stamp($date, $time, $dur), 'name' => $name,
        'phone' => trim((string)($in['phone'] ?? '')), 'email' => trim((string)($in['email'] ?? '')), 'notes' => trim((string)($in['notes'] ?? '')),
        'status' => 'accepted', 'source' => 'admin', 'created_at' => now()->format('Y-m-d H:i:s'), 'decided_at' => now()->format('Y-m-d H:i:s')];
    db()->prepare('INSERT INTO bookings(' . implode(',', array_keys($b)) . ') VALUES(' . rtrim(str_repeat('?,', count($b)), ',') . ')')->execute(array_values($b));
    $b = load_booking((int)db()->lastInsertId());
    if (!empty($in['send_email']) && $b['email']) notify_decision($b);
    json_out(['ok' => true, 'booking' => booking_public($b)]);
}

case 'schedule': {
    $svcs = db()->query('SELECT * FROM services ORDER BY sort, id')->fetchAll();
    $off = db()->prepare('SELECT * FROM time_off WHERE end_at>=? ORDER BY start_at');
    $off->execute([now()->format('Y-m-d 00:00')]);
    $keys = ['business_name', 'notify_email', 'slot_step', 'buffer', 'min_notice_hours', 'max_days', 'cancel_hours', 'deposit_percent', 'email_alerts', 'deposit_mode'];
    json_out(['ok' => true, 'hours' => hours_list(), 'time_off' => $off->fetchAll(),
        'settings' => array_combine($keys, array_map(fn($k) => setting($k, ''), $keys)) + ['from_email' => setting('from_email', ''), 'smtp_set' => setting('smtp_pass', '') !== '',
            'deposit_fixed' => (int)setting('deposit_fixed', 1000) / 100, 'deposit_min_price' => (int)setting('deposit_min_price', 1000) / 100,
            'stripe_mode' => stripe_mode(), 'webhook_set' => setting('stripe_webhook_secret', '') !== '',
            'webhook_url' => site_url('/admin/stripe-webhook.php')],
        'services' => array_map(fn($s) => ['id' => (int)$s['id'], 'category' => $s['category'], 'name' => $s['name'],
            'price' => (int)$s['price'], 'duration' => (int)$s['duration'], 'active' => (bool)$s['active']], $svcs)]);
}

case 'hours': {
    if (!$isPost) fail('Use POST.', 405);
    $rows = $in['hours'] ?? [];
    if (!is_array($rows) || count($rows) !== 7) fail('Send all seven days.');
    $up = db()->prepare('UPDATE hours SET open=?, close=?, break_start=?, break_end=? WHERE weekday=?');
    foreach ($rows as $r) {
        $wd = (int)($r['weekday'] ?? -1); if ($wd < 0 || $wd > 6) fail('Bad day.');
        $open = $r['open'] ?: null; $close = $r['close'] ?: null; $bs = $r['break_start'] ?: null; $be = $r['break_end'] ?: null;
        if ($open || $close) {
            if (!valid_hm($open) || !valid_hm($close) || $open >= $close) fail('Check the opening and closing times.');
            if ($bs || $be) {
                if (!valid_hm($bs) || !valid_hm($be) || $bs >= $be || $bs < $open || $be > $close) fail('Breaks must start and end inside opening hours.');
            }
        } else { $bs = $be = null; }
        $up->execute([$open, $close, $bs, $be, $wd]);
    }
    json_out(['ok' => true, 'hours' => hours_list()]);
}

case 'timeoff_add': {
    if (!$isPost) fail('Use POST.', 405);
    $sd = $in['start_date'] ?? ''; $ed = $in['end_date'] ?? $sd;
    $st = $in['start_time'] ?: '00:00'; $et = $in['end_time'] ?: '24:00';
    if (!valid_date($sd) || !valid_date($ed)) fail('Choose the dates.');
    if (!valid_hm($st) || !($et === '24:00' || valid_hm($et))) fail('Check the times.');
    if ("$ed $et" <= "$sd $st") fail('The end must be after the start.');
    db()->prepare('INSERT INTO time_off(start_at,end_at,note) VALUES(?,?,?)')->execute(["$sd $st", "$ed $et", trim(mb_substr((string)($in['note'] ?? ''), 0, 100))]);
    $q = db()->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('pending','accepted') AND start_at<? AND end_at>?");
    $q->execute(["$ed $et", "$sd $st"]);
    json_out(['ok' => true, 'overlapping' => (int)$q->fetchColumn()]);
}

case 'timeoff_delete':
    if (!$isPost) fail('Use POST.', 405);
    db()->prepare('DELETE FROM time_off WHERE id=?')->execute([(int)($in['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'settings': {
    if (!$isPost) fail('Use POST.', 405);
    $ints = ['slot_step' => [5, 120], 'buffer' => [0, 120], 'min_notice_hours' => [0, 336], 'max_days' => [1, 365],
             'cancel_hours' => [0, 336], 'deposit_percent' => [0, 100]];
    foreach ($ints as $k => [$lo, $hi]) if (isset($in[$k])) {
        $v = (int)$in[$k]; if ($v < $lo || $v > $hi) fail("Check the value for $k."); set_setting($k, (string)$v);
    }
    if (isset($in['notify_email'])) {
        $e = trim((string)$in['notify_email']);
        if ($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('That email address doesn\'t look right.');
        set_setting('notify_email', $e);
    }
    if (isset($in['from_email'])) {
        $e = trim((string)$in['from_email']);
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) fail('The sending address doesn\'t look right.');
        set_setting('from_email', $e);
    }
    if (isset($in['email_alerts'])) set_setting('email_alerts', $in['email_alerts'] ? '1' : '0');
    if (isset($in['deposit_mode'])) {
        if (!in_array($in['deposit_mode'], ['off', 'percent', 'fixed'], true)) fail('Choose how the deposit is worked out.');
        set_setting('deposit_mode', $in['deposit_mode']);
    }
    foreach (['deposit_fixed' => [0.5, 500], 'deposit_min_price' => [0, 1000]] as $k => [$lo, $hi]) if (isset($in[$k]) && $in[$k] !== '') {
        $v = (float)$in[$k]; if ($v < $lo || $v > $hi) fail('Check the deposit amounts.'); set_setting($k, (string)(int)round($v * 100));
    }
    if (isset($in['stripe_secret']) && trim($in['stripe_secret']) !== '') {
        $k = trim($in['stripe_secret']);
        if (!preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $k)) fail('That doesn\'t look like a Stripe secret key. It starts with sk_live_ or sk_test_.');
        set_setting('stripe_secret', $k);
    }
    if (isset($in['stripe_webhook_secret']) && trim($in['stripe_webhook_secret']) !== '') {
        $k = trim($in['stripe_webhook_secret']);
        if (!str_starts_with($k, 'whsec_')) fail('The signing secret starts with whsec_.');
        set_setting('stripe_webhook_secret', $k);
    }
    if (!empty($in['stripe_clear'])) { set_setting('stripe_secret', ''); set_setting('stripe_webhook_secret', ''); }
    if (isset($in['smtp_pass']) && $in['smtp_pass'] !== '') set_setting('smtp_pass', (string)$in['smtp_pass']);
    if (!empty($in['smtp_clear'])) set_setting('smtp_pass', '');
    if (isset($in['business_name']) && trim($in['business_name']) !== '') set_setting('business_name', trim(mb_substr($in['business_name'], 0, 60)));
    json_out(['ok' => true]);
}

case 'services': {
    if (!$isPost) fail('Use POST.', 405);
    $up = db()->prepare('UPDATE services SET name=?, price=?, duration=?, active=? WHERE id=?');
    foreach (($in['services'] ?? []) as $s) {
        $name = trim((string)($s['name'] ?? '')); $price = (int)round(((float)($s['price'] ?? 0)) * 100); $dur = (int)($s['duration'] ?? 0);
        if ($name === '' || $price < 0 || $dur < 5 || $dur > 600) fail("Check \"$name\": it needs a name, a price and a length between 5 and 600 minutes.");
        $up->execute([$name, $price, $dur, empty($s['active']) ? 0 : 1, (int)$s['id']]);
    }
    if (!empty($in['add'])) {
        $a = $in['add']; $name = trim((string)($a['name'] ?? '')); $cat = trim((string)($a['category'] ?? ''));
        if ($name === '' || $cat === '') fail('A new treatment needs a name and a category.');
        $sort = (int)db()->query('SELECT COALESCE(MAX(sort),0)+1 FROM services')->fetchColumn();
        db()->prepare('INSERT INTO services(category,name,price,duration,sort) VALUES(?,?,?,?,?)')
            ->execute([$cat, $name, (int)round(((float)($a['price'] ?? 0)) * 100), max(5, (int)($a['duration'] ?? 30)), $sort]);
    }
    json_out(['ok' => true]);
}

case 'push_subscribe': {
    if (!$isPost) fail('Use POST.', 405);
    $ep = (string)($in['endpoint'] ?? ''); $k = $in['keys'] ?? [];
    if (!preg_match('#^https://#', $ep) || empty($k['p256dh']) || empty($k['auth'])) fail('That device could not be registered.');
    db()->prepare('INSERT INTO push_subs(endpoint,p256dh,auth,label,created_at) VALUES(?,?,?,?,?)
        ON CONFLICT(endpoint) DO UPDATE SET p256dh=excluded.p256dh, auth=excluded.auth, enabled=1,
        label=CASE WHEN length(excluded.label)>0 THEN excluded.label ELSE push_subs.label END')
        ->execute([$ep, $k['p256dh'], $k['auth'], mb_substr((string)($in['label'] ?? ''), 0, 60), now()->format('Y-m-d H:i:s')]);
    json_out(['ok' => true]);
}

case 'mail_test': {
    if (!$isPost) fail('Use POST.', 405);
    $to = setting('notify_email', '');
    if ($to === '') fail('Add your email under Alerts first, then save.');
    $err = null;
    $ok = send_mail($to, 'Test email from your booking system', "This is a test. If you can read this, booking emails are working.\n\nSent " . now()->format('j M Y, H:i') . "\n", $err);
    $ok ? json_out(['ok' => true, 'to' => $to]) : fail('The email could not be sent: ' . ($err ?: 'the server refused it.'));
}

case 'photos':
    json_out(['ok' => true, 'photos' => photos(false), 'cats' => PB_PHOTO_CATS,
        'services' => db()->query('SELECT name FROM services WHERE active=1 ORDER BY sort, id')->fetchAll(PDO::FETCH_COLUMN)]);

case 'photo_add': {
    // multipart: full (max 1400px) and thumb (max 720px), already resized and stripped on the phone
    if (!$isPost) fail('Use POST.', 405);
    try { [$full] = save_upload('full', 1400); [$thumb, $w, $h] = save_upload('thumb', 720); }
    catch (Throwable $e) { if (isset($full)) remove_media($full); fail($e->getMessage()); }
    $cat = in_array($_POST['category'] ?? '', PB_PHOTO_CATS, true) ? $_POST['category'] : 'Lashes';
    $sort = (int)db()->query('SELECT COALESCE(MIN(sort),0)-1 FROM portfolio')->fetchColumn();   // new photos go first
    db()->prepare('INSERT INTO portfolio(full,thumb,w,h,category,title,sort,visible,created_at) VALUES(?,?,?,?,?,?,?,1,?)')
        ->execute([$full, $thumb, $w, $h, $cat, mb_substr(trim((string)($_POST['title'] ?? '')), 0, 60), $sort, now()->format('Y-m-d H:i:s')]);
    $q = db()->prepare('SELECT * FROM portfolio WHERE id=?'); $q->execute([(int)db()->lastInsertId()]);
    json_out(['ok' => true, 'photo' => photo_public($q->fetch())]);
}

case 'photo_before': {
    // multipart: id + full + thumb for the "before" side of a drag slider
    if (!$isPost) fail('Use POST.', 405);
    $q = db()->prepare('SELECT * FROM portfolio WHERE id=?'); $q->execute([(int)($_POST['id'] ?? 0)]);
    $p = $q->fetch() ?: fail('Photo not found.', 404);
    try { [$full] = save_upload('full', 1400); [$thumb] = save_upload('thumb', 720); }
    catch (Throwable $e) { if (isset($full)) remove_media($full); fail($e->getMessage()); }
    remove_media($p['before_full']); remove_media($p['before_thumb']);
    db()->prepare('UPDATE portfolio SET before_full=?, before_thumb=? WHERE id=?')->execute([$full, $thumb, $p['id']]);
    json_out(['ok' => true]);
}

case 'photo_update': {
    if (!$isPost) fail('Use POST.', 405);
    $q = db()->prepare('SELECT * FROM portfolio WHERE id=?'); $q->execute([(int)($in['id'] ?? 0)]);
    $p = $q->fetch() ?: fail('Photo not found.', 404);
    $title = trim(mb_substr((string)($in['title'] ?? $p['title']), 0, 60));
    if ($title === '') fail('Give the photo a short title, like "Russian volume".');
    $cat = in_array($in['category'] ?? '', PB_PHOTO_CATS, true) ? $in['category'] : $p['category'];
    if (!empty($in['remove_before'])) { remove_media($p['before_full']); remove_media($p['before_thumb']); $p['before_full'] = $p['before_thumb'] = null; }
    db()->prepare('UPDATE portfolio SET title=?, category=?, note=?, service=?, visible=?, before_full=?, before_thumb=? WHERE id=?')
        ->execute([$title, $cat, trim(mb_substr((string)($in['note'] ?? ''), 0, 300)), trim((string)($in['service'] ?? '')) ?: null,
            array_key_exists('visible', $in) ? ($in['visible'] ? 1 : 0) : (int)$p['visible'], $p['before_full'], $p['before_thumb'], $p['id']]);
    json_out(['ok' => true]);
}

case 'photo_order': {
    if (!$isPost) fail('Use POST.', 405);
    $up = db()->prepare('UPDATE portfolio SET sort=? WHERE id=?');
    foreach (array_values((array)($in['ids'] ?? [])) as $i => $id) $up->execute([$i, (int)$id]);
    json_out(['ok' => true]);
}

case 'photo_delete': {
    if (!$isPost) fail('Use POST.', 405);
    $q = db()->prepare('SELECT * FROM portfolio WHERE id=?'); $q->execute([(int)($in['id'] ?? 0)]);
    $p = $q->fetch() ?: fail('Photo not found.', 404);
    foreach (['full', 'thumb', 'before_full', 'before_thumb'] as $k) remove_media($p[$k]);
    db()->prepare('DELETE FROM portfolio WHERE id=?')->execute([$p['id']]);
    json_out(['ok' => true]);
}

case 'stripe_check': {
    if (!stripe_ready()) fail('Add your Stripe secret key first.');
    try { $a = stripe_req('GET', '/v1/account'); }
    catch (Throwable $e) { fail('Stripe said: ' . $e->getMessage()); }
    json_out(['ok' => true, 'mode' => stripe_mode(), 'name' => $a['business_profile']['name'] ?? ($a['settings']['dashboard']['display_name'] ?? ''),
        'charges' => (bool)($a['charges_enabled'] ?? false), 'payouts' => (bool)($a['payouts_enabled'] ?? false)]);
}

case 'mail_log': {
    $rows = db()->query('SELECT id,at,to_addr,subject,ok,via,error,booking_id FROM mail_log ORDER BY id DESC LIMIT 40')->fetchAll();
    json_out(['ok' => true, 'emails' => array_map(fn($r) => ['id' => (int)$r['id'], 'at' => $r['at'], 'to' => $r['to_addr'], 'subject' => $r['subject'],
        'ok' => (bool)$r['ok'], 'via' => $r['via'], 'error' => $r['error']], $rows),
        'failed' => (int)db()->query("SELECT COUNT(*) FROM mail_log WHERE ok=0 AND at > datetime('now','-7 days')")->fetchColumn()]);
}

case 'mail_resend': {
    if (!$isPost) fail('Use POST.', 405);
    $q = db()->prepare('SELECT * FROM mail_log WHERE id=?'); $q->execute([(int)($in['id'] ?? 0)]);
    $m = $q->fetch() ?: fail('Email not found.', 404);
    $err = null;
    send_mail($m['to_addr'], $m['subject'], (string)$m['body'], $err, $m['booking_id'] ? (int)$m['booking_id'] : null)
        ? json_out(['ok' => true]) : fail('It still could not be sent: ' . $err);
}

case 'push_test': {
    if (!$isPost) fail('Use POST.', 405);
    $id = (int)($in['id'] ?? 0) ?: null;
    $n = push_all('Notifications are on', 'You\'ll get a message like this for every new booking request.', '/admin/', pending_count(), $id);
    json_out(['ok' => true, 'sent' => $n]);
}

case 'devices':
    json_out(['ok' => true, 'devices' => array_map(fn($d) => ['id' => (int)$d['id'], 'label' => $d['label'] ?: 'Phone',
        'enabled' => (bool)$d['enabled'], 'added' => $d['created_at'], 'hash' => hash('sha256', $d['endpoint'])],
        db()->query('SELECT * FROM push_subs ORDER BY created_at')->fetchAll())]);

case 'device_update': {
    if (!$isPost) fail('Use POST.', 405);
    $id = (int)($in['id'] ?? 0);
    if (array_key_exists('enabled', $in)) db()->prepare('UPDATE push_subs SET enabled=? WHERE id=?')->execute([$in['enabled'] ? 1 : 0, $id]);
    if (isset($in['label']) && trim($in['label']) !== '') db()->prepare('UPDATE push_subs SET label=? WHERE id=?')->execute([mb_substr(trim($in['label']), 0, 40), $id]);
    json_out(['ok' => true]);
}

case 'device_delete':
    if (!$isPost) fail('Use POST.', 405);
    db()->prepare('DELETE FROM push_subs WHERE id=?')->execute([(int)($in['id'] ?? 0)]);
    json_out(['ok' => true]);

case 'password': {
    if (!$isPost) fail('Use POST.', 405);
    // Changes only the signed-in account's password and signs that account out elsewhere.
    if (!password_verify((string)($in['current'] ?? ''), admin_user_hash($ME))) fail('Your current password is not right.');
    $new = (string)($in['new'] ?? '');
    if (strlen($new) < 10) fail('Use at least 10 characters.');
    foreach (admin_accounts() as $name => $h) if ($name !== $ME && password_verify($new, $h)) fail('Choose a different password.');
    set_admin_user_hash($ME, password_hash($new, PASSWORD_DEFAULT));
    db()->prepare('DELETE FROM admin_tokens WHERE hash<>? AND COALESCE(user, ?)=?')->execute([hash('sha256', $_COOKIE[COOKIE] ?? ''), PB_OWNER, $ME]);
    json_out(['ok' => true]);
}

default:
    fail('Unknown action.', 404);
}
