<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RatesCacheStoreTest extends TestCase
{
    #[Test]
    public function testGetReturnsNullWhenNotCached(): void
    {
        $store = new RatesCacheStore();

        $result = $store->get('USD', 'EUR');

        $this->assertNull($result);
    }

    #[Test]
    public function testSetThenGetReturnsRate(): void
    {
        $rate = MockBuilder::exchangeRate();
        $store = new RatesCacheStore();

        $store->set($rate);
        $result = $store->get('USD', 'EUR');

        $this->assertNotNull($result);
        $this->assertSame('0.92000000', $result->rate);
    }

    #[Test]
    public function testFlushClearsCache(): void
    {
        $rate = MockBuilder::exchangeRate();
        $store = new RatesCacheStore();

        $store->set($rate);
        $store->flush();

        $result = $store->get('USD', 'EUR');
        $this->assertNull($result);
    }
}
