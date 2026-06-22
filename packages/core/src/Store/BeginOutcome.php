<?php

declare(strict_types=1);

namespace HttpIdempotency\Store;

use HttpIdempotency\Record\IdempotencyRecord;

/**
 * Result of {@see StoreInterface::begin()}: the classification plus whichever
 * payload that classification carries — a lock token when Acquired, or the
 * existing record otherwise.
 */
final readonly class BeginOutcome
{
    private function __construct(
        public BeginResult $result,
        public ?string $lockToken = null,
        public ?IdempotencyRecord $record = null,
    ) {}

    public static function acquired(string $lockToken): self
    {
        return new self(BeginResult::Acquired, lockToken: $lockToken);
    }

    public static function replay(IdempotencyRecord $record): self
    {
        return new self(BeginResult::Replay, record: $record);
    }

    public static function inProgress(IdempotencyRecord $record): self
    {
        return new self(BeginResult::InProgress, record: $record);
    }

    public static function mismatch(IdempotencyRecord $record): self
    {
        return new self(BeginResult::Mismatch, record: $record);
    }
}
