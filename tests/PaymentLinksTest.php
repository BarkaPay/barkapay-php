<?php

declare(strict_types=1);

namespace PayHub\Tests;

use PayHub\Client;
use PayHub\DTO\PaymentLink;
use PayHub\Enum\PaymentLinkStatus;
use PayHub\Enum\PaymentStatus;
use PayHub\Http\Response;
use PayHub\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class PaymentLinksTest extends TestCase
{
    private const ID = 'PL-0198c2f1-9f6a-7c4e-b1d2-3a4b5c6d7e8f';
    private const BASE = 'https://hub.barkapay.com/bf/v1/payments/links';

    /**
     * The link object exactly as the API serialises it.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function link(array $overrides = []): array
    {
        return $overrides + [
            'public_id' => self::ID,
            'slug' => '9j32HfkT2mQx4',
            'url' => 'https://checkout.payhub.africa/9j32HfkT2mQx4',
            'status' => 'active',
            'source' => 'api',
            'usage' => 'single',
            'amount' => ['type' => 'fixed', 'value' => '25000', 'min' => null, 'max' => null, 'currency' => 'XOF'],
            'title' => 'Facture #1234',
            'description' => null,
            'order' => ['id' => 'INV-1234'],
            'redirect' => ['success_url' => 'https://shop.example/ok', 'cancel_url' => null],
            'restrictions' => ['countries' => ['BF']],
            'payer' => null,
            'collect' => [],
            'notify' => ['email_invoice' => true],
            'expires_at' => '2026-12-31T23:59:59+00:00',
            'stats' => ['payments_count' => 0, 'total_collected' => '0'],
            'paid_by_payment_public_id' => null,
            'created_at' => '2026-11-16T10:15:00+00:00',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function linkResponse(array $overrides = [], int $status = 200): Response
    {
        return FakeHttpClient::ok(['payment_link' => self::link($overrides)], $status);
    }

    private function client(FakeHttpClient $http): Client
    {
        return new Client(apiKey: 'k:s', country: 'bf', maxRetries: 0, httpClient: $http);
    }

    public function testCreate(): void
    {
        $http = new FakeHttpClient([self::linkResponse([], 201)]);

        $link = $this->client($http)->paymentLinks->create([
            'title' => 'Facture #1234',
            'usage' => 'single',
            'amount' => ['type' => 'fixed', 'currency' => 'XOF', 'value' => '25000'],
            'notify_email_invoice' => true,
        ]);

        self::assertSame('POST', $http->last()->method);
        self::assertSame(self::BASE, $http->last()->url);
        $body = $http->lastJson();
        self::assertIsArray($body);
        self::assertSame(['type' => 'fixed', 'currency' => 'XOF', 'value' => '25000'], $body['amount']);

        self::assertInstanceOf(PaymentLink::class, $link);
        self::assertSame(self::ID, $link->publicId);
        self::assertSame('9j32HfkT2mQx4', $link->slug);
        self::assertSame('https://checkout.payhub.africa/9j32HfkT2mQx4', $link->url);
        self::assertSame(PaymentLinkStatus::Active, $link->status);
        self::assertSame('active', $link->rawStatus);
        self::assertTrue($link->isPayable());
        self::assertSame('api', $link->source);
        self::assertSame('single', $link->usage);
        $amount = $link->amount;
        self::assertNotNull($amount);
        self::assertSame('fixed', $amount->type);
        self::assertSame('25000', $amount->value);
        self::assertNull($amount->min);
        self::assertNull($amount->max);
        self::assertSame('XOF', $amount->currency);
        self::assertSame('Facture #1234', $link->title);
        self::assertSame(['id' => 'INV-1234'], $link->order);
        self::assertSame('https://shop.example/ok', $link->redirect['success_url'] ?? null);
        self::assertSame(['countries' => ['BF']], $link->restrictions);
        self::assertSame([], $link->collect);
        self::assertTrue($link->notifyEmailInvoice);
        self::assertSame('2026-12-31T23:59:59+00:00', $link->expiresAt);
        $stats = $link->stats;
        self::assertNotNull($stats);
        self::assertSame(0, $stats->paymentsCount);
        self::assertSame('0', $stats->totalCollected);
        self::assertNull($link->paidByPaymentPublicId);
        self::assertSame('2026-11-16T10:15:00+00:00', $link->createdAt);
        self::assertSame('Facture #1234', $link->toArray()['title']);
    }

    public function testList(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(
                ['payment_links' => [self::link(), self::link(['public_id' => 'PL-2', 'status' => 'paused'])]],
                200,
                // The API does not send `last_page` for links.
                ['current_page' => 1, 'per_page' => 2, 'total' => 14],
            ),
        ]);

        $links = $this->client($http)->paymentLinks->list(['status' => 'active,paused', 'per_page' => 2]);

        self::assertSame('GET', $http->last()->method);
        self::assertSame(self::BASE . '?status=active%2Cpaused&per_page=2', $http->last()->url);
        self::assertCount(2, $links);
        self::assertSame(PaymentLinkStatus::Paused, $links->items[1]->status);
        self::assertFalse($links->items[1]->isPayable());
        $meta = $links->meta;
        self::assertNotNull($meta);
        self::assertNull($meta->lastPage);
        self::assertSame(14, $meta->total);
        self::assertTrue($meta->hasMore());

        $ids = [];
        foreach ($links as $link) {
            $ids[] = $link->publicId;
        }
        self::assertSame([self::ID, 'PL-2'], $ids);
    }

    public function testGetByPublicIdOrSlug(): void
    {
        $http = new FakeHttpClient([self::linkResponse(), self::linkResponse()]);
        $client = $this->client($http);

        $client->paymentLinks->get(self::ID);
        self::assertSame('GET', $http->last()->method);
        self::assertSame(self::BASE . '/' . self::ID, $http->last()->url);

        $client->paymentLinks->get('9j32HfkT2mQx4', 'sn');
        self::assertSame('https://hub.barkapay.com/sn/v1/payments/links/9j32HfkT2mQx4', $http->last()->url);
    }

    public function testUpdate(): void
    {
        $http = new FakeHttpClient([self::linkResponse(['title' => 'Facture #1234 (révisée)', 'expires_at' => null])]);

        $link = $this->client($http)->paymentLinks->update(self::ID, [
            'title' => 'Facture #1234 (révisée)',
            'expires_at' => null,
        ]);

        self::assertSame('PATCH', $http->last()->method);
        self::assertSame(self::BASE . '/' . self::ID, $http->last()->url);
        // `null` clears a nullable field: it must be sent, not dropped.
        self::assertSame('{"title":"Facture #1234 (révisée)","expires_at":null}', $http->last()->body);
        self::assertSame('Facture #1234 (révisée)', $link->title);
        self::assertNull($link->expiresAt);
    }

    public function testCancel(): void
    {
        $http = new FakeHttpClient([self::linkResponse(['status' => 'canceled'])]);

        $link = $this->client($http)->paymentLinks->cancel(self::ID);

        self::assertSame('DELETE', $http->last()->method);
        self::assertSame(self::BASE . '/' . self::ID, $http->last()->url);
        self::assertNull($http->last()->body);
        self::assertSame(PaymentLinkStatus::Canceled, $link->status);
    }

    public function testPauseAndResume(): void
    {
        $http = new FakeHttpClient([
            self::linkResponse(['status' => 'paused']),
            self::linkResponse(['status' => 'active']),
        ]);
        $client = $this->client($http);

        $paused = $client->paymentLinks->pause(self::ID);
        self::assertSame('POST', $http->last()->method);
        self::assertSame(self::BASE . '/' . self::ID . '/pause', $http->last()->url);
        self::assertNull($http->last()->body);
        self::assertSame(PaymentLinkStatus::Paused, $paused->status);

        $resumed = $client->paymentLinks->resume(self::ID);
        self::assertSame('POST', $http->last()->method);
        self::assertSame(self::BASE . '/' . self::ID . '/resume', $http->last()->url);
        self::assertSame(PaymentLinkStatus::Active, $resumed->status);
    }

    public function testListPayments(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::ok(
                [
                    'payment_link' => [
                        'public_id' => self::ID,
                        'slug' => '9j32HfkT2mQx4',
                        'status' => 'active',
                        'stats' => ['payments_count' => 42, 'total_collected' => '1050000'],
                    ],
                    'payments' => [[
                        'public_id' => '0198d4a2-1b2c-7d3e-8f40-5a6b7c8d9e0f',
                        'status' => 'SUCCESSFUL',
                        'amount' => 25000,
                        'fees' => '250.00',
                        'channel' => 'hosted',
                        'link_public_id' => self::ID,
                    ]],
                ],
                200,
                ['current_page' => 1, 'last_page' => 2, 'per_page' => 25, 'total' => 42],
            ),
        ]);

        $payments = $this->client($http)->paymentLinks->listPayments(self::ID, ['status' => 'SUCCESSFUL', 'page' => 1]);

        self::assertSame('GET', $http->last()->method);
        self::assertSame(self::BASE . '/' . self::ID . '/payments?status=SUCCESSFUL&page=1', $http->last()->url);
        self::assertCount(1, $payments);
        self::assertSame(PaymentStatus::Successful, $payments->items[0]->status);
        self::assertSame('hosted', $payments->items[0]->channel);
        self::assertSame(self::ID, $payments->items[0]->linkPublicId);
        self::assertTrue($payments->meta?->hasMore());

        // The link summary travels with the page (partial: no amount, no title).
        $summary = $payments->paymentLink;
        self::assertNotNull($summary);
        self::assertSame(self::ID, $summary->publicId);
        self::assertSame(PaymentLinkStatus::Active, $summary->status);
        self::assertSame(42, $summary->stats?->paymentsCount);
        self::assertSame('1050000', $summary->stats->totalCollected);
        self::assertNull($summary->amount);
        self::assertNull($summary->title);
    }

    public function testUnknownStatusAndFieldsAreTolerated(): void
    {
        $http = new FakeHttpClient([self::linkResponse(['status' => 'archived', 'brand_new_field' => ['x' => 1]])]);

        $link = $this->client($http)->paymentLinks->get(self::ID);

        self::assertNull($link->status);
        self::assertSame('archived', $link->rawStatus);
        self::assertFalse($link->isPayable());
        self::assertSame(['x' => 1], $link->raw['brand_new_field']);
    }
}
