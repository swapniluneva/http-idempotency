<?php

declare(strict_types=1);

namespace HttpIdempotency\Store;

use HttpIdempotency\Record\IdempotencyRecord;
use HttpIdempotency\Record\StoredResponse;

/**
 * Pluggable persistence + concurrency contract. All locking semantics live
 * behind {@see self::begin()} and {@see self::complete()} so each backend can
 * implement them with its own native atomic primitive (a UNIQUE constraint, a
 * Redis SET NX, etc.). The handler never needs to know which store is in use.
 */
interface StoreInterface
{
    /**
     * Atomically acquire the lock for {@param $lookupKey}, or classify why we
     * can't. Implementations MUST make the "create-if-absent" step atomic so
     * two concurrent callers cannot both receive {@see BeginResult::Acquired}.
     *
     * A record that has passed its expiry MUST be treated as absent and may be
     * taken over (returning {@see BeginResult::Acquired} with a fresh token).
     *
     * @param  string  $lookupKey  already-scoped, hashed storage key
     * @param  string  $idempotencyKey  raw client key, persisted for diagnostics
     * @param  string  $fingerprint  request fingerprint used for mismatch detection
     * @param  int  $ttlSeconds  lifetime of the new lock/record
     */
    public function begin(
        string $lookupKey,
        string $idempotencyKey,
        string $fingerprint,
        int $ttlSeconds,
    ): BeginOutcome;

    /**
     * Persist the final response and mark the record completed — but only if
     * {@param $lockToken} still matches the record's token (optimistic CAS).
     *
     * @return bool true if this caller still owned the lock and the write
     *              landed; false if the lock was lost (e.g. taken over after
     *              expiry), in which case the response MUST be discarded.
     */
    public function complete(string $lookupKey, string $lockToken, StoredResponse $response): bool;

    /**
     * Release a held lock so a legitimate retry need not wait for TTL. A no-op
     * if the token no longer matches. Used when the wrapped handler throws.
     */
    public function release(string $lookupKey, string $lockToken): void;

    public function get(string $lookupKey): ?IdempotencyRecord;

    /**
     * Remove expired records. Backends with native TTL may return 0.
     *
     * @return int number of records purged
     */
    public function purgeExpired(): int;
}
