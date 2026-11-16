<?php

declare(strict_types=1);

namespace PayHub\DTO;

use PayHub\Util\Coerce;

/**
 * One page of payment links.
 *
 * @implements \IteratorAggregate<int, PaymentLink>
 */
final class PaymentLinkCollection implements \IteratorAggregate, \Countable
{
    /**
     * @param list<PaymentLink> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly ?Pagination $meta,
    ) {
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function fromEnvelope(array $envelope): self
    {
        $data = Coerce::arr($envelope, 'data') ?? [];

        $items = [];
        foreach (Coerce::rows($data, 'payment_links') as $row) {
            $items[] = PaymentLink::fromArray($row);
        }

        $meta = Coerce::arr($envelope, 'meta');

        return new self($items, $meta !== null ? Pagination::fromArray($meta) : null);
    }

    /**
     * @return \ArrayIterator<int, PaymentLink>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
