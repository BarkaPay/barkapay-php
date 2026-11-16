<?php

declare(strict_types=1);

namespace BarkaPay\Tests;

use BarkaPay\DTO\Balance;
use BarkaPay\DTO\Pagination;
use BarkaPay\DTO\Payment;
use BarkaPay\DTO\PaymentLink;
use BarkaPay\DTO\RateLimit;
use BarkaPay\DTO\Transfer;
use BarkaPay\Enum\PaymentLinkStatus;
use BarkaPay\Enum\PaymentStatus;
use BarkaPay\Enum\ReasonCode;
use BarkaPay\Enum\TransferStatus;
use PHPUnit\Framework\TestCase;

final class DtoTest extends TestCase
{
    /** A payment exactly as `GET /payments` returns it (Wave, Senegal). */
    private const PAYMENT = [
        'public_id' => '0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f',
        'amount' => 10000,
        'fees' => '250.00',
        'currency' => 'XOF',
        'phone_number' => '771234567',
        'country' => 'SEN',
        'operator' => 'WAVE',
        'channel' => 'hosted',
        'status' => 'FAILED',
        'order_id' => 'O-1',
        'provider_transaction_id' => null,
        'provider_reference' => 'cos-1a2b3c',
        'order_data' => ['cart' => '42'],
        'link_public_id' => 'PL-0198c2f1-9f6a-7c4e-b1d2-3a4b5c6d7e8f',
        'reason' => 'Payer refused the transaction.',
        'reason_code' => 'payer_refused',
        'provider_reason' => 'Paiement annulé',
        'redirect_url' => 'https://pay.wave.com/c/cos-1a2b3c',
        'created_at' => '2026-11-16T11:58:00+00:00',
        'future_field' => 'kept',
    ];

    public function testPaymentHydratesEveryDocumentedFieldAndKeepsRaw(): void
    {
        $payment = Payment::fromArray(self::PAYMENT);

        self::assertSame('0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f', $payment->publicId);
        self::assertSame(10000, $payment->amount);
        self::assertSame('250.00', $payment->fees);
        self::assertSame('XOF', $payment->currency);
        self::assertSame('771234567', $payment->phoneNumber);
        self::assertSame('SEN', $payment->country);
        self::assertSame('WAVE', $payment->operator);
        self::assertSame('hosted', $payment->channel);
        self::assertSame(PaymentStatus::Failed, $payment->status);
        self::assertSame('FAILED', $payment->rawStatus);
        self::assertSame('O-1', $payment->orderId);
        self::assertNull($payment->providerTransactionId);
        self::assertSame('cos-1a2b3c', $payment->providerReference);
        self::assertSame(['cart' => '42'], $payment->orderData);
        self::assertSame('PL-0198c2f1-9f6a-7c4e-b1d2-3a4b5c6d7e8f', $payment->linkPublicId);
        self::assertSame('Payer refused the transaction.', $payment->reason);
        self::assertSame('payer_refused', $payment->reasonCode);
        self::assertSame('Paiement annulé', $payment->providerReason);
        self::assertSame('https://pay.wave.com/c/cos-1a2b3c', $payment->redirectUrl);
        self::assertSame('2026-11-16T11:58:00+00:00', $payment->createdAt);
        self::assertSame('kept', $payment->toArray()['future_field']);
        self::assertSame(self::PAYMENT, $payment->raw);
        self::assertTrue($payment->isFinal());
        self::assertFalse($payment->isSuccessful());
    }

    public function testPaymentToleratesMissingFields(): void
    {
        $payment = Payment::fromArray([]);

        self::assertSame('', $payment->publicId);
        self::assertNull($payment->amount);
        self::assertNull($payment->status);
        self::assertNull($payment->rawStatus);
        self::assertNull($payment->reasonCode);
        self::assertNull($payment->redirectUrl);
        self::assertFalse($payment->isFinal());
    }

    public function testUnknownStatusIsNullOnTheEnumButKeptRaw(): void
    {
        $payment = Payment::fromArray(['public_id' => 'p', 'status' => 'SOMETHING_NEW']);
        self::assertNull($payment->status);
        self::assertSame('SOMETHING_NEW', $payment->rawStatus);
        self::assertFalse($payment->isFinal());

        $transfer = Transfer::fromArray(['public_id' => 't', 'status' => 'SOMETHING_NEW']);
        self::assertNull($transfer->status);
        self::assertSame('SOMETHING_NEW', $transfer->rawStatus);
    }

    public function testAmountsAreNeverRounded(): void
    {
        self::assertSame(100, Payment::fromArray(['amount' => 100])->amount);
        self::assertSame(100.5, Payment::fromArray(['amount' => 100.5])->amount);
        self::assertSame('100.50', Payment::fromArray(['amount' => '100.50'])->amount);
        // Fees are documented as a decimal string; a bare number is normalised.
        self::assertSame('6.25', Payment::fromArray(['fees' => '6.25'])->fees);
        self::assertSame('6.25', Payment::fromArray(['fees' => 6.25])->fees);
        self::assertSame('0', Payment::fromArray(['fees' => 0])->fees);
        self::assertSame('250', Transfer::fromArray(['fees' => 250.0])->fees);
        self::assertNull(Payment::fromArray(['fees' => null])->fees);
        self::assertNull(Payment::fromArray(['fees' => ['x']])->fees);
    }

    public function testEveryStatusTheGatewaysEmitIsKnown(): void
    {
        // app/Enums/PaymentStatus.php on the gateways.
        foreach ([
            'CREATED', 'AWAITING_OTP', 'QUEUED_FOR_PROCESSING', 'PROCESSING_OPERATOR', 'PROCESSING_SYSTEM',
            'SUCCESSFUL', 'FAILED', 'EXPIRED', 'COMPLIANCE_FLAGGED', 'CANCELED',
        ] as $status) {
            self::assertNotNull(PaymentStatus::tryFrom($status), $status);
        }

        // app/Enums/TransferStatus.php on the gateways.
        foreach ([
            'CREATED', 'PROCESSING_OPERATOR', 'PROCESSING_SYSTEM', 'SUCCESSFUL', 'FAILED', 'COMPLIANCE_FLAGGED',
            'REFUNDED', 'UNRESOLVED', 'UNCERTAIN',
        ] as $status) {
            self::assertNotNull(TransferStatus::tryFrom($status), $status);
        }

        // BarkaPay\PayLinks\PayLink::STATUS_*.
        foreach (['active', 'paused', 'paid', 'expired', 'canceled', 'admin_blocked'] as $status) {
            self::assertNotNull(PaymentLinkStatus::tryFrom($status), $status);
        }

        // App\Support\Reasons\ReasonCode::all().
        foreach ([
            'payer_insufficient_funds', 'payer_refused', 'payer_unreachable', 'invalid_otp', 'otp_expired', 'expired',
            'recipient_invalid', 'operator_unavailable', 'operator_timeout', 'unconfirmed_after_window',
            'payment_not_completed', 'amount_out_of_bounds', 'duplicate', 'provider_float_insufficient',
            'internal_error', 'unknown',
        ] as $code) {
            self::assertNotNull(ReasonCode::tryFrom($code), $code);
        }
        self::assertNull(ReasonCode::tryFrom('a_code_from_the_future'));
    }

    public function testStatusFinality(): void
    {
        self::assertTrue(PaymentStatus::Successful->isFinal());
        self::assertTrue(PaymentStatus::Failed->isFinal());
        self::assertTrue(PaymentStatus::Expired->isFinal());
        self::assertTrue(PaymentStatus::Canceled->isFinal());
        self::assertFalse(PaymentStatus::ProcessingOperator->isFinal());
        self::assertFalse(PaymentStatus::AwaitingOtp->isFinal());
        self::assertTrue(PaymentStatus::Successful->isSuccessful());
        self::assertFalse(PaymentStatus::Expired->isSuccessful());

        self::assertTrue(TransferStatus::Refunded->isFinal());
        self::assertFalse(TransferStatus::Created->isFinal());
        // The payout may be executing: never final, never a failure.
        self::assertFalse(TransferStatus::Uncertain->isFinal());
        self::assertFalse(TransferStatus::Unresolved->isFinal());
        self::assertTrue(TransferStatus::Successful->isSuccessful());

        self::assertTrue(PaymentLinkStatus::Active->isPayable());
        self::assertFalse(PaymentLinkStatus::Paused->isPayable());
        self::assertFalse(PaymentLinkStatus::AdminBlocked->isPayable());
    }

    public function testTransferHydratesEveryDocumentedField(): void
    {
        $transfer = Transfer::fromArray([
            'public_id' => 't-1',
            'amount' => 50000,
            'fees' => '500.00',
            'currency' => 'XOF',
            'phone_number' => '50123456789',
            'country' => 'BFA',
            'operator' => 'ORANGE',
            'channel' => 'api',
            'status' => 'SUCCESSFUL',
            'order_id' => 'X-1',
            'provider_transaction_id' => 'PP250.1234.A1',
            'provider_reference' => 'REF-9',
            'order_data' => null,
            'reason' => null,
            'reason_code' => null,
            'provider_reason' => null,
            'created_at' => '2026-11-16T11:58:00+00:00',
        ]);

        self::assertSame('t-1', $transfer->publicId);
        self::assertSame(50000, $transfer->amount);
        self::assertSame('500.00', $transfer->fees);
        self::assertSame(TransferStatus::Successful, $transfer->status);
        self::assertSame('SUCCESSFUL', $transfer->rawStatus);
        self::assertSame('PP250.1234.A1', $transfer->providerTransactionId);
        self::assertSame('REF-9', $transfer->providerReference);
        self::assertNull($transfer->reasonCode);
        self::assertNull($transfer->appLabel);
        self::assertTrue($transfer->isFinal());
        self::assertTrue($transfer->isSuccessful());
        self::assertSame('X-1', $transfer->toArray()['order_id']);
    }

    public function testDtoConstructorsStayCompatibleWithVersion1(): void
    {
        // 1.0.0 positional signature: 15 arguments, `raw` last.
        $payment = new Payment('p', 100, '1.00', 'XOF', '5', 'BFA', 'ORANGE', 'api', PaymentStatus::Created, 'o', null, null, null, null, []);
        self::assertSame('p', $payment->publicId);
        self::assertNull($payment->rawStatus);
        self::assertNull($payment->reasonCode);

        $transfer = new Transfer('t', 100, '1.00', 'XOF', '5', 'BFA', 'ORANGE', 'api', TransferStatus::Created, 'o', null, null, null, null, []);
        self::assertSame('t', $transfer->publicId);
        self::assertNull($transfer->providerReference);
    }

    public function testDtosAreImmutable(): void
    {
        $payment = Payment::fromArray(self::PAYMENT);

        $this->expectException(\Error::class);
        /** @phpstan-ignore-next-line the point of the test */
        $payment->amount = 1;
    }

    public function testPaymentLinkToleratesAnEmptyPayload(): void
    {
        $link = PaymentLink::fromArray([]);

        self::assertSame('', $link->publicId);
        self::assertNull($link->status);
        self::assertNull($link->amount);
        self::assertNull($link->stats);
        self::assertSame([], $link->collect);
        self::assertNull($link->notifyEmailInvoice);
        self::assertFalse($link->isPayable());
    }

    public function testBalanceShapes(): void
    {
        $fiat = Balance::fromArray(['country' => 'BF', 'currency' => 'XOF', 'available' => '1500.00', 'total' => '2000.00', 'holds' => '500.00']);
        self::assertSame('1500.00', $fiat->available);
        self::assertSame('2000.00', $fiat->total);
        self::assertSame('500.00', $fiat->holds);
        self::assertNull($fiat->balances);
        self::assertSame('BF', $fiat->toArray()['country']);

        $multi = Balance::fromArray([
            'country' => 'CC',
            'balances' => [
                ['asset' => 'USDT', 'network' => 'TRC20', 'available' => '12.50'],
                'not-a-row',
            ],
        ]);
        self::assertIsArray($multi->balances);
        self::assertCount(1, $multi->balances);
        self::assertSame('USDT', $multi->balances[0]['asset']);
    }

    public function testPaginationHasMore(): void
    {
        self::assertTrue((new Pagination(1, 3, 50, 120))->hasMore());
        self::assertFalse((new Pagination(3, 3, 50, 120))->hasMore());
        // No `last_page` (payment links): derived from the total.
        self::assertTrue((new Pagination(1, null, 25, 26))->hasMore());
        self::assertFalse((new Pagination(2, null, 25, 50))->hasMore());
        self::assertFalse((new Pagination(null, null, null, null))->hasMore());
        self::assertFalse(Pagination::fromArray(['count' => 1, 'lookup_field' => 'order_id'])->hasMore());
    }

    public function testRateLimitFromHeaders(): void
    {
        self::assertNull(RateLimit::fromHeaders(['content-type' => 'application/json']));

        $limit = RateLimit::fromHeaders(['x-ratelimit-limit' => '30', 'x-ratelimit-remaining' => '0', 'retry-after' => '12']);
        self::assertNotNull($limit);
        self::assertSame(30, $limit->limit);
        self::assertSame(0, $limit->remaining);
        self::assertSame(12, $limit->retryAfter);

        self::assertNull(RateLimit::fromHeaders(['x-ratelimit-limit' => 'abc']));
    }
}
