<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Store;

use HttpIdempotency\Clock\Clock;
use HttpIdempotency\Clock\SystemClock;
use HttpIdempotency\Record\IdempotencyRecord;
use HttpIdempotency\Record\RecordState;
use HttpIdempotency\Record\StoredResponse;
use HttpIdempotency\Store\BeginOutcome;
use HttpIdempotency\Store\StoreInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;

/**
 * Relational store. Concurrency rests on the UNIQUE constraint on `lookup_key`:
 * the first inserter wins the lock; everyone else hits a unique violation and
 * inspects the existing row. The final write is an optimistic CAS on the
 * lock token, so a request that lost its lock (e.g. via expiry takeover) cannot
 * overwrite the new owner's record.
 */
final class DatabaseStore implements StoreInterface
{
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $table,
        private readonly Clock $clock = new SystemClock,
    ) {}

    public function begin(
        string $lookupKey,
        string $idempotencyKey,
        string $fingerprint,
        int $ttlSeconds,
    ): BeginOutcome {
        $now = $this->clock->now();
        $lockToken = $this->newToken();

        try {
            $this->table()->insert([
                'lookup_key' => $lookupKey,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'state' => RecordState::Locked->value,
                'lock_token' => $lockToken,
                'response_status' => null,
                'response_headers' => null,
                'response_body' => null,
                'created_at' => $now,
                'expires_at' => $now + $ttlSeconds,
                'completed_at' => null,
            ]);

            return BeginOutcome::acquired($lockToken);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        // A row already exists. If it has expired, atomically take it over.
        $takeoverToken = $this->newToken();
        $taken = $this->table()
            ->where('lookup_key', $lookupKey)
            ->where('expires_at', '<=', $now)
            ->update([
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'state' => RecordState::Locked->value,
                'lock_token' => $takeoverToken,
                'response_status' => null,
                'response_headers' => null,
                'response_body' => null,
                'created_at' => $now,
                'expires_at' => $now + $ttlSeconds,
                'completed_at' => null,
            ]);

        if ($taken > 0) {
            return BeginOutcome::acquired($takeoverToken);
        }

        $record = $this->get($lookupKey);
        if ($record === null) {
            // Lost a race (row purged/taken between our failed insert and read):
            // retry once from the top.
            return $this->begin($lookupKey, $idempotencyKey, $fingerprint, $ttlSeconds);
        }

        if (! $record->matchesFingerprint($fingerprint)) {
            return BeginOutcome::mismatch($record);
        }

        if ($record->isCompleted()) {
            return BeginOutcome::replay($record);
        }

        return BeginOutcome::inProgress($record);
    }

    public function complete(string $lookupKey, string $lockToken, StoredResponse $response): bool
    {
        $affected = $this->table()
            ->where('lookup_key', $lookupKey)
            ->where('lock_token', $lockToken)
            ->update([
                'state' => RecordState::Completed->value,
                'response_status' => $response->status,
                'response_headers' => json_encode($response->headers),
                'response_body' => base64_encode($response->body),
                'completed_at' => $this->clock->now(),
            ]);

        return $affected > 0;
    }

    public function release(string $lookupKey, string $lockToken): void
    {
        $this->table()
            ->where('lookup_key', $lookupKey)
            ->where('lock_token', $lockToken)
            ->where('state', RecordState::Locked->value)
            ->delete();
    }

    public function get(string $lookupKey): ?IdempotencyRecord
    {
        $row = $this->table()->where('lookup_key', $lookupKey)->first();
        if ($row === null) {
            return null;
        }

        $data = (array) $row;
        if ((int) $data['expires_at'] <= $this->clock->now()) {
            return null;
        }

        return $this->hydrate($data);
    }

    public function purgeExpired(): int
    {
        return $this->table()->where('expires_at', '<=', $this->clock->now())->delete();
    }

    private function table(): Builder
    {
        return $this->connection->table($this->table);
    }

    private function newToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000/23505 across MySQL, Postgres and SQLite.
        return in_array($e->getCode(), ['23000', '23505'], true);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hydrate(array $data): IdempotencyRecord
    {
        $state = RecordState::from((string) $data['state']);
        $response = null;

        if ($state === RecordState::Completed && $data['response_status'] !== null) {
            /** @var array<string, list<string>> $headers */
            $headers = json_decode((string) ($data['response_headers'] ?? '{}'), true) ?: [];
            $response = new StoredResponse(
                status: (int) $data['response_status'],
                headers: $headers,
                body: base64_decode((string) ($data['response_body'] ?? ''), true) ?: '',
            );
        }

        return new IdempotencyRecord(
            lookupKey: (string) $data['lookup_key'],
            idempotencyKey: (string) $data['idempotency_key'],
            fingerprint: (string) $data['fingerprint'],
            state: $state,
            lockToken: (string) $data['lock_token'],
            createdAt: (int) $data['created_at'],
            expiresAt: (int) $data['expires_at'],
            response: $response,
            completedAt: $data['completed_at'] !== null ? (int) $data['completed_at'] : null,
        );
    }
}
