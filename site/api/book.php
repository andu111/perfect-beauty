<?php
// Public: create a booking request. It waits as "pending" until the studio accepts or declines it.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/availability.php';
require __DIR__ . '/../lib/notify.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Use POST.', 405);
$in = body();
if (!empty($in['company'])) json_out(['ok' => true, 'ref' => 'THANKS']);   // spam trap field, silently ignore

$name  = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
$phone = trim((string)($in['phone'] ?? ''));
$email = trim((string)($in['email'] ?? ''));
$notes = trim(mb_substr((string)($in['notes'] ?? ''), 0, 500));
$date  = (string)($in['date'] ?? '');
$time  = (string)($in['time'] ?? '');

if (mb_strlen($name) < 2 || mb_strlen($name) > 80) fail('Please enter your full name.');
$digits = preg_replace('/\D/', '', $phone);
if (strlen($digits) < 10 || strlen($digits) > 15) fail('Please enter a mobile number we can call or text.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Please enter a valid email address so we can confirm your booking.');
if (!valid_date($date) || !valid_hm($time)) fail('Please choose a day and time.');

$q = db()->prepare('SELECT * FROM services WHERE id=? AND active=1');
$q->execute([(int)($in['service'] ?? 0)]);
$svc = $q->fetch() ?: fail('That treatment is no longer available. Please choose another.');

if (!rate_ok('book', 6, 3600)) fail('Too many requests from this connection. Please call or message the studio instead.', 429);

$pdo = db();
$pdo->exec('BEGIN IMMEDIATE');   // one booking at a time, so two people can't take the same slot
try {
    if (!in_array($time, day_slots($date, (int)$svc['duration']), true)) {
        $pdo->exec('ROLLBACK');
        fail('Sorry, that time has just been taken. Please pick another.', 409, ['code' => 'taken']);
    }
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do { $ref = ''; for ($i = 0; $i < 6; $i++) $ref .= $alphabet[random_int(0, 31)];
         $c = $pdo->prepare('SELECT 1 FROM bookings WHERE ref=?'); $c->execute([$ref]); } while ($c->fetch());
    $b = [
        'ref' => $ref, 'token' => bin2hex(random_bytes(16)), 'service_id' => (int)$svc['id'], 'service_name' => $svc['name'],
        'price' => (int)$svc['price'], 'duration' => (int)$svc['duration'], 'start_at' => "$date $time",
        'end_at' => end_stamp($date, $time, (int)$svc['duration']), 'name' => $name, 'phone' => $phone, 'email' => $email,
        'notes' => $notes, 'status' => 'pending', 'source' => 'online', 'created_at' => now()->format('Y-m-d H:i:s'), 'ip' => client_ip(),
    ];
    $pdo->prepare('INSERT INTO bookings(' . implode(',', array_keys($b)) . ') VALUES(' . rtrim(str_repeat('?,', count($b)), ',') . ')')
        ->execute(array_values($b));
    $b['id'] = (int)$pdo->lastInsertId();
    $pdo->exec('COMMIT');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
    throw $e;
}

// Reply to the customer first, then send notifications.
ignore_user_abort(true);
$resp = json_encode(['ok' => true, 'ref' => $b['ref'], 'token' => $b['token']]);
header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . strlen($resp));
header('Connection: close');
echo $resp;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else { @ob_end_flush(); flush(); }
notify_new_request($b);
