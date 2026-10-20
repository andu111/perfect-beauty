<?php
/*
 * Perfect Beauty auto-deploy.
 * Run by an IONOS cron job every few minutes. When the GitHub repo has a new
 * commit, it copies the repo's site/ folder into the website folder.
 * Files whose names start with ".ht" (the password lock) are never touched.
 */

require __DIR__ . '/config.php';   // defines $TOKEN, $REPO, $BRANCH

$TARGET = __DIR__ . '/../perfect-beauty';
$STATE  = __DIR__ . '/state.json';
$LOG    = __DIR__ . '/deploy.log';
$FORCE  = in_array('force', $argv ?? [], true) || isset($_GET['force']);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }   // cron only, never from the web

function logline($msg) {
    global $LOG;
    file_put_contents($LOG, date('Y-m-d H:i:s') . "  $msg\n", FILE_APPEND);
}

function gh_get($url, $token, $toFile = null) {
    $ch = curl_init($url);
    $opts = [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            'Accept: application/vnd.github+json',
            'User-Agent: perfect-beauty-deploy',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ];
    if ($toFile) { $fh = fopen($toFile, 'w'); $opts[CURLOPT_FILE] = $fh; }
    else         { $opts[CURLOPT_RETURNTRANSFER] = true; }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if (isset($fh)) fclose($fh);
    if ($code !== 200) throw new RuntimeException("GitHub returned HTTP $code $err for $url");
    return $body;
}

function rmtree($dir) {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($dir);
}

function is_protected($rel) {
    return strncmp(basename($rel), '.ht', 3) === 0;
}

// Only one run at a time.
$lock = fopen(__DIR__ . '/deploy.lock', 'c');
if (!flock($lock, LOCK_EX | LOCK_NB)) exit(0);

$state = json_decode(@file_get_contents($STATE), true) ?: ['sha' => '', 'files' => []];
$tmp = sys_get_temp_dir() . '/pb-deploy-' . getmypid();

try {
    if (getenv('PB_TEST_ZIP')) {                       // local testing only
        $sha = getenv('PB_TEST_SHA');
    } else {
        $commit = json_decode(gh_get("https://api.github.com/repos/$REPO/commits/$BRANCH", $TOKEN), true);
        $sha = $commit['sha'] ?? '';
        if (!$sha) throw new RuntimeException('Could not read the latest commit.');
    }
    if ($sha === $state['sha'] && !$FORCE) exit(0);    // nothing new

    if (!is_dir($TARGET)) throw new RuntimeException("Website folder not found: $TARGET");
    $TARGET = realpath($TARGET);

    mkdir($tmp, 0700, true);
    $zipFile = "$tmp/repo.zip";
    if (getenv('PB_TEST_ZIP')) copy(getenv('PB_TEST_ZIP'), $zipFile);
    else gh_get("https://api.github.com/repos/$REPO/zipball/$sha", $TOKEN, $zipFile);

    $zip = new ZipArchive();
    if ($zip->open($zipFile) !== true) throw new RuntimeException('Downloaded file is not a valid zip.');
    $zip->extractTo("$tmp/x");
    $zip->close();

    $top = glob("$tmp/x/*", GLOB_ONLYDIR);
    $src = ($top[0] ?? '') . '/site';
    if (!is_dir($src)) throw new RuntimeException('The repo has no site/ folder.');

    // Copy new and changed files.
    $files = []; $changed = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isDir()) continue;
        $rel = substr($f->getPathname(), strlen($src) + 1);
        if (is_protected($rel)) continue;
        $files[] = $rel;
        $dest = "$TARGET/$rel";
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        if (!is_file($dest) || md5_file($dest) !== md5_file($f->getPathname())) {
            if (!copy($f->getPathname(), $dest)) throw new RuntimeException("Could not write $rel");
            $changed++;
        }
    }

    // Remove files that were deployed before but are no longer in the repo.
    $removed = 0;
    foreach (array_diff($state['files'], $files) as $rel) {
        if (is_protected($rel)) continue;
        $p = "$TARGET/$rel";
        if (is_file($p) && unlink($p)) $removed++;
    }

    file_put_contents($STATE, json_encode(['sha' => $sha, 'files' => $files, 'at' => date('c')], JSON_PRETTY_PRINT));
    logline('Deployed ' . substr($sha, 0, 7) . ": $changed updated, $removed removed, " . count($files) . ' files total');
} catch (Throwable $e) {
    logline('ERROR: ' . $e->getMessage());
    exit(1);
} finally {
    rmtree($tmp);
}
