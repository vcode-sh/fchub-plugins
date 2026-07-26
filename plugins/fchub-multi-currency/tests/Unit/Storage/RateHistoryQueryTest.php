<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\Queries\RateHistoryQuery;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RateHistoryQueryTest extends TestCase
{
    #[Test]
    public function testHistoryLookupPreparesTheRateTableIdentifierAndValues(): void
    {
        (new RateHistoryQuery())->forPair('usd', 'eur', 12);

        $this->assertStringContainsString(
            "FROM `wp_fchub_mc_rate_history` WHERE base_currency = 'USD' AND quote_currency = 'EUR'",
            implode(' ', $GLOBALS['wpdb']->queries),
        );
    }

    #[Test]
    public function testPruningPreparesTheRateTableIdentifier(): void
    {
        (new RateHistoryQuery())->pruneOlderThan(30);

        $this->assertStringContainsString(
            'DELETE FROM `wp_fchub_mc_rate_history` WHERE fetched_at <',
            implode(' ', $GLOBALS['wpdb']->queries),
        );
    }
}
