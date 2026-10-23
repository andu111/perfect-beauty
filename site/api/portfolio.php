<?php
// Public: portfolio photos in the order set in the booking manager.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/portfolio.php';
json_out(['ok' => true, 'photos' => photos(true)]);
