<?php

declare(strict_types=1);

namespace BarkaPay\DTO;

use BarkaPay\Util\Coerce;

/**
 * One page of payments.
 *
 * @implements \IteratorAggregate<int, Payment>
 */
final class PaymentCollection implements \IteratorAggregate, \Countable
{
    /**
     * @param list<Payment>    $items
     * @param PaymentLink|null $paymentLink summary of the link (id, slug, status, stats) — only set by
     *                                      `paymentLinks->listPayments()`
     */
    public function __construct(
        public readonly array $items,
        public readonly ?Pagination $meta,
        public readonly ?PaymentLink $paymentLink = null,
    ) {
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public static function fromEnvelope(array $envelope): self
    {
        $data = Coerce::arr($envelope, 'data') ?? [];

        $items = [];
        foreach (Coerce::rows($data, 'payments') as $row) {
            $items[] = Payment::fromArray($row);
        }

        $meta = Coerce::arr($envelope, 'meta');
        $link = Coerce::arr($data, 'payment_link');

        return new self(
            $items,
            $meta !== null ? Pagination::fromArray($meta) : null,
            $link !== null ? PaymentLink::fromArray($link) : null,
        );
    }

    /**
     * @return \ArrayIterator<int, Payment>
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
