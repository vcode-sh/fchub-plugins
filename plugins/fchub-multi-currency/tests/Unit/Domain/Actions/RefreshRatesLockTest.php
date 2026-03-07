<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Domain\Actions\RefreshRatesAction;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class RefreshRatesLockTest extends TestCase
{
    private function makeAction(): RefreshRatesAction
    {
        return new RefreshRatesAction(new ExchangeRateRepository(), new RatesCacheStore());
    }

    private function seedValidSettings(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'rate_provider'      => 'manual',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        $this->setWpdbMockResults([
            [
                'base_currency'  => 'USD',
                'quote_currency' => 'EUR',
                'rate'           => '0.92000000',
                'provider'       => 'manual',
                'fetched_at'     => gmdate('Y-m-d H:i:s'),
            ],
        ]);
    }

    #[Test]
    public function testLockAcquiredWhenNoExistingLock(): void
    {
        $this->seedValidSettings();

        // No lock exists — should acquire and execute
        $result = $this->makeAction()->execute();

        $this->assertTrue($result);
        $this->assertHookFired('fchub_mc/rates_refreshed');

        // Lock should be released after execution
        $this->assertFalse(
            get_option('fchub_mc_rate_refresh_lock', false),
            'Lock should be released after successful execution',
        );
    }

    #[Test]
    public function testLockBlocksWhenFreshLockExists(): void
    {
        // Set a fresh lock (just now)
        $this->setOption('fchub_mc_rate_refresh_lock', (string) time());

        $this->seedValidSettings();

        $result = $this->makeAction()->execute();

        $this->assertFalse($result);
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testLockBlocksWhenLockIsWithinTtl(): void
    {
        // Lock set 60 seconds ago — well within the 120-second TTL
        $this->setOption('fchub_mc_rate_refresh_lock', (string) (time() - 60));

        $this->seedValidSettings();

        $result = $this->makeAction()->execute();

        $this->assertFalse($result);
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testStaleLockIsOverriddenViaCas(): void
    {
        // Lock set 200 seconds ago — past the 120-second TTL
        $this->setOption('fchub_mc_rate_refresh_lock', (string) (time() - 200));

        $this->seedValidSettings();

        // wpdb->update returns 1 (CAS succeeds)
        $GLOBALS['wpdb_mock_update_result'] = 1;

        $result = $this->makeAction()->execute();

        $this->assertTrue($result);
        $this->assertHookFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testStaleLockCasFailurePreventsExecution(): void
    {
        // Lock set 200 seconds ago — past the 120-second TTL
        $this->setOption('fchub_mc_rate_refresh_lock', (string) (time() - 200));

        $this->seedValidSettings();

        // wpdb->update returns 0 (CAS fails — another process got the lock)
        $GLOBALS['wpdb_mock_update_result'] = 0;

        $result = $this->makeAction()->execute();

        $this->assertFalse($result);
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testLockReleasedEvenOnProviderFailure(): void
    {
        // Configure settings with a provider that will fail
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'rate_provider'      => 'manual',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        // Empty results = no rates returned
        $this->setWpdbMockResults([]);

        $result = $this->makeAction()->execute();

        $this->assertFalse($result);

        // Lock should still be released via finally block
        $this->assertFalse(
            get_option('fchub_mc_rate_refresh_lock', false),
            'Lock should be released even when rate refresh fails',
        );
    }
}
