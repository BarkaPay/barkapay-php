<?php

declare(strict_types=1);

namespace PayHub\Util;

/**
 * Defensive readers for decoded JSON — keep DTO hydration null-safe and tidy.
 *
 * @internal
 */
final class Coerce
{
    /**
     * @param array<string, mixed> $data
     */
    public static function str(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function int(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : (\is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function bool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }

    /**
     * Amount as returned by the API (a JSON number today). Returned as-is —
     * int, float or decimal string — so the SDK never rounds it.
     *
     * @param array<string, mixed> $data
     */
    public static function amount(array $data, string $key): int|float|string|null
    {
        $value = $data[$key] ?? null;

        return \is_int($value) || \is_float($value) || \is_string($value) ? $value : null;
    }

    /**
     * A decimal the API documents as a string (`fees`, balances, link amounts).
     * Some creation snapshots carry the same value as a bare JSON number; it is
     * normalised to a string so callers always get one type.
     *
     * @param array<string, mixed> $data
     */
    public static function decimal(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value) && is_finite($value)) {
            // Shortest round-trip form, never scientific notation.
            $text = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

            return $text === '' || $text === '-0' ? '0' : $text;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public static function arr(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * The rows of a JSON list of objects; anything that is not an object is skipped.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $row) {
            if (\is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
