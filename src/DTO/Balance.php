<?php

declare(strict_types=1);

namespace BarkaPay\DTO;

use BarkaPay\Util\Coerce;

/**
 * The merchant wallet balance for a country.
 *
 * `available`, `total` and `holds` are decimal strings in `currency`
 * (`"150000.00"`); `available` = `total` − `holds` is what you can disburse
 * now. Compare them with bcmath, not as floats.
 *
 * `balances` is reserved for multi-asset wallets and is null on the
 * mobile-money countries.
 */
final class Balance
{
    /**
     * @param list<array<string, mixed>>|null $balances
     * @param array<string, mixed>            $raw
     */
    public function __construct(
        public readonly ?string $country,
        public readonly ?string $currency,
        public readonly ?string $available,
        public readonly ?string $total,
        public readonly ?string $holds,
        public readonly ?array $balances,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            country: Coerce::str($data, 'country'),
            currency: Coerce::str($data, 'currency'),
            available: Coerce::decimal($data, 'available'),
            total: Coerce::decimal($data, 'total'),
            holds: Coerce::decimal($data, 'holds'),
            balances: \array_key_exists('balances', $data) && \is_array($data['balances'])
                ? Coerce::rows($data, 'balances')
                : null,
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
