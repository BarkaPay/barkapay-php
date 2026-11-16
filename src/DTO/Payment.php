<?php

declare(strict_types=1);

namespace PayHub\DTO;

use PayHub\Enum\PaymentStatus;
use PayHub\Util\Coerce;

/**
 * A collection (payment) as returned by the API. Documented fields are typed;
 * `raw` keeps the full payload, so fields added by the API later stay
 * reachable without upgrading the SDK.
 *
 * `amount` is the API's JSON number, untouched (int, or float when it has
 * decimals); `fees` is a decimal string (`"250.00"`). Do your arithmetic in
 * integers or with bcmath, not with floats.
 */
final class Payment
{
    /**
     * @param array<string, mixed>|null $orderData
     * @param array<string, mixed>      $raw
     * @param string|null               $rawStatus         status string as sent by the API (set even when `$status` is null)
     * @param string|null               $reasonCode        stable failure code — see {@see \PayHub\Enum\ReasonCode}
     * @param string|null               $providerReason    the operator's own sentence — informational, not contractual
     * @param string|null               $providerReference operator-side tracking reference (OTP transaction id, session id…)
     * @param string|null               $linkPublicId      `PL-…` id of the payment link the payer used, if any
     * @param string|null               $redirectUrl       URL the payer must open to approve (WAVE, `sn`)
     * @param PaymentLink|null          $paymentLink       only set by `payments->create()` with `channel: hosted`:
     *                                                     the link to share with the payer. No payment exists yet
     *                                                     then — every other field is empty.
     */
    public function __construct(
        public readonly string $publicId,
        public readonly int|float|string|null $amount,
        public readonly ?string $fees,
        public readonly ?string $currency,
        public readonly ?string $phoneNumber,
        public readonly ?string $country,
        public readonly ?string $operator,
        public readonly ?string $channel,
        public readonly ?PaymentStatus $status,
        public readonly ?string $orderId,
        public readonly ?string $providerTransactionId,
        public readonly ?array $orderData,
        public readonly ?string $reason,
        public readonly ?string $createdAt,
        public readonly array $raw,
        public readonly ?string $rawStatus = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $providerReason = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $linkPublicId = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?PaymentLink $paymentLink = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $statusValue = Coerce::str($data, 'status');

        return new self(
            publicId: Coerce::str($data, 'public_id') ?? '',
            amount: Coerce::amount($data, 'amount'),
            fees: Coerce::decimal($data, 'fees'),
            currency: Coerce::str($data, 'currency'),
            phoneNumber: Coerce::str($data, 'phone_number'),
            country: Coerce::str($data, 'country'),
            operator: Coerce::str($data, 'operator'),
            channel: Coerce::str($data, 'channel'),
            status: $statusValue !== null ? PaymentStatus::tryFrom($statusValue) : null,
            orderId: Coerce::str($data, 'order_id'),
            providerTransactionId: Coerce::str($data, 'provider_transaction_id'),
            orderData: Coerce::arr($data, 'order_data'),
            reason: Coerce::str($data, 'reason'),
            createdAt: Coerce::str($data, 'created_at'),
            raw: $data,
            rawStatus: $statusValue,
            reasonCode: Coerce::str($data, 'reason_code'),
            providerReason: Coerce::str($data, 'provider_reason'),
            providerReference: Coerce::str($data, 'provider_reference'),
            linkPublicId: Coerce::str($data, 'link_public_id'),
            redirectUrl: Coerce::str($data, 'redirect_url'),
        );
    }

    /**
     * The answer to a hosted creation: a payment link, and no payment yet (the
     * payments are born when payers pay on the link).
     *
     * @internal
     */
    public static function hosted(PaymentLink $link): self
    {
        return new self(
            publicId: '',
            amount: null,
            fees: null,
            currency: null,
            phoneNumber: null,
            country: null,
            operator: null,
            channel: 'hosted',
            status: null,
            orderId: null,
            providerTransactionId: null,
            orderData: null,
            reason: null,
            createdAt: null,
            raw: ['channel' => 'hosted', 'payment_link' => $link->raw],
            paymentLink: $link,
        );
    }

    /** The payment reached a final status (SUCCESSFUL, FAILED, EXPIRED or CANCELED). */
    public function isFinal(): bool
    {
        return $this->status?->isFinal() ?? false;
    }

    /** The money was collected. */
    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Successful;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
