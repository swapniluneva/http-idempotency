<?php

declare(strict_types=1);

namespace HttpIdempotency\Clock;

/**
 * A test clock whose time only moves when you tell it to.
 */
final class FrozenClock implements Clock
{
    public function __construct(private int $now = 1_000_000_000) {}

    public function now(): int
    {
        return $this->now;
    }

    public function set(int $timestamp): void
    {
        $this->now = $timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
