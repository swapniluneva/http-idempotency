<?php

declare(strict_types=1);

namespace HttpIdempotency\Key;

use HttpIdempotency\Exception\InvalidKeyException;

/**
 * The client-supplied Idempotency-Key, validated per the IETF draft (an RFC
 * 8941 structured-field string). We accept any non-empty value of printable,
 * non-control characters up to a configured maximum length — UUIDs are the
 * recommended form but not required.
 */
final readonly class IdempotencyKey implements \Stringable
{
    private function __construct(public string $value) {}

    /**
     * @throws InvalidKeyException when the key is empty, contains control
     *                             characters, or exceeds {@param $maxLength}
     */
    public static function fromHeader(?string $raw, int $maxLength): self
    {
        if ($raw === null) {
            throw InvalidKeyException::missing();
        }

        // Structured-field strings may arrive quoted; strip a single pair of
        // surrounding double quotes before validating the contents.
        $value = self::unquote($raw);

        if ($value === '' || self::hasControlChars($value)) {
            throw InvalidKeyException::missing();
        }

        // Length is measured in bytes, matching what stores persist and index.
        $length = strlen($value);
        if ($length > $maxLength) {
            throw InvalidKeyException::tooLong($length, $maxLength);
        }

        return new self($value);
    }

    private static function unquote(string $raw): string
    {
        if (strlen($raw) >= 2 && str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            return stripslashes(substr($raw, 1, -1));
        }

        return $raw;
    }

    private static function hasControlChars(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
