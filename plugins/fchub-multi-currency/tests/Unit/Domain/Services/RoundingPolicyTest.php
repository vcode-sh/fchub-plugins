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

    #[Test]
    public function testPrecision1HalfUpRoundsToNearest10(): void
    {
        $policy = new RoundingPolicy(RoundingMode::HalfUp, 1);

        $this->assertSame(160, $policy->apply('155'));
        $this->assertSame(150, $policy->apply('154'));
    }

    #[Test]
    public function testPrecision1CeilRoundsUpToNearest10(): void
    {
        $policy = new RoundingPolicy(RoundingMode::Ceil, 1);

        $this->assertSame(160, $policy->apply('151'));
        $this->assertSame(150, $policy->apply('149'));
    }

    #[Test]
    public function testPrecision1FloorRoundsDownToNearest10(): void
    {
        $policy = new RoundingPolicy(RoundingMode::Floor, 1);

        $this->assertSame(150, $policy->apply('159'));
    }

    #[Test]
    public function testPrecision2HalfUpRoundsToNearest100(): void
    {
        $policy = new RoundingPolicy(RoundingMode::HalfUp, 2);

        $this->assertSame(1600, $policy->apply('1550'));
        $this->assertSame(1500, $policy->apply('1549'));
    }

    #[Test]
    public function testPrecision2CeilRoundsUpToNearest100(): void
    {
        $policy = new RoundingPolicy(RoundingMode::Ceil, 2);

        $this->assertSame(1600, $policy->apply('1501'));
    }

    #[Test]
    public function testPrecision2NoneTruncatesToAWholeMajorUnit(): void
    {
        $policy = new RoundingPolicy(RoundingMode::None, 2);

        $this->assertSame(1500, $policy->apply('1550'));
        $this->assertSame(-1500, $policy->apply('-1550'));
    }

    #[Test]
    public function testZeroValueReturnsZero(): void
    {
        $this->assertSame(0, (new RoundingPolicy(RoundingMode::None))->apply('0'));
        $this->assertSame(0, (new RoundingPolicy(RoundingMode::HalfUp))->apply('0'));
        $this->assertSame(0, (new RoundingPolicy(RoundingMode::HalfDown))->apply('0'));
        $this->assertSame(0, (new RoundingPolicy(RoundingMode::Ceil))->apply('0'));
        $this->assertSame(0, (new RoundingPolicy(RoundingMode::Floor))->apply('0'));
    }

    #[Test]
    public function testNegativePrecisionTreatedAsZero(): void
    {
        $policy = new RoundingPolicy(RoundingMode::HalfUp, -1);

        // precision <= 0 branch applies: round(9.5) = 10
        $this->assertSame(10, $policy->apply('9.5'));
    }

    #[Test]
    public function testLargeNumberDoesNotOverflow(): void
    {
        $policy = new RoundingPolicy(RoundingMode::HalfUp);

        $this->assertSame(99999999, $policy->apply('99999999'));
    }

    #[Test]
    public function testCharmWholeRoundsToTheNearestMajorUnit(): void
    {
        $this->assertSame(35.0, RoundingPolicy::charm(34.55, 'whole', 2));
        $this->assertSame(34.0, RoundingPolicy::charm(34.02, 'whole', 2));
        $this->assertSame(1723.0, RoundingPolicy::charm(1722.52, 'whole', 2));
        $this->assertSame(1722.0, RoundingPolicy::charm(1722.0, 'whole', 0));
    }

    #[Test]
    public function testCharmEnding99LandsOnTheNextWholeUnitLessACent(): void
    {
        $this->assertSame(34.99, RoundingPolicy::charm(34.55, 'ending_99', 2));
        $this->assertSame(34.99, RoundingPolicy::charm(34.02, 'ending_99', 2));
        $this->assertSame(1722.99, RoundingPolicy::charm(1722.52, 'ending_99', 2));
        $this->assertSame(1722.0, RoundingPolicy::charm(1722.0, 'ending_99', 0));
    }

    #[Test]
    public function testCharmEnding95LandsOnTheNextWholeUnitLessFiveCents(): void
    {
        $this->assertSame(34.95, RoundingPolicy::charm(34.55, 'ending_95', 2));
        $this->assertSame(34.95, RoundingPolicy::charm(34.02, 'ending_95', 2));
        $this->assertSame(1722.95, RoundingPolicy::charm(1722.52, 'ending_95', 2));
        $this->assertSame(1722.0, RoundingPolicy::charm(1722.0, 'ending_95', 0));
    }

    #[Test]
    public function testCharmNearest5SnapsToAMultipleOfFive(): void
    {
        $this->assertSame(35.0, RoundingPolicy::charm(34.55, 'nearest_5', 2));
        $this->assertSame(35.0, RoundingPolicy::charm(34.02, 'nearest_5', 2));
        $this->assertSame(1725.0, RoundingPolicy::charm(1722.52, 'nearest_5', 2));
        $this->assertSame(1720.0, RoundingPolicy::charm(1722.0, 'nearest_5', 0));
    }

    #[Test]
    public function testCharmNearest10SnapsToAMultipleOfTen(): void
    {
        $this->assertSame(30.0, RoundingPolicy::charm(34.55, 'nearest_10', 2));
        $this->assertSame(30.0, RoundingPolicy::charm(34.02, 'nearest_10', 2));
        $this->assertSame(1720.0, RoundingPolicy::charm(1722.52, 'nearest_10', 2));
        $this->assertSame(1720.0, RoundingPolicy::charm(1722.0, 'nearest_10', 0));
    }

    #[Test]
    public function testCharmNoneAndUnknownRulesLeaveTheAmountAlone(): void
    {
        $this->assertSame(34.55, RoundingPolicy::charm(34.55, 'none', 2));
        $this->assertSame(1722.52, RoundingPolicy::charm(1722.52, 'none', 2));
        $this->assertSame(34.55, RoundingPolicy::charm(34.55, 'ending_98', 2));
    }

    #[Test]
    public function testCharmIsIdempotentOnAnAlreadyCharmedAmount(): void
    {
        $this->assertSame(34.99, RoundingPolicy::charm(34.99, 'ending_99', 2));
        $this->assertSame(34.95, RoundingPolicy::charm(34.95, 'ending_95', 2));
        $this->assertSame(35.0, RoundingPolicy::charm(35.0, 'nearest_5', 2));
    }

    #[Test]
    public function testCharmNeverPromotesASubUnitAmountAboveTheEnding(): void
    {
        $this->assertSame(0.99, RoundingPolicy::charm(0.4, 'ending_99', 2));
        $this->assertSame(0.95, RoundingPolicy::charm(0.01, 'ending_95', 2));
    }

    #[Test]
    public function testCharmLeavesFreeAndNegativeAmountsExactlyAsTheyAre(): void
    {
        // A charmed zero would advertise 0.99 for a free line, and a charmed
        // negative would flip a discount row positive. Neither is a selling price.
        $this->assertSame(0.0, RoundingPolicy::charm(0.0, 'ending_99', 2));
        $this->assertSame(0.0, RoundingPolicy::charm(0.0, 'whole', 2));
        $this->assertSame(-34.55, RoundingPolicy::charm(-34.55, 'ending_99', 2));
        $this->assertSame(-34.55, RoundingPolicy::charm(-34.55, 'nearest_10', 2));
    }
}
