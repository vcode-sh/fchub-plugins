<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Domain\Enums\RoundingMode;
use FChubMultiCurrency\Domain\Services\RoundingPolicy;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RoundingPolicyTest extends TestCase
{
    #[Test]
    public function testHalfUpRounds5Up(): void
    {
        $policy = new RoundingPolicy(RoundingMode::HalfUp);

        $this->assertSame(10, $policy->apply('9.5'));
    }

    #[Test]
    public function testHalfDownRounds5Down(): void
    {
        $policy = new RoundingPolicy(RoundingMode::HalfDown);

        $this->assertSame(9, $policy->apply('9.5'));
    }

    #[Test]
    public function testCeilAlwaysRoundsUp(): void
    {
        $policy = new RoundingPolicy(RoundingMode::Ceil);

        $this->assertSame(10, $policy->apply('9.1'));
    }

    #[Test]
    public function testFloorAlwaysRoundsDown(): void
    {
        $policy = new RoundingPolicy(RoundingMode::Floor);

        $this->assertSame(9, $policy->apply('9.9'));
    }

    #[Test]
    public function testNoneTruncates(): void
    {
        $policy = new RoundingPolicy(RoundingMode::None);

        $this->assertSame(9, $policy->apply('9.99'));
    }
}
