<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Domain\Enums\RoundingMode;
use FChubMultiCurrency\Domain\Services\PriceProjector;
use FChubMultiCurrency\Domain\Services\RoundingPolicy;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class PriceProjectorTest extends TestCase
{
    #[Test]
    public function testProjectSameCurrencyReturnsOriginal(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'USD', 'rate' => '1.00000000',
        ]);

        $result = $projector->project(1000, $rate, 'USD');

        $this->assertSame(1000, $result->minorUnits);
        $this->assertSame('USD', $result->currencyCode);
    }

    #[Test]
    public function testProjectAppliesRateConversion(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.92000000',
        ]);

        $result = $projector->project(1000, $rate, 'EUR');

        $this->assertSame(920, $result->minorUnits);
        $this->assertSame('EUR', $result->currencyCode);
    }

    #[Test]
    public function testProjectRoundsCorrectly(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::Ceil));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'GBP', 'rate' => '0.79500000',
        ]);

        // 1000 * 0.795 = 795.0 — ceil should give 795
        $result = $projector->project(1000, $rate, 'GBP');

        $this->assertSame(795, $result->minorUnits);
    }

    #[Test]
    public function testProjectZeroAmountReturnsZero(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.92000000',
        ]);

        $result = $projector->project(0, $rate, 'EUR');

        $this->assertSame(0, $result->minorUnits);
    }

    #[Test]
    public function testProjectWithLargeRate(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'VND', 'rate' => '1234.56000000',
        ]);

        // 1000 * 1234.56 = 1234560
        $result = $projector->project(1000, $rate, 'VND');

        $this->assertSame(1234560, $result->minorUnits);
    }

    #[Test]
    public function testProjectWithVerySmallRate(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'BTC', 'rate' => '0.00850000',
        ]);

        // 100000 * 0.0085 = 850
        $result = $projector->project(100000, $rate, 'BTC');

        $this->assertSame(850, $result->minorUnits);
    }

    #[Test]
    public function testProjectPreservesDisplayCurrencyCode(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'JPY', 'rate' => '149.50000000',
        ]);

        $result = $projector->project(500, $rate, 'JPY');

        $this->assertSame('JPY', $result->currencyCode);
    }

    #[Test]
    public function testProjectWithFractionalResult(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.92345678',
        ]);

        // 999 * 0.92345678 = 922.53332322 → rounds to 923
        $result = $projector->project(999, $rate, 'EUR');

        $this->assertSame(923, $result->minorUnits);
    }

    #[Test]
    public function testProjectNegativeAmountPreservesSign(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.92000000',
        ]);

        // Negative amounts arise from refund deltas; -1000 * 0.92 = -920
        $result = $projector->project(-1000, $rate, 'EUR');

        $this->assertSame(-920, $result->minorUnits);
    }

    #[Test]
    public function testProjectWithZeroRateReturnsZero(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.00000000',
        ]);

        $result = $projector->project(1000, $rate, 'EUR');

        $this->assertSame(0, $result->minorUnits);
    }

    #[Test]
    public function testProjectBcmulPrecisionWithManyDecimalPlaces(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'EUR', 'rate' => '0.33333333',
        ]);

        // 300 * 0.33333333 = 99.999999 → rounds to 100 (not 99, which float imprecision could produce)
        $result = $projector->project(300, $rate, 'EUR');

        $this->assertSame(100, $result->minorUnits);
    }

    #[Test]
    public function testProjectVeryLargeAmountDoesNotOverflow(): void
    {
        $projector = new PriceProjector(new RoundingPolicy(RoundingMode::HalfUp));
        $rate = MockBuilder::exchangeRate([
            'base_currency' => 'USD', 'quote_currency' => 'VND', 'rate' => '25000.00000000',
        ]);

        // 9999999 * 25000 = 249999975000 — fits in 64-bit int
        $result = $projector->project(9999999, $rate, 'VND');

        $this->assertSame(249999975000, $result->minorUnits);
    }
}
