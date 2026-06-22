<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

/**
 * Marker for the transport-neutral results of {@see IdempotencyHandler::evaluate()}.
 * Adapters switch on the concrete type to decide how to respond.
 *
 * @see PassThrough    no idempotency handling needed; run the app normally
 * @see ProblemOutcome reject the request with an RFC 9457 problem
 * @see ReplayOutcome  return a previously stored response
 * @see ProceedOutcome lock acquired; run the app then finalize()
 */
interface Outcome {}
