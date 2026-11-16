<?php

declare(strict_types=1);

namespace PayHub\Resources;

use PayHub\ApiClient;
use PayHub\DTO\Payment;
use PayHub\DTO\PaymentCollection;
use PayHub\DTO\PaymentLink;
use PayHub\Exception\ApiException;
use PayHub\Exception\ConfigurationException;
use PayHub\Exception\NetworkException;
use PayHub\Exception\NotFoundException;
use PayHub\Util\Coerce;

/**
 * Collections: charge a payer's mobile-money wallet.
 */
final class Payments
{
    public function __construct(private readonly ApiClient $api)
    {
    }

    /**
     * Create a payment.
     *
     * Parameters: `operator` (upper case, see `operators->info()`),
     * `phone_number` (national format, digits only, without the country code),
     * `amount` (at most two decimals), `order` (`id` required; `unique: true`
     * makes the API refuse a second payment with the same id; `data`: up to 20
     * string entries echoed back as `orderData`), and `otp` — a STRING, leading
     * zeros matter — for operators that take the code up-front.
     *
     * What happens next depends on the operator — branch on the returned status:
     *  - `SUCCESSFUL`: settled in this very response (ORANGE);
     *  - `AWAITING_OTP`: call {@see confirmOtp()} with the code the payer received (MOOV, `bf`);
     *  - `PROCESSING_OPERATOR`: the payer approves on their phone or at
     *    `$payment->redirectUrl` (WAVE); the verdict arrives by webhook.
     *
     * A refused payment throws {@see \PayHub\Exception\PaymentFailedException}
     * (the recorded attempt is on `getPayment()`).
     *
     * This call is never replayed after a timeout or a 5xx (it could charge
     * twice): on {@see \PayHub\Exception\NetworkException}, look the payment up
     * with `get($orderId)` before trying again.
     *
     * **`channel`** — `direct` (the default) is the payment described above.
     * With `'channel' => 'hosted'` nobody is charged: the other parameters are
     * those of {@see PaymentLinks::create()} (`title`, `amount` => [`type`, …],
     * …), a payment link is created, and the returned object only carries it
     * in `$payment->paymentLink` (share its `url`). This is a shortcut kept in
     * the SDK — the API has one endpoint per kind — and
     * `$client->paymentLinks->create()` is the explicit way to do the same.
     *
     * @param array<string, mixed> $params
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, parameters)
     */
    public function create(array $params, ?string $country = null): Payment
    {
        $channel = $params['channel'] ?? null;
        if (\is_string($channel) && strtolower(trim($channel)) === 'hosted') {
            // `channel` is an SDK-level switch: the API ignores the field on
            // POST /payments and would charge the payer directly, so a hosted
            // request is never sent there.
            unset($params['channel']);
            $env = $this->api->request('POST', ['payments', 'links'], $country, $params);

            return Payment::hosted(
                PaymentLink::fromArray(Coerce::arr(Coerce::arr($env, 'data') ?? [], 'payment_link') ?? []),
            );
        }

        return Payment::fromArray($this->unwrap($this->api->request('POST', ['payments'], $country, $params)));
    }

    /**
     * One page of payments, newest first.
     *
     * Filters: `status`, `operator`, `phone_number`, `order_id`, `channel`
     * (`api` | `hosted`), `amount` + `amount_operator` (`<`, `<=`, `=`, `>=`,
     * `>`), `from_date` + `to_date` (both required to filter by date),
     * `per_page` (1–200, default 50) and `page`.
     *
     * @param array<string, scalar|null> $filters
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function list(array $filters = [], ?string $country = null): PaymentCollection
    {
        return PaymentCollection::fromEnvelope(
            $this->api->request('GET', ['payments'], $country, null, $filters),
        );
    }

    /**
     * Look up a payment by its public_id (UUID) or by your order_id.
     *
     * An order_id is only unique when the payment was created with
     * `order.unique: true`; otherwise the first match is returned — use
     * `list(['order_id' => …])` to get them all.
     *
     * @throws ApiException           on any error returned by the API — {@see NotFoundException} when nothing matches
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function get(string $id, ?string $country = null): Payment
    {
        $env = $this->api->request('GET', ['payments', $id], $country);
        $rows = Coerce::rows(Coerce::arr($env, 'data') ?? [], 'payments');

        if ($rows === []) {
            throw new NotFoundException('No payment found for "' . $id . '".', 'resource_not_found', 404);
        }

        return Payment::fromArray($rows[0]);
    }

    /**
     * Confirm a payment that is AWAITING_OTP with the code the payer received
     * (MOOV in Burkina Faso — the only two-step operator; other countries
     * answer 404).
     *
     * One attempt only: a wrong code fails the payment for good
     * ({@see \PayHub\Exception\PaymentFailedException}). The code is a string —
     * `"012345"` keeps its leading zero.
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function confirmOtp(string $publicId, string $otp, ?string $country = null): Payment
    {
        $env = $this->api->request(
            'POST',
            ['payments', $publicId, 'confirm-otp'],
            $country,
            ['otp' => $otp],
        );

        return Payment::fromArray($this->unwrap($env));
    }

    /**
     * Send the OTP again for a payment that is AWAITING_OTP (MOOV, `bf`).
     *
     * @throws ApiException           on any error returned by the API (see the typed subclasses)
     * @throws NetworkException       when no response was received
     * @throws ConfigurationException on an invalid argument (country, identifier, parameters)
     */
    public function resendOtp(string $publicId, ?string $country = null): void
    {
        $this->api->request('POST', ['payments', $publicId, 'resend-otp'], $country);
    }

    /**
     * @param array<string, mixed> $env
     *
     * @return array<string, mixed>
     */
    private function unwrap(array $env): array
    {
        return Coerce::arr(Coerce::arr($env, 'data') ?? [], 'payment') ?? [];
    }
}
