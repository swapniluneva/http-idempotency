<?php

declare(strict_types=1);

namespace HttpIdempotency\Exception;

/**
 * Marker interface for every exception thrown by the library, so consumers can
 * catch the whole hierarchy with a single type.
 */
interface IdempotencyException extends \Throwable {}
