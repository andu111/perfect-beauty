<?php
/*
 * Web Push without external libraries: VAPID (RFC 8292) + aes128gcm payload encryption (RFC 8291).
 * Keys are created on first use and stored in the data folder.
 */
if (!defined('PB')) { http_response_code(404); exit; }

function b64u_enc(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Raw 65-byte uncompressed public point for an EC key. */
function ec_public_raw($key): string {
    $d = openssl_pkey_get_details($key)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

/** PEM public key from a raw 65-byte P-256 point. */
function ec_pem_from_raw(string $raw): string {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function ec_new_key() {
    return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
}

/** ['public' => base64url raw point, 'private' => PEM]; created once. */
function vapid_keys(): array {
    $file = PB_DATA . '/vapid.json';
    if (is_file($file)) return json_decode(file_get_contents($file), true);
    $k = ec_new_key();
    openssl_pkey_export($k, $pem);
    $keys = ['public' => b64u_enc(ec_public_raw($k)), 'private' => $pem];
    file_put_contents($file, json_encode($keys));
    chmod($file, 0600);
    return $keys;
}

/** Convert an OpenSSL DER ECDSA signature to the 64-byte r||s form JWT needs. */
function der_to_raw_sig(string $der): string {
    $pos = 2; if (ord($der[1]) & 0x80) $pos += ord($der[1]) & 0x7f;
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = ltrim(substr($der, $pos + 2, $len), "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function vapid_header(string $endpoint, array $keys, string $subject): string {
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $h = b64u_enc(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = b64u_enc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject], JSON_UNESCAPED_SLASHES));
    openssl_sign("$h.$c", $der, openssl_pkey_get_private($keys['private']), OPENSSL_ALGO_SHA256);
    return "vapid t=$h.$c." . b64u_enc(der_to_raw_sig($der)) . ', k=' . $keys['public'];
}

/** Encrypt $payload for a subscription (aes128gcm). Returns the request body. */
function push_encrypt(string $payload, string $p256dhB64u, string $authB64u, $ephemeral = null, ?string $salt = null): string {
    $uaPublic = b64u_dec($p256dhB64u);
    $authSecret = b64u_dec($authB64u);
    $as = $ephemeral ?? ec_new_key();
    $asPublic = ec_public_raw($as);
    $ecdh = openssl_pkey_derive(openssl_pkey_get_public(ec_pem_from_raw($uaPublic)), $as, 32);
    if ($ecdh === false) throw new RuntimeException('ECDH failed');
    $salt ??= random_bytes(16);

    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
}

/** Send one push. Returns HTTP status (201 = delivered to the push service). */
function push_send(array $sub, array $message): int {
    $keys = vapid_keys();
    $body = push_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']);
    $subject = 'mailto:' . (setting('notify_email') ?: 'bookings@perfect-beauty.uk');
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: ' . vapid_header($sub['endpoint'], $keys, $subject),
            'Content-Encoding: aes128gcm', 'Content-Type: application/octet-stream',
            'TTL: 86400', 'Urgency: high',
        ],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** Push to every switched-on device (or just device $only); forgets devices the push service says are gone. */
function push_all(string $title, string $body, string $url = '/admin/', ?int $badge = null, ?int $only = null): int {
    $sent = 0;
    $q = db()->prepare($only ? 'SELECT * FROM push_subs WHERE id=?' : 'SELECT * FROM push_subs WHERE enabled=1');
    $q->execute($only ? [$only] : []);
    foreach ($q->fetchAll() as $s) {
        try {
            $code = push_send($s, ['title' => $title, 'body' => $body, 'url' => $url, 'badge' => $badge, 'tag' => 'pb-' . md5($url)]);
        } catch (Throwable $e) { $code = 0; }
        if ($code === 404 || $code === 410) db()->prepare('DELETE FROM push_subs WHERE id=?')->execute([$s['id']]);
        if ($code >= 200 && $code < 300) $sent++;
    }
    return $sent;
}
