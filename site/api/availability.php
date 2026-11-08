<?php
// Public: free start times for a service over a range of days.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/availability.php';

// One treatment (?service=3) or several back to back (?services=3,12).
$svc = combo_services($_GET['services'] ?? $_GET['service'] ?? '');

$from = $_GET['from'] ?? now()->format('Y-m-d');
if (!valid_date($from)) fail('Bad date.');
$days = max(1, min(62, (int)($_GET['days'] ?? 21)));

$out = [];
$d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, tz());
for ($i = 0; $i < $days; $i++, $d = $d->modify('+1 day')) {
    $date = $d->format('Y-m-d');
    $open = hours_for((int)$d->format('w')) !== null;
    $out[] = ['date' => $date, 'open' => $open, 'slots' => $open ? day_slots($date, (int)$svc['duration']) : []];
}
json_out(['ok' => true, 'service' => ['name' => $svc['name'], 'duration' => (int)$svc['duration']], 'days' => $out]);
