<?php

declare(strict_types=1);

// Collect a payment and handle the three possible flows.
//
//   BARKAPAY_API_KEY="key_id:secret" php examples/collect.php

require __DIR__ . '/../vendor/autoload.php';

use BarkaPay\Client;
use BarkaPay\Enum\PaymentStatus;
use BarkaPay\Exception\BarkaPayException;
use BarkaPay\Exception\NetworkException;
use BarkaPay\Exception\PaymentFailedException;
use BarkaPay\Exception\ValidationException;

$barkapay = new Client(apiKey: getenv('BARKAPAY_API_KEY') ?: null, country: 'bf');

// Your own reference. With `unique`, the API refuses a second payment carrying
// the same id — which is what makes a manual retry safe.
$orderId = 'ORDER-' . bin2hex(random_bytes(6));

try {
    $payment = $barkapay->payments->create([
        'operator' => 'ORANGE',
        // National format, without the country code (8 digits in Burkina Faso).
        'phone_number' => '74123456',
        'amount' => 10000,
        // ORANGE takes the payer's one-time code up-front. Always a string:
        // "012345" must keep its leading zero.
        'otp' => '123456',
        'order' => ['id' => $orderId, 'unique' => true],
    ]);
} catch (ValidationException $e) {
    fwrite(STDERR, 'Invalid request: ' . json_encode($e->getErrors()) . "\n");
    exit(1);
} catch (PaymentFailedException $e) {
    // Refused by the payer or the operator. The attempt exists on the API side.
    $failed = $e->getPayment();
    fprintf(STDERR, "Payment %s refused: %s\n", $failed->publicId ?? '?', $failed->reasonCode ?? $e->getMessage());
    exit(1);
} catch (NetworkException $e) {
    // No response: the payment may or may not exist. Look it up by order id
    // before trying again — never re-send blindly.
    fprintf(STDERR, "No response (request %s). Check payments->list(['order_id' => '%s']) first.\n", $e->getRequestId() ?? '?', $orderId);
    exit(1);
} catch (BarkaPayException $e) {
    fwrite(STDERR, 'barkapay error: ' . $e->getMessage() . "\n");
    exit(1);
}

switch ($payment->status) {
    case PaymentStatus::Successful:
        printf("Payment %s settled: %s %s (fees %s)\n", $payment->publicId, (string) $payment->amount, $payment->currency ?? '', $payment->fees ?? '0');
        break;

    case PaymentStatus::AwaitingOtp:
        // MOOV (bf): the payer received a code by SMS. Collect it, then:
        //   $barkapay->payments->confirmOtp($payment->publicId, $code);
        printf("Payment %s is waiting for the payer's OTP.\n", $payment->publicId);
        break;

    default:
        // The payer approves on their phone (or at $payment->redirectUrl for
        // WAVE). The verdict arrives by webhook — see examples/webhook.php.
        printf("Payment %s is %s.\n", $payment->publicId, $payment->rawStatus ?? 'pending');
        if ($payment->redirectUrl !== null) {
            printf("Send the payer to: %s\n", $payment->redirectUrl);
        }
}
