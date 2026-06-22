<?php

declare(strict_types=1);

namespace HttpIdempotency\Tests\Engine;

use HttpIdempotency\Clock\FrozenClock;
use HttpIdempotency\Config\IdempotencyConfig;
use HttpIdempotency\Engine\IdempotencyHandler;
use HttpIdempotency\Engine\PassThrough;
use HttpIdempotency\Engine\ProblemOutcome;
use HttpIdempotency\Engine\ProceedOutcome;
use HttpIdempotency\Engine\ReplayOutcome;
use HttpIdempotency\Fingerprint\FingerprintGenerator;
use HttpIdempotency\Problem\ErrorCode;
use HttpIdempotency\Record\StoredResponse;
use HttpIdempotency\Store\ArrayStore;
use HttpIdempotency\Tests\Support\RequestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class IdempotencyHandlerTest extends TestCase
{
    private FrozenClock $clock;

    private ArrayStore $store;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock;
        $this->store = new ArrayStore($this->clock);
    }

    private function handler(IdempotencyConfig $config = new IdempotencyConfig): IdempotencyHandler
    {
        return new IdempotencyHandler(
            $this->store,
            new FingerprintGenerator($config),
            $config,
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(
        string $method = 'POST',
        string $body = '{"amount":100}',
        array $headers = [],
    ): ServerRequestInterface {
        return RequestFactory::create(method: $method, body: $body, headers: $headers);
    }

    #[Test]
    public function non_enforced_methods_pass_through(): void
    {
        $outcome = $this->handler()->evaluate($this->request(method: 'GET'));

        self::assertInstanceOf(PassThrough::class, $outcome);
    }

    #[Test]
    public function a_missing_required_key_is_a_400_missing_key_problem(): void
    {
        $outcome = $this->handler()->evaluate($this->request());

        self::assertInstanceOf(ProblemOutcome::class, $outcome);
        self::assertSame(ErrorCode::MissingKey, $outcome->problem->code);
        self::assertSame(400, $outcome->problem->status);
    }

    #[Test]
    public function a_missing_optional_key_passes_through(): void
    {
        $outcome = $this->handler()->evaluate($this->request(), requireKey: false);

        self::assertInstanceOf(PassThrough::class, $outcome);
    }

    #[Test]
    public function an_over_long_key_is_a_400_key_too_long_problem(): void
    {
        $config = new IdempotencyConfig(maxKeyLength: 10);
        $outcome = $this->handler($config)->evaluate(
            $this->request(headers: ['Idempotency-Key' => str_repeat('x', 11)]),
        );

        self::assertInstanceOf(ProblemOutcome::class, $outcome);
        self::assertSame(ErrorCode::KeyTooLong, $outcome->problem->code);
    }

    #[Test]
    public function an_over_large_body_is_a_413_problem(): void
    {
        $config = new IdempotencyConfig(maxBodyBytes: 5);
        $outcome = $this->handler($config)->evaluate(
            $this->request(body: 'this body is too long', headers: ['Idempotency-Key' => 'k-1']),
        );

        self::assertInstanceOf(ProblemOutcome::class, $outcome);
        self::assertSame(ErrorCode::BodyTooLarge, $outcome->problem->code);
        self::assertSame(413, $outcome->problem->status);
    }

    #[Test]
    public function a_first_request_acquires_the_lock(): void
    {
        $outcome = $this->handler()->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));

        self::assertInstanceOf(ProceedOutcome::class, $outcome);
        self::assertNotSame('', $outcome->lockToken);
    }

    #[Test]
    public function a_retry_while_in_flight_is_a_409_conflict(): void
    {
        $handler = $this->handler();
        $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));

        $outcome = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));

        self::assertInstanceOf(ProblemOutcome::class, $outcome);
        self::assertSame(ErrorCode::Conflict, $outcome->problem->code);
        self::assertSame(409, $outcome->problem->status);
    }

    #[Test]
    public function the_same_key_with_a_different_body_is_a_422_mismatch(): void
    {
        $handler = $this->handler();
        $handler->evaluate($this->request(body: '{"a":1}', headers: ['Idempotency-Key' => 'k-1']));

        $outcome = $handler->evaluate($this->request(body: '{"a":2}', headers: ['Idempotency-Key' => 'k-1']));

        self::assertInstanceOf(ProblemOutcome::class, $outcome);
        self::assertSame(ErrorCode::FingerprintMismatch, $outcome->problem->code);
        self::assertSame(422, $outcome->problem->status);
    }

    #[Test]
    public function a_completed_request_is_replayed(): void
    {
        $handler = $this->handler();
        $first = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        self::assertInstanceOf(ProceedOutcome::class, $first);

        $handler->finalize(
            $first->lookupKey,
            $first->lockToken,
            new StoredResponse(201, ['content-type' => ['application/json']], '{"id":"abc"}'),
        );

        $outcome = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));

        self::assertInstanceOf(ReplayOutcome::class, $outcome);
        self::assertSame(201, $outcome->response->status);
        self::assertSame('{"id":"abc"}', $outcome->response->body);
    }

    #[Test]
    public function server_errors_are_not_cached_by_default_and_release_the_lock(): void
    {
        $handler = $this->handler();
        $first = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        self::assertInstanceOf(ProceedOutcome::class, $first);

        $handler->finalize($first->lookupKey, $first->lockToken, new StoredResponse(503, [], 'unavailable'));

        // The lock was released, so a retry may attempt the operation again.
        $retry = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        self::assertInstanceOf(ProceedOutcome::class, $retry);
    }

    #[Test]
    public function server_errors_are_replayed_when_caching_is_enabled(): void
    {
        $handler = $this->handler(new IdempotencyConfig(cacheServerErrors: true));
        $first = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        self::assertInstanceOf(ProceedOutcome::class, $first);

        $handler->finalize($first->lookupKey, $first->lockToken, new StoredResponse(503, [], 'unavailable'));

        $retry = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        self::assertInstanceOf(ReplayOutcome::class, $retry);
        self::assertSame(503, $retry->response->status);
    }

    #[Test]
    public function abort_releases_the_lock_so_the_request_can_be_retried(): void
    {
        $handler = $this->handler();
        $first = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        self::assertInstanceOf(ProceedOutcome::class, $first);

        $handler->abort($first->lookupKey, $first->lockToken);

        $retry = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        self::assertInstanceOf(ProceedOutcome::class, $retry);
    }

    #[Test]
    public function different_keys_are_independent(): void
    {
        $handler = $this->handler();
        $a = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-1']));
        $b = $handler->evaluate($this->request(headers: ['Idempotency-Key' => 'k-2']));

        self::assertInstanceOf(ProceedOutcome::class, $a);
        self::assertInstanceOf(ProceedOutcome::class, $b);
        self::assertNotSame($a->lookupKey, $b->lookupKey);
    }
}
