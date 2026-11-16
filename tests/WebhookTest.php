<?php

declare(strict_types=1);

namespace PayHub\Tests;

use PayHub\Exception\SignatureVerificationException;
use PayHub\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';

    /** A delivery body exactly as the webhook dispatcher serialises it. */
    private const PAYLOAD = '{"public_id":"0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f","amount":10000,"phone_number":"50123456789",'
        . '"country":"BFA","status":"SUCCESSFUL","order_id":"ORDER-1","provider_transaction_id":"MP1","order_data":null,'
        . '"reason":null,"app_label":"ORION0","reason_code":null,"provider_reason":null,"link_public_id":null,'
        . '"type":"payment","fees":"250.00",'
        . '"event_id":"dlv:0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f:payment.succeeded:whk_1","timestamp":"2026-11-16T12:00:00+00:00"}';

    private static function signature(string $payload, int $timestamp, string $secret = self::SECRET): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    }

    private static function sign(string $payload, int $timestamp, string $secret = self::SECRET): string
    {
        return 't=' . $timestamp . ',v1=' . self::signature($payload, $timestamp, $secret);
    }

    public function testHeaderNames(): void
    {
        self::assertSame('Barkapay-Signature', Webhook::SIGNATURE_HEADER);
        self::assertSame('PayHub-Signature', Webhook::LEGACY_SIGNATURE_HEADER);
        self::assertSame(300, Webhook::DEFAULT_TOLERANCE);
    }

    public function testVerifyAndParseValidSignature(): void
    {
        $header = self::sign(self::PAYLOAD, time());

        self::assertTrue(Webhook::verify(self::PAYLOAD, $header, self::SECRET));

        $event = Webhook::parse(self::PAYLOAD, $header, self::SECRET);
        self::assertSame('payment', $event['type']);
        self::assertSame('SUCCESSFUL', $event['status']);
        self::assertSame('dlv:0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f:payment.succeeded:whk_1', $event['event_id']);
        self::assertSame('250.00', $event['fees']);
    }

    public function testMatchesAnIndependentlyComputedReferenceVector(): void
    {
        // hex(HMAC-SHA256(key = "whsec_k", message = '1700000000.{"a":1}')), computed outside PHP.
        $header = 't=1700000000,v1=a252a4340d8bbbdbac8643a2812cc7d628ad79bfba544bff054ac2fa10f795a2';

        self::assertTrue(Webhook::verify('{"a":1}', $header, 'whsec_k', 0));
        self::assertSame(['a' => 1], Webhook::parse('{"a":1}', $header, 'whsec_k', 0));
    }

    public function testRejectsTamperedPayload(): void
    {
        $header = self::sign(self::PAYLOAD, time());

        self::assertFalse(Webhook::verify(str_replace('10000', '99999', self::PAYLOAD), $header, self::SECRET));
        // Re-encoding the body (here: a trailing newline) breaks the signature too.
        self::assertFalse(Webhook::verify(self::PAYLOAD . "\n", $header, self::SECRET));
    }

    public function testRejectsWrongSecret(): void
    {
        $header = self::sign(self::PAYLOAD, time());

        self::assertFalse(Webhook::verify(self::PAYLOAD, $header, 'whsec_other'));
    }

    public function testRejectsATimestampThatDoesNotMatchTheSignature(): void
    {
        $now = time();
        $header = 't=' . ($now - 1) . ',v1=' . self::signature(self::PAYLOAD, $now);

        self::assertFalse(Webhook::verify(self::PAYLOAD, $header, self::SECRET));
    }

    public function testTimestampTolerance(): void
    {
        $now = time();

        self::assertTrue(Webhook::verify(self::PAYLOAD, self::sign(self::PAYLOAD, $now - 290), self::SECRET));
        self::assertFalse(Webhook::verify(self::PAYLOAD, self::sign(self::PAYLOAD, $now - 1000), self::SECRET));
        // A timestamp from the future is as suspicious as a stale one.
        self::assertFalse(Webhook::verify(self::PAYLOAD, self::sign(self::PAYLOAD, $now + 1000), self::SECRET));
        // Custom window.
        self::assertTrue(Webhook::verify(self::PAYLOAD, self::sign(self::PAYLOAD, $now - 1000), self::SECRET, 3600));
        self::assertFalse(Webhook::verify(self::PAYLOAD, self::sign(self::PAYLOAD, $now - 30), self::SECRET, 10));
        // 0 disables the freshness check (documented escape hatch).
        self::assertTrue(Webhook::verify(self::PAYLOAD, self::sign(self::PAYLOAD, 1700000000), self::SECRET, 0));
    }

    public function testAnEmptySecretNeverVerifies(): void
    {
        // HMAC with an empty key is computable by anyone.
        $forged = self::sign(self::PAYLOAD, time(), '');

        self::assertFalse(Webhook::verify(self::PAYLOAD, $forged, ''));
        self::assertFalse(Webhook::verify(self::PAYLOAD, $forged, '   '));

        $this->expectException(SignatureVerificationException::class);
        Webhook::parse(self::PAYLOAD, $forged, '');
    }

    public function testAcceptsSeveralV1SignaturesForSecretRotation(): void
    {
        $now = time();
        $old = self::signature(self::PAYLOAD, $now, 'whsec_old');
        $new = self::signature(self::PAYLOAD, $now, 'whsec_new');
        $header = 't=' . $now . ',v1=' . $old . ',v1=' . $new;

        self::assertTrue(Webhook::verify(self::PAYLOAD, $header, 'whsec_old'));
        self::assertTrue(Webhook::verify(self::PAYLOAD, $header, 'whsec_new'));
        self::assertFalse(Webhook::verify(self::PAYLOAD, $header, 'whsec_unknown'));
    }

    public function testToleratesWhitespaceUnknownSchemesAndUppercaseHex(): void
    {
        $now = time();
        $sig = self::signature(self::PAYLOAD, $now);

        self::assertTrue(Webhook::verify(self::PAYLOAD, ' t=' . $now . ' , v1=' . $sig . ' ', self::SECRET));
        self::assertTrue(Webhook::verify(self::PAYLOAD, 't=' . $now . ',v0=legacy,v1=' . $sig . ',v2=future', self::SECRET));
        self::assertTrue(Webhook::verify(self::PAYLOAD, 'v1=' . strtoupper($sig) . ',t=' . $now, self::SECRET));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedHeaders(): iterable
    {
        $now = time();
        $sig = self::signature(self::PAYLOAD, $now);

        yield 'empty' => [''];
        yield 'garbage' => ['not-a-signature'];
        yield 'no timestamp' => ['v1=' . $sig];
        yield 'no signature' => ['t=' . $now];
        yield 'only an unknown scheme' => ['t=' . $now . ',v0=' . $sig];
        yield 'empty timestamp' => ['t=,v1=' . $sig];
        yield 'zero timestamp' => ['t=0,v1=' . $sig];
        yield 'negative timestamp' => ['t=-' . $now . ',v1=' . $sig];
        yield 'float timestamp' => ['t=' . $now . '.5,v1=' . $sig];
        yield 'exponent timestamp' => ['t=1e9,v1=' . $sig];
        yield 'hex timestamp' => ['t=0x' . dechex($now) . ',v1=' . $sig];
        yield 'timestamp with a trailing newline' => ['t=' . $now . "\n,v1=" . $sig . "\nX"];
        yield 'two timestamps' => ['t=' . $now . ',t=' . $now . ',v1=' . $sig];
        yield 'short signature' => ['t=' . $now . ',v1=' . substr($sig, 0, 32)];
        yield 'long signature' => ['t=' . $now . ',v1=' . $sig . 'ab'];
        yield 'non-hex signature' => ['t=' . $now . ',v1=' . str_repeat('z', 64)];
        yield 'empty signature' => ['t=' . $now . ',v1='];
        yield 'part without a value' => ['t=' . $now . ',v1=' . $sig . ',oops'];
        yield 'semicolon separated' => ['t=' . $now . ';v1=' . $sig];
        yield 'oversized' => ['t=' . $now . ',v1=' . $sig . str_repeat(',v1=' . $sig, 20)];
    }

    #[DataProvider('malformedHeaders')]
    public function testRejectsMalformedHeaders(string $header): void
    {
        self::assertFalse(Webhook::verify(self::PAYLOAD, $header, self::SECRET));

        $this->expectException(SignatureVerificationException::class);
        Webhook::parse(self::PAYLOAD, $header, self::SECRET);
    }

    public function testParseThrowsOnBadSignature(): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::parse(self::PAYLOAD, self::sign(self::PAYLOAD, time()), 'whsec_other');
    }

    public function testParseRejectsAVerifiedBodyThatIsNotAJsonObject(): void
    {
        foreach (['not json', '[1,2]', '"x"', ''] as $body) {
            try {
                Webhook::parse($body, self::sign($body, time()), self::SECRET);
                self::fail('Expected SignatureVerificationException.');
            } catch (SignatureVerificationException $e) {
                self::assertStringContainsString('JSON', $e->getMessage());
            }
        }
    }

    public function testFailureMessagesNeverContainTheSecretOrTheExpectedSignature(): void
    {
        $now = time();
        $expected = self::signature(self::PAYLOAD, $now);

        try {
            Webhook::parse(self::PAYLOAD, 't=' . $now . ',v1=' . str_repeat('0', 64), self::SECRET);
            self::fail('Expected SignatureVerificationException.');
        } catch (SignatureVerificationException $e) {
            self::assertStringNotContainsString(self::SECRET, $e->getMessage());
            self::assertStringNotContainsString($expected, $e->getMessage());
        }
    }
}
