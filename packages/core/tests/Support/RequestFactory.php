<?php

declare(strict_types=1);

namespace HttpIdempotency\Tests\Support;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds PSR-7 server requests for tests without each test re-wiring nyholm.
 */
final class RequestFactory
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function create(
        string $method = 'POST',
        string $uri = 'https://api.test/payments',
        string $body = '{"amount":100}',
        array $headers = [],
    ): ServerRequestInterface {
        $factory = new Psr17Factory;
        $request = $factory->createServerRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $stream = $factory->createStream($body);
        $stream->rewind();

        return $request->withBody($stream);
    }
}
