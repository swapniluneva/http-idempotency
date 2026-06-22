<?php

declare(strict_types=1);

namespace HttpIdempotency\Laravel\Http;

use Closure;
use HttpIdempotency\Engine\IdempotencyHandler;
use HttpIdempotency\Engine\PassThrough;
use HttpIdempotency\Engine\ProblemOutcome;
use HttpIdempotency\Engine\ProceedOutcome;
use HttpIdempotency\Engine\ReplayOutcome;
use HttpIdempotency\Problem\ProblemDetail;
use HttpIdempotency\Record\StoredResponse;
use Illuminate\Http\Request;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laravel middleware that defers every decision to the framework-agnostic
 * {@see IdempotencyHandler}. It only translates between Illuminate's
 * request/response and the core's PSR-7 / DTO types.
 *
 * Usage:
 *   Route::post(...)->middleware('idempotency');            // uses config default
 *   Route::post(...)->middleware('idempotency:required');   // force-require the key
 *   Route::post(...)->middleware('idempotency:optional');   // skip when absent
 */
final class IdempotencyMiddleware
{
    public function __construct(private readonly IdempotencyHandler $handler) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $requireKey = match ($mode) {
            'required' => true,
            'optional' => false,
            default => null,
        };

        $outcome = $this->handler->evaluate($this->toPsr($request), $requireKey);

        if ($outcome instanceof PassThrough) {
            return $next($request);
        }

        if ($outcome instanceof ProblemOutcome) {
            return $this->problemResponse($outcome->problem);
        }

        if ($outcome instanceof ReplayOutcome) {
            return $this->replayResponse($outcome->response);
        }

        /** @var ProceedOutcome $outcome */
        return $this->proceed($request, $next, $outcome);
    }

    private function proceed(Request $request, Closure $next, ProceedOutcome $outcome): Response
    {
        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->handler->abort($outcome->lookupKey, $outcome->lockToken);

            throw $e;
        }

        $captured = $this->capture($response);
        if ($captured === null) {
            // Streamed/binary responses can't be replayed; don't pin the key.
            $this->handler->abort($outcome->lookupKey, $outcome->lockToken);

            return $response;
        }

        $this->handler->finalize($outcome->lookupKey, $outcome->lockToken, $captured);

        return $response;
    }

    private function toPsr(Request $request): ServerRequestInterface
    {
        $factory = new Psr17Factory;

        $psr = $factory->createServerRequest($request->getMethod(), $request->fullUrl());

        /** @var array<string, list<string>> $headers */
        $headers = $request->headers->all();
        foreach ($headers as $name => $values) {
            $psr = $psr->withHeader($name, $values);
        }

        // getContent() returns the raw body and caches it, so the downstream
        // controller can still read the request normally.
        return $psr->withBody($factory->createStream($request->getContent()));
    }

    private function capture(Response $response): ?StoredResponse
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return null;
        }

        $allow = $this->handler->config()->replayHeaders;
        $headers = [];
        foreach ($allow as $name) {
            if ($response->headers->has($name)) {
                /** @var list<string|null> $values */
                $values = $response->headers->all($name);
                $headers[strtolower($name)] = array_values(array_filter(
                    $values,
                    static fn (?string $v): bool => $v !== null,
                ));
            }
        }

        $body = $response->getContent();

        return new StoredResponse(
            status: $response->getStatusCode(),
            headers: $headers,
            body: $body === false ? '' : $body,
        );
    }

    private function replayResponse(StoredResponse $stored): Response
    {
        $response = new Response($stored->body, $stored->status);

        foreach ($stored->headers as $name => $values) {
            $response->headers->set($name, $values);
        }

        $response->headers->set($this->handler->config()->replayedHeaderName, 'true');

        return $response;
    }

    private function problemResponse(ProblemDetail $problem): Response
    {
        return new Response($problem->toJson(), $problem->status, $problem->headers());
    }
}
