<?php

declare(strict_types=1);

namespace BarkaPay\Tests;

use BarkaPay\Client;
use BarkaPay\Enum\PaymentStatus;
use BarkaPay\Enum\ReasonCode;
use BarkaPay\Enum\TransferStatus;
use BarkaPay\Exception\ApiException;
use BarkaPay\Exception\AuthenticationException;
use BarkaPay\Exception\AuthorizationException;
use BarkaPay\Exception\BarkaPayException;
use BarkaPay\Exception\ConflictException;
use BarkaPay\Exception\InsufficientBalanceException;
use BarkaPay\Exception\NetworkException;
use BarkaPay\Exception\NotFoundException;
use BarkaPay\Exception\PaymentFailedException;
use BarkaPay\Exception\RateLimitException;
use BarkaPay\Exception\ServerException;
use BarkaPay\Exception\ServiceUnavailableException;
use BarkaPay\Exception\TransferFailedException;
use BarkaPay\Exception\ValidationException;
use BarkaPay\Http\Response;
use BarkaPay\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMappingTest extends TestCase
{
    private function clientReturning(Response|\Throwable $response): Client
    {
        return new Client(
            apiKey: 'k:s',
            country: 'bf',
            maxRetries: 0,
            httpClient: new FakeHttpClient([$response]),
        );
    }

    public function testValidationErrorMapsAndExposesFields(): void
    {
        $client = $this->clientReturning(FakeHttpClient::json(422, [
            'status' => 'failed',
            'code' => 'validation_error',
            'message' => 'The `operator` field is required.',
            'request_id' => 'rid-1',
            'errors' => ['operator' => ['The `operator` field is required.']],
        ]));

        try {
            $client->payments->create(['x' => 1]);
            self::fail('Expected ValidationException.');
        } catch (ValidationException $e) {
            self::assertSame('validation_error', $e->getErrorCode());
            self::assertSame(422, $e->getHttpStatus());
            self::assertSame('rid-1', $e->getRequestId());
            self::assertSame(['operator' => ['The `operator` field is required.']], $e->getErrors());
            self::assertSame('The `operator` field is required.', $e->getMessage());
            self::assertSame([], $e->getData());
            self::assertNull($e->getPayment());
            self::assertNull($e->getRetryAfter());
            self::assertIsString($e->getResponseBody());
        }
    }

    /**
     * Every documented (status, envelope code) pair, as the gateways emit them.
     *
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function documentedErrors(): iterable
    {
        yield '400 malformed JSON' => [400, 'invalid_input', ValidationException::class];
        yield '400 insufficient balance' => [400, 'insufficient_balance', InsufficientBalanceException::class];
        yield '401 no key' => [401, 'invalid_api_key', AuthenticationException::class];
        yield '401 bad key' => [401, 'incorrect_credentials', AuthenticationException::class];
        yield '401 unauthenticated' => [401, 'unauthenticated', AuthenticationException::class];
        yield '403 scope' => [403, 'unauthorized', AuthorizationException::class];
        yield '403 ip' => [403, 'ip_not_allowed', AuthorizationException::class];
        yield '410 closed' => [410, 'merchant_closed', AuthorizationException::class];
        yield '451 suspended' => [451, 'merchant_suspended', AuthorizationException::class];
        yield '404' => [404, 'resource_not_found', NotFoundException::class];
        // The gateways answer a wrong HTTP method with 405 + code `internal_error`.
        yield '405' => [405, 'internal_error', ApiException::class];
        yield '409 duplicate' => [409, 'duplicate_transaction', ConflictException::class];
        yield '409 exists' => [409, 'resource_already_exists', ConflictException::class];
        yield '422 validation' => [422, 'validation_error', ValidationException::class];
        yield '422 invalid input' => [422, 'invalid_input', ValidationException::class];
        yield '422 unknown code' => [422, 'a_code_from_the_future', ValidationException::class];
        yield '424 payment' => [424, 'payment_failed', PaymentFailedException::class];
        yield '424 transfer' => [424, 'transfer_failed', TransferFailedException::class];
        yield '429' => [429, 'too_many_requests', RateLimitException::class];
        yield '500 internal' => [500, 'internal_error', ServerException::class];
        yield '500 external' => [500, 'external_service_error', ServerException::class];
        yield '503 service' => [503, 'service_unavailable', ServiceUnavailableException::class];
        yield '503 operator' => [503, 'operator_unavailable', ServiceUnavailableException::class];
        yield 'unknown code, unknown 4xx' => [418, 'brand_new_code', ApiException::class];
        yield 'unknown code, 5xx' => [599, 'brand_new_code', ServerException::class];
    }

    /**
     * @param class-string<ApiException> $expected
     */
    #[DataProvider('documentedErrors')]
    public function testEveryDocumentedErrorMapsToItsException(int $status, string $code, string $expected): void
    {
        $client = $this->clientReturning(FakeHttpClient::error($status, $code, 'Human message.'));

        try {
            $client->balance->get();
            self::fail('Expected ' . $expected);
        } catch (ApiException $e) {
            self::assertSame($expected, $e::class);
            self::assertInstanceOf(BarkaPayException::class, $e);
            self::assertSame($status, $e->getHttpStatus());
            self::assertSame($code, $e->getErrorCode());
            self::assertSame('Human message.', $e->getMessage());
            self::assertSame('rid-from-envelope', $e->getRequestId());
        }
    }

    public function testFailedPaymentCarriesTheRecordedAttempt(): void
    {
        $client = $this->clientReturning(FakeHttpClient::error(424, 'payment_failed', 'Payer has insufficient funds.', [
            'data' => ['payment' => [
                'public_id' => '0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f',
                'amount' => 10000,
                'phone_number' => '50123456789',
                'country' => 'BFA',
                'operator' => 'ORANGE',
                'order_id' => 'ORDER-1',
                'fees' => '250.00',
                'currency' => 'XOF',
                'status' => 'FAILED',
                'reason' => 'Payer has insufficient funds.',
                'reason_code' => 'payer_insufficient_funds',
                'provider_reason' => 'Solde insuffisant',
            ]],
        ]));

        try {
            $client->payments->create(['operator' => 'ORANGE']);
            self::fail('Expected PaymentFailedException.');
        } catch (PaymentFailedException $e) {
            self::assertSame(424, $e->getHttpStatus());
            self::assertSame('payment_failed', $e->getErrorCode());
            self::assertNull($e->getTransfer());

            $payment = $e->getPayment();
            self::assertNotNull($payment);
            self::assertSame('0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f', $payment->publicId);
            self::assertSame(PaymentStatus::Failed, $payment->status);
            self::assertTrue($payment->isFinal());
            self::assertFalse($payment->isSuccessful());
            self::assertSame('payer_insufficient_funds', $payment->reasonCode);
            self::assertSame(ReasonCode::PayerInsufficientFunds, ReasonCode::tryFrom((string) $payment->reasonCode));
            self::assertSame('Solde insuffisant', $payment->providerReason);
            self::assertSame('ORDER-1', $payment->orderId);
        }
    }

    public function testFailedTransferCarriesTheRecordedAttempt(): void
    {
        $client = $this->clientReturning(FakeHttpClient::error(424, 'transfer_failed', 'Recipient account is invalid.', [
            'data' => ['transfer' => [
                'public_id' => 't-1', 'amount' => 50000, 'status' => 'FAILED', 'fees' => 500, 'currency' => 'XOF',
                'app_label' => 'ORION0', 'reason_code' => 'recipient_invalid', 'provider_reason' => null,
            ]],
        ]));

        try {
            $client->transfers->create(['operator' => 'ORANGE']);
            self::fail('Expected TransferFailedException.');
        } catch (TransferFailedException $e) {
            self::assertNull($e->getPayment());
            $transfer = $e->getTransfer();
            self::assertNotNull($transfer);
            self::assertSame(TransferStatus::Failed, $transfer->status);
            self::assertSame('recipient_invalid', $transfer->reasonCode);
            self::assertSame('500', $transfer->fees);
        }
    }

    public function testOperatorUnavailableExposesRetryAfterAndTheAttempt(): void
    {
        $client = $this->clientReturning(FakeHttpClient::error(
            503,
            'operator_unavailable',
            'Operator service unavailable.',
            ['data' => ['payment' => ['public_id' => 'p-1', 'status' => 'FAILED', 'reason_code' => 'operator_unavailable']]],
            ['Retry-After' => '30', 'X-RateLimit-Remaining' => '12'],
        ));

        try {
            $client->payments->create(['operator' => 'ORANGE']);
            self::fail('Expected ServiceUnavailableException.');
        } catch (ServiceUnavailableException $e) {
            self::assertSame('operator_unavailable', $e->getErrorCode());
            self::assertSame(30, $e->getRetryAfter());
            self::assertSame('12', $e->getResponseHeader('x-ratelimit-remaining'));
            self::assertSame('30', $e->getResponseHeaders()['retry-after']);
            self::assertSame('p-1', $e->getPayment()?->publicId);
        }
    }

    public function testRateLimitErrorExposesRetryAfter(): void
    {
        $client = $this->clientReturning(FakeHttpClient::error(
            429,
            'too_many_requests',
            'Too many requests. Slow down and retry after the indicated delay.',
            [],
            ['retry-after' => '17', 'x-ratelimit-limit' => '30', 'x-ratelimit-remaining' => '0'],
        ));

        try {
            $client->balance->get();
            self::fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertSame(17, $e->getRetryAfter());
            $limit = $client->lastRateLimit();
            self::assertNotNull($limit);
            self::assertSame(30, $limit->limit);
            self::assertSame(0, $limit->remaining);
            self::assertSame(17, $limit->retryAfter);
        }
    }

    public function testRequestIdFallsBackToTheResponseHeaderThenToTheOneWeSent(): void
    {
        $fromHeader = $this->clientReturning(new Response(502, '<html>Bad gateway</html>', ['X-Request-Id' => 'rid-header']));
        try {
            $fromHeader->ping();
            self::fail('Expected ServerException.');
        } catch (ServerException $e) {
            self::assertSame('rid-header', $e->getRequestId());
            self::assertSame('', $e->getErrorCode());
            self::assertSame('barkapay API error (HTTP 502).', $e->getMessage());
            self::assertSame('<html>Bad gateway</html>', $e->getResponseBody());
        }

        $http = new FakeHttpClient([new Response(500, '')]);
        $ours = new Client(apiKey: 'k:s', country: 'bf', maxRetries: 0, httpClient: $http);
        try {
            $ours->ping();
            self::fail('Expected ServerException.');
        } catch (ServerException $e) {
            self::assertSame($http->last()->headers['X-Request-Id'], $e->getRequestId());
        }
    }

    public function testARedirectIsAnErrorNeverAnEmptySuccess(): void
    {
        $client = $this->clientReturning(new Response(301, '<html>Moved</html>', ['Location' => 'https://elsewhere.test/']));

        try {
            $client->payments->create(['operator' => 'ORANGE']);
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(301, $e->getHttpStatus());
            self::assertStringContainsString('redirect', $e->getMessage());
            self::assertSame('https://elsewhere.test/', $e->getResponseHeader('Location'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnEnvelope(): iterable
    {
        yield 'html' => ['<html>captive portal</html>'];
        yield 'empty' => [''];
        yield 'json list' => ['[1,2,3]'];
        yield 'json scalar' => ['"ok"'];
        yield 'truncated json' => ['{"status":"success","data":{'];
        // JSON objects that are not the success envelope.
        yield 'empty object' => ['{}'];
        yield 'foreign object' => ['{"foo":"bar"}'];
        yield 'unknown status' => ['{"status":"pending","data":{"payment":{"public_id":"p"}}}'];
        yield 'upper-case status' => ['{"status":"SUCCESS","data":{"payment":{"public_id":"p"}}}'];
        yield 'no status' => ['{"message":"queued","data":{"payment":{"public_id":"p"}}}'];
    }

    #[DataProvider('notAnEnvelope')]
    public function testA2xxThatIsNotTheJsonEnvelopeIsAnError(string $body): void
    {
        $client = $this->clientReturning(new Response(200, $body));

        try {
            $client->payments->create(['operator' => 'ORANGE']);
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(200, $e->getHttpStatus());
            self::assertSame(ApiException::class, $e::class);
            self::assertNotNull($e->getRequestId());
            self::assertSame($body, $e->getResponseBody());
        }
    }

    public function testA204OrA202WithoutTheEnvelopeIsAnError(): void
    {
        foreach ([new Response(204, ''), new Response(202, '{"message":"queued"}')] as $response) {
            try {
                $this->clientReturning($response)->transfers->create(['operator' => 'ORANGE']);
                self::fail('Expected ApiException.');
            } catch (ApiException $e) {
                self::assertSame($response->statusCode, $e->getHttpStatus());
            }
        }
    }

    /**
     * A success envelope that does not carry the resource the call is about
     * must never be turned into an empty object — least of all an empty list,
     * which the caller would read as "this operation does not exist".
     *
     * @return iterable<string, array{\Closure(Client): mixed, array<string, mixed>, string}>
     */
    public static function successWithoutTheResource(): iterable
    {
        yield 'create payment' => [fn (Client $c) => $c->payments->create(['operator' => 'ORANGE']), [], 'data.payment'];
        yield 'create payment, wrong key' => [fn (Client $c) => $c->payments->create([]), ['transfer' => ['public_id' => 't']], 'data.payment'];
        yield 'create payment, scalar' => [fn (Client $c) => $c->payments->create([]), ['payment' => 'p-1'], 'data.payment'];
        yield 'confirm otp' => [fn (Client $c) => $c->payments->confirmOtp('p', '123456'), [], 'data.payment'];
        yield 'list payments' => [fn (Client $c) => $c->payments->list(['order_id' => 'O-1']), [], 'data.payments'];
        yield 'get payment' => [fn (Client $c) => $c->payments->get('O-1'), [], 'data.payments'];
        yield 'create transfer' => [fn (Client $c) => $c->transfers->create(['operator' => 'ORANGE']), [], 'data.transfer'];
        yield 'list transfers' => [fn (Client $c) => $c->transfers->list(['order_id' => 'O-1']), [], 'data.transfers'];
        yield 'get transfer' => [fn (Client $c) => $c->transfers->get('O-1'), [], 'data.transfers'];
        yield 'create link' => [fn (Client $c) => $c->paymentLinks->create(['title' => 'x']), [], 'data.payment_link'];
        yield 'get link' => [fn (Client $c) => $c->paymentLinks->get('PL-1'), [], 'data.payment_link'];
        yield 'pause link' => [fn (Client $c) => $c->paymentLinks->pause('PL-1'), [], 'data.payment_link'];
        yield 'list links' => [fn (Client $c) => $c->paymentLinks->list(), [], 'data.payment_links'];
        yield 'link payments' => [fn (Client $c) => $c->paymentLinks->listPayments('PL-1'), ['payment_link' => []], 'data.payments'];
    }

    /**
     * @param \Closure(Client): mixed $call
     * @param array<string, mixed>    $data
     */
    #[DataProvider('successWithoutTheResource')]
    public function testASuccessEnvelopeWithoutTheExpectedResourceIsAnError(\Closure $call, array $data, string $missing): void
    {
        $client = $this->clientReturning(FakeHttpClient::ok($data));

        try {
            $call($client);
            self::fail('Expected ApiException.');
        } catch (ApiException $e) {
            self::assertSame(ApiException::class, $e::class);
            self::assertSame(200, $e->getHttpStatus());
            self::assertStringContainsString('`' . $missing . '`', $e->getMessage());
            self::assertSame('9b1c7e44-5f2a-4c8d-9a3e-1f6b0d2a7c45', $e->getRequestId());
        }
    }

    public function testAnEnvelopeWithoutDataIsAnErrorForReadsThatReturnData(): void
    {
        $noData = FakeHttpClient::json(200, ['status' => 'success', 'code' => 'success', 'message' => 'OK']);

        foreach ([
            fn (Client $c) => $c->ping(),
            fn (Client $c) => $c->me(),
            fn (Client $c) => $c->balance->get(),
            fn (Client $c) => $c->operators->info(),
            fn (Client $c) => $c->operators->availability(),
        ] as $call) {
            try {
                $call($this->clientReturning($noData));
                self::fail('Expected ApiException.');
            } catch (ApiException $e) {
                self::assertStringContainsString('`data`', $e->getMessage());
            }
        }

        // …but not for the one call that returns nothing.
        $this->clientReturning($noData)->payments->resendOtp('p');
        $this->addToAssertionCount(1);
    }

    public function testHeaderValuesGivenAsListsByACustomTransportAreTolerated(): void
    {
        // A PSR-7 adapter hands over name => list of values.
        /** @var array<string, list<string>> $psr7 */
        $psr7 = ['X-Request-Id' => ['rid-list'], 'X-RateLimit-Limit' => ['30'], 'X-RateLimit-Remaining' => ['7'], 'Vary' => ['Accept', 'Origin']];
        $ok = new Response(200, '{"status":"success","code":"success","data":{"version":"2.0.0"}}', $psr7);

        $client = $this->clientReturning($ok);
        self::assertSame('2.0.0', $client->ping()['version']);
        self::assertSame('rid-list', $client->lastRequestId());
        self::assertSame(7, $client->lastRateLimit()?->remaining);
        self::assertSame('Accept, Origin', $ok->header('vary'));

        $limited = new Response(429, '{"status":"error","code":"too_many_requests","message":"slow"}', ['Retry-After' => ['42']]);
        try {
            $this->clientReturning($limited)->payments->create(['operator' => 'ORANGE']);
            self::fail('Expected RateLimitException.');
        } catch (RateLimitException $e) {
            self::assertSame(42, $e->getRetryAfter());
            self::assertSame('42', $e->getResponseHeader('Retry-After'));
        }
    }

    public function testAnErrorEnvelopeUnderA2xxStatusIsStillAnError(): void
    {
        $client = $this->clientReturning(FakeHttpClient::json(200, [
            'status' => 'failed', 'code' => 'payment_failed', 'message' => 'refused',
        ]));

        $this->expectException(PaymentFailedException::class);
        $client->payments->create(['operator' => 'ORANGE']);
    }

    public function testHugeErrorBodiesAreTruncatedOnTheException(): void
    {
        $client = $this->clientReturning(new Response(502, str_repeat('x', 100000)));

        try {
            $client->ping();
            self::fail('Expected ServerException.');
        } catch (ServerException $e) {
            self::assertSame(16384, \strlen((string) $e->getResponseBody()));
        }
    }

    public function testNetworkErrorBubblesUp(): void
    {
        $client = $this->clientReturning(new NetworkException('connection refused', 7, null, true));

        try {
            $client->ping();
            self::fail('Expected NetworkException.');
        } catch (NetworkException $e) {
            self::assertInstanceOf(BarkaPayException::class, $e);
            self::assertTrue($e->isConnectionFailure());
            self::assertSame(7, $e->getCode());
            self::assertNotNull($e->getRequestId());
        }
    }

    public function testNetworkExceptionKeepsItsHistoricalConstructor(): void
    {
        // 1.0.0 signature: (message, code) — the request is then assumed sent.
        $e = new NetworkException('timeout', 28);

        self::assertFalse($e->isConnectionFailure());
        self::assertNull($e->getRequestId());
    }

    public function testApiExceptionKeepsItsHistoricalConstructor(): void
    {
        $e = new ApiException('msg', 'code', 400, 'rid', ['f' => ['m']]);

        self::assertSame('code', $e->getErrorCode());
        self::assertSame([], $e->getData());
        self::assertSame([], $e->getResponseHeaders());
        self::assertNull($e->getResponseBody());
        self::assertSame(
            ValidationException::class,
            ApiException::fromEnvelope(422, ['code' => 'validation_error', 'message' => 'm'])::class,
        );
    }
}
