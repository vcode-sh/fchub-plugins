<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\EventLogRepository;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EventLogRepositoryTest extends TestCase
{
    #[Test]
    public function testCountByEventBuildsAggregateMap(): void
    {
        $this->setWpdbMockResults([
            (object) ['event' => 'context_switched', 'total' => 4],
            (object) ['event' => 'rates_refreshed', 'total' => 2],
        ]);

        $counts = (new EventLogRepository())->countByEvent();

        $this->assertSame(4, $counts['context_switched']);
        $this->assertSame(2, $counts['rates_refreshed']);
    }

    #[Test]
    public function testTopCurrenciesForEventAggregatesPayloads(): void
    {
        $this->setWpdbMockResults([
            (object) ['payload' => '{"currency":"EUR"}'],
            (object) ['payload' => '{"currency":"EUR"}'],
            (object) ['payload' => '{"currency":"USD"}'],
        ]);

        $rows = (new EventLogRepository())->topCurrenciesForEvent('context_switched', 5);

        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertSame(2, $rows[0]['total']);
        $this->assertSame('USD', $rows[1]['currency']);
    }
}
