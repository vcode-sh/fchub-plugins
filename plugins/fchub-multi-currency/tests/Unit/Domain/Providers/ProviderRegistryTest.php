<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Providers;

use FChubMultiCurrency\Domain\Providers\EcbProvider;
use FChubMultiCurrency\Domain\Providers\ExchangeRateApiProvider;
use FChubMultiCurrency\Domain\Providers\ManualProvider;
use FChubMultiCurrency\Domain\Providers\OpenExchangeRatesProvider;
use FChubMultiCurrency\Domain\Providers\ProviderRegistry;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProviderRegistryTest extends TestCase
{
    #[Test]
    public function testResolvesExchangeRateApiByDefault(): void
    {
        $provider = ProviderRegistry::resolve(new OptionStore());

        $this->assertInstanceOf(ExchangeRateApiProvider::class, $provider);
    }

    #[Test]
    public function testResolvesEcbProvider(): void
    {
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'ecb']);

        $provider = ProviderRegistry::resolve(new OptionStore());

        $this->assertInstanceOf(EcbProvider::class, $provider);
    }

    #[Test]
    public function testResolvesOpenExchangeRates(): void
    {
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'open_exchange_rates']);

        $provider = ProviderRegistry::resolve(new OptionStore());

        $this->assertInstanceOf(OpenExchangeRatesProvider::class, $provider);
    }

    #[Test]
    public function testResolvesManualProvider(): void
    {
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'manual']);

        $provider = ProviderRegistry::resolve(new OptionStore());

        $this->assertInstanceOf(ManualProvider::class, $provider);
    }

    #[Test]
    public function testInvalidSlugFallsBackToExchangeRateApi(): void
    {
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'invalid_slug']);

        $provider = ProviderRegistry::resolve(new OptionStore());

        $this->assertInstanceOf(ExchangeRateApiProvider::class, $provider);
    }
}
