<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Store;

use HttpIdempotency\Clock\Clock;
use HttpIdempotency\Clock\SystemClock;
use HttpIdempotency\Record\IdempotencyRecord;
use HttpIdempotency\Record\RecordState;
use HttpIdempotency\Record\StoredResponse;
use HttpIdempotency\Store\BeginOutcome;
use HttpIdempotency\Store\StoreInterface;
use Illuminate\Redis\Connections\Connection;
use Predis\ClientInterface;

/**
 * Redis store. Each idempotency key is a single Redis key holding a JSON record,
 * with the native key TTL enforcing expiry (so purgeExpired() is a no-op).
 *
 * Atomicity comes from server-side Lua: begin() creates the key only if absent
 * within one script; complete()/release() check the lock token before writing,
 * giving the same optimistic-CAS guarantee as the database store.
 */
final class RedisStore implements StoreInterface
{
    private const BEGIN = <<<'LUA'
        local existing = redis.call('GET', KEYS[1])
        if existing == false then
            redis.call('SET', KEYS[1], ARGV[1], 'EX', tonumber(ARGV[2]))
            return 'ACQUIRED'
        end
        return existing
    LUA;

    private const COMPLETE = <<<'LUA'
        local existing = redis.call('GET', KEYS[1])
        if existing == false then return 0 end
        local data = cjson.decode(existing)
        if data['lock_token'] ~= ARGV[1] then return 0 end
        local pttl = redis.call('PTTL', KEYS[1])
        if pttl and pttl > 0 then
            redis.call('SET', KEYS[1], ARGV[2], 'PX', pttl)
        else
            redis.call('SET', KEYS[1], ARGV[2])
        end
        return 1
    LUA;

    private const RELEASE = <<<'LUA'
        local existing = redis.call('GET', KEYS[1])
        if existing == false then return 0 end
        local data = cjson.decode(existing)
        if data['state'] == 'completed' then return 0 end
        if data['lock_token'] ~= ARGV[1] then return 0 end
        redis.call('DEL', KEYS[1])
        return 1
    LUA;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $prefix = 'idempotency:',
        private readonly Clock $clock = new SystemClock,
    ) {}

    public function begin(
        string $lookupKey,
        string $idempotencyKey,
        string $fingerprint,
        int $ttlSeconds,
    ): BeginOutcome {
        $now = $this->clock->now();
        $lockToken = bin2hex(random_bytes(16));

        $record = new IdempotencyRecord(
            lookupKey: $lookupKey,
            idempotencyKey: $idempotencyKey,
            fingerprint: $fingerprint,
            state: RecordState::Locked,
            lockToken: $lockToken,
            createdAt: $now,
            expiresAt: $now + $ttlSeconds,
        );

        $result = $this->eval(self::BEGIN, [$this->key($lookupKey)], [$this->serialize($record), (string) $ttlSeconds]);

        if ($result === 'ACQUIRED') {
            return BeginOutcome::acquired($lockToken);
        }

        $existing = $this->deserialize((string) $result);
        if ($existing === null) {
            // Key vanished (expired) between GET and our read: retry once.
            return $this->begin($lookupKey, $idempotencyKey, $fingerprint, $ttlSeconds);
        }

        if (! $existing->matchesFingerprint($fingerprint)) {
            return BeginOutcome::mismatch($existing);
        }

        if ($existing->isCompleted()) {
            return BeginOutcome::replay($existing);
        }

        return BeginOutcome::inProgress($existing);
    }

    public function complete(string $lookupKey, string $lockToken, StoredResponse $response): bool
    {
        $current = $this->get($lookupKey);
        if ($current === null) {
            return false;
        }

        $completed = new IdempotencyRecord(
            lookupKey: $current->lookupKey,
            idempotencyKey: $current->idempotencyKey,
            fingerprint: $current->fingerprint,
            state: RecordState::Completed,
            lockToken: $current->lockToken,
            createdAt: $current->createdAt,
            expiresAt: $current->expiresAt,
            response: $response,
            completedAt: $this->clock->now(),
        );

        $result = $this->eval(self::COMPLETE, [$this->key($lookupKey)], [$lockToken, $this->serialize($completed)]);

        return (int) $result === 1;
    }

    public function release(string $lookupKey, string $lockToken): void
    {
        $this->eval(self::RELEASE, [$this->key($lookupKey)], [$lockToken]);
    }

    public function get(string $lookupKey): ?IdempotencyRecord
    {
        $raw = $this->connection->command('get', [$this->key($lookupKey)]);
        if (! is_string($raw)) {
            return null;
        }

        return $this->deserialize($raw);
    }

    public function purgeExpired(): int
    {
        // Redis evicts expired keys natively; nothing to sweep.
        return 0;
    }

    private function key(string $lookupKey): string
    {
        return $this->prefix.$lookupKey;
    }

    private function serialize(IdempotencyRecord $record): string
    {
        $data = $record->toArray();
        if ($record->response !== null) {
            // Base64 the body so arbitrary bytes survive JSON encoding.
            $data['response']['body'] = base64_encode($record->response->body);
        }

        return (string) json_encode($data);
    }

    private function deserialize(string $raw): ?IdempotencyRecord
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['response']) && is_array($data['response']) && isset($data['response']['body'])) {
            $data['response']['body'] = base64_decode((string) $data['response']['body'], true) ?: '';
        }

        return IdempotencyRecord::fromArray($data);
    }

    /**
     * Run a Lua script, papering over the phpredis vs predis argument-order
     * difference in their respective EVAL signatures.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $args
     */
    private function eval(string $script, array $keys, array $args): mixed
    {
        $client = $this->connection->client();

        if (class_exists(ClientInterface::class) && $client instanceof ClientInterface) {
            // predis: eval(script, numKeys, key..., arg...)
            return $this->connection->command('eval', [$script, count($keys), ...$keys, ...$args]);
        }

        // phpredis: eval(script, [keys..., args...], numKeys)
        return $this->connection->command('eval', [$script, [...$keys, ...$args], count($keys)]);
    }
}
