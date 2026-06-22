<?php

declare(strict_types=1);

namespace HttpIdempotency\Problem;

/**
 * Stable, machine-readable error codes surfaced to clients via the RFC 9457
 * "code" extension member. Each case maps to an HTTP status, a short title and
 * a URI reference fragment used to build the Problem Details "type" URI.
 */
enum ErrorCode: string
{
    case MissingKey = 'MISSING_KEY';
    case KeyTooLong = 'KEY_TOO_LONG';
    case BodyTooLarge = 'BODY_TOO_LARGE';
    case FingerprintMismatch = 'FINGERPRINT_MISMATCH';
    case Conflict = 'CONFLICT';

    /**
     * HTTP status code mandated by the IETF idempotency-key draft for each case.
     */
    public function status(): int
    {
        return match ($this) {
            self::MissingKey => 400,
            self::KeyTooLong => 400,
            self::BodyTooLarge => 413,
            self::FingerprintMismatch => 422,
            self::Conflict => 409,
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::MissingKey => 'Idempotency-Key header is required',
            self::KeyTooLong => 'Idempotency-Key is too long',
            self::BodyTooLarge => 'Request body is too large',
            self::FingerprintMismatch => 'Idempotency-Key reused with a different request',
            self::Conflict => 'A request with this Idempotency-Key is already in progress',
        };
    }

    /**
     * Fragment appended to the configured problem-type base URI to form the
     * stable "type" URI for this error.
     */
    public function typeSlug(): string
    {
        return strtolower(str_replace('_', '-', $this->value));
    }
}
