# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.1.0] - 2026-11-16

Backward compatible with 1.0.0. Brings the SDK in line with the current API and hardens it.

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
- `Webhook::SIGNATURE_HEADER` (`Barkapay-Signature`) and `Webhook::LEGACY_SIGNATURE_HEADER`;
  several `v1` signatures in one header are accepted (secret rotation).
- `Client` options `connectTimeout` and `maxRetryDelay`; `CurlClient` option `caBundle`.
- `NetworkException::isConnectionFailure()` and `getRequestId()`; `Request::redactedHeaders()`;
  `Response::header()`.
- CI on PHP 8.4 and 8.5, `composer audit`, and tests that lint every code sample of the README.

### Changed

- **Retries are now safe for money.** Reads are retried on network errors and on
  429 / 502 / 503 / 504. Writes (payments, transfers, OTP confirmation, payment-link changes)
  are retried only when the API provably did not process them: a 429, or a connection that was
  never established. They are no longer replayed after a timeout or a 503.
- Retries wait with exponential backoff and jitter and honour `Retry-After`; when the API asks
  for a longer wait than `maxRetryDelay` (5 s by default) the error is thrown immediately.
- The webhook header is now documented as `Barkapay-Signature` (renamed by the API on
  2026-11-15; the signature format is unchanged). Deduplicate deliveries on `event_id`.
- `fees` is always exposed as a decimal string, including on creation responses where the API
  sends a bare number.
- Documentation and messages use the barkapay brand. Package name and namespace are unchanged.

### Fixed

- The `User-Agent` reported version `0.1.0`; it now carries the real SDK version.
- `EXPIRED` payments and `UNCERTAIN` transfers hydrated with `status === null`.
- A response that was not the JSON envelope (redirect, HTML error page, empty body) was returned
  as an empty success — `payments->create()` could hand back an empty `Payment`. It now throws
  an `ApiException`.
- `payments->get('')` and friends silently hit the list endpoint and returned its first row;
  empty identifiers are now refused.
- An empty request body was sent as `[]` instead of `{}`.
- Request parameters that cannot be encoded as JSON raised a bare `JsonException`; they now
  raise `ConfigurationException`.
- Float amounts were encoded with spurious digits (`1234.5599999999999`) on hosts configured
  with `serialize_precision = 17`.

### Security

- The API key is no longer stored in a property of the client: `var_dump()`, `print_r()`,
  `var_export()`, `dd()` / `dump()` and debuggers cannot print it any more. `Request` masks it
  in `var_dump()` / `print_r()`, and it is marked `#[\SensitiveParameter]` so PHP 8.2+ redacts
  it from stack traces. A key containing whitespace or control characters is refused (header
  injection).
- Identifiers and country codes are validated before being placed in the URL: `.`, `..`, empty
  values and control characters are refused, everything else is percent-encoded. A value such
  as `..` could previously redirect a call to another endpoint.
- TLS verification is set explicitly and cannot be disabled; redirects are never followed and
  the transport is restricted to HTTP(S).
- Plain `http://` to the barkapay hosts is refused (it sent the API key in clear text before
  the redirect); base URLs carrying credentials, a query string or a fragment are refused.
- Webhooks: an empty signing secret never verifies; the header parser is strict (single
  numeric timestamp, 64-hex signatures, bounded length); all candidate signatures are compared
  in constant time.
- `payments->create()` refuses `channel: hosted`: the API ignores that field and would execute
  a direct payment instead of returning a link.
- A timeout of `0` (wait forever) is no longer passed to curl.

## [1.0.0] - 2026-06-27

### Added

- Initial PHP SDK: `Client` with `payments`, `transfers`, `balance`, `operators`
  resources plus `ping()` / `me()`.
- Typed DTOs (`Payment`, `Transfer`, `Balance`) and status enums.
- Webhook signature verification (`PayHub\Webhook`).
- Typed exception hierarchy mapped from the API error envelope.
- Zero-dependency curl transport with automatic retries on 429/503/network.

[Unreleased]: https://github.com/BarkaPay/payhub-php/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/BarkaPay/payhub-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/BarkaPay/payhub-php/releases/tag/v1.0.0
