# Security Policy

## Reporting a vulnerability

Please **do not** open a public issue for security vulnerabilities.

- Preferred: GitHub's [private vulnerability reporting](https://github.com/BarkaPay/payhub-php/security/advisories/new).
- Or email **security@payhub.africa** with the details and reproduction steps.

We aim to acknowledge reports within 72 hours, and to ship a fix or a mitigation for confirmed
issues as a patch release of the latest minor version.

## Supported versions

| Version | Security fixes |
|---|---|
| 1.1.x | yes |
| 1.0.x | no — upgrade to 1.1 (drop-in) |

## What the SDK guarantees

- **Transport** — the server certificate and host name are always verified; there is no option
  to disable it. Redirects are never followed, so the `Authorization` header cannot be sent to
  another URL. Plain `http://` to the barkapay hosts is refused.
- **Credentials** — the API key is never written to an exception message. It is not stored in
  any property of the client, so `var_dump()`, `print_r()`, `var_export()`, `dd()` / `dump()`
  and debuggers cannot print it. On PHP 8.2+ it is redacted from stack traces
  (`#[\SensitiveParameter]`).
- **URLs** — country codes and identifiers are validated and percent-encoded; relative path
  segments (`.`, `..`), empty values and control characters are refused.
- **Retries** — a request that moves money is never replayed unless the API provably did not
  process it.
- **Webhooks** — signatures are compared in constant time, stale or malformed headers are
  rejected, and an empty signing secret never verifies.

## What stays your responsibility

- Keep `sk_live_…` API keys and `whsec_…` webhook secrets out of your repository, your
  front-end and your logs. Load them from the environment or a secret manager; rotate a key
  from the dashboard as soon as you suspect a leak.
- A custom HTTP client receives the real `Authorization` header in
  `PayHub\Http\Request::$headers`: log `redactedHeaders()` instead, and do not dump the request
  with tools other than `var_dump()` / `print_r()`.
- `serialize($client)` deliberately carries the API key (so a queued job can use the client):
  treat that payload as a secret, or better, serialize your own configuration and rebuild the
  client.
- On PHP 8.1, keep `zend.exception_ignore_args = On` (the production default) so arguments are
  not captured in stack traces.
- Always verify webhooks against the **raw** request body (`PayHub\Webhook::parse()` /
  `verify()`) before trusting them, keep the default 300-second tolerance, and deduplicate on
  `event_id`.
- A custom `HttpClientInterface` must verify TLS and must not follow redirects.
- Restrict your API application to the scopes and IP addresses it needs.
