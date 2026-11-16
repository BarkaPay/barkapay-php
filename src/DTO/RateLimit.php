<?php

declare(strict_types=1);

namespace BarkaPay\DTO;

use BarkaPay\Util\Coerce;

/**
 * Rate-limit budget reported by an API response (`X-RateLimit-*` headers).
 *
 * Budgets are per route family and shared by all the API applications of a
 * merchant; the numbers are operational settings that may change — read them
 * at runtime rather than hard-coding them.
 */
final class RateLimit
{
    /**
     * @param int|null $limit      requests allowed per window for the route family just called
     * @param int|null $remaining  requests left in the current window
     * @param int|null $retryAfter seconds to wait before retrying — only sent when the limit was hit (429)
     */
    public function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?int $retryAfter = null,
    ) {
    }

    /**
     * @param array<string, string> $headers response headers, lower-cased names
     */
    public static function fromHeaders(array $headers): ?self
    {
        $limit = Coerce::digits($headers['x-ratelimit-limit'] ?? null);
        $remaining = Coerce::digits($headers['x-ratelimit-remaining'] ?? null);
        $retryAfter = Coerce::digits($headers['retry-after'] ?? null);

        if ($limit === null && $remaining === null && $retryAfter === null) {
            return null;
        }

        return new self($limit, $remaining, $retryAfter);
    }
}
