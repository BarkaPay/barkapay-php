# Contributing

Thanks for helping improve the barkapay PHP SDK.

## Development setup

```bash
composer install
```

## Checks (must pass before opening a PR)

```bash
composer test     # PHPUnit
composer stan     # PHPStan (level max) on src, tests and examples
composer cs       # php-cs-fixer (dry-run) — run `composer cs-fix` to apply
composer audit    # known vulnerabilities in the (dev) dependencies
```

CI runs them on PHP 8.1 to 8.5.

## Scope & conventions

- The SDK mirrors the public API: keep the surface in sync with it, and identical in spirit to
  the Node.js and Python SDKs (same resource and method names).
- No runtime dependencies: stick to `ext-curl` + `ext-json` so the SDK stays safe to embed
  inside WordPress / WooCommerce.
- Backward compatibility: new constructor parameters go last with a default; DTOs must keep
  tolerating unknown fields and unknown enum values.
- Never weaken the safety rules: no option to disable TLS verification, no redirect following,
  no automatic replay of a request that moves money, no credential in a message or a dump.
- Add or update tests with every behaviour change; never call the live API from tests (use the
  injectable `HttpClientInterface`). Every PHP sample in the README is linted by the test suite.
- Open an issue first for larger changes so we can align on the design.

## Releasing (Packagist)

Packagist serves `barkapay/barkapay-php` straight from Git tags — there is no publish workflow to
run. To cut a release:

1. set `BarkaPay\Client::VERSION` and add the version section to `CHANGELOG.md` (a test checks
   that the two agree);
2. merge to `main` and wait for a green CI;
3. tag and push:

```bash
git tag -a vX.Y.Z -m "vX.Y.Z"
git push origin vX.Y.Z
```

Packagist picks up the new tag through the GitHub webhook. The package follows SemVer; tags
must be `vX.Y.Z`.
