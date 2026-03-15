<?php

declare(strict_types=1);

namespace FluentCrm\App\Services\Funnel {
    class BaseTrigger
    {
        public function __construct()
        {
        }
    }
}

namespace FChubMemberships\Tests\Unit\Modules {

    use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    if (!defined('FLUENTCART_VERSION')) {
        define('FLUENTCART_VERSION', '1.0.0');
    }

    final class InfrastructureModuleRuntimeTest extends PluginTestCase
    {
        public function test_runtime_cron_handlers_execute_in_the_test_harness(): void
        {
            $insertedTables = [];

            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static fn(string $query): array => [];
            $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): array|object|null => null;
            $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int|string => 0;
            $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$insertedTables): int {
                $insertedTables[] = $table;
                $wpdb->insert_id++;
                return 1;
            };

            $module = new InfrastructureModule();
            $module->runValidityCheck();
            $module->runDripProcess();
            $module->runExpiryNotifications();
            $module->runDailyStats();
            $module->runAuditCleanup();
            $module->runTrialCheck();
            $module->runPlanSchedule();

            self::assertContains('wp_fchub_membership_stats_daily', $insertedTables);
            self::assertNotEmpty($GLOBALS['_fchub_test_queries']);
        }
    }
}
