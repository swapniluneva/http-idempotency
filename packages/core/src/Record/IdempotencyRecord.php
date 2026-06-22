<?php

declare(strict_types=1);

namespace HttpIdempotency\Record;

/**
 * The persisted state for a single idempotency lookup key: its fingerprint, its
 * lifecycle state, the lock token of the owning request, expiry, and (once
 * completed) the captured response to replay.
 *
 * Timestamps are Unix seconds so every store backend can persist them uniformly.
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public string $lookupKey,
        public string $idempotencyKey,
        public string $fingerprint,
        public RecordState $state,
        public string $lockToken,
        public int $createdAt,
        public int $expiresAt,
        public ?StoredResponse $response = null,
        public ?int $completedAt = null,
    ) {}

    public function isCompleted(): bool
    {
        return $this->state === RecordState::Completed;
    }

    public function isExpired(int $now): bool
    {
        return $this->expiresAt <= $now;
    }

    public function matchesFingerprint(string $fingerprint): bool
    {
        return hash_equals($this->fingerprint, $fingerprint);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lookup_key' => $this->lookupKey,
            'idempotency_key' => $this->idempotencyKey,
            'fingerprint' => $this->fingerprint,
            'state' => $this->state->value,
            'lock_token' => $this->lockToken,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'completed_at' => $this->completedAt,
            'response' => $this->response?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array{status: int, headers: array<string, list<string>>, body: string}|null $response */
        $response = $data['response'] ?? null;

        return new self(
            lookupKey: self::asString($data['lookup_key'] ?? ''),
            idempotencyKey: self::asString($data['idempotency_key'] ?? ''),
            fingerprint: self::asString($data['fingerprint'] ?? ''),
            state: RecordState::from(self::asString($data['state'] ?? '')),
            lockToken: self::asString($data['lock_token'] ?? ''),
            createdAt: self::asInt($data['created_at'] ?? 0),
            expiresAt: self::asInt($data['expires_at'] ?? 0),
            response: $response !== null ? StoredResponse::fromArray($response) : null,
            completedAt: isset($data['completed_at']) ? self::asInt($data['completed_at']) : null,
        );
    }

    private static function asString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
