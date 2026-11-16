<?php

declare(strict_types=1);

namespace PayHub\Tests;

use PayHub\ApiClient;
use PayHub\Exception\NetworkException;
use PayHub\Exception\RateLimitException;
use PayHub\Exception\ServerException;
use PayHub\Exception\ServiceUnavailableException;
use PayHub\Http\Response;
use PayHub\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The retry policy is a money-safety property: reads are retried, a write is
 * replayed on 429 only (the API has no idempotency key).
 */
final class RetryTest extends TestCase
{
    /** @var list<float> seconds the client asked to sleep */
    private array $sleeps = [];

    private function api(FakeHttpClient $http, int $maxRetries = 2, float $maxRetryDelay = 5.0): ApiClient
    {
        $this->sleeps = [];

        return new ApiClient(
            httpClient: $http,
            authorization: 'Bearer k:s',
            baseUrl: 'https://hub.barkapay.com',
            defaultCountry: 'bf',
            maxRetries: $maxRetries,
            userAgent: 'test',
            maxRetryDelay: $maxRetryDelay,
            sleeper: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    private static function unavailable(string $code = 'service_unavailable'): Response
    {
        return FakeHttpClient::error(503, $code, 'down');
    }

    public function testReadIsRetriedOn503ThenSucceeds(): void
    {
        $http = new FakeHttpClient([self::unavailable(), self::unavailable(), FakeHttpClient::ok()]);

        $this->api($http)->request('GET', ['ping']);

        self::assertCount(3, $http->requests);
        self::assertCount(2, $this->sleeps);
        // Same logical call: the correlation id does not change between attempts.
        self::assertSame($http->requests[0]->headers['X-Request-Id'], $http->requests[2]->headers['X-Request-Id']);
    }

    public function testReadIsRetriedOnGatewayErrorsAndTimeouts(): void
    {
        $http = new FakeHttpClient([
            new Response(502, '<html>Bad gateway</html>'),
            new NetworkException('Operation timed out', 28),
            FakeHttpClient::ok(),
        ]);

        $this->api($http)->request('GET', ['balance']);

        self::assertCount(3, $http->requests);
    }

    public function testReadIsNotRetriedOnA500(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::error(500, 'internal_error')]);

        try {
            $this->api($http)->request('GET', ['ping']);
            self::fail('Expected ServerException.');
        } catch (ServerException) {
            self::assertCount(1, $http->requests);
        }
    }

    public function testRetriesStopAtMaxRetries(): void
    {
        $http = new FakeHttpClient([self::unavailable(), self::unavailable(), self::unavailable()]);

        try {
            $this->api($http, 2)->request('GET', ['ping']);
            self::fail('Expected ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
            self::assertCount(3, $http->requests);
        }
    }

    public function testNoRetryWhenDisabled(): void
    {
        $http = new FakeHttpClient([self::unavailable()]);

        try {
            $this->api($http, 0)->request('GET', ['ping']);
            self::fail('Expected ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
            self::assertCount(1, $http->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function writes(): iterable
    {
        yield 'create payment' => ['POST', ['payments']];
        yield 'create transfer' => ['POST', ['transfers']];
        yield 'confirm otp' => ['POST', ['payments', 'p1', 'confirm-otp']];
        yield 'resend otp' => ['POST', ['payments', 'p1', 'resend-otp']];
        yield 'create link' => ['POST', ['payments', 'links']];
        yield 'update link' => ['PATCH', ['payments', 'links', 'PL-1']];
        yield 'cancel link' => ['DELETE', ['payments', 'links', 'PL-1']];
        yield 'pause link' => ['POST', ['payments', 'links', 'PL-1', 'pause']];
        yield 'resume link' => ['POST', ['payments', 'links', 'PL-1', 'resume']];
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('writes')]
    public function testWriteIsNeverReplayedOnA503(string $method, array $segments): void
    {
        $http = new FakeHttpClient([self::unavailable('operator_unavailable'), FakeHttpClient::ok()]);

        try {
            $this->api($http)->request($method, $segments, null, ['x' => 1]);
            self::fail('Expected ServiceUnavailableException.');
        } catch (ServiceUnavailableException) {
            self::assertCount(1, $http->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('writes')]
    public function testWriteIsNeverReplayedAfterATimeout(string $method, array $segments): void
    {
        // The API may have processed the request: replaying could pay twice.
        $http = new FakeHttpClient([new NetworkException('Operation timed out', 28), FakeHttpClient::ok()]);

        try {
            $this->api($http)->request($method, $segments, null, ['x' => 1]);
            self::fail('Expected NetworkException.');
        } catch (NetworkException $e) {
            self::assertCount(1, $http->requests);
            self::assertFalse($e->isConnectionFailure());
            self::assertSame($http->requests[0]->headers['X-Request-Id'], $e->getRequestId());
        }
    }

    public function testWriteIsNeverReplayedOnGatewayErrors(): void
    {
        foreach ([502, 504] as $status) {
            $http = new FakeHttpClient([new Response($status, ''), FakeHttpClient::ok()]);

            try {
                $this->api($http)->request('POST', ['transfers'], null, ['x' => 1]);
                self::fail('Expected ServerException.');
            } catch (ServerException) {
                self::assertCount(1, $http->requests);
            }
        }
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('writes')]
    public function testWriteIsNeverReplayedOnANetworkErrorOfAnyKind(string $method, array $segments): void
    {
        // Not even when the transport says the connection was never
        // established: the rule is one line — writes are replayed on 429 only.
        $http = new FakeHttpClient([
            new NetworkException('Could not resolve host', 6, null, true),
            FakeHttpClient::ok(),
        ]);

        try {
            $this->api($http)->request($method, $segments, null, ['x' => 1]);
            self::fail('Expected NetworkException.');
        } catch (NetworkException $e) {
            self::assertCount(1, $http->requests);
            self::assertSame([], $this->sleeps);
            // The information stays available for the caller's own decision.
            self::assertTrue($e->isConnectionFailure());
        }
    }

    public function testReadIsRetriedWhenTheConnectionCouldNotBeEstablished(): void
    {
        $http = new FakeHttpClient([
            new NetworkException('Could not resolve host', 6, null, true),
            FakeHttpClient::ok(),
        ]);

        $this->api($http)->request('GET', ['payments']);

        self::assertCount(2, $http->requests);
    }

    public function testWriteIsRetriedOnA429BecauseNothingWasProcessed(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::error(429, 'too_many_requests', 'slow down', [], ['Retry-After' => '2']),
            FakeHttpClient::ok(['payment' => ['public_id' => 'p1']]),
        ]);

        $this->api($http)->request('POST', ['payments'], null, ['x' => 1]);

        self::assertCount(2, $http->requests);
        // Retry-After is honoured: at least the 2 s the API asked for.
        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(2.0, $this->sleeps[0]);
    }

    public function testRetryAfterLongerThanTheCapSurfacesTheErrorAtOnce(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::error(429, 'too_many_requests', 'slow down', [], ['retry-after' => '42']),
            FakeHttpClient::ok(),
        ]);

        try {
            $this->api($http, 2, 5.0)->request('GET', ['payments']);
            self::fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertCount(1, $http->requests);
            self::assertSame([], $this->sleeps);
            self::assertSame(42, $e->getRetryAfter());
        }
    }

    public function testRetryAfterAsAnHttpDateIsUnderstood(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::error(503, 'service_unavailable', 'down', [], ['Retry-After' => gmdate('D, d M Y H:i:s', time() + 3) . ' GMT']),
            FakeHttpClient::ok(),
        ]);

        $this->api($http)->request('GET', ['ping']);

        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(1.0, $this->sleeps[0]);
        self::assertLessThanOrEqual(5.0, $this->sleeps[0]);
    }

    public function testBackoffIsExponentialJitteredAndCapped(): void
    {
        for ($run = 0; $run < 25; ++$run) {
            $http = new FakeHttpClient([
                self::unavailable(), self::unavailable(), self::unavailable(), self::unavailable(), self::unavailable(),
                FakeHttpClient::ok(),
            ]);

            $this->api($http, 5, 2.0)->request('GET', ['ping']);

            self::assertCount(5, $this->sleeps);
            // attempt n waits between 50% and 100% of min(cap, 0.3 * 2^n)
            foreach ([0.3, 0.6, 1.2, 2.0, 2.0] as $i => $ceiling) {
                self::assertGreaterThanOrEqual($ceiling / 2, $this->sleeps[$i]);
                self::assertLessThanOrEqual($ceiling, $this->sleeps[$i]);
            }
        }
    }

    public function testJitterActuallyVaries(): void
    {
        $seen = [];
        for ($run = 0; $run < 20; ++$run) {
            $http = new FakeHttpClient([self::unavailable(), FakeHttpClient::ok()]);
            $this->api($http)->request('GET', ['ping']);
            $seen[(string) $this->sleeps[0]] = true;
        }

        self::assertGreaterThan(1, \count($seen));
    }
}
