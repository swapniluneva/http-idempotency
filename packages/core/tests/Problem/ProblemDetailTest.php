<?php

declare(strict_types=1);

namespace HttpIdempotency\Tests\Problem;

use HttpIdempotency\Problem\ErrorCode;
use HttpIdempotency\Problem\ProblemDetail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProblemDetailTest extends TestCase
{
    /**
     * @return iterable<string, array{ErrorCode, int}>
     */
    public static function statusMap(): iterable
    {
        yield 'missing key' => [ErrorCode::MissingKey, 400];
        yield 'key too long' => [ErrorCode::KeyTooLong, 400];
        yield 'body too large' => [ErrorCode::BodyTooLarge, 413];
        yield 'fingerprint mismatch' => [ErrorCode::FingerprintMismatch, 422];
        yield 'conflict' => [ErrorCode::Conflict, 409];
    }

    #[Test]
    #[DataProvider('statusMap')]
    public function each_error_code_maps_to_the_draft_mandated_status(ErrorCode $code, int $status): void
    {
        self::assertSame($status, $code->status());
    }

    #[Test]
    public function it_builds_a_rfc9457_body_with_a_code_member(): void
    {
        $problem = ProblemDetail::fromCode(
            ErrorCode::FingerprintMismatch,
            'https://errors.test/problems',
            'The key was reused with a different body.',
        );

        $body = $problem->toArray();

        self::assertSame('https://errors.test/problems/fingerprint-mismatch', $body['type']);
        self::assertSame(422, $body['status']);
        self::assertSame('FINGERPRINT_MISMATCH', $body['code']);
        self::assertSame('The key was reused with a different body.', $body['detail']);
        self::assertArrayHasKey('title', $body);
    }

    #[Test]
    public function it_omits_detail_when_not_provided(): void
    {
        $body = ProblemDetail::fromCode(ErrorCode::Conflict, 'https://errors.test/problems')->toArray();

        self::assertArrayNotHasKey('detail', $body);
    }

    #[Test]
    public function it_emits_problem_json_content_type_and_help_link(): void
    {
        $problem = ProblemDetail::fromCode(ErrorCode::Conflict, 'https://errors.test/problems');
        $headers = $problem->headers();

        self::assertSame('application/problem+json', $headers['Content-Type']);
        self::assertSame('<https://errors.test/problems/conflict>; rel="help"', $headers['Link']);
    }

    #[Test]
    public function extensions_are_merged_into_the_body(): void
    {
        $problem = ProblemDetail::fromCode(ErrorCode::KeyTooLong, 'https://errors.test/problems')
            ->withExtensions(['maxLength' => 255]);

        self::assertSame(255, $problem->toArray()['maxLength']);
    }
}
