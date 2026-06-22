<?php

declare(strict_types=1);

namespace HttpIdempotency\Tests\Store;

use HttpIdempotency\Clock\FrozenClock;
use HttpIdempotency\Record\RecordState;
use HttpIdempotency\Record\StoredResponse;
use HttpIdempotency\Store\BeginResult;
use HttpIdempotency\Store\StoreInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural contract every StoreInterface implementation must satisfy.
 * Concrete store tests (ArrayStore here, Database/Redis in the Laravel package)
 * extend this and only provide a freshly-built store bound to the given clock.
 */
abstract class StoreContractTestCase extends TestCase
{
    protected FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
    }

    abstract protected function createStore(FrozenClock $clock): StoreInterface;

    private function response(int $status = 201, string $body = 'ok'): StoredResponse
    {
        return new StoredResponse($status, ['content-type' => ['application/json']], $body);
    }

    #[Test]
    public function begin_acquires_a_lock_for_a_fresh_key(): void
    {
        $store = $this->createStore($this->clock);

        $outcome = $store->begin('key-1', 'raw-1', 'fp-1', 60);

        self::assertSame(BeginResult::Acquired, $outcome->result);
        self::assertNotNull($outcome->lockToken);
    }

    #[Test]
    public function a_second_begin_while_in_flight_reports_in_progress(): void
    {
        $store = $this->createStore($this->clock);
        $store->begin('key-1', 'raw-1', 'fp-1', 60);

        $second = $store->begin('key-1', 'raw-1', 'fp-1', 60);

        self::assertSame(BeginResult::InProgress, $second->result);
    }

    #[Test]
    public function exactly_one_of_two_concurrent_begins_acquires(): void
    {
        $store = $this->createStore($this->clock);

        $first = $store->begin('key-1', 'raw-1', 'fp-1', 60);
        $second = $store->begin('key-1', 'raw-1', 'fp-1', 60);

        $results = [$first->result, $second->result];
        self::assertContains(BeginResult::Acquired, $results);
        self::assertContains(BeginResult::InProgress, $results);
        self::assertNotSame($first->result, $second->result);
    }

    #[Test]
    public function begin_with_a_different_fingerprint_reports_mismatch(): void
    {
        $store = $this->createStore($this->clock);
        $store->begin('key-1', 'raw-1', 'fp-1', 60);

        $second = $store->begin('key-1', 'raw-1', 'fp-DIFFERENT', 60);

        self::assertSame(BeginResult::Mismatch, $second->result);
    }

    #[Test]
    public function after_completion_begin_reports_replay_with_the_stored_response(): void
    {
        $store = $this->createStore($this->clock);
        $acquired = $store->begin('key-1', 'raw-1', 'fp-1', 60);
        $store->complete('key-1', (string) $acquired->lockToken, $this->response(201, 'created'));

        $replay = $store->begin('key-1', 'raw-1', 'fp-1', 60);

        self::assertSame(BeginResult::Replay, $replay->result);
        self::assertNotNull($replay->record);
        self::assertNotNull($replay->record->response);
        self::assertSame(201, $replay->record->response->status);
        self::assertSame('created', $replay->record->response->body);
    }

    #[Test]
    public function complete_fails_when_the_lock_token_does_not_match(): void
    {
        $store = $this->createStore($this->clock);
        $store->begin('key-1', 'raw-1', 'fp-1', 60);

        self::assertFalse($store->complete('key-1', 'not-the-token', $this->response()));
    }

    #[Test]
    public function an_expired_lock_can_be_taken_over(): void
    {
        $store = $this->createStore($this->clock);
        $store->begin('key-1', 'raw-1', 'fp-1', 60);

        $this->clock->advance(61);

        $takeover = $store->begin('key-1', 'raw-1', 'fp-1', 60);
        self::assertSame(BeginResult::Acquired, $takeover->result);
    }

    #[Test]
    public function complete_after_takeover_rejects_the_stale_owner(): void
    {
        $store = $this->createStore($this->clock);
        $stale = $store->begin('key-1', 'raw-1', 'fp-1', 60);

        $this->clock->advance(61);
        $store->begin('key-1', 'raw-1', 'fp-1', 60); // takeover with a new token

        self::assertFalse($store->complete('key-1', (string) $stale->lockToken, $this->response()));
    }

    #[Test]
    public function release_lets_a_fresh_request_acquire_again(): void
    {
        $store = $this->createStore($this->clock);
        $acquired = $store->begin('key-1', 'raw-1', 'fp-1', 60);

        $store->release('key-1', (string) $acquired->lockToken);

        $again = $store->begin('key-1', 'raw-1', 'fp-1', 60);
        self::assertSame(BeginResult::Acquired, $again->result);
    }

    #[Test]
    public function release_does_not_discard_a_completed_record(): void
    {
        $store = $this->createStore($this->clock);
        $acquired = $store->begin('key-1', 'raw-1', 'fp-1', 60);
        $store->complete('key-1', (string) $acquired->lockToken, $this->response());

        $store->release('key-1', (string) $acquired->lockToken);

        self::assertSame(BeginResult::Replay, $store->begin('key-1', 'raw-1', 'fp-1', 60)->result);
    }

    #[Test]
    public function get_returns_the_record_and_hides_expired_ones(): void
    {
        $store = $this->createStore($this->clock);
        $store->begin('key-1', 'raw-1', 'fp-1', 60);

        $record = $store->get('key-1');
        self::assertNotNull($record);
        self::assertSame(RecordState::Locked, $record->state);

        $this->clock->advance(61);
        self::assertNull($store->get('key-1'));
    }

    #[Test]
    public function purge_expired_removes_only_expired_records(): void
    {
        $store = $this->createStore($this->clock);
        $store->begin('old', 'raw-old', 'fp', 30);
        $store->begin('new', 'raw-new', 'fp', 300);

        $this->clock->advance(31);
        // Stores with native TTL may report 0 purged; the observable contract
        // is that the expired record is gone and the live one remains.
        $store->purgeExpired();

        self::assertNull($store->get('old'));
        self::assertNotNull($store->get('new'));
    }
}
