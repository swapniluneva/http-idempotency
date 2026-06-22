# HTTP Idempotency for PHP

[![CI](https://github.com/swapniluneva/http-idempotency/actions/workflows/ci.yml/badge.svg)](https://github.com/swapniluneva/http-idempotency/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.2%20|%208.3%20|%208.4-777bb4.svg)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-11%20|%2012-ff2d20.svg)](https://laravel.com/)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Safe retries for mutating HTTP requests in Laravel. A client sends an
`Idempotency-Key` header; the server processes the first request once, stores
the result, and **replays** that exact response for any retry carrying the same
key — so a flaky network or an impatient user never creates two orders or
charges a card twice.

Implements the IETF
[`Idempotency-Key` HTTP header draft](https://ietf-wg-httpapi.github.io/idempotency/draft-ietf-httpapi-idempotency-key-header.html),
with RFC 9457 problem-detail errors. The design also draws on
[Stripe's idempotency approach](https://stripe.com/blog/idempotency).
See [References](#references).

```php
Route::post('/orders')->middleware('idempotency');
```

That's the whole integration. Everything below is detail.

---

## Contents

- [Quick Start](#quick-start)
- [Installation](#installation)
- [Configuration](#configuration)
- [Redis Setup](#redis-setup)
- [Behaviour & error codes](#behaviour--error-codes)
- [Common Use Cases](#common-use-cases)
  - [Payments](#payments)
  - [Order creation](#order-creation)
  - [Webhooks](#webhooks)
  - [Mobile retries](#mobile-retries)
- [FAQ](#faq)
- [References](#references)

---

## Quick Start

Add the middleware to any route that creates or mutates state:

```php
use Illuminate\Support\Facades\Route;

Route::post('/orders')->middleware('idempotency');
```

Clients send a unique key per logical operation (a UUID is ideal):

```http
POST /orders HTTP/1.1
Idempotency-Key: 8e03978e-40d5-43e8-bc93-6894a57f9324
Content-Type: application/json

{"sku": "ABC-123", "qty": 2}
```

- **First call** → runs normally, response is stored.
- **Retry with the same key + body** → the stored response is returned verbatim
  with an extra `Idempotency-Replayed: true` header. Your controller does **not**
  run again.
- **Retry while the first is still in flight** → `409 Conflict`.
- **Same key, _different_ body** → `422 Unprocessable Entity`.

Per-route overrides:

```php
Route::post('/orders')->middleware('idempotency:required');  // 400 if the key is missing
Route::post('/notes')->middleware('idempotency:optional');   // no key? just pass through
```

---

## Installation

```bash
composer require httpidempotency/laravel
```

The service provider is auto-discovered. Publish the config and (for the
database driver) run the migration:

```bash
php artisan vendor:publish --tag=idempotency-config
php artisan migrate
```

> Requires PHP 8.2+ and Laravel 11 or 12.

---

## Configuration

`php artisan vendor:publish --tag=idempotency-config` writes
`config/idempotency.php`. The defaults are production-sane; the keys you are
most likely to touch:

```php
return [
    // 'database' | 'redis' | 'array' (array = in-memory, tests only)
    'driver' => env('IDEMPOTENCY_DRIVER', 'database'),

    'header_name'  => 'Idempotency-Key',
    'methods'      => ['POST', 'PATCH'],   // which verbs are enforced
    'key_required' => true,                // 400 MISSING_KEY when absent

    'max_key_length' => 255,
    'max_body_bytes' => 1048576,           // 1 MiB
    'ttl_seconds'    => 86400,             // how long a key is remembered (24h)

    // Persist 5xx responses? Off by default so clients can safely retry them.
    'cache_server_errors' => false,

    // What makes two requests "the same" (SHA-256 fingerprint):
    'fingerprint' => [
        'query_string' => true,
        'headers'      => [],              // e.g. ['authorization'] to scope per token
    ],

    // Response headers captured and replayed alongside the body/status:
    'replay_headers' => ['content-type', 'location'],

    // RFC 9457 "type" URI base; each error appends a slug.
    'problem_type_base_uri' => 'https://your-app.test/problems',

    // Expired-record cleanup (database driver; Redis self-expires).
    'purge' => ['schedule' => true, 'cron' => '0 3 * * *'],
];
```

Publish the migration too if you want to edit it:

```bash
php artisan vendor:publish --tag=idempotency-migrations
```

---

## Redis Setup

Redis is the recommended driver for distributed / high-concurrency deployments:
locking is enforced by an atomic server-side Lua script, and keys expire via
Redis' native TTL (no cleanup job needed).

1. Make sure you have a Redis connection (Laravel ships one; `phpredis` or
   `predis` both work).
2. Point the driver at it:

```env
IDEMPOTENCY_DRIVER=redis
```

```php
// config/idempotency.php
'redis' => [
    'connection' => 'default',   // a key under config/database.php "redis"
    'prefix'     => 'idempotency:',
],
```

No migration is required for Redis. Expiry is automatic, so
`idempotency:purge` reports nothing to do.

> **Database vs Redis** — both are safe under concurrency. The database driver
> uses a `UNIQUE` constraint to elect the single winner; Redis uses `SET NX`
> inside a Lua script. Pick Redis when you have many app servers and high write
> rates; the database driver is perfectly fine for moderate load and one fewer
> moving part.

---

## Behaviour & error codes

Errors are returned as `application/problem+json` (RFC 9457) with a stable
machine-readable `code`:

| Scenario | HTTP | `code` |
| --- | --- | --- |
| Required `Idempotency-Key` missing | `400` | `MISSING_KEY` |
| Key longer than `max_key_length` | `400` | `KEY_TOO_LONG` |
| Body over `max_body_bytes` | `413` | `BODY_TOO_LARGE` |
| Same key reused with a different request | `422` | `FINGERPRINT_MISMATCH` |
| Retry while the original is still in flight | `409` | `CONFLICT` |
| Key seen, original completed | original status | replayed (`Idempotency-Replayed: true`) |
| First time | processed normally | — |

Example error body:

```json
{
  "type": "https://your-app.test/problems/conflict",
  "title": "A request with this Idempotency-Key is already in progress",
  "status": 409,
  "code": "CONFLICT"
}
```

---

## Common Use Cases

### Payments

The canonical case: never charge a customer twice because the response timed out.

```php
Route::post('/payments')->middleware('idempotency');
```

The client generates one key per payment attempt and reuses it on every retry of
*that* attempt:

```js
const key = crypto.randomUUID();
await fetch('/payments', {
  method: 'POST',
  headers: { 'Idempotency-Key': key, 'Content-Type': 'application/json' },
  body: JSON.stringify({ amount: 4999, currency: 'usd' }),
});
// Network blip? Retry with the SAME key — you get the original result, not a 2nd charge.
```

Tip: keep `cache_server_errors => false` (the default) so a `502` from your
payment gateway can be retried rather than being replayed forever.

### Order creation

Stop double-submits from double-clicks or back-button resubmits.

```php
Route::post('/orders')->middleware('idempotency:required');
```

Requiring the key (`:required`) guarantees every order-creation call is
deduplicated — a missing key is rejected with `400 MISSING_KEY` instead of
silently creating a duplicate.

### Webhooks

Providers (Stripe, GitHub, …) retry webhooks and may deliver the same event more
than once. Use the provider's event id as the idempotency key so your handler
runs exactly once per event. Point `header_name` at the provider's id header:

```php
// config/idempotency.php  (or a dedicated route group / config override)
'header_name' => 'Stripe-Event-Id',
```

```php
Route::post('/webhooks/stripe')->middleware('idempotency');
```

A re-delivered event now replays the stored `2xx` instead of reprocessing.
(If a provider sends no stable id header, have a tiny upstream middleware copy
one into `Idempotency-Key`.)

### Mobile retries

Mobile clients on flaky networks retry aggressively. Generate the key once when
the user taps the button and reuse it for the lifetime of that action:

```kotlin
val key = UUID.randomUUID().toString()   // created when the user taps "Pay"
// reuse `key` across all automatic retries of this request
```

The user can lose signal mid-request and your app can retry safely — the server
either finishes the original or replays its result.

---

## FAQ

**What happens if two requests arrive simultaneously?**
Exactly one wins. Acquiring the key is a single atomic operation — a Redis
`SET NX` (Lua) on the Redis driver, or an `INSERT` against a `UNIQUE` constraint
on the database driver. The winner runs your code; the other sees the original
in flight and gets `409 Conflict`. Once the winner finishes, retries replay its
stored response. There is no window where both run. (This is covered by a test
that forks 30 real OS processes racing the same key and asserts a single
winner.)

**What if the first request crashes or never finishes?**
If the controller throws, the lock is released immediately so a retry can run.
If the process is killed outright, the record expires after `ttl_seconds` and
the key becomes usable again. `5xx` responses are not cached by default, so they
stay retryable.

**Do I need Redis?**
No. The default `database` driver is fully concurrency-safe. Choose Redis for
many app servers / high write rates and to skip the cleanup job (native TTL).

**What counts as "the same request"?**
A SHA-256 fingerprint of method + path + (optionally) query string + body, plus
any headers you list under `fingerprint.headers`. Same key + same fingerprint →
replay. Same key + different fingerprint → `422 FINGERPRINT_MISMATCH`.

**Which HTTP methods are protected?**
`POST` and `PATCH` by default (the non-idempotent verbs). Configurable via
`methods`. `GET`/`PUT`/`DELETE` pass straight through.

**How long are keys remembered?**
`ttl_seconds` (24h by default). The database driver purges expired rows via the
auto-scheduled `php artisan idempotency:purge`; Redis expires them natively.

**How should clients generate keys?**
A UUID (v4) per logical operation. The key must be **reused across retries of the
same operation** and be **new for a genuinely new operation**.

**Can the same key be used by different users safely?**
Bind your own `HttpIdempotency\Engine\ScopeResolver` in the container to
namespace keys per authenticated user/tenant, so identical keys from different
callers never collide.

---

## Packages & development

This repo ships two Composer packages (PHP namespace `HttpIdempotency\`):

| Package | What it is |
| --- | --- |
| `httpidempotency/core` | Framework-agnostic engine (PSR-7/PSR-15): key validation, SHA-256 fingerprinting, the decision engine, RFC 9457 problem details, and the pluggable store contract. No framework deps. |
| `httpidempotency/laravel` | Laravel middleware, service provider, config, database/Redis stores, purge command. |

```bash
(cd packages/core && composer install)
(cd packages/laravel && composer install)
composer install            # root: installs Pint

composer lint               # Pint (style check)        composer fix   # apply
composer test:core          # core unit + store-contract tests
composer test:laravel       # Laravel feature + store + concurrency tests
composer stan:core          # PHPStan (max) on the core
composer stan:laravel       # PHPStan on the Laravel adapter
(cd packages/core && composer bench)   # PHPBench fingerprint/store benchmarks
```

Redis tests are skipped unless a server is reachable (`REDIS_HOST`/`REDIS_PORT`).
Cloudflare KV/D1 stores (over Cloudflare's REST API) are planned — the
`StoreInterface` already accommodates them.

### Releasing (monorepo → Packagist)

The two packages are developed here but published from individual read-only
repositories that Packagist installs from. In development, the Laravel package
resolves `httpidempotency/core` through a local `path` repository (symlink);
when published, Composer ignores that and pulls core from Packagist via the
`^0.1` constraint.

One-time setup:

1. Create the two target repos and submit each to Packagist (it reads the name
   from the split repo's `composer.json`: `httpidempotency/core`,
   `httpidempotency/laravel`).
2. Add an `ACCESS_TOKEN` secret (a PAT with `repo` scope) to this repo so the
   split workflow can push.

Each release:

```bash
composer mono:validate                 # all packages share consistent dep versions
composer mono:bump -- 0.2.0            # sync inter-package constraints (optional)
git tag v0.1.0 && git push --tags      # .github/workflows/split.yml mirrors + tags both repos
```

`.github/workflows/split.yml` mirrors `packages/*` to the split repos on every
push to `main`, and propagates tags so Packagist publishes the matching release.

## References

- **IETF draft — [The Idempotency-Key HTTP Header Field](https://ietf-wg-httpapi.github.io/idempotency/draft-ietf-httpapi-idempotency-key-header.html)** (HTTP API Working Group). The specification this library implements: header syntax, the missing-key (400), in-progress (409) and reuse (422) responses, and key-retention guidance.
  - Tracker / revisions: <https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/>
- **[Designing robust and predictable APIs with idempotency](https://stripe.com/blog/idempotency)** — Stripe's engineering blog. Background and rationale for idempotency keys on mutating endpoints; informed the fingerprinting and concurrency-handling design here.
- **[RFC 9457 — Problem Details for HTTP APIs](https://www.rfc-editor.org/rfc/rfc9457)** — the `application/problem+json` error format used for every rejection.

## License

MIT.
