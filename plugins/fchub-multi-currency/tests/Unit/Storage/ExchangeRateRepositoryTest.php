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
}
