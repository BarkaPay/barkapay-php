<?php

declare(strict_types=1);

namespace PayHub\Resources;

use PayHub\ApiClient;
use PayHub\DTO\Transfer;
use PayHub\DTO\TransferCollection;
use PayHub\Exception\ApiException;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NetworkException;
use PayHub\Exception\NotFoundException;
use PayHub\Util\Coerce;

/**
 * Disbursements: pay out from your wallet to a mobile-money account.
 */
final class Transfers
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Create a transfer. Amount + fees are reserved from your wallet at once.
     *
     * Parameters: `operator` (upper case), `phone_number` (national format,
     * digits only, without the country code), `amount` (at most two decimals),
     * `order` (`id` required; `unique: true` makes the API refuse a second
     * transfer with the same id — the closest thing to an idempotency key),
     * and `ignore_double_spend_risk` to bypass the guard that refuses a
     * near-identical transfer for 60 seconds (409).
     *
     * A returned status that is not final (`PROCESSING_OPERATOR`, `UNCERTAIN`)
     * means the payout is in flight: never send it again — the verdict
     * arrives by webhook, or poll {@see get()}.
     *
     * Errors: {@see \PayHub\Exception\InsufficientBalanceException} (400),
     * {@see \PayHub\Exception\ConflictException} (409),
     * {@see \PayHub\Exception\TransferFailedException} (424, the recorded
     * attempt is on `getTransfer()`).
     *
     * This call is never replayed after a timeout or a 5xx (it could pay
     * twice): on {@see \PayHub\Exception\NetworkException}, look the transfer
     * up with `list(['order_id' => $orderId])` before trying again.
     *
     * @param array<string, mixed> $params
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function create(array $params, ?string $country = null): Transfer
    {
        return Transfer::fromArray($this->unwrap($this->api->request('POST', ['transfers'], $country, $params)));
    }

    /**
     * One page of transfers, newest first.
     *
     * Filters: `status`, `operator`, `phone_number`, `order_id`, `amount` +
     * `amount_operator` (`<`, `<=`, `=`, `>=`, `>`), `from_date` + `to_date`
     * (both required to filter by date), `per_page` (1–200, default 50) and
     * `page`.
     *
     * @param array<string, scalar|null> $filters
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function list(array $filters = [], ?string $country = null): TransferCollection
    {
        return TransferCollection::fromEnvelope(
            $this->api->request('GET', ['transfers'], $country, null, $filters),
        );
    }

    /**
     * Look up a transfer by its public_id (UUID) or by your order_id.
     *
     * An order_id is only unique when the transfer was created with
     * `order.unique: true`; otherwise the first match is returned — use
     * `list(['order_id' => …])` to get them all.
     *
     * An identifier shaped like a UUID is always looked up as a public_id: if
     * your own order ids are UUIDs, find them with `list(['order_id' => …])`.
     *
     * @throws ApiException           on any error returned by the API — {@see NotFoundException} when nothing matches
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function get(string $id, ?string $country = null): Transfer
    {
        $env = $this->api->request('GET', ['transfers', $id], $country);
        $rows = Coerce::rows(Coerce::arr($env, 'data') ?? [], 'transfers');

        if ($rows === []) {
            throw new NotFoundException('No transfer found for "' . $id . '".', 'resource_not_found', 404);
        }

        return Transfer::fromArray($rows[0]);
    }

    /**
     * @param array<string, mixed> $env
     *
     * @return array<string, mixed>
     */
    private function unwrap(array $env): array
    {
        return Coerce::arr(Coerce::arr($env, 'data') ?? [], 'transfer') ?? [];
    }
}
