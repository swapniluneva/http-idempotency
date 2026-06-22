<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

/**
 * The lock was acquired. The adapter runs the application, then calls
 * {@see IdempotencyHandler::finalize()} with the captured response (or
 * {@see IdempotencyHandler::abort()} if the app threw), passing back this
 * outcome's {@see $lookupKey} and {@see $lockToken}.
 */
final readonly class ProceedOutcome implements Outcome
{
    public function __construct(
        public string $lookupKey,
        public string $lockToken,
    ) {}
}
