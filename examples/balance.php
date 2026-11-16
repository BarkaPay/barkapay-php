<?php

declare(strict_types=1);

// Read-only tour: connectivity, balance, operators, latest payments.
//
//   BARKAPAY_API_KEY="key_id:secret" php examples/balance.php [bf|bj|sn]

require __DIR__ . '/../vendor/autoload.php';

use BarkaPay\Client;
use BarkaPay\Exception\BarkaPayException;

$barkapay = new Client(apiKey: getenv('BARKAPAY_API_KEY') ?: null, country: $argv[1] ?? 'bf');

try {
    $pong = $barkapay->ping();
    printf("API version: %s\n", is_string($pong['version'] ?? null) ? $pong['version'] : '?');

    $balance = $barkapay->balance->get();
    printf("Available: %s %s (holds: %s)\n", $balance->available ?? '0', $balance->currency ?? '', $balance->holds ?? '0');

    $info = $barkapay->operators->info();
    foreach (is_array($info['operators'] ?? null) ? $info['operators'] : [] as $country => $operators) {
        printf("Operators in %s: %s\n", (string) $country, implode(', ', array_keys(is_array($operators) ? $operators : [])));
    }

    $payments = $barkapay->payments->list(['per_page' => 5]);
    printf("Latest payments (%d of %d):\n", count($payments), $payments->meta->total ?? 0);
    foreach ($payments as $payment) {
        printf("  %s  %-20s %s %s\n", $payment->publicId, $payment->rawStatus ?? '?', (string) $payment->amount, $payment->currency ?? '');
    }

    $limit = $barkapay->lastRateLimit();
    printf("Rate limit: %s of %s requests left in this window.\n", (string) ($limit->remaining ?? '?'), (string) ($limit->limit ?? '?'));
} catch (BarkaPayException $e) {
    fwrite(STDERR, 'barkapay error: ' . $e->getMessage() . "\n");
    exit(1);
}
