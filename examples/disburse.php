<?php

declare(strict_types=1);

// Pay out to a mobile-money account.
//
//   PAYHUB_API_KEY="key_id:secret" php examples/disburse.php

require __DIR__ . '/../vendor/autoload.php';

use PayHub\Client;
use PayHub\Exception\ConflictException;
use PayHub\Exception\InsufficientBalanceException;
use PayHub\Exception\NetworkException;
use PayHub\Exception\PayHubException;
use PayHub\Exception\TransferFailedException;

$barkapay = new Client(apiKey: getenv('PAYHUB_API_KEY') ?: null, country: 'bf');

$orderId = 'PAYOUT-' . bin2hex(random_bytes(6));

try {
    $transfer = $barkapay->transfers->create([
        'operator' => 'ORANGE',
        'phone_number' => '50123456789',
        'amount' => 50000,
        'order' => ['id' => $orderId, 'unique' => true],
    ]);
} catch (InsufficientBalanceException $e) {
    fwrite(STDERR, "Not enough balance: top up the wallet first.\n");
    exit(1);
} catch (ConflictException $e) {
    // This order id was already used, or a near-identical transfer was sent
    // less than 60 seconds ago. Nothing new was created.
    fwrite(STDERR, 'Duplicate transfer: ' . $e->getMessage() . "\n");
    exit(1);
} catch (TransferFailedException $e) {
    $failed = $e->getTransfer();
    fprintf(STDERR, "Transfer %s refused: %s\n", $failed->publicId ?? '?', $failed->reasonCode ?? $e->getMessage());
    exit(1);
} catch (NetworkException $e) {
    // No response: the payout may be executing. Look it up by order id before
    // doing anything else — sending it again could pay twice.
    fprintf(STDERR, "No response (request %s). Check transfers->get('%s') first.\n", $e->getRequestId() ?? '?', $orderId);
    exit(1);
} catch (PayHubException $e) {
    fwrite(STDERR, 'barkapay error: ' . $e->getMessage() . "\n");
    exit(1);
}

if ($transfer->isSuccessful()) {
    printf("Transfer %s paid: %s %s\n", $transfer->publicId, (string) $transfer->amount, $transfer->currency ?? '');
} else {
    // Not final yet (PROCESSING_OPERATOR, UNCERTAIN…): the payout is in flight.
    // Wait for the webhook or poll transfers->get(); never send it again.
    printf("Transfer %s is %s.\n", $transfer->publicId, $transfer->rawStatus ?? 'pending');
}
