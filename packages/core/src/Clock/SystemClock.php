<?php

declare(strict_types=1);

namespace HttpIdempotency\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
