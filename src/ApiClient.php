<?php

declare(strict_types=1);

namespace PayHub;

use PayHub\DTO\RateLimit;
use PayHub\Exception\ApiException;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NetworkException;
use PayHub\Http\HttpClientInterface;
use PayHub\Http\Request;
use PayHub\Http\Response;
use PayHub\Util\Json;
use PayHub\Util\Path;
use PayHub\Util\RequestId;
use PayHub\Util\Secret;

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
 *  - writes (`POST`, `PATCH`, `DELETE`) are retried only when the API provably
 *    did not process them: a 429 (rejected by the rate limiter before any
 *    work) or a connection that was never established. A timeout or a 5xx on
 *    a write is surfaced to the caller: the API has no idempotency key, so
 *    replaying a payment or a transfer blindly could move money twice.
 *
 * Waits use exponential backoff with jitter and honour `Retry-After`. When the
 * API asks for a longer wait than `$maxRetryDelay`, the error is thrown
 * immediately instead of blocking the caller.
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
        private readonly float $maxRetryDelay = 5.0,
        private readonly ?\Closure $sleeper = null,
    ) {
        $this->authorization = new Secret($authorization);
    }

    /**
     * @param list<string>                    $segments path segments after `/v1/`; each one is validated and encoded
     * @param array<string, mixed>|null       $body
     * @param array<string, scalar|null>|null $query
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
                if ($attempt < $this->maxRetries && ($isRead || $e->isConnectionFailure())) {
                    $this->wait($this->backoff($attempt++));

                    continue;
                }

                throw $e->withRequestId($requestId);
            }

            if ($attempt < $this->maxRetries && $this->isRetryable($response->statusCode, $isRead)) {
                $delay = $this->retryDelay($attempt, $response);
                if ($delay !== null) {
                    ++$attempt;
                    $this->wait($delay);

                    continue;
                }
            }

            return $this->handle($response, $requestId);
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
    private function handle(Response $response, string $requestId): array
    {
        $status = $response->statusCode;

        $headers = [];
        foreach ($response->headers as $name => $value) {
            $headers[strtolower((string) $name)] = $value;
        }

        $this->lastRateLimit = RateLimit::fromHeaders($headers);
        if (isset($headers['x-request-id']) && $headers['x-request-id'] !== '') {
            $this->lastRequestId = $headers['x-request-id'];
        }

        $success = $status >= 200 && $status < 300;

        if ($success && $status === 204 && trim($response->body) === '') {
            return [];
        }

        $envelope = Json::decode($response->body);
        $envStatus = $envelope['status'] ?? null;

        if ($success && $envelope !== null && $envStatus !== 'error' && $envStatus !== 'failed') {
            return $envelope;
        }

        // Anything else is an error: a non-2xx status, an error envelope, or a
        // body that is not the JSON envelope at all (HTML error page, redirect).
        $envelope ??= [];
        if (!\is_string($envelope['request_id'] ?? null) || $envelope['request_id'] === '') {
            $envelope['request_id'] = $this->lastRequestId ?? $requestId;
        }

        throw ApiException::fromEnvelope($status, $envelope, $headers, $response->body);
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
    private function retryDelay(int $attempt, Response $response): ?float
    {
        $retryAfter = $this->retryAfter($response);
        if ($retryAfter === null) {
            return $this->backoff($attempt);
        }

        if ($retryAfter > $this->maxRetryDelay) {
            return null;
        }

        return max((float) $retryAfter, $this->backoff($attempt));
    }

    private function retryAfter(Response $response): ?int
    {
        $value = $response->header('Retry-After');
        if ($value === null || $value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        $date = strtotime($value);

        return $date === false ? null : max(0, $date - time());
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
