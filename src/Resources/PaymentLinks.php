<?php

declare(strict_types=1);

namespace PayHub\Resources;

use PayHub\ApiClient;
use PayHub\DTO\PaymentCollection;
use PayHub\DTO\PaymentLink;
use PayHub\DTO\PaymentLinkCollection;
use PayHub\Exception\ApiException;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NetworkException;
use PayHub\Util\Coerce;

/**
 * Payment links: durable URLs on the barkapay-hosted checkout. Your customer
 * opens the link, picks an operator and pays — no checkout to build.
 *
 * A link is not a payment: payments are born when payers pay. They are regular
 * payments (`channel: hosted`, `linkPublicId` set) and fire the usual
 * `payment.*` webhooks on the application that created the link.
 *
 * Every method takes the link's `PL-…` public id or its 13-character slug.
 * Required API scopes: `payment_links:create`, `payment_links:read`,
 * `payment_links:manage`.
 */
final class PaymentLinks
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Create a payment link — share the returned `url` with your customer.
     *
     * Parameters: `title` (required), `amount` (required:
     * `['type' => 'fixed', 'value' => '25000', 'currency' => 'XOF']`,
     * `['type' => 'range', 'min' => …, 'max' => …]` or `['type' => 'open']`),
     * `usage` (`single` — default, closed by its first successful payment — or
     * `multi`), `description`, `order` (`id`, `data`), `redirect`
     * (`success_url`, `cancel_url`), `restrictions` (`countries`, `operators`),
     * `payer` (`full_name`, `email`, `phone`, `locale`), `collect` (`full_name`
     * / `email` / `city` / `motif` => `required` | `optional` | `off`),
     * `notify_email_invoice` and `expires_at` (ISO-8601, in the future).
     *
     * @param array<string, mixed> $params
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function create(array $params, ?string $country = null): PaymentLink
    {
        return $this->one($this->api->request('POST', ['payments', 'links'], $country, $params));
    }

    /**
     * One page of your payment links, newest first.
     *
     * Filters: `status` (one value, or several separated by commas:
     * `active,paused`), `usage` (`single` | `multi`), `source` (`api` | `ui`),
     * `per_page` (1–100, default 25) and `page`.
     *
     * @param array<string, scalar|null> $params
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function list(array $params = [], ?string $country = null): PaymentLinkCollection
    {
        return PaymentLinkCollection::fromEnvelope(
            $this->api->request('GET', ['payments', 'links'], $country, null, $params),
        );
    }

    /**
     * Retrieve a payment link by its `PL-…` public id or its slug.
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function get(string $id, ?string $country = null): PaymentLink
    {
        return $this->one($this->api->request('GET', ['payments', 'links', $id], $country));
    }

    /**
     * Edit an active or paused link. Only the presentation fields can change:
     * `title`, `description`, `expires_at` and `redirect` (send `null` to
     * clear a nullable field). Amount, currency and usage are immutable.
     *
     * @param array<string, mixed> $params
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function update(string $id, array $params, ?string $country = null): PaymentLink
    {
        return $this->one($this->api->request('PATCH', ['payments', 'links', $id], $country, $params));
    }

    /**
     * Cancel an active or paused link for good: the checkout refuses new
     * payments at once. Payments already made are not affected.
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function cancel(string $id, ?string $country = null): PaymentLink
    {
        return $this->one($this->api->request('DELETE', ['payments', 'links', $id], $country));
    }

    /**
     * Put an active link on hold: the checkout refuses new payments until {@see resume()}.
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function pause(string $id, ?string $country = null): PaymentLink
    {
        return $this->one($this->api->request('POST', ['payments', 'links', $id, 'pause'], $country));
    }

    /**
     * Re-open a paused link.
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function resume(string $id, ?string $country = null): PaymentLink
    {
        return $this->one($this->api->request('POST', ['payments', 'links', $id, 'resume'], $country));
    }

    /**
     * One page of the payments made through a link, newest first. The
     * collection's `paymentLink` carries the link summary (status, stats).
     *
     * Each country only returns its own payments: for a link open to several
     * countries, call this once per country (`$country` argument). The link's
     * `stats` are global either way.
     *
     * Filters: `status`, `operator`, `phone_number`, `from_date`, `to_date`,
     * `per_page` (1–100, default 25) and `page`.
     *
     * @param array<string, scalar|null> $params
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function listPayments(string $id, array $params = [], ?string $country = null): PaymentCollection
    {
        return PaymentCollection::fromEnvelope(
            $this->api->request('GET', ['payments', 'links', $id, 'payments'], $country, null, $params),
        );
    }

    /**
     * @param array<string, mixed> $env
     */
    private function one(array $env): PaymentLink
    {
        return PaymentLink::fromArray(Coerce::arr(Coerce::arr($env, 'data') ?? [], 'payment_link') ?? []);
    }
}
