<?php

declare(strict_types=1);

namespace PayHub\DTO;

use PayHub\Enum\TransferStatus;
use PayHub\Util\Coerce;

/**
 * A disbursement (transfer) as returned by the API. Documented fields are
 * typed; `raw` keeps the full payload, so fields added by the API later stay
 * reachable without upgrading the SDK.
 *
 * `amount` is the API's JSON number, untouched; `fees` is a decimal string.
 */
final class Transfer
{
    /**
     * @param array<string, mixed>|null $orderData
     * @param array<string, mixed>      $raw
     * @param string|null               $rawStatus         status string as sent by the API (set even when `$status` is null)
     * @param string|null               $reasonCode        stable failure code — see {@see \PayHub\Enum\ReasonCode}
     * @param string|null               $providerReason    the operator's own sentence — informational, not contractual
     * @param string|null               $providerReference reference of this payout on the operator side (set before it is sent)
     * @param string|null               $appLabel          label of the API application that created the transfer (creation responses only)
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
        public readonly ?TransferStatus $status,
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
        public readonly ?string $appLabel = null,
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
            status: $statusValue !== null ? TransferStatus::tryFrom($statusValue) : null,
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
            appLabel: Coerce::str($data, 'app_label'),
        );
    }

    /** The transfer reached a final status (SUCCESSFUL, FAILED or REFUNDED). */
    public function isFinal(): bool
    {
        return $this->status?->isFinal() ?? false;
    }

    /** The money was paid out. */
    public function isSuccessful(): bool
    {
        return $this->status === TransferStatus::Successful;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
