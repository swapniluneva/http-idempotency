<?php

declare(strict_types=1);

namespace HttpIdempotency\Engine;

/**
 * The request needs no idempotency handling (non-enforced method, or no key on
 * a route where the key is optional). The adapter should just run the app.
 */
final readonly class PassThrough implements Outcome {}
