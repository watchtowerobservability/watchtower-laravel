<?php

// The key is read from the environment only; never commit it.
return [
    'enabled' => env('WT_ENABLED', true),
    'service' => env('WT_SERVICE', env('APP_NAME', 'laravel')),
    'endpoint' => env('WT_OTLP_ENDPOINT', env('WT_ENDPOINT')), // WT_ENDPOINT: deprecated alias
    'key' => env('WT_INGEST_KEY'),
    'environment' => env('WT_ENVIRONMENT', env('APP_ENV')),
    'release' => env('WT_RELEASE'),
    'queue_spans' => true,
    'query_spans' => true,
];
