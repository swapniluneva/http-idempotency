<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Tests\Feature;

use HttpIdempotency\Fingerprint\FingerprintGenerator;
use HttpIdempotency\Store\StoreInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\Test;

final class IdempotencyMiddlewareTest extends TestCase
{
    private function key(string $value = 'key-123'): array
    {
        return ['Idempotency-Key' => $value];
    }

    #[Test]
    public function a_first_request_is_processed_normally(): void
    {
        $response = $this->postJson('/payments', ['amount' => 100], $this->key());

        $response->assertStatus(201);
        $response->assertHeaderMissing('Idempotency-Replayed');
        self::assertSame(1, self::$sideEffects);
    }

    #[Test]
    public function a_retry_replays_the_stored_response_without_re_running(): void
    {
        $first = $this->postJson('/payments', ['amount' => 100], $this->key());
        $second = $this->postJson('/payments', ['amount' => 100], $this->key());

        $second->assertStatus(201);
        $second->assertHeader('Idempotency-Replayed', 'true');
        self::assertSame($first->json('id'), $second->json('id'), 'Same stored body is replayed');
        self::assertSame(1, self::$sideEffects, 'The endpoint ran only once');
    }

    #[Test]
    public function a_missing_key_is_rejected_with_400_missing_key(): void
    {
        $response = $this->postJson('/payments', ['amount' => 100]);

        $response->assertStatus(400);
        $response->assertHeader('Content-Type', 'application/problem+json');
        $response->assertJsonPath('code', 'MISSING_KEY');
        self::assertSame(0, self::$sideEffects);
    }

    #[Test]
    public function an_over_long_key_is_rejected_with_400_key_too_long(): void
    {
        config()->set('idempotency.max_key_length', 10);

        $response = $this->postJson('/payments', ['amount' => 100], $this->key(str_repeat('x', 11)));

        $response->assertStatus(400);
        $response->assertJsonPath('code', 'KEY_TOO_LONG');
    }

    #[Test]
    public function an_over_large_body_is_rejected_with_413(): void
    {
        config()->set('idempotency.max_body_bytes', 5);

        $response = $this->postJson('/payments', ['amount' => 100], $this->key());

        $response->assertStatus(413);
        $response->assertJsonPath('code', 'BODY_TOO_LARGE');
    }

    #[Test]
    public function the_same_key_with_a_different_body_is_rejected_with_422(): void
    {
        $this->postJson('/payments', ['amount' => 100], $this->key());
        $response = $this->postJson('/payments', ['amount' => 999], $this->key());

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'FINGERPRINT_MISMATCH');
    }

    #[Test]
    public function an_in_flight_request_with_the_same_key_is_rejected_with_409(): void
    {
        // Simulate the original still being in flight by holding the lock with a
        // record whose fingerprint matches what the middleware will compute.
        $key = 'inflight-key';
        $store = $this->app->make(StoreInterface::class);
        $generator = $this->app->make(FingerprintGenerator::class);

        $psr = (new Psr17Factory)->createServerRequest('POST', 'http://localhost/payments')
            ->withBody((new Psr17Factory)->createStream(''));
        $fingerprint = $generator->generate($psr);
        $lookupKey = hash('sha256', "\x00".$key);

        $store->begin($lookupKey, $key, $fingerprint, 60);

        $response = $this->call('POST', '/payments', [], [], [], $this->serverHeaders($this->key($key)));

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'CONFLICT');
    }

    #[Test]
    public function an_optional_route_passes_through_without_a_key(): void
    {
        $this->postJson('/optional')->assertStatus(200)->assertJson(['ok' => true]);
    }

    #[Test]
    public function server_errors_are_not_cached_and_can_be_retried(): void
    {
        $this->postJson('/boom', [], $this->key())->assertStatus(503);
        $this->postJson('/boom', [], $this->key())->assertStatus(503);

        self::assertSame(2, self::$sideEffects, '5xx releases the lock so the retry re-runs');
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
