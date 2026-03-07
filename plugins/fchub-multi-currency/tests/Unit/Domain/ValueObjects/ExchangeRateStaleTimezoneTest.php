<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\ValueObjects;

use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExchangeRateStaleTimezoneTest extends TestCase
{
    #[Test]
    public function testIsStaleComparesAgainstUtcTime(): void
    {
        // Create a rate fetched exactly 2 hours ago in UTC
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);

        $rate = ExchangeRate::from([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92',
            'provider'       => 'manual',
            'fetched_at'     => $twoHoursAgo,
        ]);

        // With a 1-hour threshold, 2 hours old should be stale
        $this->assertTrue($rate->isStale(3600));

        // With a 3-hour threshold, 2 hours old should not be stale
        $this->assertFalse($rate->isStale(10800));
    }

    #[Test]
    public function testIsStaleAtExactThresholdBoundary(): void
    {
        // Rate fetched exactly at the staleness threshold (e.g., 3600 seconds ago)
        $exactlyAtThreshold = gmdate('Y-m-d H:i:s', time() - 3600);

        $rate = ExchangeRate::from([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92',
            'provider'       => 'manual',
            'fetched_at'     => $exactlyAtThreshold,
        ]);

        // At exactly the threshold, (time() - fetched) == maxAge, which is NOT > maxAge
        // So it should NOT be stale (uses strict > comparison)
        $this->assertFalse($rate->isStale(3600));
    }

    #[Test]
    public function testIsStaleOneSecondPastThreshold(): void
    {
        // Rate fetched 1 second past the threshold
        $justPastThreshold = gmdate('Y-m-d H:i:s', time() - 3601);

        $rate = ExchangeRate::from([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92',
            'provider'       => 'manual',
            'fetched_at'     => $justPastThreshold,
        ]);

        $this->assertTrue($rate->isStale(3600));
    }

    #[Test]
    public function testFromUsesGmdateAsDefaultFetchedAt(): void
    {
        // When fetched_at is not provided, ExchangeRate::from() uses gmdate('Y-m-d H:i:s')
        $before = gmdate('Y-m-d H:i:s');

        $rate = ExchangeRate::from([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92',
            'provider'       => 'manual',
            // No fetched_at — should default to gmdate()
        ]);

        $after = gmdate('Y-m-d H:i:s');

        // The fetched_at should be between $before and $after (UTC)
        $this->assertGreaterThanOrEqual($before, $rate->fetchedAt);
        $this->assertLessThanOrEqual($after, $rate->fetchedAt);

        // A freshly created rate should not be stale even with a short threshold
        $this->assertFalse($rate->isStale(60));
    }

    #[Test]
    public function testIsStaleReturnsTrueForInvalidFetchedAt(): void
    {
        $rate = ExchangeRate::from([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92',
            'provider'       => 'manual',
            'fetched_at'     => 'not-a-valid-date',
        ]);

        // Invalid date should always be considered stale
        $this->assertTrue($rate->isStale(3600));
    }

    #[Test]
    public function testIsStaleUsesTimeNotCurrentTime(): void
    {
        // The implementation uses time() (UTC) rather than current_time() (WP local)
        // This test verifies the behaviour is consistent regardless of WP timezone setting
        $fetchedAt = gmdate('Y-m-d H:i:s', time() - 1800); // 30 minutes ago

        $rate = ExchangeRate::from([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92',
            'provider'       => 'manual',
            'fetched_at'     => $fetchedAt,
        ]);

        // 30 min old, with 1 hour threshold: not stale
        $this->assertFalse($rate->isStale(3600));

        // 30 min old, with 29 min threshold: stale
        $this->assertTrue($rate->isStale(1740));
    }

    #[Test]
    public function testIsStaleWorksCorrectlyUnderNonUtcTimezone(): void
    {
        $originalTz = date_default_timezone_get();

        try {
            date_default_timezone_set('America/New_York');

            // Rate fetched 2 hours ago (UTC)
            $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);

            $staleRate = ExchangeRate::from([
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92',
                'provider'       => 'manual',
                'fetched_at'     => $twoHoursAgo,
            ]);

            // 1-hour threshold, 2 hours old: must be stale
            $this->assertTrue($staleRate->isStale(3600));

            // Rate fetched 30 minutes ago (UTC)
            $thirtyMinAgo = gmdate('Y-m-d H:i:s', time() - 1800);

            $freshRate = ExchangeRate::from([
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92',
                'provider'       => 'manual',
                'fetched_at'     => $thirtyMinAgo,
            ]);

            // 1-hour threshold, 30 min old: must NOT be stale
            $this->assertFalse($freshRate->isStale(3600));
        } finally {
            date_default_timezone_set($originalTz);
        }
    }
}
