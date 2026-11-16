<?php

declare(strict_types=1);

namespace BarkaPay\DTO;

use BarkaPay\Enum\PaymentLinkStatus;
use BarkaPay\Util\Coerce;

/**
 * A payment link: a durable URL on the barkapay-hosted checkout where your
 * customer picks an operator and pays. Share `url` with the payer.
 *
 * Documented fields are typed; `raw` keeps the full payload, so fields added
 * by the API later stay reachable without upgrading the SDK.
 */
final class PaymentLink
{
    /**
     * @param string                    $publicId              `PL-…` identifier
     * @param string|null               $slug                  13-character public slug (the last segment of `url`)
     * @param string|null               $url                   checkout URL to share with the payer
     * @param string|null               $rawStatus             status string as sent by the API (set even when `$status` is null)
     * @param string|null               $source                `api` or `ui` (created from the dashboard)
     * @param string|null               $usage                 `single` (dies on its first successful payment) or `multi`
     * @param PaymentLinkAmount|null    $amount                null only on the summary returned by `paymentLinks->listPayments()`
     * @param array<string, mixed>|null $order                 `['id' => …, 'data' => […]]`, attached to every payment of the link
     * @param array<string, mixed>|null $redirect              `['success_url' => …, 'cancel_url' => …]`
     * @param array<string, mixed>|null $restrictions          `['countries' => […], 'operators' => […]]`
     * @param array<string, mixed>|null $payer                 known payer (`full_name`, `email`, `phone`, `locale`)
     * @param array<string, mixed>      $collect               fields the checkout asks for (`required` / `optional` / `off`)
     * @param string|null               $expiresAt             ISO-8601 date-time, null when the link never expires
     * @param string|null               $paidByPaymentPublicId the payment that closed a single-use link
     * @param array<string, mixed>      $raw
     */
    public function __construct(
        public readonly string $publicId,
        public readonly ?string $slug,
        public readonly ?string $url,
        public readonly ?PaymentLinkStatus $status,
        public readonly ?string $rawStatus,
        public readonly ?string $source,
        public readonly ?string $usage,
        public readonly ?PaymentLinkAmount $amount,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly ?array $order,
        public readonly ?array $redirect,
        public readonly ?array $restrictions,
        public readonly ?array $payer,
        public readonly array $collect,
        public readonly ?bool $notifyEmailInvoice,
        public readonly ?string $expiresAt,
        public readonly ?PaymentLinkStats $stats,
        public readonly ?string $paidByPaymentPublicId,
        public readonly ?string $createdAt,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $statusValue = Coerce::str($data, 'status');
        $amount = Coerce::arr($data, 'amount');
        $stats = Coerce::arr($data, 'stats');
        $notify = Coerce::arr($data, 'notify');

        return new self(
            publicId: Coerce::str($data, 'public_id') ?? '',
            slug: Coerce::str($data, 'slug'),
            url: Coerce::str($data, 'url'),
            status: $statusValue !== null ? PaymentLinkStatus::tryFrom($statusValue) : null,
            rawStatus: $statusValue,
            source: Coerce::str($data, 'source'),
            usage: Coerce::str($data, 'usage'),
            amount: $amount !== null ? PaymentLinkAmount::fromArray($amount) : null,
            title: Coerce::str($data, 'title'),
            description: Coerce::str($data, 'description'),
            order: Coerce::arr($data, 'order'),
            redirect: Coerce::arr($data, 'redirect'),
            restrictions: Coerce::arr($data, 'restrictions'),
            payer: Coerce::arr($data, 'payer'),
            collect: Coerce::arr($data, 'collect') ?? [],
            notifyEmailInvoice: $notify !== null ? Coerce::bool($notify, 'email_invoice') : null,
            expiresAt: Coerce::str($data, 'expires_at'),
            stats: $stats !== null ? PaymentLinkStats::fromArray($stats) : null,
            paidByPaymentPublicId: Coerce::str($data, 'paid_by_payment_public_id'),
            createdAt: Coerce::str($data, 'created_at'),
            raw: $data,
        );
    }

    /**
     * The hosted checkout accepts payments on this link, as far as its status
     * says (an `active` link past its `expiresAt` is refused by the checkout).
     */
    public function isPayable(): bool
    {
        return $this->status === PaymentLinkStatus::Active;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
