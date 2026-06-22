<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Default resolver: a single global scope. Keys are unique across the whole
 * application regardless of caller.
 */
final class NullScopeResolver implements ScopeResolver
{
    public function resolve(ServerRequestInterface $request): string
    {
        return '';
    }
}
