<?php

declare(strict_types=1);

namespace HttpIdempotency\Benchmarks;

use HttpIdempotency\Record\StoredResponse;
use HttpIdempotency\Store\ArrayStore;

/**
 * Measures the begin -> complete -> replay cycle for the in-memory store, the
 * baseline against which Database/Redis store latency can be compared.
 *
 * @BeforeMethods({"setUp"})
 */
final class StoreBench
{
    private ArrayStore $store;

    private StoredResponse $response;

    private int $counter = 0;

    public function setUp(): void
    {
        $this->store = new ArrayStore;
        $this->response = new StoredResponse(201, ['content-type' => ['application/json']], '{"id":"x"}');
    }

    public function benchBeginComplete(): void
    {
        $key = 'k-'.$this->counter++;
        $outcome = $this->store->begin($key, $key, 'fp', 60);
        $this->store->complete($key, (string) $outcome->lockToken, $this->response);
    }

    public function benchReplay(): void
    {
        $this->store->begin('replay', 'replay', 'fp', 60);
    }
}
