<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class UninstallContractTest extends PluginTestCase
{
    private const TABLE_SUFFIXES = [
        'webhook_deliveries',
        'webhook_events',
        'crm_projection_jobs',
        'provider_operations',
        'entitlement_edges',
        'grant_sources',
        'audit_log',
        'stats_daily',
        'drip_notifications',
        'validity_log',
        'protection_rules',
        'event_locks',
        'mutation_requests',
        'grants',
        'plan_rules',
        'plans',
    ];

    private const RECURRING_HOOKS = [
        'fchub_memberships_validity_check',
        'fchub_memberships_drip_process',
        'fchub_memberships_expiry_notify',
        'fchub_memberships_daily_stats',
        'fchub_memberships_audit_cleanup',
        'fchub_memberships_trial_check',
        'fchub_memberships_plan_schedule',
        'fchub_memberships_webhook_reconcile',
        'fchub_memberships_webhook_cleanup',
    ];

    private const QUEUED_HOOKS = [
        'fchub_memberships_process_provider_operation',
        'fchub_memberships_process_crm_projection',
        'fchub_memberships_deliver_webhook',
        'fchub_memberships_send_email',
    ];

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOptOutLeavesEveryOwnedResourceUntouched(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'uninstall_remove_data' => 'no',
        ];
        $GLOBALS['_fchub_test_roles']['administrator'] = $this->administratorRole();

        $this->runUninstall();

        self::assertSame([], $GLOBALS['_fchub_test_queries']);
        self::assertSame([], $GLOBALS['_fchub_test_deleted_options'] ?? []);
        self::assertSame([], $GLOBALS['_fchub_test_cleared_events']);
        self::assertSame([], $GLOBALS['_fchub_test_as_unscheduled_actions'] ?? []);
        self::assertSame([], $GLOBALS['_fchub_test_unscheduled_events'] ?? []);
        self::assertSame([], $GLOBALS['_fchub_test_deleted_transients']);
        self::assertSame([], $GLOBALS['_fchub_test_removed_capabilities'] ?? []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOptInRemovesAllOwnedDataSchedulesAndAdministratorCapability(): void
    {
        $GLOBALS['_fchub_test_options'] = [
            'fchub_memberships_settings' => ['uninstall_remove_data' => 'yes'],
            'fchub_memberships_db_version' => '1.9.0',
            'fchub_memberships_feature_flags' => ['provider_graph' => true],
            'fchub_memberships_fluentcrm_reconciliation_health' => ['status' => 'healthy'],
            'unrelated_option' => 'preserve-me',
        ];
        $GLOBALS['_fchub_test_roles']['administrator'] = $this->administratorRole();
        $GLOBALS['_fchub_test_cron_array'] = [
            1773439200 => [
                'fchub_memberships_process_provider_operation' => [
                    'first' => ['args' => [17]],
                ],
                'unrelated_hook' => [
                    'second' => ['args' => ['keep']],
                ],
            ],
        ];

        $this->runUninstall();

        $dropQueries = array_values(array_filter(
            $GLOBALS['_fchub_test_queries'],
            static fn (array $query): bool => $query[0] === 'query'
                && str_starts_with($query[1], 'DROP TABLE IF EXISTS ')
        ));
        $droppedTableSuffixes = array_map(
            static fn (array $query): string => substr(
                $query[1],
                strlen('DROP TABLE IF EXISTS wp_fchub_membership_')
            ),
            $dropQueries
        );
        sort($droppedTableSuffixes, SORT_STRING);
        $expectedTableSuffixes = self::TABLE_SUFFIXES;
        sort($expectedTableSuffixes, SORT_STRING);
        self::assertSame($expectedTableSuffixes, $droppedTableSuffixes);

        self::assertEqualsCanonicalizing(
            [
                'fchub_memberships_settings',
                'fchub_memberships_db_version',
                'fchub_memberships_feature_flags',
                'fchub_memberships_fluentcrm_reconciliation_health',
            ],
            $GLOBALS['_fchub_test_deleted_options']
        );
        self::assertSame('preserve-me', $GLOBALS['_fchub_test_options']['unrelated_option']);

        self::assertEqualsCanonicalizing(
            array_map(
                static fn (string $hook): array => [$hook, []],
                array_merge(self::RECURRING_HOOKS, self::QUEUED_HOOKS)
            ),
            $GLOBALS['_fchub_test_cleared_events']
        );
        self::assertEqualsCanonicalizing(
            array_map(static fn (string $hook): array => [$hook, [], ''], self::QUEUED_HOOKS),
            $GLOBALS['_fchub_test_as_unscheduled_actions']
        );
        self::assertContains(
            [1773439200, 'fchub_memberships_process_provider_operation', [17]],
            $GLOBALS['_fchub_test_unscheduled_events']
        );
        self::assertNotContains(
            [1773439200, 'unrelated_hook', ['keep']],
            $GLOBALS['_fchub_test_unscheduled_events']
        );
        self::assertSame(
            ['fchub_memberships_plan_hierarchy'],
            $GLOBALS['_fchub_test_deleted_transients']
        );
        self::assertSame(
            [['administrator', 'manage_fchub_memberships']],
            $GLOBALS['_fchub_test_removed_capabilities']
        );
        self::assertSame(['administrator'], $GLOBALS['_fchub_test_role_lookups']);
    }

    private function runUninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', true);
        }

        require dirname(__DIR__, 3) . '/uninstall.php';
    }

    private function administratorRole(): object
    {
        return new class {
            public function remove_cap(string $capability): void
            {
                $GLOBALS['_fchub_test_removed_capabilities'][] = [
                    'administrator',
                    $capability,
                ];
            }
        };
    }
}
