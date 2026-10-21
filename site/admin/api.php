<?php
// Admin API for the studio's booking manager. Everything except login needs the admin cookie.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/availability.php';
require __DIR__ . '/../lib/notify.php';

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
    if (!$accounts) fail('The admin password has not been set up yet.', 403);
    if (!rate_ok('login', 8, 900, false)) fail('Too many attempts. Wait 15 minutes and try again.', 429);
    // One password box: the password picks the account (Alexandra's or andu's).
    $pw = (string)($in['password'] ?? ''); $who = null;
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
        'devices' => (int)db()->query('SELECT COUNT(*) FROM push_subs')->fetchColumn(),
        'notify_email' => setting('notify_email', ''), 'user' => $ME]);

case 'bookings': {
    $scope = $_GET['scope'] ?? 'pending';
    $today = now()->format('Y-m-d H:i');
    if ($scope === 'pending') {
        $q = db()->query("SELECT * FROM bookings WHERE status='pending' ORDER BY start_at");
    } elseif ($scope === 'day' && valid_date($_GET['date'] ?? '')) {
        $d = $_GET['date'];
        $q = db()->prepare("SELECT * FROM bookings WHERE start_at>=? AND start_at<? AND status IN ('pending','accepted','cancelled') ORDER BY start_at");
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
    if (($in['email'] ?? true) !== false) notify_decision($b);
    json_out(['ok' => true, 'booking' => booking_public($b), 'pending' => pending_count()]);
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
    $keys = ['business_name', 'notify_email', 'slot_step', 'buffer', 'min_notice_hours', 'max_days', 'cancel_hours', 'deposit_percent'];
    json_out(['ok' => true, 'hours' => hours_list(), 'time_off' => $off->fetchAll(),
        'settings' => array_combine($keys, array_map(fn($k) => setting($k, ''), $keys)) + ['from_email' => setting('from_email', ''), 'smtp_set' => setting('smtp_pass', '') !== ''],
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
        ON CONFLICT(endpoint) DO UPDATE SET p256dh=excluded.p256dh, auth=excluded.auth')
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

case 'push_test':
    if (!$isPost) fail('Use POST.', 405);
    $n = push_all('Notifications are on', 'You\'ll get a message like this for every new booking request.', '/admin/', pending_count());
    json_out(['ok' => true, 'sent' => $n]);

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
