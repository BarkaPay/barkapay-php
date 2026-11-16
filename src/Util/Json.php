<?php

declare(strict_types=1);

namespace PayHub\Util;

use PayHub\Exception\ConfigurationException;

/**
 * @internal
 */
final class Json
{
    /**
     * Encode a request body.
     *
     * Floats are always written in their shortest round-trip form: a host
     * configured with `serialize_precision = 17` would otherwise turn
     * `1234.56` into `1234.5599999999999`, an amount the API rejects.
     *
     * @throws ConfigurationException when the value cannot be represented as JSON
     */
    public static function encode(mixed $value): string
    {
        // Some shared hosts disable ini_set(): the default precision is then kept.
        $previous = \function_exists('ini_get') && \function_exists('ini_set')
            ? ini_get('serialize_precision')
            : false;
        $changed = $previous !== false && $previous !== '-1' && ini_set('serialize_precision', '-1') !== false;

        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $e) {
            throw new ConfigurationException(
                'The request parameters cannot be encoded as JSON: ' . $e->getMessage() . '.',
                0,
                $e,
            );
        } finally {
            if ($changed) {
                ini_set('serialize_precision', (string) $previous);
            }
        }
    }

    /**
     * Encode a request body, always as a JSON object (`[]` becomes `{}`).
     *
     * @param array<string, mixed> $body
     *
     * @throws ConfigurationException
     */
    public static function encodeObject(array $body): string
    {
        return $body === [] ? '{}' : self::encode($body);
    }

    /**
     * Decode a JSON object into an associative array. Returns null for empty
     * input or anything that isn't a JSON object (a list, a scalar, HTML…).
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $json): ?array
    {
        $json = trim($json);
        if ($json === '' || $json[0] !== '{') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
