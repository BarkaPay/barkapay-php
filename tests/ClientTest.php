<?php

declare(strict_types=1);

namespace PayHub\Tests;

use PayHub\Client;
use PayHub\DTO\Payment;
use PayHub\Enum\PaymentStatus;
use PayHub\Enum\TransferStatus;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NotFoundException;
use PayHub\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private function client(FakeHttpClient $http, string $country = 'bf'): Client
    {
        return new Client(apiKey: 'pk_live_x:sk_live_y', country: $country, maxRetries: 0, httpClient: $http);
    }

    public function testCreatePaymentBuildsSignedCountryScopedRequest(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(201, [
                'status' => 'success',
                'code' => 'resource_created',
                'data' => ['payment' => [
                    'public_id' => 'pay_1',
                    'status' => 'SUCCESSFUL',
                    'amount' => 10000,
                    'currency' => 'XOF',
                ]],
            ]),
        ]);

        $payment = $this->client($http)->payments->create([
            'operator' => 'ORANGE',
            'phone_number' => '50123456789',
            'amount' => 10000,
            'otp' => '012345',
            'order' => ['id' => 'O1'],
        ]);

        self::assertInstanceOf(Payment::class, $payment);
        self::assertSame('pay_1', $payment->publicId);
        self::assertSame(PaymentStatus::Successful, $payment->status);
        self::assertTrue($payment->isSuccessful());

        $request = $http->lastRequest;
        self::assertNotNull($request);
        self::assertSame('POST', $request->method);
        self::assertSame('https://hub.barkapay.com/bf/v1/payments', $request->url);
        self::assertSame('Bearer pk_live_x:sk_live_y', $request->headers['Authorization']);
        self::assertSame('application/json', $request->headers['Accept']);
        self::assertSame('application/json', $request->headers['Content-Type']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $request->headers['X-Request-Id'],
        );

        $body = $http->lastJson();
        self::assertIsArray($body);
        self::assertSame('ORANGE', $body['operator']);
        // The OTP is a code, not a number: its leading zero must survive.
        self::assertSame('012345', $body['otp']);
    }

    public function testUserAgentCarriesTheSdkVersion(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok()]);
        $this->client($http)->ping();

        self::assertSame(
            'PayHub-PHP/' . Client::VERSION . ' PHP/' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            $http->last()->headers['User-Agent'],
        );
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', Client::VERSION);
    }

    public function testDefaultBaseUrlIsTheHub(): void
    {
        self::assertSame('https://hub.barkapay.com', Client::DEFAULT_BASE_URL);
    }

    public function testCountryCanBeOverriddenPerCall(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok()]);
        $this->client($http)->ping('SN');

        self::assertSame('https://hub.barkapay.com/sn/v1/ping', $http->last()->url);
    }

    public function testMissingCountryIsAConfigurationError(): void
    {
        $http = new FakeHttpClient([]);
        $client = new Client(apiKey: 'k:s', httpClient: $http);

        $this->expectException(ConfigurationException::class);
        $client->ping();
    }

    public function testAccidentalBearerPrefixIsForgiven(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok()]);
        $client = new Client(apiKey: 'Bearer k:s', country: 'bf', maxRetries: 0, httpClient: $http);

        $client->ping();

        self::assertSame('Bearer k:s', $http->last()->headers['Authorization']);
    }

    public function testKeyIdAndSecretCanBePassedSeparately(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok()]);
        $client = new Client(keyId: 'pk_live_x', secret: 'sk_live_y', country: 'bf', maxRetries: 0, httpClient: $http);

        $client->ping();

        self::assertSame('Bearer pk_live_x:sk_live_y', $http->last()->headers['Authorization']);
    }

    public function testMissingCredentialsAreRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        new Client(country: 'bf');
    }

    public function testPingAndMeReturnTheDataObject(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(['version' => '2.0.0', 'documentation' => 'https://example.test']),
            FakeHttpClient::ok(['merchant' => ['public_id' => 'm1'], 'api_app' => ['scopes' => ['payments:read']]]),
        ]);
        $client = $this->client($http);

        self::assertSame('2.0.0', $client->ping()['version']);
        self::assertSame('https://hub.barkapay.com/bf/v1/ping', $http->last()->url);

        $me = $client->me();
        self::assertSame(['public_id' => 'm1'], $me['merchant']);
        self::assertSame('https://hub.barkapay.com/bf/v1/me', $http->last()->url);
        self::assertSame('GET', $http->last()->method);
        self::assertNull($http->last()->body);
    }

    public function testListHydratesCollectionPaginationAndQueryString(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(
                ['payments' => [
                    ['public_id' => 'p1', 'status' => 'SUCCESSFUL'],
                    ['public_id' => 'p2', 'status' => 'FAILED'],
                ]],
                200,
                ['current_page' => 2, 'last_page' => 3, 'per_page' => 2, 'total' => 6],
            ),
        ]);

        $list = $this->client($http)->payments->list([
            'status' => 'SUCCESSFUL',
            'amount' => 500,
            'amount_operator' => '>=',
            'from_date' => null,
            'page' => 2,
            'per_page' => 2,
        ]);

        self::assertCount(2, $list);
        $meta = $list->meta;
        self::assertNotNull($meta);
        self::assertSame(6, $meta->total);
        self::assertSame(2, $meta->currentPage);
        self::assertTrue($meta->hasMore());
        self::assertNull($list->paymentLink);

        $ids = [];
        foreach ($list as $payment) {
            $ids[] = $payment->publicId;
        }
        self::assertSame(['p1', 'p2'], $ids);
        self::assertSame(
            'https://hub.barkapay.com/bf/v1/payments?status=SUCCESSFUL&amount=500&amount_operator=%3E%3D&page=2&per_page=2',
            $http->last()->url,
        );
    }

    public function testGetPaymentReturnsTheFirstMatch(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(
                ['payments' => [['public_id' => 'p1', 'status' => 'EXPIRED', 'order_id' => 'ORDER 1&A=x']]],
                200,
                ['count' => 1, 'lookup_field' => 'order_id'],
            ),
        ]);

        $payment = $this->client($http)->payments->get('ORDER 1&A=x');

        self::assertSame('p1', $payment->publicId);
        self::assertSame(PaymentStatus::Expired, $payment->status);
        self::assertTrue($payment->isFinal());
        // The identifier is percent-encoded: it stays ONE path segment.
        self::assertSame('https://hub.barkapay.com/bf/v1/payments/ORDER%201%26A%3Dx', $http->last()->url);
    }

    public function testGetThrowsNotFoundWhenTheApiReturnsNoRow(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(['payments' => []])]);

        $this->expectException(NotFoundException::class);
        $this->client($http)->payments->get('nope');
    }

    public function testConfirmAndResendOtp(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(['payment' => ['public_id' => 'p1', 'status' => 'SUCCESSFUL']]),
            FakeHttpClient::json(200, ['status' => 'success', 'code' => 'success', 'message' => 'OTP resent.']),
        ]);
        $client = $this->client($http);

        $payment = $client->payments->confirmOtp('p1', '012345');
        self::assertSame(PaymentStatus::Successful, $payment->status);
        self::assertSame('https://hub.barkapay.com/bf/v1/payments/p1/confirm-otp', $http->last()->url);
        self::assertSame(['otp' => '012345'], $http->lastJson());

        $client->payments->resendOtp('p1');
        self::assertSame('POST', $http->last()->method);
        self::assertSame('https://hub.barkapay.com/bf/v1/payments/p1/resend-otp', $http->last()->url);
        self::assertNull($http->last()->body);
    }

    public function testCreateIsAlwaysADirectPaymentAndSendsTheParametersUntouched(): void
    {
        // The API has no `channel` switch on POST /payments (hosted checkout is
        // the payment-links resource): the SDK neither interprets nor reroutes
        // anything — what the caller passes is what is sent.
        $http = new FakeHttpClient([FakeHttpClient::ok(['payment' => ['public_id' => 'p1', 'status' => 'SUCCESSFUL']])]);

        $params = ['operator' => 'ORANGE', 'phone_number' => '74123456', 'amount' => 100, 'order' => ['id' => 'O1'], 'channel' => 'hosted'];
        $payment = $this->client($http)->payments->create($params);

        self::assertCount(1, $http->requests);
        self::assertSame('https://hub.barkapay.com/bf/v1/payments', $http->last()->url);
        self::assertSame($params, $http->lastJson());
        self::assertSame('p1', $payment->publicId);
    }

    public function testTransfersCreateListAndGet(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(['transfer' => [
                'public_id' => 't1', 'status' => 'UNCERTAIN', 'amount' => 50000, 'fees' => 500, 'app_label' => 'ORION0',
            ]], 201),
            FakeHttpClient::ok(
                ['transfers' => [['public_id' => 't1', 'status' => 'REFUNDED']]],
                200,
                ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 1],
            ),
            FakeHttpClient::ok(['transfers' => [['public_id' => 't1', 'status' => 'SUCCESSFUL', 'provider_reference' => 'REF9']]]),
        ]);
        $client = $this->client($http);

        $created = $client->transfers->create([
            'operator' => 'ORANGE',
            'phone_number' => '50123456789',
            'amount' => 50000,
            'order' => ['id' => 'X1', 'unique' => true],
        ]);
        self::assertSame('https://hub.barkapay.com/bf/v1/transfers', $http->last()->url);
        self::assertSame('POST', $http->last()->method);
        self::assertSame(TransferStatus::Uncertain, $created->status);
        self::assertFalse($created->isFinal());
        self::assertSame('500', $created->fees);
        self::assertSame('ORION0', $created->appLabel);

        $list = $client->transfers->list(['status' => 'REFUNDED']);
        self::assertCount(1, $list);
        self::assertFalse($list->meta?->hasMore());
        self::assertSame('https://hub.barkapay.com/bf/v1/transfers?status=REFUNDED', $http->last()->url);

        $one = $client->transfers->get('t1');
        self::assertSame('REF9', $one->providerReference);
        self::assertTrue($one->isSuccessful());
        self::assertSame('https://hub.barkapay.com/bf/v1/transfers/t1', $http->last()->url);
    }

    public function testBalanceGet(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok([
                'country' => 'BF', 'currency' => 'XOF',
                'available' => '1500.00', 'total' => '1500.00', 'holds' => '0.00',
            ]),
        ]);

        $balance = $this->client($http)->balance->get();

        self::assertSame('1500.00', $balance->available);
        self::assertSame('XOF', $balance->currency);
        self::assertNull($balance->balances);
        self::assertSame('https://hub.barkapay.com/bf/v1/balance', $http->last()->url);
    }

    public function testOperatorsEndpoints(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(['operators' => ['BFA' => ['ORANGE' => ['otp_required' => true]]], 'supported_countries' => ['BFA']]),
            FakeHttpClient::ok(['operational' => true, 'services' => []]),
        ]);
        $client = $this->client($http);

        self::assertSame(['BFA'], $client->operators->info()['supported_countries']);
        self::assertSame('https://hub.barkapay.com/bf/v1/operators/info', $http->last()->url);

        self::assertTrue($client->operators->availability()['operational']);
        self::assertSame('https://hub.barkapay.com/bf/v1/operators/availability', $http->last()->url);
    }

    public function testRateLimitAndRequestIdOfTheLastResponseAreExposed(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(
                200,
                ['status' => 'success', 'code' => 'success', 'data' => []],
                ['X-RateLimit-Limit' => '30', 'x-ratelimit-remaining' => '29', 'X-Request-Id' => 'rid-header'],
            ),
        ]);
        // Nothing to report before the first call.
        $fresh = $this->client(new FakeHttpClient([]));
        self::assertNull($fresh->lastRateLimit());
        self::assertNull($fresh->lastRequestId());

        $client = $this->client($http);
        $client->ping();

        $limit = $client->lastRateLimit();
        self::assertNotNull($limit);
        self::assertSame(30, $limit->limit);
        self::assertSame(29, $limit->remaining);
        self::assertNull($limit->retryAfter);
        self::assertSame('rid-header', $client->lastRequestId());
    }

    public function testEmptyBodyIsSentAsAJsonObject(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(['payment_link' => ['public_id' => 'PL-1']])]);

        $this->client($http)->paymentLinks->update('PL-1', []);

        self::assertSame('{}', $http->last()->body);
    }

    public function testFloatAmountsAreEncodedInShortestFormWhateverThePhpPrecision(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok(['payment' => ['public_id' => 'p']])]);

        $previous = ini_set('serialize_precision', '17');
        try {
            $this->client($http)->payments->create(['amount' => 1234.56, 'order' => ['id' => 'O']]);
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }

        self::assertStringContainsString('"amount":1234.56,', (string) $http->last()->body);
        self::assertSame((string) $previous, ini_get('serialize_precision'));
    }

    public function testUnencodableParametersAreAConfigurationError(): void
    {
        $http = new FakeHttpClient([]);

        $this->expectException(ConfigurationException::class);
        $this->client($http)->payments->create(['operator' => "\xB1\x31"]);
    }
}
