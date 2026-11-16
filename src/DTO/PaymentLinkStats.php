<?php

declare(strict_types=1);

namespace BarkaPay\DTO;

use BarkaPay\Util\Coerce;

/**
 * Totals of a payment link. Only SUCCESSFUL payments are counted, across every
 * country the link is open to.
 */
final class PaymentLinkStats
{
    /**
     * @param string|null $totalCollected decimal string, major units of the link currency
     */
    public function __construct(
        public readonly ?int $paymentsCount,
        public readonly ?string $totalCollected,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            paymentsCount: Coerce::int($data, 'payments_count'),
            totalCollected: Coerce::decimal($data, 'total_collected'),
        );
    }
}
