<?php

declare(strict_types=1);

namespace BarkaPay;

use BarkaPay\DTO\RateLimit;
use BarkaPay\Exception\ApiException;
use BarkaPay\Exception\ConfigurationException;
use BarkaPay\Exception\NetworkException;
use BarkaPay\Http\HttpClientInterface;
use BarkaPay\Http\Request;
use BarkaPay\Http\Response;
use BarkaPay\Util\Headers;
use BarkaPay\Util\Json;
use BarkaPay\Util\Path;
use BarkaPay\Util\RequestId;
use BarkaPay\Util\Secret;

/**
 * Internal transport: turns a logical call (method, path segments, country,
 * body) into an authenticated HTTP request against
 * `https://hub.barkapay.com/{country}/v1/…`, retries what is safe to retry,
 * and unwraps the standard response envelope (throwing a typed
 * {@see ApiException} on error).
 *
 * Retry policy — a request is only ever repeated when that cannot duplicate
 * an operation:
 *  - reads (`GET`) are retried on network errors and on 429 / 502 / 503 / 504;
 *  - writes (`POST`, `PATCH`, `DELETE`) are retried on 429 only: the rate
 *    limiter refuses the request before any work is done. A network error, a
 *    timeout or a 5xx on a write is always surfaced to the caller: the API
 *    has no idempotency key, so replaying a payment or a transfer blindly
 *    could move money twice.
 *
 * Waits use exponential backoff with jitter and honour `Retry-After`. When the
 * API asks for a longer wait than `$maxRetryDelay` (10 s by default; never
 * more than 10 s for a write), the error is thrown immediately instead of
 * blocking the caller.
 *
 * @internal
 */
final class ApiClient
{
    /** First backoff step, in seconds; doubles at every attempt. */
    private const BASE_DELAY = 0.3;

    private const READ_METHODS = ['GET', 'HEAD'];

    /** Statuses worth retrying for a read. */
    private const TRANSIENT_STATUSES = [429, 502, 503, 504];

    /**
     * Longest `Retry-After` a write is ever replayed after, whatever
     * `$maxRetryDelay` says: money is not sent from a call that has been
     * asleep for longer. The API asks for 10 s on a real 429.
     */
    public const MAX_WRITE_RETRY_AFTER = 10.0;

    /** The `Authorization` header value — see {@see Secret} for why it is not a plain property. */
    private readonly Secret $authorization;

    private ?RateLimit $lastRateLimit = null;

    private ?string $lastRequestId = null;

    /**
     * @param (\Closure(float): void)|null $sleeper waits the given number of seconds (replaced in tests)
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter]
        string $authorization,
        private readonly string $baseUrl,
        private readonly string $defaultCountry,
        private readonly int $maxRetries,
        private readonly string $userAgent,
        private readonly float $maxRetryDelay = 10.0,
        private readonly ?\Closure $sleeper = null,
    ) {
        $this->authorization = new Secret($authorization);
    }

    /**
     * @param list<string>                    $segments path segments after `/v1/`; each one is validated and encoded
     * @param array<string, mixed>|null       $body
     * @param array<string, scalar|null>|null $query
     * @param string|null                     $expect   what a successful answer must contain: a key of `data` holding
     *                                                  an object or a list (`payment`, `payments`, …), `''` for `data`
     *                                                  itself, or null when the envelope alone is enough
     *
     * @return array<string, mixed> the decoded response envelope
     *
     * @throws ConfigurationException on an invalid country, identifier or body
     * @throws NetworkException       when no response was received
     * @throws ApiException           on any error response
     */
    public function request(
        string $method,
        array $segments,
        ?string $country = null,
        ?array $body = null,
        ?array $query = null,
        ?string $expect = null,
    ): array {
        $method = strtoupper($method);

        $cc = trim($country ?? $this->defaultCountry);
        if ($cc === '') {
            throw new ConfigurationException('No country given for this call and no default set on the client.');
        }

        $path = [];
        foreach ($segments as $segment) {
            $path[] = Path::segment($segment);
        }

        $url = $this->baseUrl . '/' . Path::country($cc) . '/v1/' . implode('/', $path);
        if ($query !== null) {
            $filtered = array_filter($query, static fn ($v): bool => $v !== null);
            if ($filtered !== []) {
                $url .= '?' . http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
            }
        }

        $requestId = RequestId::generate();
        $headers = [
            'Authorization' => $this->authorization->reveal(),
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
            'X-Request-Id' => $requestId,
        ];

        $payload = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = Json::encodeObject($body);
        }

        $request = new Request($method, $url, $headers, $payload);
        $isRead = \in_array($method, self::READ_METHODS, true);

        $this->lastRequestId = $requestId;
        $this->lastRateLimit = null;

        $attempt = 0;
        while (true) {
            try {
                $response = $this->httpClient->send($request);
            } catch (NetworkException $e) {
                // Never for a write, whatever the failure: only the caller can
                // tell (by looking the operation up) whether it is safe.
                if ($isRead && $attempt < $this->maxRetries) {
                    $this->wait($this->backoff($attempt++));

                    continue;
                }

                throw $e->withRequestId($requestId);
            }

            if ($attempt < $this->maxRetries && $this->isRetryable($response->statusCode, $isRead)) {
                $delay = $this->retryDelay($attempt, $response, $isRead);
                if ($delay !== null) {
                    ++$attempt;
                    $this->wait($delay);

                    continue;
                }
            }

            return $this->handle($response, $requestId, $expect);
        }
    }

    /** Rate-limit budget reported by the most recent response, if any. */
    public function lastRateLimit(): ?RateLimit
    {
        return $this->lastRateLimit;
    }

    /** `X-Request-Id` of the most recent call (the id the API logged it under). */
    public function lastRequestId(): ?string
    {
        return $this->lastRequestId;
    }

    /**
     * What `var_dump()` / `print_r()` show: the configuration, never the key.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'defaultCountry' => $this->defaultCountry,
            'maxRetries' => $this->maxRetries,
            'maxRetryDelay' => $this->maxRetryDelay,
            'userAgent' => $this->userAgent,
            'authorization' => 'Bearer [REDACTED]',
            'httpClient' => $this->httpClient::class,
        ];
    }

    private function wait(float $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);
        } elseif (\function_exists('usleep')) {
            // Hosts that disable usleep() simply retry without waiting.
            usleep((int) round($seconds * 1_000_000));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function handle(Response $response, string $requestId, ?string $expect): array
    {
        $status = $response->statusCode;
        $headers = $response->normalizedHeaders();

        $this->lastRateLimit = RateLimit::fromHeaders($headers);
        if (($headers['x-request-id'] ?? '') !== '') {
            $this->lastRequestId = $headers['x-request-id'];
        }

        $envelope = Json::decode($response->body);

        // Success is exactly: a 2xx status AND the envelope saying so AND the
        // resource the call is about. Everything else raises — a redirect, an
        // HTML page, an empty body, an error envelope, or a JSON object that
        // is not the answer we asked for must never look like a success.
        $problem = null;
        if ($status >= 200 && $status < 300 && $envelope !== null && ($envelope['status'] ?? null) === 'success') {
            $problem = self::missing($envelope, $expect);
            if ($problem === null) {
                return $envelope;
            }
        }

        $envelope ??= [];
        if ($problem !== null) {
            $envelope = ['message' => $problem] + array_diff_key($envelope, ['code' => true]);
        }
        if (!\is_string($envelope['request_id'] ?? null) || $envelope['request_id'] === '') {
            $envelope['request_id'] = $this->lastRequestId ?? $requestId;
        }

        throw ApiException::fromEnvelope($status, $envelope, $headers, $response->body);
    }

    /**
     * What a success envelope lacks to be the expected answer, or null.
     *
     * @param array<string, mixed> $envelope
     */
    private static function missing(array $envelope, ?string $expect): ?string
    {
        if ($expect === null) {
            return null;
        }

        $data = $envelope['data'] ?? null;
        if ($expect === '') {
            return \is_array($data) ? null : 'Unexpected response from the barkapay API: the envelope has no `data`.';
        }

        return \is_array($data) && \is_array($data[$expect] ?? null)
            ? null
            : 'Unexpected response from the barkapay API: the envelope has no `data.' . $expect . '`.';
    }

    private function isRetryable(int $status, bool $isRead): bool
    {
        // 429: refused by the rate limiter before any processing — safe for
        // every method. The other transient statuses say nothing about what
        // the API did with a write, so they are retried for reads only.
        return $status === 429 || ($isRead && \in_array($status, self::TRANSIENT_STATUSES, true));
    }

    /**
     * Seconds to wait before the next attempt, or null when the API asks for
     * a longer wait than the caller accepts (the error is then surfaced).
     */
    private function retryDelay(int $attempt, Response $response, bool $isRead): ?float
    {
        $retryAfter = $this->retryAfter($response);
        if ($retryAfter === null) {
            return $this->backoff($attempt);
        }

        $limit = $isRead ? $this->maxRetryDelay : min($this->maxRetryDelay, self::MAX_WRITE_RETRY_AFTER);
        if ($retryAfter > $limit) {
            return null;
        }

        return max((float) $retryAfter, $this->backoff($attempt));
    }

    private function retryAfter(Response $response): ?int
    {
        return Headers::retryAfter($response->normalizedHeaders());
    }

    /**
     * Exponential backoff with jitter: a random wait between 50% and 100% of
     * `BASE_DELAY * 2^attempt`, capped at `$maxRetryDelay`.
     */
    private function backoff(int $attempt): float
    {
        $ceiling = min($this->maxRetryDelay, self::BASE_DELAY * (2 ** $attempt));
        if ($ceiling <= 0) {
            return 0.0;
        }

        return $ceiling * (0.5 + random_int(0, 1000) / 2000);
    }
}
