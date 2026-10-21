<?php
/*
 * Minimal authenticated SMTP sender (no external libraries).
 * Port 465 = TLS from the start (IONOS default), 587 = STARTTLS, anything else = plain (local testing only).
 */
if (!defined('PB')) { http_response_code(404); exit; }

function smtp_send(array $cfg, string $from, string $fromName, string $to, string $replyTo, string $subject, string $text, ?string &$error = null): bool {
    $host = $cfg['host']; $port = (int)$cfg['port'];
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $target = ($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port";
    $fp = @stream_socket_client($target, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { $error = "Could not connect to $host:$port ($errstr)"; return false; }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): array {
        $lines = '';
        while (($l = fgets($fp, 1024)) !== false) { $lines .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
        return [(int)substr($lines, 0, 3), trim($lines)];
    };
    $cmd = function (string $c, array $ok, string $label = '') use ($fp, $read, &$error): bool {
        fwrite($fp, $c . "\r\n");
        [$code, $msg] = $read();
        if (!in_array($code, $ok, true)) { $error = ($label ?: strtok($c, ' ')) . " failed: $msg"; return false; }
        return true;
    };

    $ehloName = parse_url(site_url('/'), PHP_URL_HOST) ?: 'localhost';
    [$code, $msg] = $read();
    if ($code !== 220) { $error = "Server greeting: $msg"; fclose($fp); return false; }
    $ok = $cmd("EHLO $ehloName", [250]);
    if ($ok && $port === 587) {
        $ok = $cmd('STARTTLS', [220])
           && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
           && $cmd("EHLO $ehloName", [250]);
    }
    $ok = $ok && $cmd('AUTH LOGIN', [334])
        && $cmd(base64_encode($cfg['user']), [334], 'Username')
        && $cmd(base64_encode($cfg['pass']), [235], 'Password')
        && $cmd("MAIL FROM:<$from>", [250])
        && $cmd("RCPT TO:<$to>", [250, 251], 'Recipient')
        && $cmd('DATA', [354]);
    if ($ok) {
        $domain = substr(strrchr($from, '@'), 1) ?: 'localhost';
        $headers = [
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8', 'Q') . " <$from>",
            "To: <$to>",
            'Reply-To: ' . $replyTo,
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'Q'),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text))), 76, "\r\n"));
        $ok = $cmd($body . "\r\n.", [250], 'Sending');
    }
    @fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}
