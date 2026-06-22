<?php

declare(strict_types=1);

namespace HttpIdempotency\Store;

/**
 * The four mutually-exclusive outcomes of attempting to begin processing a
 * request for a given idempotency key.
 */
enum BeginResult
{
    /** No prior record existed (or a prior one had expired): the caller now owns the lock and must proceed. */
    case Acquired;

    /** A completed record exists: replay its stored response. */
    case Replay;

    /** A non-expired locked record exists with the same fingerprint: the original is still in flight (HTTP 409). */
    case InProgress;

    /** A record exists with a different fingerprint: the key was reused with a different request (HTTP 422). */
    case Mismatch;
}
