<?php

declare(strict_types=1);

namespace BarkaPay\Util;

/**
 * Tolerant readers for HTTP response headers, whatever the transport handed
 * over (plain strings, or PSR-7 style lists of values).
 *
 * @internal
 */
final class Headers
{
    /**
     * Lower-cased names, one string per header (several values joined with ", ").
     * Anything that is not a string or a list of scalars is dropped.
     *
     * @param array<array-key, mixed> $headers
     *
     * @return array<string, string>
     */
    public static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                $value = implode(', ', array_map(
                    static fn (mixed $item): string => \is_scalar($item) ? (string) $item : '',
                    $value,
                ));
            }

            if (\is_scalar($value)) {
                $normalized[strtolower((string) $name)] = trim((string) $value);
            }
        }

        return $normalized;
    }

    /**
     * Seconds announced by `Retry-After` (delta-seconds or an HTTP date), or null.
     *
     * @param array<string, string> $headers normalised headers
     */
    public static function retryAfter(array $headers): ?int
    {
        $value = $headers['retry-after'] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        $seconds = Coerce::digits($value);
        if ($seconds !== null) {
            return $seconds;
        }

        $date = strtotime($value);

        return $date === false ? null : max(0, $date - time());
    }
}
