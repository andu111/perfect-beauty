<?php
/* Portfolio photos: stored in media/portfolio/ (outside what deploys touch), listed in the portfolio table. */
if (!defined('PB')) { http_response_code(404); exit; }

const PB_PHOTO_CATS = ['Lashes', 'Lips', 'Brows', 'Nails', 'Waxing', 'Studio'];
define('PB_MEDIA_DIR', dirname(__DIR__) . '/media/portfolio');

function photo_public(array $r): array {
    return ['id' => (int)$r['id'], 'full' => $r['full'], 'thumb' => $r['thumb'], 'w' => (int)$r['w'], 'h' => (int)$r['h'],
        'before' => $r['before_full'], 'before_thumb' => $r['before_thumb'], 'cat' => $r['category'], 't' => $r['title'],
        'd' => (string)$r['note'], 'svc' => (string)$r['service'], 'visible' => (bool)$r['visible'], 'sort' => (int)$r['sort']];
}

function photos(bool $onlyVisible): array {
    $q = db()->query('SELECT * FROM portfolio' . ($onlyVisible ? ' WHERE visible=1' : '') . ' ORDER BY sort, id');
    return array_map('photo_public', $q->fetchAll());
}

/**
 * Saves an uploaded JPEG (already resized on the phone) and returns [path, width, height].
 * $field is the name of the file in the upload. Throws with a message the admin can read.
 */
function save_upload(string $field, int $maxSide): array {
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('The photo didn\'t upload. Try again.');
    if ($f['size'] > 8 * 1024 * 1024) throw new RuntimeException('That photo is too big.');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[2] !== IMAGETYPE_JPEG) throw new RuntimeException('Only photos (JPEG) can be added.');
    if ($info[0] > $maxSide + 4 || $info[1] > $maxSide + 4) throw new RuntimeException('The photo wasn\'t resized. Reload the page and try again.');
    if (!is_dir(PB_MEDIA_DIR) && !mkdir(PB_MEDIA_DIR, 0755, true)) throw new RuntimeException('The website can\'t create its photo folder.');
    $name = bin2hex(random_bytes(10)) . ($maxSide < 1000 ? '-sm' : '') . '.jpg';
    if (!move_uploaded_file($f['tmp_name'], PB_MEDIA_DIR . '/' . $name)) throw new RuntimeException('The website couldn\'t save the photo.');
    chmod(PB_MEDIA_DIR . '/' . $name, 0644);
    return ['media/portfolio/' . $name, $info[0], $info[1]];
}

/** Removes a photo file if it was uploaded through the manager (never the built-in website images). */
function remove_media(?string $path): void {
    if ($path && preg_match('#^media/portfolio/[a-f0-9]{20}(-sm)?\.jpg$#', $path)) @unlink(dirname(__DIR__) . '/' . $path);
}
