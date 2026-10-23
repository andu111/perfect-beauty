<?php
/*
 * Deposits through Stripe Checkout (no external libraries).
 *
 * Flow: the customer books -> booking is 'awaiting_payment' and holds the slot for ~35 minutes ->
 * Stripe Checkout reserves the deposit on their card (manual capture) -> the booking becomes 'pending'
 * and the studio is notified -> Accept takes the deposit, Decline releases it, a later cancellation refunds it.
 */
if (!defined('PB')) { http_response_code(404); exit; }
require_once __DIR__ . '/availability.php';
require_once __DIR__ . '/notify.php';

const PB_HOLD_MINUTES = 35;   // Stripe Checkout sessions last 31 minutes; keep the slot a little longer

function stripe_base(): string { return getenv('PB_STRIPE_BASE') ?: 'https://api.stripe.com'; }
function stripe_ready(): bool { return setting('stripe_secret', '') !== ''; }
function stripe_mode(): string { $k = setting('stripe_secret', ''); return str_contains($k, '_live_') ? 'live' : ($k ? 'test' : 'off'); }

/** Calls the Stripe API. Returns the decoded response or throws with Stripe's own message. */
function stripe_req(string $method, string $path, array $params = []): array {
    $url = stripe_base() . $path;
    $body = http_build_query($params);
    $ch = curl_init($method === 'GET' && $body ? "$url?$body" : $url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_USERPWD => setting('stripe_secret', '') . ':',
        CURLOPT_HTTPHEADER => ['Stripe-Version: 2024-06-20'],
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException("Couldn't reach Stripe ($err).");
    $j = json_decode($raw, true);
    if (!is_array($j)) throw new RuntimeException("Stripe sent an unexpected answer (HTTP $code).");
    if ($code >= 400) throw new RuntimeException($j['error']['message'] ?? "Stripe refused the request (HTTP $code).");
    return $j;
}

/** Deposit in pence for a service price, using the rules in Settings. 0 = no deposit. */
function deposit_for(int $price): int {
    if (!stripe_ready()) return 0;
    $mode = setting('deposit_mode', 'off');
    if ($mode === 'off' || $price < (int)setting('deposit_min_price', 1000)) return 0;
    $d = $mode === 'fixed' ? (int)setting('deposit_fixed', 1000) : (int)round($price * (int)setting('deposit_percent', 0) / 100);
    $d = min($d, $price);
    return $d >= 50 ? $d : 0;   // Stripe's minimum charge is 30p; keep a little above it
}

/** Creates a Stripe Checkout page for a booking's deposit and returns its URL. */
function stripe_checkout(array $b): string {
    $rest = (int)$b['price'] - (int)$b['deposit'];
    $s = stripe_req('POST', '/v1/checkout/sessions', [
        'mode' => 'payment',
        'customer_email' => $b['email'],
        'client_reference_id' => $b['ref'],
        'expires_at' => time() + 31 * 60,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => ['currency' => 'gbp', 'unit_amount' => (int)$b['deposit'],
                'product_data' => ['name' => 'Deposit: ' . $b['service_name'],
                    'description' => nice_when($b['start_at']) . ($rest > 0 ? ' · ' . money($rest) . ' to pay in the studio' : '')]],
        ]],
        'payment_intent_data' => [
            'capture_method' => 'manual',   // reserved now, taken only when the studio accepts
            'description' => setting('business_name', 'Perfect Beauty') . ' deposit ' . $b['ref'],
            'metadata' => ['booking_id' => $b['id'], 'ref' => $b['ref']],
        ],
        'metadata' => ['booking_id' => $b['id'], 'ref' => $b['ref']],
        'success_url' => site_url('/my-booking.php?t=' . $b['token'] . '&paid=1'),
        'cancel_url' => site_url('/my-booking.php?t=' . $b['token'] . '&unpaid=1'),
    ]);
    db()->prepare('UPDATE bookings SET stripe_session=?, deposit_status=?, hold_until=? WHERE id=?')
        ->execute([$s['id'], 'awaiting', now()->modify('+' . PB_HOLD_MINUTES . ' minutes')->format('Y-m-d H:i:s'), $b['id']]);
    return $s['url'];
}

/**
 * Checks Stripe for a booking that is waiting for its deposit and moves it on.
 * Used by the webhook and when the customer returns from the payment page. Safe to call repeatedly.
 * Returns the fresh booking row.
 */
function stripe_sync(array $b): array {
    if (!$b['stripe_session'] || !in_array($b['deposit_status'], ['awaiting', null], true)) return $b;
    $s = stripe_req('GET', '/v1/checkout/sessions/' . rawurlencode($b['stripe_session']), ['expand' => ['payment_intent']]);
    $pi = $s['payment_intent'] ?? null;
    $piStatus = is_array($pi) ? ($pi['status'] ?? '') : '';
    if (($s['status'] ?? '') === 'expired') {
        db()->prepare("UPDATE bookings SET deposit_status='unpaid', status=CASE WHEN status='awaiting_payment' THEN 'expired' ELSE status END WHERE id=?")->execute([$b['id']]);
        return reload_booking($b['id']);
    }
    if (!in_array($piStatus, ['requires_capture', 'succeeded'], true)) return $b;   // not paid yet

    $pdo = db();
    $pdo->exec('BEGIN IMMEDIATE');
    $b = reload_booking($b['id']);
    if ($b['deposit_status'] !== 'awaiting') { $pdo->exec('COMMIT'); return $b; }   // another request got here first
    $revive = $b['status'] === 'awaiting_payment' ||
        ($b['status'] === 'expired' && !clashes(substr($b['start_at'], 0, 10), substr($b['start_at'], 11, 5), (int)$b['duration'], (int)$b['id']));
    if ($revive) {
        $pdo->prepare("UPDATE bookings SET status='pending', deposit_status=?, stripe_pi=?, hold_until=NULL WHERE id=?")
            ->execute([$piStatus === 'succeeded' ? 'captured' : 'authorized', $pi['id'], $b['id']]);
        $pdo->exec('COMMIT');
        $b = reload_booking($b['id']);
        notify_new_request($b);
        return $b;
    }
    // Paid after the time had gone to someone else: give the money back straight away.
    $pdo->prepare("UPDATE bookings SET stripe_pi=?, deposit_status=? WHERE id=?")
        ->execute([$pi['id'], $piStatus === 'succeeded' ? 'captured' : 'authorized', $b['id']]);
    $pdo->exec('COMMIT');
    $b = reload_booking($b['id']);
    deposit_release($b, 'The time was taken while the payment was finishing');
    $b = reload_booking($b['id']);
    send_mail((string)$b['email'], 'Your booking did not go through',
        "Hi " . explode(' ', trim($b['name']))[0] . ",\n\nSorry, the time you chose (" . nice_when($b['start_at']) . ") was booked by someone else " .
        "while your payment was finishing, so we couldn't hold it for you.\n\nWe've released the " . money((int)$b['deposit']) .
        " held on your card. Your bank may take a few days to remove it from your statement.\n\nPlease choose another time: " .
        site_url('/#book') . "\n" . signoff(), $e, (int)$b['id']);
    return $b;
}

/** Takes a held deposit (on Accept). Returns null or an error message. */
function deposit_capture(array $b): ?string {
    if ($b['deposit_status'] !== 'authorized' || !$b['stripe_pi']) return null;
    try {
        stripe_req('POST', '/v1/payment_intents/' . rawurlencode($b['stripe_pi']) . '/capture');
        db()->prepare("UPDATE bookings SET deposit_status='captured' WHERE id=?")->execute([$b['id']]);
        return null;
    } catch (Throwable $e) {
        db()->prepare("UPDATE bookings SET deposit_status='failed' WHERE id=?")->execute([$b['id']]);
        return 'The deposit could not be taken: ' . $e->getMessage();
    }
}

/** Gives a deposit back: releases a hold, or refunds a deposit already taken. Returns null or an error message. */
function deposit_release(array $b, string $why = ''): ?string {
    if (!$b['stripe_pi'] || !in_array($b['deposit_status'], ['authorized', 'captured'], true)) return null;
    try {
        if ($b['deposit_status'] === 'authorized') {
            stripe_req('POST', '/v1/payment_intents/' . rawurlencode($b['stripe_pi']) . '/cancel', ['cancellation_reason' => 'requested_by_customer']);
            $new = 'released';
        } else {
            stripe_req('POST', '/v1/refunds', ['payment_intent' => $b['stripe_pi'], 'metadata' => ['reason' => mb_substr($why, 0, 200)]]);
            $new = 'refunded';
        }
        db()->prepare('UPDATE bookings SET deposit_status=? WHERE id=?')->execute([$new, $b['id']]);
        return null;
    } catch (Throwable $e) {
        return 'The deposit could not be returned automatically: ' . $e->getMessage() . ' Refund it from the Stripe app.';
    }
}

function reload_booking(int $id): array {
    $q = db()->prepare('SELECT * FROM bookings WHERE id=?'); $q->execute([$id]);
    return $q->fetch();
}

/** Verifies a Stripe webhook signature header ("t=...,v1=..."). */
function stripe_verify(string $payload, string $header, string $secret, int $tolerance = 300): bool {
    $t = null; $sigs = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't') $t = (int)$v; elseif ($k === 'v1') $sigs[] = $v;
    }
    if (!$t || !$sigs || abs(time() - $t) > $tolerance) return false;
    $expected = hash_hmac('sha256', "$t.$payload", $secret);
    foreach ($sigs as $s) if (hash_equals($expected, $s)) return true;
    return false;
}
