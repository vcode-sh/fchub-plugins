<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit;

use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class UninstallCleanupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Define WP_UNINSTALL_PLUGIN if not already defined
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }
    }

    private function runUninstall(): void
    {
        // Set the remove_data flag so uninstall actually cleans up
        $this->setOption('fchub_mc_settings', ['uninstall_remove_data' => 'yes']);

        // Simulate the options that should be cleaned up
        $this->setOption('fchub_mc_db_version', '1.0.0');
        $this->setOption('fchub_mc_feature_flags', ['some_flag' => true]);
        $this->setOption('fchub_mc_rate_refresh_lock', (string) time());

        require FCHUB_MC_PATH . 'uninstall.php';
    }

    #[Test]
    public function testRateRefreshLockOptionIsDeleted(): void
    {
        $this->setOption('fchub_mc_rate_refresh_lock', (string) time());

        $this->runUninstall();

        $this->assertFalse(
            get_option('fchub_mc_rate_refresh_lock', false),
            'fchub_mc_rate_refresh_lock option should be deleted during uninstall',
        );
    }

    #[Test]
    public function testSettingsOptionIsDeleted(): void
    {
        $this->runUninstall();

        $this->assertFalse(
            get_option('fchub_mc_settings', false),
            'fchub_mc_settings option should be deleted during uninstall',
        );
    }

    #[Test]
    public function testDbVersionOptionIsDeleted(): void
    {
        $this->runUninstall();

        $this->assertFalse(
            get_option('fchub_mc_db_version', false),
            'fchub_mc_db_version option should be deleted during uninstall',
        );
    }

    #[Test]
    public function testFeatureFlagsOptionIsDeleted(): void
    {
        $this->runUninstall();

        $this->assertFalse(
            get_option('fchub_mc_feature_flags', false),
            'fchub_mc_feature_flags option should be deleted during uninstall',
        );
    }

    #[Test]
    public function testRateLimiterTransientsCleanupQueryExecuted(): void
    {
        $this->runUninstall();

        $queries = $GLOBALS['wpdb']->queries;

        // Find the query that deletes rate limiter transients
        $found = false;
        foreach ($queries as $query) {
            if (str_contains($query, '_transient_fchub_mc_rl_') || str_contains($query, '_transient_timeout_fchub_mc_rl_')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Uninstall should execute a query to delete rate limiter transients matching _transient_fchub_mc_rl_*');
    }

    #[Test]
    public function testCustomTablesDropQueryExecuted(): void
    {
        $this->runUninstall();

        $queries = $GLOBALS['wpdb']->queries;

        $foundEventLog = false;
        $foundRateHistory = false;

        foreach ($queries as $query) {
            if (str_contains($query, 'fchub_mc_event_log')) {
                $foundEventLog = true;
            }
            if (str_contains($query, 'fchub_mc_rate_history')) {
                $foundRateHistory = true;
            }
        }

        $this->assertTrue($foundEventLog, 'Uninstall should drop the fchub_mc_event_log table');
        $this->assertTrue($foundRateHistory, 'Uninstall should drop the fchub_mc_rate_history table');
    }

    #[Test]
    public function testDiagnosticsTransientDeleted(): void
    {
        set_transient('fchub_mc_has_stale_rates', true);

        $this->runUninstall();

        $this->assertFalse(
            get_transient('fchub_mc_has_stale_rates'),
            'Diagnostics transient should be deleted during uninstall',
        );
    }

    #[Test]
    public function testObjectCacheGroupFlushed(): void
    {
        // Set some cache data in the group
        wp_cache_set('test_key', 'test_value', 'fchub_mc_rates');

        $this->runUninstall();

        // The entire group should be flushed
        $this->assertFalse(
            wp_cache_get('test_key', 'fchub_mc_rates'),
            'Object cache group should be flushed during uninstall',
        );
    }

    #[Test]
    public function testNoCleanupWhenRemoveDataIsNotSet(): void
    {
        // Set settings without the remove_data flag
        $this->setOption('fchub_mc_settings', []);
        $this->setOption('fchub_mc_rate_refresh_lock', (string) time());
        $this->setOption('fchub_mc_db_version', '1.0.0');

        require FCHUB_MC_PATH . 'uninstall.php';

        // Options should still exist
        $this->assertNotFalse(
            get_option('fchub_mc_rate_refresh_lock', false),
            'Options should be preserved when uninstall_remove_data is not set',
        );
    }
}
