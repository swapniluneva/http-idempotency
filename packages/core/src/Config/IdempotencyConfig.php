<?php

declare(strict_types=1);

namespace HttpIdempotency\Config;

/**
 * Immutable, framework-agnostic configuration for the idempotency handler and
 * fingerprinting. Framework adapters build this from their own config sources
 * (e.g. {@see self::fromArray()} for Laravel's config array).
 */
final readonly class IdempotencyConfig
{
    /**
     * @param  list<string>  $methods  HTTP methods the handler enforces (upper-case)
     * @param  list<string>  $fingerprintHeaders  request headers folded into the fingerprint (lower-case)
     * @param  list<string>  $replayHeaders  response headers persisted and replayed (lower-case)
     */
    public function __construct(
        public string $headerName = 'Idempotency-Key',
        public string $replayedHeaderName = 'Idempotency-Replayed',
        public int $maxKeyLength = 255,
        public int $maxBodyBytes = 1_048_576,
        public int $ttlSeconds = 86_400,
        public bool $keyRequired = true,
        public bool $cacheServerErrors = false,
        public array $methods = ['POST', 'PATCH'],
        public bool $fingerprintQueryString = true,
        public array $fingerprintHeaders = [],
        public array $replayHeaders = ['content-type', 'location'],
        public string $problemTypeBaseUri = 'https://httpidempotency.dev/problems',
    ) {}

    public function enforcesMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * Build from a loosely-typed config array (the shape published by the
     * Laravel adapter's config/idempotency.php). Unknown keys are ignored;
     * missing keys fall back to the constructor defaults.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $defaults = new self;
        $fingerprint = is_array($config['fingerprint'] ?? null) ? $config['fingerprint'] : [];

        return new self(
            headerName: self::str($config, 'header_name', $defaults->headerName),
            replayedHeaderName: self::str($config, 'replayed_header_name', $defaults->replayedHeaderName),
            maxKeyLength: self::int($config, 'max_key_length', $defaults->maxKeyLength),
            maxBodyBytes: self::int($config, 'max_body_bytes', $defaults->maxBodyBytes),
            ttlSeconds: self::int($config, 'ttl_seconds', $defaults->ttlSeconds),
            keyRequired: (bool) ($config['key_required'] ?? $defaults->keyRequired),
            cacheServerErrors: (bool) ($config['cache_server_errors'] ?? $defaults->cacheServerErrors),
            methods: self::upperList($config['methods'] ?? $defaults->methods),
            fingerprintQueryString: (bool) ($fingerprint['query_string'] ?? $defaults->fingerprintQueryString),
            fingerprintHeaders: self::lowerList($fingerprint['headers'] ?? $defaults->fingerprintHeaders),
            replayHeaders: self::lowerList($config['replay_headers'] ?? $defaults->replayHeaders),
            problemTypeBaseUri: self::str($config, 'problem_type_base_uri', $defaults->problemTypeBaseUri),
        );
    }

    /** @param array<string, mixed> $config */
    private static function str(array $config, string $key, string $default): string
    {
        $value = $config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @param array<string, mixed> $config */
    private static function int(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return list<string>
     */
    private static function upperList(mixed $value): array
    {
        return array_values(array_map(
            static fn (string $v): string => strtoupper(trim($v)),
            is_array($value) ? array_filter($value, 'is_string') : [],
        ));
    }

    /**
     * @return list<string>
     */
    private static function lowerList(mixed $value): array
    {
        return array_values(array_map(
            static fn (string $v): string => strtolower(trim($v)),
            is_array($value) ? array_filter($value, 'is_string') : [],
        ));
    }
}
