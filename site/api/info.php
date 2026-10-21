<?php
// Public: services, prices, opening hours and booking rules for the website.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/availability.php';

$cats = [];
foreach (db()->query('SELECT * FROM services WHERE active=1 ORDER BY sort, id') as $s)
    $cats[$s['category']][] = ['id' => (int)$s['id'], 'name' => $s['name'], 'price' => (int)$s['price'], 'duration' => (int)$s['duration']];

json_out([
    'ok' => true,
    'categories' => array_map(fn($k, $v) => ['cat' => $k, 'items' => $v], array_keys($cats), $cats),
    'hours' => hours_list(),
    'rules' => [
        'min_notice_hours' => (int)setting('min_notice_hours', 12),
        'max_days' => (int)setting('max_days', 60),
        'deposit_percent' => (int)setting('deposit_percent', 0),
    ],
]);
