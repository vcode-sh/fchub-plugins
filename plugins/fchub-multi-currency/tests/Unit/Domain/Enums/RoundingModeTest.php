<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Enums;

use FChubMultiCurrency\Domain\Enums\RoundingMode;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RoundingModeTest extends TestCase
{
    #[Test]
    public function testAllCasesHaveLabels(): void
    {
        foreach (RoundingMode::cases() as $case) {
            $this->assertNotEmpty($case->label());
        }
    }

    #[Test]
    public function testFromStringReturnsCorrectCase(): void
    {
        $this->assertSame(RoundingMode::HalfUp, RoundingMode::from('half_up'));
        $this->assertSame(RoundingMode::Floor, RoundingMode::from('floor'));
    }

    #[Test]
    public function testNoneCaseValue(): void
    {
        $this->assertSame('none', RoundingMode::None->value);
    }
}
