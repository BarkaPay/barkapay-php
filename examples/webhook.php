<?php

declare(strict_types=1);

// Minimal webhook receiver. Mount this at the URL registered on your API
// application, with BARKAPAY_WEBHOOK_SECRET set to that webhook's signing secret
// (whsec_…).

require __DIR__ . '/../vendor/autoload.php';

use BarkaPay\Exception\SignatureVerificationException;
use BarkaPay\Webhook;

$secret = getenv('BARKAPAY_WEBHOOK_SECRET');
if (!is_string($secret) || $secret === '') {
    // Never fall back to a default secret: anyone could forge a delivery.
    http_response_code(500);
    exit;
}

// The RAW body, exactly as received — not a re-encoded copy.
$payload = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_BARKAPAY_SIGNATURE'] ?? '';

try {
    $event = Webhook::parse($payload, is_string($signature) ? $signature : '', $secret);
} catch (SignatureVerificationException) {
    http_response_code(400);
    exit;
}

// Deliveries are at-least-once: `event_id` is stable across the retries of one
// delivery, so use it to ignore what you already processed.
$eventId = is_string($event['event_id'] ?? null) ? $event['event_id'] : '';

// There is no `event` field — derive it from `type` and `status`.
$type = $event['type'] ?? null;     // "payment" | "transfer"
$status = $event['status'] ?? null; // "SUCCESSFUL" | "FAILED" | "EXPIRED" | …
$publicId = is_string($event['public_id'] ?? null) ? $event['public_id'] : '';

if ($type === 'payment' && $status === 'SUCCESSFUL') {
    // payment.succeeded — mark the order ($event['order_id']) as paid.
} elseif ($type === 'payment') {
    // payment.failed / payment.expired — $event['reason_code'] says why.
} elseif ($type === 'transfer' && $status === 'SUCCESSFUL') {
    // transfer.succeeded
} elseif ($type === 'transfer') {
    // transfer.failed — $event['reason_code'] says why.
}

// Answer fast with a 2xx; do the heavy work asynchronously.
http_response_code(200);
echo 'ok';
