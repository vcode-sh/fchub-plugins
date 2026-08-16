<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Domain\Enums\StaleRateFallback;
use FChubMultiCurrency\Domain\Services\ExchangeRateService;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExchangeRateServiceTest extends TestCase
{
    #[Test]
    public function testSameCurrencyReturnsUnityRate(): void
    {
        $service = new ExchangeRateService(new ExchangeRateRepository(), new RatesCacheStore());

        $rate = $service->getRate('USD', 'USD');

        $this->assertNotNull($rate);
        $this->assertSame('1.00000000', $rate->rate);
    }

    #[Test]
    public function testReturnsCachedRate(): void
    {
        $cache = new RatesCacheStore();
        $cache->set(\FChubMultiCurrency\Tests\Support\MockBuilder::exchangeRate());

        $service = new ExchangeRateService(new ExchangeRateRepository(), $cache);
        $rate = $service->getRate('USD', 'EUR');

        $this->assertNotNull($rate);
        $this->assertSame('0.92000000', $rate->rate);
    }

    #[Test]
    public function testFallsBackToRepositoryWhenNotCached(): void
    {
        // Set up wpdb to return a rate row
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.93000000',
            'provider'       => 'manual',
            'fetched_at'     => date('Y-m-d H:i:s'),
        ]);

        $service = new ExchangeRateService(new ExchangeRateRepository(), new RatesCacheStore());
        $rate = $service->getRate('USD', 'EUR');

        $this->assertNotNull($rate);
        $this->assertSame('0.93000000', $rate->rate);
    }

    #[Test]
    public function testReturnsNullWhenNotFound(): void
    {
        $this->setWpdbMockRow(null);

        $service = new ExchangeRateService(new ExchangeRateRepository(), new RatesCacheStore());
        $rate = $service->getRate('USD', 'JPY');

        $this->assertNull($rate);
    }

    #[Test]
    public function testBaseFallbackRejectsAStaleCachedRate(): void
    {
        $cache = new RatesCacheStore();
        $cache->set(MockBuilder::exchangeRate([
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 7201),
        ]));
        $service = new ExchangeRateService(new ExchangeRateRepository(), $cache);

        $rate = $service->getUsableRate('USD', 'EUR', 7200, StaleRateFallback::Base);

        $this->assertNull($rate);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }

    #[Test]
    public function testLastKnownFallbackReturnsTheSameStaleCachedRate(): void
    {
        $cache = new RatesCacheStore();
        $cache->set(MockBuilder::exchangeRate([
            'rate' => '0.87000000',
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 7201),
        ]));
        $service = new ExchangeRateService(new ExchangeRateRepository(), $cache);

        $rate = $service->getUsableRate('USD', 'EUR', 7200, StaleRateFallback::LastKnown);

        $this->assertNotNull($rate);
        $this->assertSame('0.87000000', $rate->rate);
    }

    #[Test]
    public function testRateAtTheAgeBoundaryIsStillUsable(): void
    {
        $cache = new RatesCacheStore();
        $cache->set(MockBuilder::exchangeRate([
            'fetched_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        ]));
        $service = new ExchangeRateService(new ExchangeRateRepository(), $cache);

        $rate = $service->getUsableRate('USD', 'EUR', 3600, StaleRateFallback::Base);

        $this->assertNotNull($rate);
    }

    #[Test]
    public function testFutureDatedRateIsNeverUsableForBaseFallback(): void
    {
        $cache = new RatesCacheStore();
        $cache->set(MockBuilder::exchangeRate([
            'fetched_at' => gmdate('Y-m-d H:i:s', time() + 60),
        ]));
        $service = new ExchangeRateService(new ExchangeRateRepository(), $cache);

        $rate = $service->getUsableRate('USD', 'EUR', 3600, StaleRateFallback::Base);

        $this->assertNull($rate);
    }

    #[Test]
    public function testBaseCurrencyUnityRateNeverDependsOnRateHistory(): void
    {
        $service = new ExchangeRateService(new ExchangeRateRepository(), new RatesCacheStore());

        $rate = $service->getUsableRate('USD', 'USD', 1, StaleRateFallback::Base);

        $this->assertNotNull($rate);
        $this->assertSame('1.00000000', $rate->rate);
        $this->assertSame([], $GLOBALS['wpdb']->queries);
    }
}
