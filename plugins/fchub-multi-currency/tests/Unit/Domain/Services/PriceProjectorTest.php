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
}
