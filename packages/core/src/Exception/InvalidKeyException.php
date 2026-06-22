<?php

declare(strict_types=1);

namespace HttpIdempotency\Exception;

use HttpIdempotency\Problem\ErrorCode;

/**
 * Thrown when a supplied Idempotency-Key cannot be accepted. Carries the
 * specific {@see ErrorCode} so the handler can produce the right problem
 * response without re-deriving the reason.
 */
final class InvalidKeyException extends \InvalidArgumentException implements IdempotencyException
{
    public function __construct(public readonly ErrorCode $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function missing(): self
    {
        return new self(ErrorCode::MissingKey, 'A valid Idempotency-Key header is required.');
    }

    public static function tooLong(int $length, int $maxLength): self
    {
        return new self(
            ErrorCode::KeyTooLong,
            sprintf('Idempotency-Key length %d exceeds the maximum of %d characters.', $length, $maxLength),
        );
    }
}
