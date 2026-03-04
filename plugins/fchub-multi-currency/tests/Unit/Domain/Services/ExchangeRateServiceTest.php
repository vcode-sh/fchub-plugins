<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Domain\Services\ExchangeRateService;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
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
}
