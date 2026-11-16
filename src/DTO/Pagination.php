<?php

declare(strict_types=1);

namespace PayHub\DTO;

use PayHub\Util\Coerce;

/**
 * Page information of a list response. Ask for another page with the `page`
 * query parameter (`['page' => 2, 'per_page' => 50]`).
 */
final class Pagination
{
    /**
     * @param int|null $lastPage not sent by every list endpoint (payment links omit it) — use {@see hasMore()}
     */
    public function __construct(
        public readonly ?int $currentPage,
        public readonly ?int $lastPage,
        public readonly ?int $perPage,
        public readonly ?int $total,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function fromArray(array $meta): self
    {
        return new self(
            currentPage: Coerce::int($meta, 'current_page'),
            lastPage: Coerce::int($meta, 'last_page'),
            perPage: Coerce::int($meta, 'per_page'),
            total: Coerce::int($meta, 'total'),
        );
    }

    /** True when at least one more page exists after this one. */
    public function hasMore(): bool
    {
        if ($this->currentPage === null) {
            return false;
        }

        if ($this->lastPage !== null) {
            return $this->currentPage < $this->lastPage;
        }

        if ($this->perPage === null || $this->total === null) {
            return false;
        }

        return $this->currentPage * $this->perPage < $this->total;
    }
}
