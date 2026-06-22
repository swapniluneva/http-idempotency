<?php

declare(strict_types=1);

namespace HttpIdempotency\Benchmarks;

use HttpIdempotency\Config\IdempotencyConfig;
use HttpIdempotency\Fingerprint\FingerprintGenerator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Measures SHA-256 fingerprinting throughput across body sizes — the per-request
 * overhead the middleware adds on the hot path.
 *
 * @BeforeMethods({"setUp"})
 */
final class FingerprintBench
{
    private FingerprintGenerator $generator;

    /** @var array<string, ServerRequestInterface> */
    private array $requests = [];

    public function setUp(): void
    {
        $this->generator = new FingerprintGenerator(new IdempotencyConfig);

        $factory = new Psr17Factory;
        foreach (['small' => 256, 'medium' => 16_384, 'large' => 262_144] as $name => $size) {
            $request = $factory->createServerRequest('POST', 'https://api.test/payments?b=2&a=1')
                ->withBody($factory->createStream(str_repeat('x', $size)));
            $this->requests[$name] = $request;
        }
    }

    public function benchSmallBody(): void
    {
        $this->generator->generate($this->requests['small']);
    }

    public function benchMediumBody(): void
    {
        $this->generator->generate($this->requests['medium']);
    }

    public function benchLargeBody(): void
    {
        $this->generator->generate($this->requests['large']);
    }
}
