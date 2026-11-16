<?php

declare(strict_types=1);

namespace PayHub\Util;

use PayHub\Exception\ConfigurationException;

/**
 * Builds URL path segments from caller-supplied values (country codes,
 * public ids, order ids, slugs). Every dynamic value is validated, then
 * percent-encoded, so it can never escape its own segment.
 *
 * @internal
 */
final class Path
{
    /** Longest identifier the API accepts (`order.id` is capped at 255). */
    private const MAX_SEGMENT_LENGTH = 255;

    /**
     * Validate and normalise a country code (`bf`, `bj`, `sn`, …).
     *
     * @throws ConfigurationException
     */
    public static function country(string $country): string
    {
        $code = strtolower(trim($country));

        if (preg_match('/^[a-z]{2}$/D', $code) !== 1) {
            throw new ConfigurationException(
                'Invalid country code: expected two letters such as "bf", "bj" or "sn".',
            );
        }

        return $code;
    }

    /**
     * Validate and percent-encode one dynamic path segment.
     *
     * `rawurlencode()` alone is not enough: `.` and `..` survive it, and HTTP
     * clients collapse dot segments — `payments/links/..` would silently hit
     * `payments/` instead. Empty values would do the same (`payments/` is the
     * list endpoint), so both are refused up front.
     *
     * @param string $what human name of the value, used in the error message
     *
     * @throws ConfigurationException
     */
    public static function segment(string $value, string $what = 'identifier'): string
    {
        if (trim($value) === '') {
            throw new ConfigurationException('The ' . $what . ' must not be empty.');
        }

        if ($value === '.' || $value === '..') {
            throw new ConfigurationException('The ' . $what . ' must not be a relative path segment.');
        }

        if (\strlen($value) > self::MAX_SEGMENT_LENGTH) {
            throw new ConfigurationException(
                'The ' . $what . ' is too long (max ' . self::MAX_SEGMENT_LENGTH . ' bytes).',
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new ConfigurationException('The ' . $what . ' must not contain control characters.');
        }

        return rawurlencode($value);
    }
}
