<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

use HttpIdempotency\Problem\ProblemDetail;

/**
 * The request must be rejected. The adapter renders {@see $problem} as an
 * `application/problem+json` response with the problem's status and headers.
 */
final readonly class ProblemOutcome implements Outcome
{
    public function __construct(public ProblemDetail $problem) {}
}
