# barkapay PHP SDK

Official PHP client for the [barkapay API](https://barkapay.com/developers) — mobile-money
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
composer require barkapay/barkapay-php
```

## Quick start

```php
use BarkaPay\Client;

$barkapay = new Client(
    apiKey: getenv('BARKAPAY_API_KEY') ?: null, // the "key_id:secret" pair from the dashboard
    country: 'bf',                              // default country, overridable per call
);

$payment = $barkapay->payments->create([
    'operator'     => 'ORANGE',       // upper case, as listed by operators->info()
    'phone_number' => '74123456',     // national format, no country code
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
use BarkaPay\Enum\PaymentStatus;
use BarkaPay\Exception\PaymentFailedException;

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
$payment = $barkapay->payments->get('ORDER-2026-002'); // …or by your order id (*)

$page = $barkapay->payments->list(['status' => 'SUCCESSFUL', 'per_page' => 50]);
foreach ($page as $payment) {
    echo $payment->publicId, ' ', $payment->amount, ' ', $payment->currency, PHP_EOL;
}
```

- (*) `get()` cannot look up an order id that is shaped like a UUID (the API reads it as a
  public id) or that contains a `/`: use `list(['order_id' => …])` for those.
- `phone_number` is the national number, digits only, **without the country code**: 8 digits in
  Burkina Faso, 10 in Bénin, 9 in Sénégal. `operator` is upper case (`ORANGE`, `MOOV`, `MTN`,
  `WAVE`).
- The OTP is a **string**: `'012345'` keeps its leading zero, `012345` does not.
- `EXPIRED` means the payer never completed (distinct from `FAILED`, a real refusal).
  `$payment->isFinal()` is true for `SUCCESSFUL`, `FAILED`, `EXPIRED` and `CANCELED`.
- On a failed payment, `reasonCode` is a stable code you can branch on
  (`BarkaPay\Enum\ReasonCode`), `reason` its English sentence and `providerReason` the operator's
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
- `pause()` and `resume()` never fail on the link's state: called on a link that is not active
  (resp. not paused) they return it unchanged — check the returned `status`. `update()` and
  `cancel()` on a closed link throw `ValidationException`.
- `listPayments()` returns the payments held by the country you call. For a link open to
  several countries, call it once per country; the link's `stats` are global either way.
- Required scopes on the API application: `payment_links:create`, `payment_links:read`,
  `payment_links:manage`.
- `payments->create()` is always a direct payment: hosted checkout goes through this resource
  only.

## Transfers

```php
use BarkaPay\Exception\ConflictException;
use BarkaPay\Exception\InsufficientBalanceException;
use BarkaPay\Exception\TransferFailedException;

try {
    $transfer = $barkapay->transfers->create([
        'operator'     => 'ORANGE',
        'phone_number' => '74123456',
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
use BarkaPay\Exception\SignatureVerificationException;
use BarkaPay\Webhook;

$payload = file_get_contents('php://input') ?: '';
$header  = $_SERVER['HTTP_BARKAPAY_SIGNATURE'] ?? '';

try {
    $event = Webhook::parse($payload, $header, getenv('BARKAPAY_WEBHOOK_SECRET') ?: '');
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
- Pass the **raw** body (`php://input`), never a re-encoded `json_encode(json_decode(…))`: the
  bytes would differ and the signature would never match.
- An empty secret, or a tolerance of 0 or less, throws `ConfigurationException`: a missing
  environment variable fails loudly instead of accepting or silently refusing every delivery.

## Errors

Every error returned by the API throws an exception extending `BarkaPay\Exception\ApiException`:

| Exception | HTTP | When |
|---|---|---|
| `ValidationException` | 422, 400 | Invalid request — `getErrors()` maps each field to its messages. Also raised, without field details, when the resource is not in the right state (confirming a payment that is not `AWAITING_OTP`, editing a closed link) |
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
Catch everything the SDK throws with `BarkaPay\Exception\BarkaPayException`.

```php
use BarkaPay\Exception\ApiException;
use BarkaPay\Exception\NetworkException;
use BarkaPay\Exception\ValidationException;

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

`ApiException` also exposes `getData()`, `getRetryAfter()`, `getResponseHeaders()` /
`getResponseHeader('x-ratelimit-remaining')` and `getResponseBody()`. `NetworkException::getRequestId()`
is the `X-Request-Id` that was sent — quote it to support to learn whether the request arrived.

A `2xx` whose body is not the expected JSON envelope (an HTML page, an empty body, a JSON object
without the resource) throws `ApiException` too — never an empty object passed off as a success.
After a create call it means "outcome unknown", not "refused": look the operation up.

## Retries and safe replays

The SDK retries on its own only what cannot duplicate an operation:

| Request | Retried on |
|---|---|
| Reads (`get`, `list`, `balance`, `operators`, `ping`, `me`) | network errors, 429, 502, 503, 504 |
| Writes (`create`, `confirmOtp`, `resendOtp`, `update`, `cancel`, `pause`, `resume`) | 429 only — the request was not processed |

Waits use exponential backoff with jitter and honour `Retry-After`. If the API asks for a longer
wait than `maxRetryDelay` (10 s by default — and never more than 10 s for a write), the error is
thrown at once with `getRetryAfter()` set, instead of blocking your request. `maxRetries`
(default 2) bounds the number of extra attempts.

**A network error, a timeout or a 5xx on a payment or a transfer is never retried
automatically**: the API has no idempotency key, and the operation may have gone through. To
replay safely yourself:

1. always send your own reference in `order.id`, with `order.unique => true` — the API then
   refuses a second operation with the same id (`ConflictException`);
2. on `NetworkException`, `ServiceUnavailableException` or `ServerException` (any 5xx), look
   the operation up first:
   `payments->list(['order_id' => $orderId])` / `transfers->list(['order_id' => $orderId])`;
3. only create it again if that list is empty.

An `order.id` sent with `unique` stays taken even when its payment or transfer failed: give
every new attempt its own id (`ORDER-42-2`, `ORDER-42-3`…).

## Rate limits

Limits are per route family and shared by all the API applications of a merchant. They are
operational settings: read them at runtime rather than hard-coding them.

```php
$barkapay->payments->list();

$limit = $barkapay->lastRateLimit();   // from the response that was just received
echo $limit?->remaining, ' / ', $limit?->limit, PHP_EOL;
```

A `RateLimitException` carries the same information: `getRetryAfter()` (seconds),
`getResponseHeader('x-ratelimit-limit')` and `getResponseHeader('x-ratelimit-remaining')`.

## Amounts

- Send `amount` as an integer or a decimal **string** (`10000`, `'1500.50'`) — not as the result
  of float arithmetic. At most two decimals.
- `amount` comes back as the API's JSON number; `fees`, balances and payment-link amounts come
  back as decimal strings (`"250.00"`). Use integers or `bcmath` for arithmetic and comparisons.

## Configuration

```php
use BarkaPay\Client;

$barkapay = new Client(
    apiKey:         getenv('BARKAPAY_API_KEY') ?: null,
    country:        'bf',
    baseUrl:        'https://hub.barkapay.com', // default
    maxRetries:     2,     // 0 disables automatic retries
    timeout:        40.0,  // seconds, whole request (the hub itself answers 503 after 30 s)
    connectTimeout: 10.0,  // seconds, connection only
    maxRetryDelay:  10.0,  // seconds, longest wait between two attempts
);
```

`baseUrl` must be `https://`. Plain `http://` is accepted for a loopback host only
(`localhost`, `127.0.0.1`, `[::1]`) — or, when you pass your own `httpClient`, for a mock server
on another host. It is always refused for a barkapay host.

To use your own HTTP stack, pass `httpClient:` an implementation of
`BarkaPay\Http\HttpClientInterface` (it must verify TLS and must not follow redirects). The
default transport accepts a custom CA bundle — `new CurlClient(caBundle: '/path/cacert.pem')` —
and offers no way to turn certificate verification off.

`$barkapay->lastRequestId()` returns the `X-Request-Id` of the latest call, for your logs.

## Security

- TLS certificates and host names are always verified; redirects are never followed (a `3xx`
  is an error); plain `http://` is refused outside of loopback (see Configuration).
- A response body larger than 10 MiB is refused (`NetworkException`).
- The API key never appears in exception messages nor in dumps of the client (`var_dump()`,
  `print_r()`, `var_export()`, `dd()`), and PHP 8.2+ redacts it from the stack traces of the SDK
  and of its default transport. The `Request` object handed to a custom HTTP client does carry
  the `Authorization` header: log `$request->redactedHeaders()`, never the object.
- Identifiers and country codes are validated and percent-encoded before they reach a URL: an
  empty identifier, `.`, `..`, or one containing `/`, `?`, `#` or a control character is refused
  before any request; a country code is two letters.
- See [SECURITY.md](SECURITY.md) to report a vulnerability.

## Upgrading from 1.x

2.0 is the same SDK under its final name: the package, the namespace and one interface were
renamed, nothing else changed in the API you call.

```bash
composer remove barkapay/payhub-php
composer require barkapay/barkapay-php:^2.0
```

- namespace `PayHub\` → `BarkaPay\` (a project-wide search and replace of `PayHub\` is enough);
- `PayHub\Exception\PayHubException` → `BarkaPay\Exception\BarkaPayException`;
- `Webhook::LEGACY_SIGNATURE_HEADER` is removed: the API only sends `Barkapay-Signature`
  (`$_SERVER['HTTP_BARKAPAY_SIGNATURE']`);
- the `User-Agent` sent to the API is now `barkapay-php/2.0.0 …`;
- the examples read `BARKAPAY_API_KEY` and `BARKAPAY_WEBHOOK_SECRET`.

Still on 1.0? Also read the 1.1 notes in [CHANGELOG.md](CHANGELOG.md): writes are no longer
replayed after a network error, a timeout or a 503 (they could be executed twice), the default
timeout is 40 s, `baseUrl` must be `https://`, an empty webhook secret is refused, `EXPIRED`
payments and `UNCERTAIN` transfers have an enum case. Deduplicate webhooks on `event_id`.

## License

MIT.
