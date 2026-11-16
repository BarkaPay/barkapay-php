<?php

declare(strict_types=1);

namespace PayHub;

use PayHub\DTO\RateLimit;
use PayHub\Exception\ApiException;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NetworkException;
use PayHub\Http\CurlClient;
use PayHub\Http\HttpClientInterface;
use PayHub\Resources\Balance;
use PayHub\Resources\Operators;
use PayHub\Resources\PaymentLinks;
use PayHub\Resources\Payments;
use PayHub\Resources\Transfers;
use PayHub\Util\Coerce;

/**
 * Entry point to the barkapay API.
 *
 * ```php
 * $barkapay = new \PayHub\Client(apiKey: 'key_id:secret', country: 'bf');
 * $payment = $barkapay->payments->create([
 *     'operator'     => 'ORANGE',
 *     'phone_number' => '74123456',
 *     'amount'       => 10000,
 *     'otp'          => '123456',
 *     'order'        => ['id' => 'ORDER-2026-001'],
 * ]);
 * ```
 */
final class Client
{
    /** SDK version, sent in the `User-Agent` header. */
    public const VERSION = '1.1.0';

    public const DEFAULT_BASE_URL = 'https://hub.barkapay.com';

    public readonly Payments $payments;
    public readonly Transfers $transfers;
    public readonly Balance $balance;
    public readonly Operators $operators;
    public readonly PaymentLinks $paymentLinks;

    private readonly ApiClient $api;

    /**
     * @param string|null              $apiKey         the merchant key as "key_id:secret" (what you copy from the
     *                                                 dashboard). Do NOT prefix it with "Bearer" — the SDK does that.
     * @param string|null              $keyId          alternatively, the key id…
     * @param string|null              $secret         …and the secret, passed separately
     * @param string                   $country        default country code (`bf`, `bj`, `sn`); overridable per call
     * @param string                   $baseUrl        API base URL — HTTPS. Plain http:// is accepted for a loopback host
     *                                                 only (localhost, 127.0.0.1, [::1]), or for a non-barkapay host
     *                                                 when you pass your own `$httpClient`
     * @param int                      $maxRetries     automatic retries of what is safe to retry (see README → Retries)
     * @param float                    $timeout        total time allowed for one request, in seconds
     * @param HttpClientInterface|null $httpClient     custom transport; `$timeout` / `$connectTimeout` then do not apply
     * @param float                    $connectTimeout time allowed to establish the connection, in seconds
     * @param float                    $maxRetryDelay  longest wait between two attempts, in seconds (default 10). When the
     *                                                 API asks for more (`Retry-After`), the error is thrown instead.
     *                                                 A write is never replayed after more than 10 s.
     *
     * @throws ConfigurationException on missing credentials or an unusable base URL
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $keyId = null,
        #[\SensitiveParameter]
        ?string $secret = null,
        string $country = '',
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $maxRetries = 2,
        float $timeout = CurlClient::DEFAULT_TIMEOUT,
        ?HttpClientInterface $httpClient = null,
        float $connectTimeout = CurlClient::DEFAULT_CONNECT_TIMEOUT,
        float $maxRetryDelay = 10.0,
    ) {
        $this->api = new ApiClient(
            httpClient: $httpClient ?? new CurlClient($timeout, $connectTimeout),
            authorization: self::buildAuthorization($apiKey, $keyId, $secret),
            baseUrl: self::normalizeBaseUrl($baseUrl, $httpClient !== null),
            defaultCountry: strtolower(trim($country)),
            maxRetries: max(0, $maxRetries),
            userAgent: self::userAgent(),
            maxRetryDelay: max(0.0, $maxRetryDelay),
        );

        $this->payments = new Payments($this->api);
        $this->transfers = new Transfers($this->api);
        $this->balance = new Balance($this->api);
        $this->operators = new Operators($this->api);
        $this->paymentLinks = new PaymentLinks($this->api);
    }

    /**
     * Liveness check (also confirms your key works).
     *
     * @return array<string, mixed> `['version' => …, 'documentation' => …]`
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function ping(?string $country = null): array
    {
        return Coerce::arr($this->api->request('GET', ['ping'], $country, expect: ''), 'data') ?? [];
    }

    /**
     * Current merchant + API application details (scopes, permissions).
     *
     * @return array<string, mixed> `['merchant' => […], 'api_app' => […]]`
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function me(?string $country = null): array
    {
        return Coerce::arr($this->api->request('GET', ['me'], $country, expect: ''), 'data') ?? [];
    }

    /**
     * Rate-limit budget reported by the most recent API response
     * (`X-RateLimit-Limit` / `X-RateLimit-Remaining`), or null before the
     * first call. Budgets are per route family.
     */
    public function lastRateLimit(): ?RateLimit
    {
        return $this->api->lastRateLimit();
    }

    /** `X-Request-Id` of the most recent call — the id to quote to support. */
    public function lastRequestId(): ?string
    {
        return $this->api->lastRequestId();
    }

    /**
     * Never expose the API key through `var_dump()` / `print_r()` / debuggers.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['version' => self::VERSION, 'api' => $this->api];
    }

    private static function userAgent(): string
    {
        return 'PayHub-PHP/' . self::VERSION . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    private static function buildAuthorization(
        #[\SensitiveParameter]
        ?string $apiKey,
        ?string $keyId,
        #[\SensitiveParameter]
        ?string $secret,
    ): string {
        $token = null;

        if ($apiKey !== null && trim($apiKey) !== '') {
            $token = trim($apiKey);
            // Forgive an accidental "Bearer " prefix so we never send "Bearer Bearer …".
            if (stripos($token, 'bearer ') === 0) {
                $token = trim(substr($token, 7));
            }
        } elseif ($keyId !== null && $secret !== null && trim($keyId) !== '' && trim($secret) !== '') {
            $token = trim($keyId) . ':' . trim($secret);
        }

        if ($token === null || $token === '') {
            throw new ConfigurationException('Provide `apiKey` ("key_id:secret") or both `keyId` and `secret`.');
        }

        // The token becomes an HTTP header value: visible ASCII only. The
        // message deliberately never echoes the key.
        if (preg_match('/^[\x21-\x7E]+$/D', $token) !== 1) {
            throw new ConfigurationException(
                'The API key contains whitespace or non-printable characters — copy it again from the dashboard.',
            );
        }

        return 'Bearer ' . $token;
    }

    private static function normalizeBaseUrl(string $baseUrl, bool $customTransport): string
    {
        $url = rtrim(trim($baseUrl), '/');

        // Printable ASCII only: no whitespace, no control character can reach the transport.
        if (preg_match('/^[\x21-\x7E]+$/D', $url) !== 1) {
            throw new ConfigurationException('Invalid base URL: expected an absolute https:// URL.');
        }

        $parts = parse_url($url);

        $scheme = \is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        // "hub.barkapay.com." (root dot) is the same host as "hub.barkapay.com".
        $host = \is_array($parts) ? rtrim(strtolower($parts['host'] ?? ''), '.') : '';

        if (!\is_array($parts) || $host === '' || !\in_array($scheme, ['http', 'https'], true)) {
            throw new ConfigurationException('Invalid base URL: expected an absolute https:// URL.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException(
                'Invalid base URL: it must not contain credentials, a query string or a fragment.',
            );
        }

        // Plain HTTP would send the API key in clear text. The barkapay hosts
        // only answer it with a redirect, so it can never be what you want.
        if ($scheme === 'http' && ($host === 'barkapay.com' || str_ends_with($host, '.barkapay.com'))) {
            throw new ConfigurationException(
                'The barkapay API is only served over HTTPS: use ' . self::DEFAULT_BASE_URL . '.',
            );
        }

        // Elsewhere, plain HTTP is for a local mock server: a loopback host,
        // or any host reached through a transport of your own.
        if ($scheme === 'http' && !$customTransport && !self::isLoopback($host)) {
            throw new ConfigurationException(
                'The base URL must use https:// (http:// is accepted for localhost only): '
                . 'the API key must never travel in clear text.',
            );
        }

        return $url;
    }

    /** `localhost`, `127.0.0.0/8` or `::1` — `$host` lower-cased, without the root dot. */
    private static function isLoopback(string $host): bool
    {
        if ($host === 'localhost' || $host === '[::1]' || $host === '::1') {
            return true;
        }

        return preg_match('/^127(\.(25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])){3}$/D', $host) === 1;
    }
}
