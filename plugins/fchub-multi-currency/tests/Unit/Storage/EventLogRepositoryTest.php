<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\EventLogRepository;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EventLogRepositoryTest extends TestCase
{
    #[Test]
    public function testFindByUserPreparesTheEventTableIdentifier(): void
    {
        (new EventLogRepository())->findByUser(42, 10, 5);

        $this->assertStringContainsString(
            'FROM `wp_fchub_mc_event_log` WHERE user_id = 42',
            implode(' ', $GLOBALS['wpdb']->queries),
        );
    }

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
        $this->assertStringContainsString(
            'FROM `wp_fchub_mc_event_log` GROUP BY event',
            implode(' ', $GLOBALS['wpdb']->queries),
        );
    }

    /**
     * Visitor traffic appends to this table on every switch, so without a
     * retention delete it grows for the life of the site — rate_history gets
     * one, and the event log needs the same.
     */
    #[Test]
    public function testPruneOlderThanDeletesByAgeOnly(): void
    {
        $repository = new EventLogRepository();

        $deleted = $repository->pruneOlderThan(90);

        $this->assertIsInt($deleted);
        $lastQuery = end($GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('DELETE FROM', $lastQuery);
        $this->assertStringContainsString('wp_fchub_mc_event_log', $lastQuery);
        $this->assertStringContainsString('created_at <', $lastQuery);
        $this->assertStringNotContainsString('user_id', $lastQuery, 'Retention is by age, never by user.');
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
