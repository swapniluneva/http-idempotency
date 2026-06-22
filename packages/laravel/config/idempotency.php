<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Store driver
    |--------------------------------------------------------------------------
    | Where idempotency records live: "database", "redis", or "array" (in-memory,
    | single process — for testing only). Each driver's connection is below.
    */
    'driver' => env('IDEMPOTENCY_DRIVER', 'database'),

    'database' => [
        'connection' => env('IDEMPOTENCY_DB_CONNECTION'), // null = default connection
        'table' => 'idempotency_keys',
    ],

    'redis' => [
        'connection' => env('IDEMPOTENCY_REDIS_CONNECTION', 'default'),
        'prefix' => 'idempotency:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request handling
    |--------------------------------------------------------------------------
    */
    'header_name' => 'Idempotency-Key',
    'replayed_header_name' => 'Idempotency-Replayed',

    // Methods the middleware enforces. The draft targets the non-idempotent ones.
    'methods' => ['POST', 'PATCH'],

    // When true, an enforced method without a key is rejected (400 MISSING_KEY).
    // Override per route with the middleware argument: ->middleware('idempotency:optional').
    'key_required' => true,

    'max_key_length' => 255,
    'max_body_bytes' => 1048576, // 1 MiB

    // Record lifetime in seconds; expired keys may be reused and are purged.
    'ttl_seconds' => 86400, // 24 hours

    // Persist 5xx responses for replay? Off by default so clients can retry them.
    'cache_server_errors' => false,

    /*
    |--------------------------------------------------------------------------
    | Fingerprinting (SHA-256)
    |--------------------------------------------------------------------------
    | A request reusing a key with a different fingerprint is rejected (422).
    */
    'fingerprint' => [
        'query_string' => true,
        // Request headers folded into the fingerprint, e.g. ['authorization'].
        'headers' => [],
    ],

    // Response headers captured and replayed alongside the stored body/status.
    'replay_headers' => ['content-type', 'location'],

    /*
    |--------------------------------------------------------------------------
    | RFC 9457 Problem Details
    |--------------------------------------------------------------------------
    | Base URI for the "type" member; each error appends a slug, e.g.
    | https://example.com/problems/fingerprint-mismatch
    */
    'problem_type_base_uri' => env('IDEMPOTENCY_PROBLEM_BASE_URI', 'https://httpidempotency.dev/problems'),

    /*
    |--------------------------------------------------------------------------
    | Expired record cleanup
    |--------------------------------------------------------------------------
    | Auto-schedule `idempotency:purge` daily. Redis self-expires via native TTL,
    | so this mainly matters for the database driver.
    */
    'purge' => [
        'schedule' => true,
        'cron' => '0 3 * * *',
    ],
];
