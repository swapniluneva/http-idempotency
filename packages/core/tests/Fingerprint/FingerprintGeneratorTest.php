<?php

declare(strict_types=1);

namespace HttpIdempotency\Tests\Fingerprint;

use HttpIdempotency\Config\IdempotencyConfig;
use HttpIdempotency\Fingerprint\FingerprintGenerator;
use HttpIdempotency\Tests\Support\RequestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FingerprintGeneratorTest extends TestCase
{
    private function generator(IdempotencyConfig $config = new IdempotencyConfig): FingerprintGenerator
    {
        return new FingerprintGenerator($config);
    }

    #[Test]
    public function identical_requests_produce_identical_fingerprints(): void
    {
        $gen = $this->generator();
        $a = $gen->generate(RequestFactory::create(body: '{"amount":100}'));
        $b = $gen->generate(RequestFactory::create(body: '{"amount":100}'));

        self::assertSame($a, $b);
        self::assertSame(64, strlen($a), 'SHA-256 hex digest is 64 chars');
    }

    #[Test]
    public function a_different_body_changes_the_fingerprint(): void
    {
        $gen = $this->generator();
        $a = $gen->generate(RequestFactory::create(body: '{"amount":100}'));
        $b = $gen->generate(RequestFactory::create(body: '{"amount":200}'));

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function a_different_method_changes_the_fingerprint(): void
    {
        $gen = $this->generator();
        $a = $gen->generate(RequestFactory::create(method: 'POST'));
        $b = $gen->generate(RequestFactory::create(method: 'PATCH'));

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function query_parameter_order_does_not_matter(): void
    {
        $gen = $this->generator();
        $a = $gen->generate(RequestFactory::create(uri: 'https://api.test/p?b=2&a=1'));
        $b = $gen->generate(RequestFactory::create(uri: 'https://api.test/p?a=1&b=2'));

        self::assertSame($a, $b);
    }

    #[Test]
    public function the_query_string_is_ignored_when_disabled(): void
    {
        $gen = $this->generator(new IdempotencyConfig(fingerprintQueryString: false));
        $a = $gen->generate(RequestFactory::create(uri: 'https://api.test/p?a=1'));
        $b = $gen->generate(RequestFactory::create(uri: 'https://api.test/p?a=2'));

        self::assertSame($a, $b);
    }

    #[Test]
    public function configured_headers_are_folded_into_the_fingerprint(): void
    {
        $gen = $this->generator(new IdempotencyConfig(fingerprintHeaders: ['x-tenant']));
        $a = $gen->generate(RequestFactory::create(headers: ['X-Tenant' => 'acme']));
        $b = $gen->generate(RequestFactory::create(headers: ['X-Tenant' => 'globex']));

        self::assertNotSame($a, $b);
    }

    #[Test]
    public function it_leaves_the_body_stream_readable_for_downstream(): void
    {
        $request = RequestFactory::create(body: '{"amount":100}');
        $this->generator()->generate($request);

        self::assertSame('{"amount":100}', (string) $request->getBody());
    }
}
