<?php

declare(strict_types=1);

namespace PayHub;

use PayHub\Exception\ConfigurationException;
use PayHub\Exception\SignatureVerificationException;
use PayHub\Util\Json;

/**
 * Verify the signature of incoming webhooks.
 *
 * Every delivery carries a `Barkapay-Signature` header of the form
 * `t=<unix_ts>,v1=<hex>` where `v1 = HMAC-SHA256("{t}.{rawBody}")` keyed with
 * the webhook's signing secret (`whsec_…`). Always verify against the RAW
 * request body — not a re-encoded copy.
 *
 * ```php
 * $event = \PayHub\Webhook::parse(
 *     file_get_contents('php://input') ?: '',
 *     $_SERVER['HTTP_BARKAPAY_SIGNATURE'] ?? '',
 *     $webhookSecret,
 * );
 * ```
 *
 * The body has no `event` field: derive it from `type` (`payment` |
 * `transfer`) and `status`. Deliveries are at-least-once — deduplicate on
 * `event_id`, which is stable across the retries of one delivery.
 */
final class Webhook
{
    /** The header carrying the signature. In `$_SERVER`: `HTTP_BARKAPAY_SIGNATURE`. */
    public const SIGNATURE_HEADER = 'Barkapay-Signature';

    /**
     * Name of the same header before 2026-11-15. No longer sent; kept so an
     * endpoint can read both during a migration (`HTTP_PAYHUB_SIGNATURE`).
     */
    public const LEGACY_SIGNATURE_HEADER = 'PayHub-Signature';

    /** Maximum age (and clock skew) of a signature, in seconds. */
    public const DEFAULT_TOLERANCE = 300;

    /** A genuine header is ~80 bytes; anything far larger is not ours. */
    private const MAX_HEADER_LENGTH = 1024;

    /**
     * @param string $payload         the raw request body
     * @param string $signatureHeader the `Barkapay-Signature` header value
     * @param string $secret          the webhook signing secret (`whsec_…`)
     * @param int    $tolerance       maximum age of the signature in seconds (default 300, must be > 0)
     *
     * @throws ConfigurationException when the secret is empty or the tolerance is not positive — integration
     *                                mistakes, never reported as a plain `false`
     */
    public static function verify(
        string $payload,
        string $signatureHeader,
        #[\SensitiveParameter]
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): bool {
        try {
            self::assert($payload, $signatureHeader, $secret, $tolerance);

            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /**
     * Verify and decode the webhook body in one step.
     *
     * @param string $payload         the raw request body
     * @param string $signatureHeader the `Barkapay-Signature` header value
     * @param string $secret          the webhook signing secret (`whsec_…`)
     * @param int    $tolerance       maximum age of the signature in seconds (see {@see verify()})
     *
     * @return array<string, mixed> the event: `event_id`, `timestamp`, `type`, `public_id`, `status`, `amount`,
     *                              `fees`, `order_id`, `reason_code`, …
     *
     * @throws SignatureVerificationException when the signature is missing, malformed, stale or wrong,
     *                                        or the verified body is not a JSON object
     * @throws ConfigurationException         when the secret is empty or the tolerance is not positive
     */
    public static function parse(
        string $payload,
        string $signatureHeader,
        #[\SensitiveParameter]
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
    ): array {
        self::assert($payload, $signatureHeader, $secret, $tolerance);

        $data = Json::decode($payload);
        if ($data === null) {
            throw new SignatureVerificationException('Webhook payload is not a JSON object.');
        }

        return $data;
    }

    /**
     * @throws SignatureVerificationException
     * @throws ConfigurationException
     */
    private static function assert(
        string $payload,
        string $header,
        #[\SensitiveParameter]
        string $secret,
        int $tolerance,
    ): void {
        // An empty key would make the HMAC computable by anyone: fail loudly
        // when the secret was not configured.
        if (trim($secret) === '') {
            throw new ConfigurationException(
                'The webhook signing secret is empty: refusing to verify. Set the whsec_… secret of your endpoint.',
            );
        }

        // 0 or less would switch the replay window off.
        if ($tolerance <= 0) {
            throw new ConfigurationException('The webhook tolerance must be a positive number of seconds (default 300).');
        }

        $parsed = self::parseHeader($header);
        if ($parsed === null) {
            throw new SignatureVerificationException('Malformed or missing ' . self::SIGNATURE_HEADER . ' header.');
        }

        [$timestamp, $signatures] = $parsed;

        if (abs(time() - $timestamp) > $tolerance) {
            throw new SignatureVerificationException('Webhook timestamp is outside the tolerance window.');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        // Check every candidate, without stopping at the first match, so the
        // time taken does not depend on which one (if any) is valid.
        $valid = false;
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $valid = true;
            }
        }

        if (!$valid) {
            throw new SignatureVerificationException('Webhook signature mismatch.');
        }
    }

    /**
     * Strict parser for `t=<digits>,v1=<64 hex>[,v1=<64 hex>…]`. Several `v1`
     * entries are accepted (secret rotation); unknown schemes (`v0=…`, a
     * future `v2=…`) are ignored. Anything else is rejected.
     *
     * @return array{0: int, 1: list<string>}|null
     */
    private static function parseHeader(string $header): ?array
    {
        $header = trim($header);
        if ($header === '' || \strlen($header) > self::MAX_HEADER_LENGTH) {
            return null;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (\count($pair) !== 2) {
                return null;
            }

            [$key, $value] = [trim($pair[0]), trim($pair[1])];

            if ($key === 't') {
                // One timestamp only, plain digits (no sign, no exponent).
                if ($timestamp !== null || preg_match('/^[0-9]{1,12}$/D', $value) !== 1) {
                    return null;
                }
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                if (preg_match('/^[0-9a-fA-F]{64}$/D', $value) !== 1) {
                    return null;
                }
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $timestamp <= 0 || $signatures === []) {
            return null;
        }

        return [$timestamp, $signatures];
    }
}
