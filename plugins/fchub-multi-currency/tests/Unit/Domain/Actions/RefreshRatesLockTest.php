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
            'rate_provider'      => 'exchange_rate_api',
            'rate_provider_api_key' => 'api-key',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);

        $body = (string) json_encode([
            'result' => 'success',
            'conversion_rates' => [
                'USD' => '1.00000000',
                'EUR' => '0.92000000',
            ],
        ]);
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => $body,
            'response' => ['code' => 200],
        ];
        $GLOBALS['wp_mock_remote_body'] = $body;
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
    public function testSuccessfulStaleLockCasInvalidatesTheOptionsCache(): void
    {
        $this->setOption('fchub_mc_rate_refresh_lock', (string) (time() - 200));
        $this->seedValidSettings();
        wp_cache_set('fchub_mc_rate_refresh_lock', 'stale', 'options');

        $GLOBALS['wpdb_mock_update_result'] = 1;
        $this->makeAction()->execute();

        $this->assertFalse(wp_cache_get('fchub_mc_rate_refresh_lock', 'options'));
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
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'rate_provider'      => 'exchange_rate_api',
            'rate_provider_api_key' => 'api-key',
            'display_currencies' => [
                ['code' => 'EUR'],
            ],
        ]);
        $body = '{"result":"error"}';
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => $body,
            'response' => ['code' => 200],
        ];
        $GLOBALS['wp_mock_remote_body'] = $body;

        $result = $this->makeAction()->execute();

        $this->assertFalse($result);

        // Lock should still be released via finally block
        $this->assertFalse(
            get_option('fchub_mc_rate_refresh_lock', false),
            'Lock should be released even when rate refresh fails',
        );
    }
}
