<?php
/* Emails to customers and to the studio, plus phone pushes to the admin. */
if (!defined('PB')) { http_response_code(404); exit; }
require_once __DIR__ . '/push.php';

require_once __DIR__ . '/smtp.php';

/** Sends through the bookings mailbox when its password is saved in Settings, otherwise through the server. */
function send_mail(string $to, string $subject, string $text, ?string &$error = null): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) { $error = 'No valid email address.'; return false; }
    $from = setting('from_email', 'bookings@perfect-beauty.uk');
    $name = setting('business_name', 'Perfect Beauty');
    if (setting('smtp_pass', '') !== '' && !getenv('PB_MAIL_LOG')) {
        return smtp_send(['host' => setting('smtp_host', 'smtp.ionos.co.uk'), 'port' => (int)setting('smtp_port', 465),
            'user' => $from, 'pass' => setting('smtp_pass')], $from, $name, $to, setting('notify_email') ?: $from, $subject, $text, $error);
    }
    $headers = [
        'From: ' . mb_encode_mimeheader($name) . " <$from>",
        'Reply-To: ' . (setting('notify_email') ?: $from),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    if (getenv('PB_MAIL_LOG')) {   // local testing: write instead of sending
        file_put_contents(getenv('PB_MAIL_LOG'), "TO: $to\nSUBJECT: $subject\n$text\n-----\n", FILE_APPEND);
        return true;
    }
    return @mail($to, mb_encode_mimeheader($subject), $text, implode("\r\n", $headers), "-f$from");
}

function booking_lines(array $b): string {
    return "Treatment: {$b['service_name']}\n" .
           "When: " . nice_when($b['start_at']) . " (" . dur_text((int)$b['duration']) . ")\n" .
           "Price: " . money((int)$b['price']) . "\n" .
           "Reference: {$b['ref']}\n";
}

function signoff(): string {
    return "\n" . setting('business_name', 'Perfect Beauty') . "\n" . site_url('/') . "\n";
}

function pending_count(): int {
    return (int)db()->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
}

/* --- events --- */

function notify_new_request(array $b): void {
    $first = explode(' ', trim($b['name']))[0];
    push_all('New booking request', "$first · {$b['service_name']} · " . nice_when($b['start_at']), '/admin/#b' . $b['id'], pending_count());
    if (setting('email_alerts', '1') === '1') send_mail(setting('notify_email', ''), "New booking request: {$b['name']}, " . nice_when($b['start_at']),
        "New request waiting for you to accept or decline.\n\n" . booking_lines($b) .
        "\nClient: {$b['name']}\nPhone: {$b['phone']}\nEmail: {$b['email']}\n" .
        ($b['notes'] ? "Notes: {$b['notes']}\n" : '') . "\nOpen your bookings: " . site_url('/admin/') . "\n");
    send_mail((string)$b['email'], 'We have your booking request',
        "Hi $first,\n\nThanks for your request. It's not confirmed yet: you'll get another email as soon as it's accepted.\n\n" .
        booking_lines($b) . "\nView or cancel your request: " . site_url('/my-booking.php?t=' . $b['token']) . "\n" . signoff());
}

function notify_decision(array $b): void {
    $first = explode(' ', trim($b['name']))[0];
    if ($b['status'] === 'accepted') {
        send_mail((string)$b['email'], 'Your appointment is confirmed: ' . nice_when($b['start_at']),
            "Hi $first,\n\nGreat news, your appointment is confirmed.\n\n" . booking_lines($b) .
            "\nNeed to change or cancel? " . site_url('/my-booking.php?t=' . $b['token']) . "\n" . signoff());
    } elseif ($b['status'] === 'declined') {
        send_mail((string)$b['email'], "Sorry, we can't fit you in at that time",
            "Hi $first,\n\nUnfortunately we can't do " . nice_when($b['start_at']) . " for {$b['service_name']}.\n" .
            ($b['reason'] ? "\n{$b['reason']}\n" : '') .
            "\nPlease pick another time here: " . site_url('/#book') . "\n" . signoff());
    } elseif ($b['status'] === 'cancelled') {
        send_mail((string)$b['email'], 'Your appointment has been cancelled',
            "Hi $first,\n\nYour appointment on " . nice_when($b['start_at']) . " for {$b['service_name']} has been cancelled.\n" .
            ($b['reason'] ? "\n{$b['reason']}\n" : '') . "\nBook a new time: " . site_url('/#book') . "\n" . signoff());
    }
}

function notify_customer_cancelled(array $b): void {
    push_all('Booking cancelled', "{$b['name']} cancelled {$b['service_name']} · " . nice_when($b['start_at']), '/admin/#b' . $b['id'], pending_count());
    if (setting('email_alerts', '1') === '1') send_mail(setting('notify_email', ''), "Cancelled: {$b['name']}, " . nice_when($b['start_at']),
        "{$b['name']} cancelled online. The time is free again.\n\n" . booking_lines($b) . "\nPhone: {$b['phone']}\n");
}
