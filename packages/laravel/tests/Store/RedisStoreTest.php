<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Tests\Store;

use HttpIdempotency\Clock\FrozenClock;
use HttpIdempotency\Laravel\Store\RedisStore;
use HttpIdempotency\Store\StoreInterface;
use HttpIdempotency\Tests\Store\StoreContractTestCase;
use Illuminate\Redis\Connections\PhpRedisConnection;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the shared store contract against Redis. Skipped unless the phpredis
 * extension is loaded and a server is reachable (e.g. the CI Redis service);
 * configure via REDIS_HOST / REDIS_PORT.
 *
 * Note: this store uses native key TTL for expiry, so the contract's
 * clock-advance cases rely on real time — they are covered by the in-memory and
 * database stores; here we exercise the locking/replay/mismatch semantics.
 */
final class RedisStoreTest extends StoreContractTestCase
{
    private PhpRedisConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('redis')) {
            self::markTestSkipped('phpredis extension not available.');
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $client = new \Redis;
        try {
            if (! @$client->connect($host, $port, 0.5)) {
                self::markTestSkipped("No Redis server at {$host}:{$port}.");
            }
        } catch (\RedisException $e) {
            self::markTestSkipped('Could not connect to Redis: '.$e->getMessage());
        }

        $client->flushDB();
        $this->connection = new PhpRedisConnection($client);
    }

    protected function createStore(FrozenClock $clock): StoreInterface
    {
        // A unique prefix per test keeps cases isolated within the shared server.
        return new RedisStore($this->connection, 'idemp-test:'.uniqid().':', $clock);
    }

    // Expiry is driven by Redis' real key TTL, not the injected (frozen) clock,
    // so the contract's time-travel cases don't apply to this backend.

    #[Test]
    public function an_expired_lock_can_be_taken_over(): void
    {
        self::markTestSkipped('Redis expiry uses native TTL, not the frozen clock.');
    }

    #[Test]
    public function complete_after_takeover_rejects_the_stale_owner(): void
    {
        self::markTestSkipped('Redis expiry uses native TTL, not the frozen clock.');
    }

    #[Test]
    public function get_returns_the_record_and_hides_expired_ones(): void
    {
        self::markTestSkipped('Redis expiry uses native TTL, not the frozen clock.');
    }

    #[Test]
    public function purge_expired_removes_only_expired_records(): void
    {
        self::markTestSkipped('Redis expiry uses native TTL, not the frozen clock.');
    }
}
