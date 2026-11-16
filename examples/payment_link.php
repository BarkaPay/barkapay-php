<?php

declare(strict_types=1);

// Create a hosted payment link, then follow the payments made through it.
//
//   PAYHUB_API_KEY="key_id:secret" php examples/payment_link.php
//
// The API application needs the scopes payment_links:create, payment_links:read
// and payment_links:manage.

require __DIR__ . '/../vendor/autoload.php';

use PayHub\Client;
use PayHub\Exception\PayHubException;
use PayHub\Exception\ValidationException;

$barkapay = new Client(apiKey: getenv('PAYHUB_API_KEY') ?: null, country: 'bf');

try {
    $link = $barkapay->paymentLinks->create([
        'title' => 'Invoice #1234',
        'usage' => 'single', // closed by its first successful payment; 'multi' for a reusable link
        'amount' => ['type' => 'fixed', 'currency' => 'XOF', 'value' => '25000'],
        'order' => ['id' => 'INV-1234'],
        'redirect' => [
            'success_url' => 'https://shop.example/thanks',
            'cancel_url' => 'https://shop.example/cart',
        ],
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 7 * 86400),
    ]);

    printf("Share this URL with your customer: %s\n", $link->url ?? '?');
    printf("Link %s is %s.\n", $link->publicId, $link->rawStatus ?? '?');

    // Later: the payments made through the link (this country's slice).
    $payments = $barkapay->paymentLinks->listPayments($link->publicId);
    printf(
        "%d successful payment(s), %s %s collected.\n",
        $payments->paymentLink->stats->paymentsCount ?? 0,
        $payments->paymentLink->stats->totalCollected ?? '0',
        $link->amount->currency ?? '',
    );
    foreach ($payments as $payment) {
        printf("  %s  %s\n", $payment->publicId, $payment->rawStatus ?? '?');
    }

    // Lifecycle: pause() / resume() are reversible, cancel() is not.
    //   $barkapay->paymentLinks->pause($link->publicId);
    //   $barkapay->paymentLinks->resume($link->publicId);
    //   $barkapay->paymentLinks->cancel($link->publicId);
} catch (ValidationException $e) {
    fwrite(STDERR, 'Invalid request: ' . json_encode($e->getErrors()) . "\n");
    exit(1);
} catch (PayHubException $e) {
    fwrite(STDERR, 'barkapay error: ' . $e->getMessage() . "\n");
    exit(1);
}
