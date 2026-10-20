<?php
// Deploy check: shows the server paths, runs one deploy now, and prints the exact cron command.
// Sits behind the preview password. Remove before the site goes public.
header('Content-Type: text/plain; charset=utf-8');

$deployDir = realpath(__DIR__ . '/../deploy');
echo "Website folder : " . __DIR__ . "\n";
echo "Deploy folder  : " . ($deployDir ?: 'NOT FOUND - expected a folder called deploy next to perfect-beauty') . "\n";
echo "PHP version    : " . PHP_VERSION . "\n";
echo "curl / zip     : " . (extension_loaded('curl') ? 'yes' : 'NO') . ' / ' . (class_exists('ZipArchive') ? 'yes' : 'NO') . "\n";
$bins = array_merge(glob('/usr/bin/php*-cli') ?: [], glob('/usr/bin/php8*') ?: []);
echo "PHP programs   : " . implode('  ', array_unique($bins)) . "\n\n";

if (!$deployDir) exit;
echo "Files in deploy: " . implode(', ', array_diff(scandir($deployDir), ['.', '..'])) . "\n";

$cfg = "$deployDir/config.php";
if (!is_file($cfg)) { echo "\nconfig.php is missing from the deploy folder.\n"; exit; }
$src = file_get_contents($cfg);
if (strpos($src, 'PASTE_TOKEN_HERE') !== false) { echo "\nconfig.php still has PASTE_TOKEN_HERE. Paste the GitHub token in and upload it again.\n"; exit; }
if (preg_match('/[\x{2018}\x{2019}\x{201C}\x{201D}]/u', $src)) { echo "\nconfig.php has curly quotes (TextEdit changed them). Retype them as straight ' quotes.\n"; exit; }
if (strpos(file_get_contents("$deployDir/deploy.php"), 'PB_ALLOW_WEB') === false) { echo "\nUpload the new deploy.php into the deploy folder first.\n"; exit; }

$best = '';
$best = "/usr/bin/php" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;
echo "\nCron command to use:\n  " . $best . " $deployDir/deploy.php\n";

echo "\nRunning a deploy now...\n";
register_shutdown_function(function () use ($deployDir) {
    $log = @file("$deployDir/deploy.log");
    echo $log ? "Last log lines:\n  " . implode('  ', array_slice($log, -5)) : "No log was written.\n";
});
define('PB_ALLOW_WEB', true);
$argv = ['deploy.php', 'force'];
require "$deployDir/deploy.php";
