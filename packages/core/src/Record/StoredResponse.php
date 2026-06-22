<?php

declare(strict_types=1);

namespace HttpIdempotency\Record;

/**
 * A captured HTTP response, persisted so a retry carrying the same
 * Idempotency-Key can be answered with an identical result.
 *
 * Headers are stored only for the configured replay allowlist. The body is
 * held as a raw string; streamed/file responses are out of scope for replay
 * and adapters should decline to capture them.
 */
final readonly class StoredResponse
{
    /**
     * @param  array<string, list<string>>  $headers  header name (lower-case) => values
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    /**
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'headers' => $this->headers,
            'body' => $this->body,
        ];
    }

    /**
     * @param  array{status: int, headers: array<string, list<string>>, body: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            status: $data['status'],
            headers: $data['headers'],
            body: $data['body'],
        );
    }
}
