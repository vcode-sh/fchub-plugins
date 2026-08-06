<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Plan;

use FChubMemberships\Domain\Plan\PlanSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanSlugTest extends TestCase
{
    #[DataProvider('canonicalSlugProvider')]
    public function testCanonicalizeUsesWordPressSlugRules(string $input, string $expected): void
    {
        self::assertSame($expected, PlanSlug::canonicalize($input));
    }

    public static function canonicalSlugProvider(): array
    {
        return [
            'plain text' => ['Gold Membership', 'gold-membership'],
            'surrounding punctuation' => ['-- Gold Membership --', 'gold-membership'],
            'empty canonical value' => ['---', ''],
        ];
    }

    public function testCanonicalizeNeverExceedsTheDatabaseColumnLimit(): void
    {
        $slug = PlanSlug::canonicalize(str_repeat('long-title-', 20));

        self::assertLessThanOrEqual(PlanSlug::MAX_LENGTH, strlen($slug));
        self::assertSame(rtrim($slug, '-'), $slug);
    }

    public function testAppendSuffixReservesRoomWithinTheDatabaseColumnLimit(): void
    {
        $slug = PlanSlug::appendSuffix(str_repeat('long-title-', 20), 12);

        self::assertLessThanOrEqual(PlanSlug::MAX_LENGTH, strlen($slug));
        self::assertStringEndsWith('-12', $slug);
    }
}
