<?php

declare(strict_types=1);

namespace HttpIdempotency\Clock;

/**
 * Minimal time source in Unix seconds. Kept deliberately small (rather than
 * pulling in PSR-20 DateTimeImmutable conversions) because stores reason about
 * expiry purely as integer timestamps. Swap {@see FrozenClock} in tests.
 */
interface Clock
{
    public function now(): int;
}
