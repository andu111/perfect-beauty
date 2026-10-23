<?php
// Stripe calls this when a deposit is paid or a payment page expires. Signed with the webhook secret from Settings.
// Lives in /admin/ because that folder is reachable without the preview password.
define('PB', 1);
require __DIR__ . '/../lib/app.php';
require __DIR__ . '/../lib/availability.php';
require __DIR__ . '/../lib/notify.php';
require __DIR__ . '/../lib/stripe.php';

$payload = file_get_contents('php://input') ?: '';
$secret = setting('stripe_webhook_secret', '');
if ($secret === '' || !stripe_verify($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', $secret)) { http_response_code(400); exit('Bad signature'); }

$event = json_decode($payload, true) ?: [];
$obj = $event['data']['object'] ?? [];
if (in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.expired'], true)) {
    $q = db()->prepare('SELECT * FROM bookings WHERE stripe_session=?');
    $q->execute([(string)($obj['id'] ?? '')]);
    if ($b = $q->fetch()) {
        try { stripe_sync($b); }
        catch (Throwable $e) { http_response_code(500); exit('Retry later'); }   // Stripe retries automatically
    }
}
http_response_code(200);
echo 'ok';
