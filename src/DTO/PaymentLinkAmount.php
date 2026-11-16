<?php

declare(strict_types=1);

namespace BarkaPay\DTO;

use BarkaPay\Util\Coerce;

/**
 * What a payment link asks the payer for. Values are decimal strings in major
 * units of `currency` (`"25000"`).
 */
final class PaymentLinkAmount
{
    /**
     * @param string|null $type  `fixed` (pay `value`), `open` (payer chooses) or `range` (between `min` and `max`)
     * @param string|null $value set for `fixed`
     * @param string|null $min   set for `range`
     * @param string|null $max   set for `range`
     */
    public function __construct(
        public readonly ?string $type,
        public readonly ?string $value,
        public readonly ?string $min,
        public readonly ?string $max,
        public readonly ?string $currency,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            type: Coerce::str($data, 'type'),
            value: Coerce::decimal($data, 'value'),
            min: Coerce::decimal($data, 'min'),
            max: Coerce::decimal($data, 'max'),
            currency: Coerce::str($data, 'currency'),
        );
    }
}
