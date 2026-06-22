<?php

declare(strict_types=1);

namespace HttpIdempotency\Fingerprint;

use HttpIdempotency\Config\IdempotencyConfig;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Produces a deterministic SHA-256 fingerprint of the parts of a request that
 * must be identical across retries. Detecting a fingerprint change lets the
 * handler reject reuse of a key with a different payload (RFC: HTTP 422).
 *
 * Canonical input (newline-separated), in a fixed order so map ordering never
 * affects the digest:
 *   METHOD
 *   path
 *   sorted query string        (only when enabled)
 *   selected request headers   (sorted, "name: value")
 *   <blank line>
 *   raw body
 */
final readonly class FingerprintGenerator
{
    public function __construct(private IdempotencyConfig $config) {}

    public function generate(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();

        $parts = [
            strtoupper($request->getMethod()),
            $uri->getPath(),
        ];

        if ($this->config->fingerprintQueryString) {
            $parts[] = $this->canonicalQuery($uri->getQuery());
        }

        foreach ($this->canonicalHeaders($request) as $line) {
            $parts[] = $line;
        }

        // Blank separator before the (potentially large) body.
        $parts[] = '';

        $context = hash_init('sha256');
        hash_update($context, implode("\n", $parts)."\n");
        $this->hashBody($context, $request);

        return hash_final($context);
    }

    private function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = explode('&', $query);
        sort($pairs, SORT_STRING);

        return implode('&', $pairs);
    }

    /**
     * @return list<string>
     */
    private function canonicalHeaders(ServerRequestInterface $request): array
    {
        if ($this->config->fingerprintHeaders === []) {
            return [];
        }

        $lines = [];
        foreach ($this->config->fingerprintHeaders as $name) {
            // getHeaderLine already comma-joins multiple values deterministically.
            $lines[] = $name.': '.$request->getHeaderLine($name);
        }

        sort($lines, SORT_STRING);

        return $lines;
    }

    private function hashBody(\HashContext $context, ServerRequestInterface $request): void
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (! $body->eof()) {
            $chunk = $body->read(8192);
            if ($chunk === '') {
                break;
            }
            hash_update($context, $chunk);
        }

        // Leave the stream readable for downstream middleware/handlers.
        if ($body->isSeekable()) {
            $body->rewind();
        }
    }
}
