# barkapay PHP SDK

Official PHP client for the [barkapay API](https://payhub.africa/developers) — mobile-money
collections, payouts and hosted payment links across West Africa, behind one API.

- **Zero runtime dependencies** (just `ext-curl` + `ext-json`) — safe to embed inside WordPress / WooCommerce.
- Typed, immutable objects for payments, transfers, payment links and balances.
- Webhook signature verification built in.
- Safe by default: TLS always verified, redirects never followed, money-moving calls never replayed blindly.

Available countries: Burkina Faso (`bf`), Bénin (`bj`), Sénégal (`sn`).

## Requirements

PHP **8.1+** with the `curl` and `json` extensions.

## Install

```bash
composer require barkapay/payhub-php
```

## Quick start

```php
use PayHub\Client;

$barkapay = new Client(
    apiKey: getenv('PAYHUB_API_KEY') ?: null, // the "key_id:secret" pair from the dashboard
    country: 'bf',                            // default country, overridable per call
);

$payment = $barkapay->payments->create([
    'operator'     => 'ORANGE',
    'phone_number' => '50123456789',
    'amount'       => 10000,
    'otp'          => '123456',       // operators that take the payer's code up-front (e.g. ORANGE)
    'order'        => ['id' => 'ORDER-2026-001', 'unique' => true],
]);

echo $payment->publicId, ' ', $payment->rawStatus, PHP_EOL;
```

> **Authentication** — pass the `key_id:secret` pair as `apiKey` (or `keyId` and `secret`
> separately). The SDK adds the `Bearer` prefix itself. Keep the key on your server: never ship
> it to a browser or a mobile app, never commit it.

Every method takes an optional last argument `$country` to target another country than the
client's default: `$barkapay->balance->get('sn')`.

## Payments

```php
use PayHub\Enum\PaymentStatus;
use PayHub\Exception\PaymentFailedException;

try {
    $payment = $barkapay->payments->create([
        'operator'     => 'MOOV',
        'phone_number' => '70123456',
        'amount'       => 5000,
        'order'        => ['id' => 'ORDER-2026-002', 'unique' => true],
    ]);
} catch (PaymentFailedException $e) {
    // The payer or the operator refused. The attempt is recorded on the API side:
    $failed = $e->getPayment();          // status FAILED
    error_log('Payment refused: ' . ($failed->reasonCode ?? $e->getMessage()));

    return;
}

match ($payment->status) {
    // Settled in this very response.
    PaymentStatus::Successful => fulfilOrder($payment->orderId),
    // The payer received a code by SMS: collect it, then confirm.
    PaymentStatus::AwaitingOtp => askPayerForOtp($payment->publicId),
    // The payer approves on their phone (or at $payment->redirectUrl for WAVE):
    // the verdict arrives by webhook.
    default => waitForWebhook($payment->publicId),
};
```

What happens after `create()` depends on the operator — always branch on the returned status:

| Status | Meaning | Operators today |
|---|---|---|
| `SUCCESSFUL` | Settled synchronously | ORANGE (`bf`, `sn`) — the payer's OTP is sent in `otp` |
| `AWAITING_OTP` | Call `confirmOtp()` with the code the payer received | MOOV (`bf`) |
| `PROCESSING_OPERATOR` | The payer approves on their side; the verdict arrives by webhook | MTN (`bj`), WAVE (`sn`, open `$payment->redirectUrl`) |

`$barkapay->operators->info()` is the authoritative list of operators for a country, with their
amount bounds and whether they need an OTP.

```php
$payment = $barkapay->payments->confirmOtp($publicId, '012345'); // MOOV bf — one attempt only
$barkapay->payments->resendOtp($publicId);

$payment = $barkapay->payments->get($publicId);        // by public_id…
$payment = $barkapay->payments->get('ORDER-2026-002'); // …or by your order id

$page = $barkapay->payments->list(['status' => 'SUCCESSFUL', 'per_page' => 50]);
foreach ($page as $payment) {
    echo $payment->publicId, ' ', $payment->amount, ' ', $payment->currency, PHP_EOL;
}
```

- The OTP is a **string**: `'012345'` keeps its leading zero, `012345` does not.
- `EXPIRED` means the payer never completed (distinct from `FAILED`, a real refusal).
  `$payment->isFinal()` is true for `SUCCESSFUL`, `FAILED`, `EXPIRED` and `CANCELED`.
- On a failed payment, `reasonCode` is a stable code you can branch on
  (`PayHub\Enum\ReasonCode`), `reason` its English sentence and `providerReason` the operator's
  own words. **The status is the verdict**; the reason code is only an indication.
- A status this SDK version does not know yet gives `$payment->status === null`;
  `$payment->rawStatus` always holds the string the API sent.

## Payment links

A payment link is a durable URL on the barkapay-hosted checkout: your customer opens it, picks
an operator and pays. Nothing to build on your side.

```php
$link = $barkapay->paymentLinks->create([
    'title'  => 'Invoice #1234',
    'usage'  => 'single',                                  // or 'multi' for a reusable link
    'amount' => ['type' => 'fixed', 'currency' => 'XOF', 'value' => '25000'],
    'order'  => ['id' => 'INV-1234'],
    'redirect' => ['success_url' => 'https://shop.example/thanks'],
]);

echo $link->url, PHP_EOL; // share this with your customer

$link  = $barkapay->paymentLinks->get($link->publicId);    // by PL-… id or by slug
$links = $barkapay->paymentLinks->list(['status' => 'active,paused']);
$link  = $barkapay->paymentLinks->update($link->publicId, ['title' => 'Invoice #1234 (revised)']);
$link  = $barkapay->paymentLinks->pause($link->publicId);  // checkout refuses new payments
$link  = $barkapay->paymentLinks->resume($link->publicId);
$link  = $barkapay->paymentLinks->cancel($link->publicId); // for good

// Payments made through the link (per country — see below).
$payments = $barkapay->paymentLinks->listPayments($link->publicId, ['status' => 'SUCCESSFUL']);
echo $payments->paymentLink?->stats?->totalCollected, PHP_EOL;
```

- A link is not a payment: payments are born when payers pay. They are regular payments
  (`channel` = `hosted`, `linkPublicId` set) and fire the usual `payment.*` webhooks on the API
  application that created the link.
- `amount.type` is `fixed` (`value`), `range` (`min` / `max`) or `open` (the payer chooses).
  Amounts are decimal strings in major units.
- `update()` only changes `title`, `description`, `expires_at` and `redirect`.
- `listPayments()` returns the payments held by the country you call. For a link open to
  several countries, call it once per country; the link's `stats` are global either way.
- Required scopes on the API application: `payment_links:create`, `payment_links:read`,
  `payment_links:manage`.
- `payments->create()` always executes a direct payment — there is no `channel: hosted` switch
  on it; hosted checkout is this resource.

## Transfers

```php
use PayHub\Exception\ConflictException;
use PayHub\Exception\InsufficientBalanceException;
use PayHub\Exception\TransferFailedException;

try {
    $transfer = $barkapay->transfers->create([
        'operator'     => 'ORANGE',
        'phone_number' => '50123456789',
        'amount'       => 50000,
        'order'        => ['id' => 'PAYOUT-2026-001', 'unique' => true],
    ]);
} catch (InsufficientBalanceException $e) {
    // Top up the wallet: amount + fees exceed what is available.
} catch (ConflictException $e) {
    // Same order id already used, or a near-identical transfer was sent < 60 s ago.
} catch (TransferFailedException $e) {
    $failed = $e->getTransfer(); // status FAILED, reasonCode, providerReason
}

$transfer = $barkapay->transfers->get('PAYOUT-2026-001');
$page     = $barkapay->transfers->list(['from_date' => '2026-11-01', 'to_date' => '2026-11-30']);
```

A transfer that is not final (`PROCESSING_OPERATOR`, `UNCERTAIN`) is **in flight**: the payout
may be executing. Never send it again — wait for the webhook or poll `transfers->get()`.

## Balance & operators

```php
$balance = $barkapay->balance->get();     // available / total / holds, decimal strings in ->currency
echo $balance->available, ' ', $balance->currency, PHP_EOL;

$operators    = $barkapay->operators->info();          // operators, OTP requirement, amount bounds
$availability = $barkapay->operators->availability();  // operational / degraded / down per operator
$me           = $barkapay->me();                       // merchant + API application (scopes, permissions)
$pong         = $barkapay->ping();
```

## Pagination

List methods return one page: a countable, iterable collection with `->items` and `->meta`.

```php
$page = 1;
do {
    $payments = $barkapay->payments->list(['page' => $page, 'per_page' => 100]);
    foreach ($payments as $payment) {
        // …
    }
    $page++;
} while ($payments->meta?->hasMore());
```

## Webhooks

Every delivery is signed with the `Barkapay-Signature` header. Verify it against the **raw**
request body, with the signing secret (`whsec_…`) of the webhook:

```php
use PayHub\Exception\SignatureVerificationException;
use PayHub\Webhook;

$payload = file_get_contents('php://input') ?: '';
$header  = $_SERVER['HTTP_BARKAPAY_SIGNATURE'] ?? '';

try {
    $event = Webhook::parse($payload, $header, getenv('PAYHUB_WEBHOOK_SECRET') ?: '');
} catch (SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

// $event['type'] is "payment" or "transfer"; $event['status'] is the final status.
// Deliveries are at-least-once: deduplicate on $event['event_id'].
http_response_code(200);
```

- The signature is `t=<unix>,v1=<hex>` with `v1 = HMAC-SHA256("{t}.{rawBody}", secret)`. The SDK
  compares in constant time and rejects signatures older than 300 seconds (4th argument).
- The body has no `event` field. Derive the event from `type` + `status`: `payment` +
  `SUCCESSFUL` is `payment.succeeded`, `FAILED` is `payment.failed`, `EXPIRED` is
  `payment.expired`; same for `transfer`.
- Answer with any `2xx` quickly and do the work afterwards; other answers are retried.
- `Webhook::verify()` does the same check and returns a boolean.
- An empty secret never verifies: if the environment variable is missing, the delivery is
  refused rather than accepted unsigned.

## Errors

Every error returned by the API throws an exception extending `PayHub\Exception\ApiException`:

| Exception | HTTP | When |
|---|---|---|
| `ValidationException` | 422, 400 | Invalid request — `getErrors()` maps each field to its messages |
| `AuthenticationException` | 401 | Missing or wrong API key |
| `AuthorizationException` | 403, 410, 451 | Missing scope, operator not enabled, IP not allowed, account suspended or closed |
| `NotFoundException` | 404 | Unknown resource, endpoint or country |
| `ConflictException` | 409 | Duplicate: `order.unique` already used, or the 60 s duplicate-transfer guard |
| `InsufficientBalanceException` | 400 | The wallet cannot cover the transfer |
| `PaymentFailedException` | 424 | Payment refused by the payer or the operator — `getPayment()` |
| `TransferFailedException` | 424 | Transfer refused by the operator — `getTransfer()` |
| `RateLimitException` | 429 | Too many requests — `getRetryAfter()` |
| `ServiceUnavailableException` | 503 | Operator or service temporarily unavailable — `getRetryAfter()` |
| `ServerException` | 5xx | Unexpected error on the API side |

Outside of API responses: `NetworkException` (no response received), `ConfigurationException`
(invalid argument or client configuration) and `SignatureVerificationException` (webhooks).
Catch everything the SDK throws with `PayHub\Exception\PayHubException`.

```php
use PayHub\Exception\ApiException;
use PayHub\Exception\NetworkException;
use PayHub\Exception\ValidationException;

try {
    $barkapay->payments->create($params);
} catch (ValidationException $e) {
    foreach ($e->getErrors() as $field => $messages) {
        // e.g. "phone_number" => ["…"]
    }
} catch (ApiException $e) {
    error_log(sprintf(
        'barkapay error %s (HTTP %d), request %s: %s',
        $e->getErrorCode(),   // machine-readable: "payment_failed", "operator_unavailable", …
        $e->getHttpStatus(),
        $e->getRequestId(),   // quote it to support
        $e->getMessage(),
    ));
} catch (NetworkException $e) {
    // No response. For a payment or a transfer, do NOT assume it failed — see below.
}
```

`ApiException` also exposes `getData()`, `getRetryAfter()`, `getResponseHeaders()` and
`getResponseBody()`.

## Retries and safe replays

The SDK retries on its own only what cannot duplicate an operation:

| Request | Retried on |
|---|---|
| Reads (`get`, `list`, `balance`, `operators`, `ping`, `me`) | network errors, 429, 502, 503, 504 |
| Writes (`create`, `confirmOtp`, `resendOtp`, `update`, `cancel`, `pause`, `resume`) | 429 (the request was not processed) and connections that were never established |

Waits use exponential backoff with jitter and honour `Retry-After`. If the API asks for a longer
wait than `maxRetryDelay` (5 s by default), the error is thrown at once with `getRetryAfter()`
set, instead of blocking your request.

**A timeout or a 5xx on a payment or a transfer is never retried automatically**: the API has no
idempotency key, and the operation may have gone through. To replay safely yourself:

1. always send your own reference in `order.id`, with `order.unique => true` — the API then
   refuses a second operation with the same id (`ConflictException`);
2. on `NetworkException` (unless `$e->isConnectionFailure()` is true) or
   `ServiceUnavailableException`, look the operation up first:
   `payments->get($orderId)` / `transfers->get($orderId)`;
3. only create it again if that lookup throws `NotFoundException`.

## Rate limits

Limits are per route family and shared by all the API applications of a merchant. They are
operational settings: read them at runtime rather than hard-coding them.

```php
$barkapay->payments->list();

$limit = $barkapay->lastRateLimit();   // from the response that was just received
echo $limit?->remaining, ' / ', $limit?->limit, PHP_EOL;
```

## Amounts

- Send `amount` as an integer or a decimal **string** (`10000`, `'1500.50'`) — not as the result
  of float arithmetic. At most two decimals.
- `amount` comes back as the API's JSON number; `fees`, balances and payment-link amounts come
  back as decimal strings (`"250.00"`). Use integers or `bcmath` for arithmetic and comparisons.

## Configuration

```php
use PayHub\Client;

$barkapay = new Client(
    apiKey:         getenv('PAYHUB_API_KEY') ?: null,
    country:        'bf',
    baseUrl:        'https://hub.barkapay.com', // default
    maxRetries:     2,     // 0 disables automatic retries
    timeout:        30.0,  // seconds, whole request
    connectTimeout: 10.0,  // seconds, connection only
    maxRetryDelay:  5.0,   // seconds, longest wait between two attempts
);
```

To use your own HTTP stack, pass `httpClient:` an implementation of
`PayHub\Http\HttpClientInterface` (it must verify TLS and must not follow redirects). The
default transport accepts a custom CA bundle — `new CurlClient(caBundle: '/path/cacert.pem')` —
and offers no way to turn certificate verification off.

`$barkapay->lastRequestId()` returns the `X-Request-Id` of the latest call, for your logs.

## Security

- TLS certificates and host names are always verified; redirects are never followed; plain
  `http://` to the barkapay hosts is refused.
- The API key never appears in exception messages, in dumps of the client (`var_dump()`,
  `print_r()`, `var_export()`, `dd()`), nor (PHP 8.2+) in stack traces.
- Identifiers and country codes are validated and percent-encoded before they reach a URL.
- See [SECURITY.md](SECURITY.md) to report a vulnerability.

## Upgrading from 1.0

1.1 is backward compatible. Worth knowing:

- writes are no longer replayed after a timeout or a 503 (they could be executed twice);
- `EXPIRED` payments and `UNCERTAIN` transfers now have an enum case (they used to hydrate as
  `status === null`);
- the webhook header is `Barkapay-Signature` (`$_SERVER['HTTP_BARKAPAY_SIGNATURE']`) — the old
  `PayHub-Signature` name is no longer sent;
- deduplicate webhooks on `event_id`.

See [CHANGELOG.md](CHANGELOG.md) for the full list.

## License

MIT.
