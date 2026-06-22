<?php

declare(strict_types=1);

namespace HttpIdempotency\Store;

use HttpIdempotency\Clock\Clock;
use HttpIdempotency\Clock\SystemClock;
use HttpIdempotency\Record\IdempotencyRecord;
use HttpIdempotency\Record\RecordState;
use HttpIdempotency\Record\StoredResponse;

/**
 * In-process, single-worker store backed by a PHP array. Intended for tests and
 * single-process setups only — it shares nothing across workers/requests, so it
 * provides no distributed locking. Use the Database or Redis store in production.
 */
final class ArrayStore implements StoreInterface
{
    /** @var array<string, IdempotencyRecord> */
    private array $records = [];

    public function __construct(private readonly Clock $clock = new SystemClock) {}

    public function begin(
        string $lookupKey,
        string $idempotencyKey,
        string $fingerprint,
        int $ttlSeconds,
    ): BeginOutcome {
        $now = $this->clock->now();
        $existing = $this->records[$lookupKey] ?? null;

        // An expired record is treated as absent and taken over.
        if ($existing !== null && ! $existing->isExpired($now)) {
            if (! $existing->matchesFingerprint($fingerprint)) {
                return BeginOutcome::mismatch($existing);
            }

            if ($existing->isCompleted()) {
                return BeginOutcome::replay($existing);
            }

            return BeginOutcome::inProgress($existing);
        }

        $lockToken = bin2hex(random_bytes(16));
        $this->records[$lookupKey] = new IdempotencyRecord(
            lookupKey: $lookupKey,
            idempotencyKey: $idempotencyKey,
            fingerprint: $fingerprint,
            state: RecordState::Locked,
            lockToken: $lockToken,
            createdAt: $now,
            expiresAt: $now + $ttlSeconds,
        );

        return BeginOutcome::acquired($lockToken);
    }

    public function complete(string $lookupKey, string $lockToken, StoredResponse $response): bool
    {
        $existing = $this->records[$lookupKey] ?? null;
        if ($existing === null || ! hash_equals($existing->lockToken, $lockToken)) {
            return false;
        }

        $this->records[$lookupKey] = new IdempotencyRecord(
            lookupKey: $existing->lookupKey,
            idempotencyKey: $existing->idempotencyKey,
            fingerprint: $existing->fingerprint,
            state: RecordState::Completed,
            lockToken: $existing->lockToken,
            createdAt: $existing->createdAt,
            expiresAt: $existing->expiresAt,
            response: $response,
            completedAt: $this->clock->now(),
        );

        return true;
    }

    public function release(string $lookupKey, string $lockToken): void
    {
        $existing = $this->records[$lookupKey] ?? null;
        if ($existing === null || $existing->isCompleted()) {
            return;
        }

        if (hash_equals($existing->lockToken, $lockToken)) {
            unset($this->records[$lookupKey]);
        }
    }

    public function get(string $lookupKey): ?IdempotencyRecord
    {
        $existing = $this->records[$lookupKey] ?? null;
        if ($existing === null || $existing->isExpired($this->clock->now())) {
            return null;
        }

        return $existing;
    }

    public function purgeExpired(): int
    {
        $now = $this->clock->now();
        $purged = 0;

        foreach ($this->records as $key => $record) {
            if ($record->isExpired($now)) {
                unset($this->records[$key]);
                $purged++;
            }
        }

        return $purged;
    }
}
