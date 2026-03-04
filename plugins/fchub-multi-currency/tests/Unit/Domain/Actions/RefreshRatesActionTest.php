<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Domain\Actions\RefreshRatesAction;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RefreshRatesActionTest extends TestCase
{
    #[Test]
    public function testSkipsWhenLocked(): void
    {
        // Simulate lock via wp_cache
        $GLOBALS['wp_cache_store']['']['fchub_mc_rate_refresh_lock'] = true;

        $action = new RefreshRatesAction(new ExchangeRateRepository(), new RatesCacheStore());
        $action->execute();

        // No rates_refreshed hook should fire
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testHappyPathRefreshesRatesAndReleasesLock(): void
    {
        // Configure settings: manual provider, USD base, EUR display currency
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'rate_provider'      => 'manual',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        // Seed wpdb so ManualProvider->fetchRates() returns a rate via findAllLatest()
        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92000000',
                'provider'       => 'manual',
                'fetched_at'     => date('Y-m-d H:i:s'),
            ],
        ]);

        $action = new RefreshRatesAction(new ExchangeRateRepository(), new RatesCacheStore());
        $action->execute();

        $this->assertHookFired('fchub_mc/rates_refreshed');

        // Lock should be released after execution
        $this->assertFalse(
            wp_cache_get('fchub_mc_rate_refresh_lock'),
            'Lock should be released after execution',
        );
    }

    #[Test]
    public function testEmptyRatesDoesNotFireHookButReleasesLock(): void
    {
        // Configure settings: manual provider, USD base, EUR display currency
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'rate_provider'      => 'manual',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        // Seed wpdb with empty results so ManualProvider returns no rates
        $this->setWpdbMockResults([]);

        $action = new RefreshRatesAction(new ExchangeRateRepository(), new RatesCacheStore());
        $action->execute();

        $this->assertHookNotFired('fchub_mc/rates_refreshed');

        // Lock should still be released
        $this->assertFalse(
            wp_cache_get('fchub_mc_rate_refresh_lock'),
            'Lock should be released even when rates are empty',
        );
    }
}
