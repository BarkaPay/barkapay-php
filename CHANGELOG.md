# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [2.0.0] - 2026-11-16

The SDK takes its final name. 2.0 is a pure rename of 1.1.0: same resources, same methods,
same retry rules (reads replayed on 429/502/503/504/network errors; writes only on a 429 asking
for 10 s or less), same behaviour.

### Changed (breaking)

- Composer package `barkapay/payhub-php` → **`barkapay/barkapay-php`**. The old package is
  abandoned in favour of the new one and receives no further release.
- Namespace `PayHub\` → **`BarkaPay\`**.
- `PayHubException` (the interface every SDK exception implements) → **`BarkaPayException`**.
- `User-Agent` sent to the API: `barkapay-php/<version> php/<version>`.
- Examples and documentation read `BARKAPAY_API_KEY` and `BARKAPAY_WEBHOOK_SECRET`.
- Repository moved to `github.com/BarkaPay/barkapay-php`; documentation at
  `https://barkapay.com/developers`.

### Removed

- `Webhook::LEGACY_SIGNATURE_HEADER` (`PayHub-Signature`): the API no longer sends that header,
  only `Barkapay-Signature` (`Webhook::SIGNATURE_HEADER`).

### Migrating from 1.x

1. `composer remove barkapay/payhub-php && composer require barkapay/barkapay-php:^2.0`
2. Replace `PayHub\` with `BarkaPay\` and `PayHubException` with `BarkaPayException` in your
   code.
3. If you read `Webhook::LEGACY_SIGNATURE_HEADER`, drop it and read `Webhook::SIGNATURE_HEADER`.

## [1.1.0] - 2026-11-16

Backward compatible with 1.0.0: no public name was removed and every 1.0.0 call keeps its
signature. Several behaviours are deliberately stricter or safer — read "Behaviour changes to
check when upgrading" below.

### Security

- The API key is no longer stored in a property of the client: `var_dump()`, `print_r()`,
  `var_export()`, `dd()` / `dump()` and debuggers cannot print it any more. `Request` masks it
  in `var_dump()` / `print_r()`, and it is marked `#[\SensitiveParameter]` so PHP 8.2+ redacts
  it from stack traces. A key containing whitespace or control characters is refused (header
  injection).
- Identifiers and country codes are validated before being placed in the URL: `.`, `..`, empty
  values, `/`, `?`, `#` and control characters are refused, everything else is
  percent-encoded; a country code is two letters. A value such as `..` could previously
  redirect a call to another endpoint.
- TLS verification is set explicitly and cannot be disabled; redirects are never followed and
  the transport is restricted to HTTP(S).
- **No clear-text key.** Plain `http://` is refused for a barkapay host, and for any other host
  that is not loopback (`localhost`, `127.0.0.1`, `[::1]`) unless you pass your own
  `httpClient` (a mock server); base URLs carrying credentials, a query string or a fragment
  are refused.
- Webhooks: an empty signing secret or a tolerance of 0 or less throws `ConfigurationException`
  (1.0.0 used an empty secret as the HMAC key, and 0 switched the replay check off); the header
  parser is strict (single numeric timestamp, 64-hex signatures, bounded length); all candidate
  signatures are compared in constant time.
- A response body larger than 10 MiB is refused (`NetworkException`), whether it announces its
  size or streams it.
- Timeouts are clamped to 1 ms – 10 min: `0`, sub-millisecond, infinite or overflowing values
  can no longer reach curl as "wait forever".
- `Request` masks the key in `json_encode()` too, and the default transport keeps the request
  out of stack traces (PHP 8.2+), including when curl itself rejects the options.
- Base URLs containing whitespace, control or non-ASCII characters are refused.

### Added

- **Payment links** — new `$client->paymentLinks` resource: `create()`, `list()`, `get()`,
  `update()`, `cancel()`, `pause()`, `resume()` and `listPayments()`, with the `PaymentLink`,
  `PaymentLinkAmount`, `PaymentLinkStats` and `PaymentLinkCollection` objects and the
  `PaymentLinkStatus` enum.
- `Payment` and `Transfer`: `reasonCode`, `providerReason`, `providerReference`, `rawStatus`,
  plus `linkPublicId` and `redirectUrl` on payments and `appLabel` on transfers; `isFinal()` and
  `isSuccessful()` helpers.
- `PaymentStatus::Expired` and `TransferStatus::Uncertain`; `ReasonCode` enum with the API's
  failure-code catalogue.
- Typed errors for the API's business failures: `PaymentFailedException` and
  `TransferFailedException` (HTTP 424) and `InsufficientBalanceException` (HTTP 400).
- `ApiException`: `getPayment()` / `getTransfer()` (the attempt recorded by the API on a failed
  creation), `getData()`, `getRetryAfter()`, `getResponseHeaders()`, `getResponseHeader()` and
  `getResponseBody()`.
- `Client::lastRateLimit()` (`X-RateLimit-Limit` / `X-RateLimit-Remaining` of the latest
  response) and `Client::lastRequestId()`.
- `Pagination::hasMore()`; `PaymentCollection::$paymentLink` (set by
  `paymentLinks->listPayments()`).
- `Webhook::SIGNATURE_HEADER` (`Barkapay-Signature`) and `Webhook::LEGACY_SIGNATURE_HEADER`.
  A header carrying several `v1` signatures is tolerated (the API sends one).
- `Client` options `connectTimeout` and `maxRetryDelay`; `CurlClient` option `caBundle`.
- `NetworkException::isConnectionFailure()` and `getRequestId()`; `Request::redactedHeaders()`;
  `Response::header()`.
- CI on PHP 8.4 and 8.5, `composer audit`, and tests that lint every code sample of the README.

### Changed

- **Retries are now safe for money.** Reads are retried on network errors and on
  429 / 502 / 503 / 504. Writes (payments, transfers, OTP confirmation, payment-link changes)
  are retried on 429 only — the one case where the API did not process the request. They are
  no longer replayed after a network error, a timeout or a 503: the API has no idempotency key,
  and a blind replay could move money twice.
- Retries wait with exponential backoff and jitter and honour `Retry-After`; when the API asks
  for a longer wait than `maxRetryDelay` (10 s by default, and never more than 10 s for a
  write) the error is thrown immediately.
- `maxRetries` is capped at 10 (`Client::MAX_RETRIES`), like the Node and Python SDKs.
- **Default timeout raised from 30 to 40 seconds** (connection: 10 s), so the SDK receives the
  hub's own answer (it gives a gateway 30 seconds, then answers 503) instead of timing out on an
  unknown outcome.
- The webhook header is now documented as `Barkapay-Signature` (renamed by the API on
  2026-11-15; the signature format is unchanged). Deduplicate deliveries on `event_id`.
- `fees` is always exposed as a decimal string, including on creation responses where the API
  sends a bare number.
- Documentation and messages use the barkapay brand. Package name and namespace are unchanged.

### Behaviour changes to check when upgrading

- Writes are no longer re-sent after a 503, a 5xx or a network error (see Security): handle
  `ServiceUnavailableException`, `ServerException` and `NetworkException` yourself, after
  checking with `list(['order_id' => …])` that the operation was not recorded.
- A `Retry-After` above `maxRetryDelay` (10 s) — or above 10 s for a write — is no longer slept
  on: `RateLimitException` is thrown at once with `getRetryAfter()`.
- A plain-`http` `baseUrl` on a non-loopback host now throws `ConfigurationException` unless you
  pass your own `httpClient`.
- `Webhook::verify()` / `parse()` throw `ConfigurationException` for an empty secret or a
  tolerance of 0 or less.
- An identifier containing `/`, `?` or `#` throws `ConfigurationException` before any request.

### Fixed

- The `User-Agent` reported version `0.1.0`; it now carries the real SDK version.
- `EXPIRED` payments and `UNCERTAIN` transfers hydrated with `status === null`.
- A response that was not the success envelope (redirect, HTML error page, empty body, foreign
  JSON), or a success envelope without the resource the call is about, was returned as an empty
  success — `payments->create()` could hand back an empty `Payment`, a list could come back
  empty. Both now throw an `ApiException`.
- `payments->get('')` and friends silently hit the list endpoint and returned its first row;
  empty identifiers are now refused. An identifier containing `/` (which the API can only
  answer with a misleading 404) is refused with a pointer to `list(['order_id' => …])`.
- The SDK called `ctype_digit()` without declaring `ext-ctype`; it no longer uses that
  extension.
- Header values handed over as lists by a custom transport (PSR-7 style) are accepted.
- An empty request body was sent as `[]` instead of `{}`.
- Request parameters that cannot be encoded as JSON raised a bare `JsonException`; they now
  raise `ConfigurationException`.
- Float amounts were encoded with spurious digits (`1234.5599999999999`) on hosts configured
  with `serialize_precision = 17`.

## [1.0.0] - 2026-06-27

### Added

- Initial PHP SDK: `Client` with `payments`, `transfers`, `balance`, `operators`
  resources plus `ping()` / `me()`.
- Typed DTOs (`Payment`, `Transfer`, `Balance`) and status enums.
- Webhook signature verification (`BarkaPay\Webhook`).
- Typed exception hierarchy mapped from the API error envelope.
- Zero-dependency curl transport with automatic retries on 429/503/network.

[Unreleased]: https://github.com/BarkaPay/barkapay-php/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/BarkaPay/barkapay-php/compare/v1.1.0...v2.0.0
[1.1.0]: https://github.com/BarkaPay/barkapay-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/BarkaPay/barkapay-php/releases/tag/v1.0.0
