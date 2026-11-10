<?php
// One-time: sends http:// and www. visitors to https://perfect-beauty.uk (permanent redirect in .htaccess).
// Only for someone signed in to the admin. Tests the result and puts the old file back if anything breaks.
define('PB', 1);
require __DIR__ . '/lib/app.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

function signed_in(): bool {
    $t = $_COOKIE['pb_admin'] ?? '';
    if (strlen($t) !== 64) return false;
    $q = db()->prepare('SELECT 1 FROM admin_tokens WHERE hash=? AND expires_at>?');
    $q->execute([hash('sha256', $t), time()]);
    return (bool)$q->fetchColumn();
}
/** Status code and redirect target of one request, without following redirects. */
function probe(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15, CURLOPT_HEADER => true]);
    curl_exec($ch);
    $r = [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL)];
    curl_close($ch);
    return $r;
}

const BLOCK = "# --- Perfect Beauty https ---\n" .
    "RewriteEngine On\n" .
    "RewriteCond %{SERVER_PORT} !=443 [OR]\n" .
    "RewriteCond %{HTTP_HOST} ^www\\. [NC]\n" .
    "RewriteRule ^(.*)$ https://perfect-beauty.uk/$1 [R=301,L]\n" .
    "# --- end https ---\n";

$ht = __DIR__ . '/.htaccess';
$src = is_file($ht) ? file_get_contents($ht) : '';
$has = str_contains($src, 'Perfect Beauty https');
$done = false; $err = '';

if (signed_in() && !$has && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['confirm'] ?? '') === 'yes') {
    copy($ht, $ht . '.before-https');
    if (file_put_contents($ht, BLOCK . $src) === false) {
        $err = 'The website could not change its settings file. Nothing was changed.';
    } else {
        clearstatcache();
        $main = probe('https://perfect-beauty.uk/robots.txt');
        $http = probe('http://perfect-beauty.uk/robots.txt');
        $www  = probe('https://www.perfect-beauty.uk/robots.txt');
        $ok = $main[0] === 200 && $http[0] === 301 && $http[1] === 'https://perfect-beauty.uk/robots.txt'
            && $www[0] === 301 && $www[1] === 'https://perfect-beauty.uk/robots.txt';
        if ($ok) { $done = true; $has = true; }
        else {
            file_put_contents($ht, $src);   // put it back exactly as it was
            $err = "The redirect didn't test correctly, so the old settings were put back and the website is unchanged. " .
                "(Checks: https {$main[0]}, http {$http[0]} → " . ($http[1] ?: 'none') . ", www {$www[0]} → " . ($www[1] ?: 'none') . ')';
        }
    }
}
?><!doctype html>
<html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Secure address · Perfect Beauty</title>
<style>
body{margin:0;background:#F7F5F8;color:#1F1A24;font:16px/1.55 system-ui,-apple-system,sans-serif;padding:32px 16px}
main{max-width:480px;margin:0 auto;display:grid;gap:16px}
h1{font:32px/1.15 Georgia,serif;margin:0}
.card{background:#fff;border:1px solid #E4DEE6;border-radius:16px;padding:20px;display:grid;gap:12px}
.warn{background:#FBF0DC;color:#5a3b06;border-radius:12px;padding:12px 14px}
.ok{background:#E3F1EA;color:#1f4d36;border-radius:12px;padding:12px 14px}
button,a.btn{font:inherit;font-weight:600;border:0;border-radius:999px;padding:14px;background:#8A2F55;color:#fff;text-align:center;text-decoration:none;cursor:pointer}
label{display:flex;gap:10px;align-items:flex-start;font-size:15px}
input{width:20px;height:20px;accent-color:#8A2F55;margin-top:2px}
.muted{color:#6B6372;font-size:14px;margin:0}
</style></head><body><main>
<h1>Secure address</h1>
<?php if (!signed_in()): ?>
  <div class="card"><p style="margin:0">Sign in to the admin first, then come back to this page.</p>
  <a class="btn" href="/admin/">Open the admin</a></div>
<?php elseif ($done): ?>
  <div class="card"><div class="ok"><b>Done and tested.</b> Everyone now lands on https://perfect-beauty.uk, whichever address they type.</div>
  <p class="muted">Google will switch its listing to the secure address over the next few visits.</p>
  <a class="btn" href="/">Open the website</a></div>
<?php elseif ($has): ?>
  <div class="card"><div class="ok">This is already set up: http:// and www. visitors go to https://perfect-beauty.uk.</div><a class="btn" href="/">Open the website</a></div>
<?php else: ?>
  <div class="card">
    <p style="margin:0">This sends anyone who types <b>http://</b> or <b>www.</b> to the secure address <b>https://perfect-beauty.uk</b>, and tells Google that's the one to show.</p>
    <?php if ($err): ?><div class="warn"><?= $h($err) ?></div><?php endif; ?>
    <form method="post" style="display:grid;gap:12px">
      <label><input type="checkbox" name="confirm" value="yes" required> Switch on the secure address</label>
      <button type="submit">Switch on</button>
    </form>
    <p class="muted">It's tested straight away. If anything doesn't work, the old settings are put back automatically.</p>
  </div>
<?php endif; ?>
</main></body></html>
