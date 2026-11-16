<?php

declare(strict_types=1);

namespace PayHub\Tests;

use PayHub\Client;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\PayHubException;
use PayHub\Http\CurlClient;
use PayHub\Http\Request;
use PayHub\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private const KEY = 'pk_live_PUBLICPART:sk_live_SUPERSECRETVALUE';

    private function client(FakeHttpClient $http): Client
    {
        return new Client(apiKey: self::KEY, country: 'bf', maxRetries: 0, httpClient: $http);
    }

    // ---------------------------------------------------------------- key leaks

    /**
     * Everything a dumper built on the `(array)` cast can reach (Symfony
     * VarDumper behind `dd()` / `dump()`, debuggers, error pages): private
     * properties included, and the variables bound to closures.
     *
     * @param array<int, true> $seen
     */
    private static function reachable(mixed $value, array &$seen = []): string
    {
        if (\is_object($value)) {
            if (isset($seen[spl_object_id($value)])) {
                return '';
            }
            $seen[spl_object_id($value)] = true;

            $out = $value::class . ' ';
            if ($value instanceof \Closure) {
                $out .= self::reachable((new \ReflectionFunction($value))->getStaticVariables(), $seen);
            }

            return $out . self::reachable((array) $value, $seen);
        }

        if (\is_array($value)) {
            $out = '';
            foreach ($value as $key => $item) {
                $out .= $key . ' ' . self::reachable($item, $seen) . ' ';
            }

            return $out;
        }

        return \is_scalar($value) ? (string) $value : '';
    }

    public function testDumpingTheClientNeverRevealsTheApiKey(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok()]);
        $client = $this->client($http);
        $client->ping();

        ob_start();
        var_dump($client);
        $dump = (string) ob_get_clean();

        $all = $dump
            . print_r($client, true)
            . print_r($client->payments, true)
            . print_r($client->paymentLinks, true)
            . json_encode($client);

        self::assertStringNotContainsString('SUPERSECRETVALUE', $all);
        self::assertStringNotContainsString('PUBLICPART', $all);
        self::assertStringContainsString('[REDACTED]', $all);
    }

    public function testTheKeyIsNotAPropertyOfAnySdkObject(): void
    {
        // With the real transport (the fake one keeps the requests it was handed).
        $client = new Client(apiKey: self::KEY, country: 'bf');

        $reachable = self::reachable($client) . var_export($client, true);

        self::assertStringContainsString('PayHub\\ApiClient', $reachable);
        self::assertStringContainsString('hub.barkapay.com', $reachable);
        self::assertStringNotContainsString('SUPERSECRETVALUE', $reachable);
        self::assertStringNotContainsString('PUBLICPART', $reachable);
    }

    public function testACloneOfTheClientKeepsWorking(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok()]);
        $clone = clone $this->client($http);

        $clone->ping();

        self::assertSame('Bearer ' . self::KEY, $http->last()->headers['Authorization']);
    }

    public function testSerialisingTheClientStillRoundTripsAsInVersion1(): void
    {
        // Explicitly asking for a serialized client carries the key along —
        // that payload must be protected like the key itself (see SECURITY.md).
        $client = new Client(apiKey: self::KEY, country: 'bf', maxRetries: 0);

        $restored = unserialize(serialize($client));

        self::assertInstanceOf(Client::class, $restored);
        $dump = print_r($restored, true) . var_export($restored, true) . self::reachable($restored);
        self::assertStringNotContainsString('SUPERSECRETVALUE', $dump);

        // The restored client is fully functional: it fails on the country
        // check, i.e. after the credentials were read back.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Invalid country code');
        $restored->ping('not-a-country');
    }

    public function testDumpingARequestMasksCredentials(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::ok()]);
        $this->client($http)->ping();

        $request = $http->lastRequest;
        self::assertNotNull($request);

        $dump = print_r($request, true);
        self::assertStringNotContainsString('SUPERSECRETVALUE', $dump);
        self::assertStringContainsString('[REDACTED]', $dump);
        self::assertSame('[REDACTED]', $request->redactedHeaders()['Authorization']);
        self::assertSame('application/json', $request->redactedHeaders()['Accept']);
        // The transport still gets the real header.
        self::assertSame('Bearer ' . self::KEY, $request->headers['Authorization']);
    }

    public function testRedactedHeadersCoverEveryCredentialHeaderWhateverItsCase(): void
    {
        $request = new Request('GET', 'https://example.test', [
            'authorization' => 'Bearer a',
            'X-API-KEY' => 'b',
            'Proxy-Authorization' => 'c',
            'X-Request-Id' => 'rid',
        ]);

        self::assertSame(
            ['authorization' => '[REDACTED]', 'X-API-KEY' => '[REDACTED]', 'Proxy-Authorization' => '[REDACTED]', 'X-Request-Id' => 'rid'],
            $request->redactedHeaders(),
        );
    }

    public function testErrorsRaisedByTheSdkNeverCarryTheApiKey(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::error(401, 'incorrect_credentials', 'Invalid API credentials.'),
            FakeHttpClient::error(422, 'validation_error', 'bad', ['errors' => ['operator' => ['required']]]),
        ]);
        $client = $this->client($http);

        foreach ([fn () => $client->me(), fn () => $client->payments->create([])] as $call) {
            try {
                $call();
                self::fail('Expected an exception.');
            } catch (PayHubException $e) {
                $text = $e->getMessage() . $e->getTraceAsString() . print_r($e, true);
                self::assertStringNotContainsString('SUPERSECRETVALUE', $text);
            }
        }
    }

    public function testStackTracesRedactCredentialsEvenWhenArgumentsAreCollected(): void
    {
        if (PHP_VERSION_ID < 80200) {
            self::markTestSkipped('#[SensitiveParameter] only exists from PHP 8.2.');
        }

        $previous = ini_set('zend.exception_ignore_args', '0');

        try {
            try {
                // Fails inside the constructor, with the key among its arguments.
                new Client(apiKey: self::KEY, keyId: 'kid', secret: 'OTHERSECRETVALUE', baseUrl: 'ftp://nope');
                self::fail('Expected ConfigurationException.');
            } catch (ConfigurationException $e) {
                $trace = $e->getTraceAsString() . print_r($e->getTrace(), true);
                self::assertStringContainsString('SensitiveParameterValue', $trace);
                self::assertStringNotContainsString('SUPERSECRETVALUE', $trace);
                self::assertStringNotContainsString('OTHERSECRETVALUE', $trace);
            }

            try {
                \PayHub\Webhook::parse('{}', 'garbage', 'whsec_SUPERSECRETVALUE');
                self::fail('Expected SignatureVerificationException.');
            } catch (\PayHub\Exception\SignatureVerificationException $e) {
                self::assertStringNotContainsString('SUPERSECRETVALUE', $e->getTraceAsString() . print_r($e->getTrace(), true));
            }
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    public function testAMalformedKeyIsRefusedWithoutBeingEchoed(): void
    {
        foreach (["pk:sk\r\nX-Injected: 1", "pk:sk\nfoo", 'pk: sk', "pk\x00:sk", 'pk:sécret'] as $key) {
            try {
                new Client(apiKey: $key, country: 'bf');
                self::fail('Expected ConfigurationException.');
            } catch (ConfigurationException $e) {
                self::assertStringNotContainsString('sk', $e->getMessage());
                self::assertStringNotContainsString('Injected', $e->getMessage());
            }
        }
    }

    // --------------------------------------------------------------- base URL

    public function testPlainHttpToTheBarkapayHostsIsRefused(): void
    {
        foreach ([
            'http://hub.barkapay.com',
            'HTTP://Hub.BarkaPay.com/',
            'http://barkapay.com',
            'http://hub.barkapay.com:80',
            'http://hub.barkapay.com.',
            'http://hub.barkapay.com.:80/',
        ] as $url) {
            try {
                new Client(apiKey: 'k:s', baseUrl: $url);
                self::fail('Expected ConfigurationException for ' . $url);
            } catch (ConfigurationException $e) {
                self::assertStringContainsString('HTTPS', $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBaseUrls(): iterable
    {
        yield 'empty' => [''];
        yield 'no scheme' => ['hub.barkapay.com'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://hub.barkapay.com'];
        yield 'credentials' => ['https://user:pass@hub.barkapay.com'];
        yield 'query string' => ['https://hub.barkapay.com?x=1'];
        yield 'fragment' => ['https://hub.barkapay.com/#x'];
        yield 'no host' => ['https://'];
    }

    #[DataProvider('invalidBaseUrls')]
    public function testInvalidBaseUrlsAreRefused(string $url): void
    {
        $this->expectException(ConfigurationException::class);
        new Client(apiKey: 'k:s', baseUrl: $url);
    }

    public function testACustomBaseUrlIsUsedAsIs(): void
    {
        // A local mock server over plain HTTP stays possible.
        $http = new FakeHttpClient([FakeHttpClient::ok(), FakeHttpClient::ok()]);

        (new Client(apiKey: 'k:s', country: 'bf', baseUrl: 'http://127.0.0.1:8080/mock/', httpClient: $http))->ping();
        self::assertSame('http://127.0.0.1:8080/mock/bf/v1/ping', $http->last()->url);

        (new Client(apiKey: 'k:s', country: 'bf', baseUrl: 'https://proxy.example.test', httpClient: $http))->ping();
        self::assertSame('https://proxy.example.test/bf/v1/ping', $http->last()->url);
    }

    // --------------------------------------------------------- path injection

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'dot' => ['.'];
        yield 'dot dot' => ['..'];
        yield 'newline' => ["abc\ndef"];
        yield 'nul byte' => ["abc\x00"];
        yield 'too long' => [str_repeat('a', 256)];
    }

    #[DataProvider('hostileIdentifiers')]
    public function testHostileIdentifiersNeverReachTheNetwork(string $id): void
    {
        $http = new FakeHttpClient([]);
        $client = $this->client($http);

        $calls = [
            fn () => $client->payments->get($id),
            fn () => $client->payments->confirmOtp($id, '123456'),
            fn () => $client->payments->resendOtp($id),
            fn () => $client->transfers->get($id),
            fn () => $client->paymentLinks->get($id),
            fn () => $client->paymentLinks->update($id, ['title' => 'x']),
            fn () => $client->paymentLinks->cancel($id),
            fn () => $client->paymentLinks->pause($id),
            fn () => $client->paymentLinks->resume($id),
            fn () => $client->paymentLinks->listPayments($id),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('Expected ConfigurationException.');
            } catch (ConfigurationException) {
                // expected
            }
        }

        self::assertCount(0, $http->requests);
    }

    public function testIdentifiersCannotEscapeTheirPathSegment(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(['payment_link' => []]),
            FakeHttpClient::ok(['payments' => [['public_id' => 'p']]]),
            FakeHttpClient::ok(['payment_link' => []]),
        ]);
        $client = $this->client($http);

        $client->paymentLinks->cancel('../../transfers');
        self::assertSame('https://hub.barkapay.com/bf/v1/payments/links/..%2F..%2Ftransfers', $http->last()->url);

        $client->payments->get('x?per_page=200#frag');
        self::assertSame('https://hub.barkapay.com/bf/v1/payments/x%3Fper_page%3D200%23frag', $http->last()->url);

        $client->paymentLinks->pause('%2e%2e');
        self::assertSame('https://hub.barkapay.com/bf/v1/payments/links/%252e%252e/pause', $http->last()->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileCountries(): iterable
    {
        yield 'traversal' => ['../admin'];
        yield 'dots' => ['..'];
        yield 'slash' => ['bf/v1/transfers'];
        yield 'iso3' => ['bfa'];
        yield 'one letter' => ['b'];
        yield 'encoded' => ['%62%66'];
        yield 'host change' => ['@evil.test'];
        yield 'inner newline' => ["b\nf"];
        yield 'digits' => ['b1'];
    }

    #[DataProvider('hostileCountries')]
    public function testHostileCountryCodesNeverReachTheNetwork(string $country): void
    {
        $http = new FakeHttpClient([]);

        foreach ([
            fn () => $this->client($http)->ping($country),
            fn () => (new Client(apiKey: 'k:s', country: $country, httpClient: $http))->balance->get(),
        ] as $call) {
            try {
                $call();
                self::fail('Expected ConfigurationException.');
            } catch (ConfigurationException) {
                // expected
            }
        }

        self::assertCount(0, $http->requests);
    }

    // ------------------------------------------------------------- transport

    public function testCurlTransportVerifiesTlsAndNeverFollowsRedirects(): void
    {
        $options = (new CurlClient())->options(
            new Request('POST', 'https://hub.barkapay.com/bf/v1/payments', ['Authorization' => 'Bearer k:s'], '{}'),
        );

        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $options[CURLOPT_MAXREDIRS]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
        self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
        self::assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('{}', $options[CURLOPT_POSTFIELDS]);
        self::assertIsArray($options[CURLOPT_HTTPHEADER]);
        self::assertContains('Authorization: Bearer k:s', $options[CURLOPT_HTTPHEADER]);
        self::assertArrayNotHasKey(CURLOPT_CAINFO, $options);
    }

    public function testCurlTransportHasSaneDefaultTimeouts(): void
    {
        $request = new Request('GET', 'https://hub.barkapay.com/bf/v1/ping');

        $defaults = (new CurlClient())->options($request);
        self::assertSame(30000, $defaults[CURLOPT_TIMEOUT_MS]);
        self::assertSame(10000, $defaults[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertArrayNotHasKey(CURLOPT_POSTFIELDS, $defaults);

        $custom = (new CurlClient(4.5, 1.25))->options($request);
        self::assertSame(4500, $custom[CURLOPT_TIMEOUT_MS]);
        self::assertSame(1250, $custom[CURLOPT_CONNECTTIMEOUT_MS]);

        // 0 means "no timeout" for curl: it is never passed through.
        $zero = (new CurlClient(0.0, -1.0))->options($request);
        self::assertSame(30000, $zero[CURLOPT_TIMEOUT_MS]);
        self::assertSame(10000, $zero[CURLOPT_CONNECTTIMEOUT_MS]);

        // A connect timeout can never exceed the total timeout.
        $capped = (new CurlClient(2.0, 10.0))->options($request);
        self::assertSame(2000, $capped[CURLOPT_CONNECTTIMEOUT_MS]);
    }

    public function testBodylessWritesStillAnnounceAnEmptyBody(): void
    {
        $options = (new CurlClient())->options(new Request('POST', 'https://hub.barkapay.com/bf/v1/payments/p/resend-otp'));

        self::assertSame('', $options[CURLOPT_POSTFIELDS]);
    }

    public function testACaBundleCanBeProvidedButMustExist(): void
    {
        $bundle = tempnam(sys_get_temp_dir(), 'ca');
        self::assertIsString($bundle);

        try {
            $options = (new CurlClient(caBundle: $bundle))->options(new Request('GET', 'https://hub.barkapay.com/bf/v1/ping'));
            self::assertSame($bundle, $options[CURLOPT_CAINFO]);
            self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        } finally {
            unlink($bundle);
        }

        $this->expectException(ConfigurationException::class);
        new CurlClient(caBundle: '/nonexistent/cacert.pem');
    }
}
