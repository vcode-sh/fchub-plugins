<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\ValueObjects;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyContextTest extends TestCase
{
    #[Test]
    public function testBaseOnlyCreatesOneToOneContext(): void
    {
        $base = MockBuilder::currency('USD');
        $context = CurrencyContext::baseOnly($base);

        $this->assertTrue($context->isBaseDisplay);
        $this->assertSame('USD', $context->displayCurrency->code);
        $this->assertSame('USD', $context->baseCurrency->code);
        $this->assertSame('1.00000000', $context->rate->rate);
        $this->assertSame(ResolverSource::Fallback, $context->source);
    }

    #[Test]
    public function testBaseOnlyRateProviderIsManual(): void
    {
        $base = MockBuilder::currency('EUR');
        $context = CurrencyContext::baseOnly($base);

        $this->assertSame(RateProvider::Manual, $context->rate->provider);
    }
}
