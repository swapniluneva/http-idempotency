<?php

declare(strict_types=1);

namespace HttpIdempotency\Tests\Store;

use HttpIdempotency\Clock\FrozenClock;
use HttpIdempotency\Store\ArrayStore;
use HttpIdempotency\Store\StoreInterface;

final class ArrayStoreTest extends StoreContractTestCase
{
    protected function createStore(FrozenClock $clock): StoreInterface
    {
        return new ArrayStore($clock);
    }
}
