<?php
// Public: services, prices, opening hours and booking rules for the website.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/availability.php';
require __DIR__ . '/../lib/stripe.php';

$cats = [];
foreach (db()->query('SELECT * FROM services WHERE active=1 ORDER BY sort, id') as $s)
    $cats[$s['category']][] = ['id' => (int)$s['id'], 'name' => $s['name'], 'price' => (int)$s['price'], 'duration' => (int)$s['duration'],
        'deposit' => deposit_for((int)$s['price'])];

json_out([
    'ok' => true,
    'categories' => array_map(fn($k, $v) => ['cat' => $k, 'items' => $v], array_keys($cats), $cats),
    'hours' => hours_list(),
    'rules' => [
        'min_notice_hours' => (int)setting('min_notice_hours', 12),
        'max_months' => (int)setting('max_months', 2),
        'last_date' => booking_last_date()->format('Y-m-d'),
        'deposits' => deposit_for(100000) > 0 || deposit_for(5000) > 0,
        // so the website can show the deposit for a combination of treatments (the server still decides)
        'deposit' => stripe_ready() ? ['mode' => setting('deposit_mode', 'off'), 'percent' => (int)setting('deposit_percent', 0),
            'fixed' => (int)setting('deposit_fixed', 1000), 'min' => (int)setting('deposit_min_price', 1000)] : null,
        'no_deposit_ok' => setting('allow_no_deposit', '1') === '1',
        'cancel_hours' => (int)setting('cancel_hours', 24),
    ],
]);
