<?php

declare(strict_types=1);

namespace HttpIdempotency\Problem;

/**
 * An RFC 9457 "Problem Details" object. Immutable; rendered to an
 * `application/problem+json` body plus the headers a framework adapter should
 * emit (Content-Type, and an RFC 8288 Link to documentation).
 */
final readonly class ProblemDetail
{
    /**
     * @param  array<string, scalar|null>  $extensions  extra members merged into the JSON body
     */
    public function __construct(
        public ErrorCode $code,
        public string $type,
        public string $title,
        public int $status,
        public ?string $detail = null,
        public array $extensions = [],
    ) {}

    public static function fromCode(ErrorCode $code, string $typeBaseUri, ?string $detail = null): self
    {
        return new self(
            code: $code,
            type: rtrim($typeBaseUri, '/').'/'.$code->typeSlug(),
            title: $code->title(),
            status: $code->status(),
            detail: $detail,
        );
    }

    /**
     * @param  array<string, scalar|null>  $extensions
     */
    public function withExtensions(array $extensions): self
    {
        return new self(
            code: $this->code,
            type: $this->type,
            title: $this->title,
            status: $this->status,
            detail: $this->detail,
            extensions: [...$this->extensions, ...$extensions],
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function toArray(): array
    {
        $body = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'code' => $this->code->value,
        ];

        if ($this->detail !== null) {
            $body['detail'] = $this->detail;
        }

        return [...$body, ...$this->extensions];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES);
    }

    /**
     * Headers an adapter should attach to the response carrying this problem.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return [
            'Content-Type' => 'application/problem+json',
            'Link' => '<'.$this->type.'>; rel="help"',
        ];
    }
}
