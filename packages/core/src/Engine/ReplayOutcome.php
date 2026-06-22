<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

use HttpIdempotency\Record\StoredResponse;

/**
 * A completed response already exists for this key. The adapter reconstructs it
 * and adds the configured "replayed" marker header.
 */
final readonly class ReplayOutcome implements Outcome
{
    public function __construct(public StoredResponse $response) {}
}
