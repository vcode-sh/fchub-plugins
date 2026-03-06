<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExchangeRateRepositoryTest extends TestCase
{
    #[Test]
    public function testFindLatestReturnsNullWhenNoRows(): void
    {
        $this->setWpdbMockRow(null);

        $repo = new ExchangeRateRepository();
        $result = $repo->findLatest('USD', 'EUR');

        $this->assertNull($result);
    }

    #[Test]
    public function testFindLatestReturnsExchangeRate(): void
    {
        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => '2026-01-01 12:00:00',
        ]);

        $repo = new ExchangeRateRepository();
        $result = $repo->findLatest('USD', 'EUR');

        $this->assertNotNull($result);
        $this->assertSame('USD', $result->baseCurrency);
        $this->assertSame('EUR', $result->quoteCurrency);
        $this->assertSame('0.92000000', $result->rate);
    }

    #[Test]
    public function testInsertCreatesRow(): void
    {
        $rate = MockBuilder::exchangeRate();
        $repo = new ExchangeRateRepository();

        $repo->insert($rate);

        $this->assertStringContainsString('INSERT INTO', $GLOBALS['wpdb']->queries[0] ?? '');
    }

    #[Test]
    public function testFindAllLatestReturnsEmptyArrayWhenNoResults(): void
    {
        $this->setWpdbMockResults([]);

        $repo = new ExchangeRateRepository();
        $result = $repo->findAllLatest('USD');

        $this->assertSame([], $result);
    }

    #[Test]
    public function testFindAllLatestReturnsExchangeRateArray(): void
    {
        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92000000',
                'provider'       => 'manual',
                'fetched_at'     => '2026-01-01 12:00:00',
            ],
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'GBP',
                'rate'           => '0.79000000',
                'provider'       => 'manual',
                'fetched_at'     => '2026-01-01 12:00:00',
            ],
        ]);

        $repo = new ExchangeRateRepository();
        $result = $repo->findAllLatest('USD');

        $this->assertCount(2, $result);
        $this->assertSame('USD', $result[0]->baseCurrency);
        $this->assertSame('EUR', $result[0]->quoteCurrency);
        $this->assertSame('0.92000000', $result[0]->rate);
        $this->assertSame('USD', $result[1]->baseCurrency);
        $this->assertSame('GBP', $result[1]->quoteCurrency);
        $this->assertSame('0.79000000', $result[1]->rate);
    }
}
