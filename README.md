# watchtowerobservability/laravel

Watchtower for Laravel, over `watchtowerobservability/sdk` (the thin OpenTelemetry PHP distribution).
Exceptions with trace ids, queue job spans, Eloquent/query spans and a log channel.

Status: 0.x, early. APIs may change between minor versions.

## Install

```sh
composer config repositories.watchtower-php vcs https://github.com/watchtowerobservability/watchtower-php
composer config repositories.watchtower-laravel vcs https://github.com/watchtowerobservability/watchtower-laravel
composer require watchtowerobservability/laravel
php artisan vendor:publish --tag=watchtower-config   # optional
```

## Quick start

```dotenv
WT_INGEST_KEY=...            # secret ingest key; never commit it
WT_OTLP_ENDPOINT=https://ingest.example.com
WT_RELEASE=2026.10.31
```

```php
// config/logging.php
'channels' => ['watchtower' => ['driver' => 'watchtower', 'level' => 'error']],
```

Auto-discovery registers the provider. Every reported exception, failed job and slow query now lands in
Watchtower linked by trace id. Capture by hand with `app(\Watchtower\Laravel\Reporter::class)->capture($e)`,
which returns the trace id.

## Integrations

| Feature | How |
|---|---|
| Exception handler | `reportable()` hook on the app handler; respects `$dontReport` |
| Queue jobs | consumer span per job, exception on the span, flush after each job |
| Eloquent / query builder | client span per query (`db.query.text`, bindings never recorded) |
| Log channel | `driver => watchtower`: exceptions in context are captured, other records become span events |

## Configuration

| Env | Config key | Default |
|---|---|---|
| `WT_INGEST_KEY` | `key` | none (masked in any debug output) |
| `WT_OTLP_ENDPOINT` | `endpoint` | `http://localhost:4318` (`WT_ENDPOINT` accepted, deprecated) |
| `WT_SERVICE` | `service` | `APP_NAME` |
| `WT_ENVIRONMENT` | `environment` | `APP_ENV` |
| `WT_RELEASE` | `release` | none |
| `WT_ENABLED` | `enabled` | `true` |

`queue_spans` and `query_spans` toggle the two listeners. Incoming `wt.*` attributes are dropped.

## Provenance

Every release artifact carries a GitHub build attestation. Verify one with
`gh attestation verify <file> --repo watchtowerobservability/watchtower-laravel`.

## License

Apache-2.0. Source of truth is the Watchtower monorepo; this repository is a published mirror.
