<?php

declare(strict_types=1);

namespace HttpIdempotency\Record;

enum RecordState: string
{
    /** A request is in flight; the lock is held and no response is stored yet. */
    case Locked = 'locked';

    /** The original request finished and its response is available for replay. */
    case Completed = 'completed';
}
