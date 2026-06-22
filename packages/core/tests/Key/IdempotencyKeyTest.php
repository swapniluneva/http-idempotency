<?php

declare(strict_types=1);

namespace HttpIdempotency\Tests\Key;

use HttpIdempotency\Exception\InvalidKeyException;
use HttpIdempotency\Key\IdempotencyKey;
use HttpIdempotency\Problem\ErrorCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdempotencyKeyTest extends TestCase
{
    #[Test]
    public function it_accepts_a_uuid_style_key(): void
    {
        $key = IdempotencyKey::fromHeader('8e03978e-40d5-43e8-bc93-6894a57f9324', 255);

        self::assertSame('8e03978e-40d5-43e8-bc93-6894a57f9324', $key->value);
        self::assertSame('8e03978e-40d5-43e8-bc93-6894a57f9324', (string) $key);
    }

    #[Test]
    public function it_strips_structured_field_quoting(): void
    {
        $key = IdempotencyKey::fromHeader('"quoted-key"', 255);

        self::assertSame('quoted-key', $key->value);
    }

    #[Test]
    public function it_rejects_a_missing_key_as_missing(): void
    {
        $this->expectExceptionObject(InvalidKeyException::missing());

        IdempotencyKey::fromHeader(null, 255);
    }

    #[Test]
    public function it_rejects_an_empty_key_as_missing(): void
    {
        try {
            IdempotencyKey::fromHeader('', 255);
            self::fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            self::assertSame(ErrorCode::MissingKey, $e->errorCode);
        }
    }

    #[Test]
    public function it_rejects_control_characters_as_missing(): void
    {
        try {
            IdempotencyKey::fromHeader("bad\nkey", 255);
            self::fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            self::assertSame(ErrorCode::MissingKey, $e->errorCode);
        }
    }

    #[Test]
    public function it_rejects_a_key_over_the_maximum_length(): void
    {
        try {
            IdempotencyKey::fromHeader(str_repeat('a', 65), 64);
            self::fail('Expected InvalidKeyException');
        } catch (InvalidKeyException $e) {
            self::assertSame(ErrorCode::KeyTooLong, $e->errorCode);
        }
    }

    #[Test]
    public function it_accepts_a_key_exactly_at_the_maximum_length(): void
    {
        $key = IdempotencyKey::fromHeader(str_repeat('a', 64), 64);

        self::assertSame(64, strlen($key->value));
    }
}
