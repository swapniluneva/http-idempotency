<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Derives an isolation scope for an idempotency key so the same key used by
 * different clients (or tenants) never collides. The returned string is folded
 * into the storage lookup key. Return '' for a global namespace.
 */
interface ScopeResolver
{
    public function resolve(ServerRequestInterface $request): string;
}
