<?php

declare(strict_types=1);

namespace FluentCart\App\Modules\Integrations {
    #[\AllowDynamicProperties]
    class BaseIntegrationManager
    {
        public function __construct(string $title = '', string $key = '', int $priority = 10)
        {
        }

        public function register(): void
        {
            $GLOBALS['_fchub_test_registered_integrations'][] = static::class;
        }

        protected function actionFields(): array
        {
            return [];
        }
    }
}

namespace FChubMemberships\Tests\Unit\Modules {

    use FChubMemberships\Modules\Runtime\FluentCartRuntimeModule;
    use FChubMemberships\Tests\Unit\PluginTestCase;
    use PHPUnit\Framework\Attributes\DataProvider;

    if (!defined('FLUENTCART_VERSION')) {
        define('FLUENTCART_VERSION', '1.0.0');
    }

    final class RuntimeModuleBootFeatureTest extends PluginTestCase
    {
        public function test_boot_runtime_runs_migrations_and_registers_runtime_wiring(): void
        {
            $GLOBALS['_fchub_test_is_admin'] = true;
            $GLOBALS['_fchub_test_post_types'] = ['post', 'page'];
            $GLOBALS['_fchub_test_taxonomies'] = ['category'];
            $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '0.9.0';
            $GLOBALS['_fchub_test_registered_integrations'] = [];
            $this->installVerifiedSchema();

            $module = new FluentCartRuntimeModule();
            $module->bootRuntime();

            self::assertSame(FCHUB_MEMBERSHIPS_DB_VERSION, $GLOBALS['_fchub_test_options']['fchub_memberships_db_version']);
            self::assertNotEmpty($GLOBALS['_fchub_test_dbdelta']);
            self::assertContains('fchub_restrict', array_keys($GLOBALS['_fchub_test_shortcodes']));
            self::assertContains('fchub_my_memberships', array_keys($GLOBALS['_fchub_test_shortcodes']));
            self::assertArrayHasKey('fluent_cart/integration/global_integration_settings_memberships', $GLOBALS['_fchub_test_filters']);
            self::assertContains(
                'FChubMemberships\\Integration\\MembershipAccessIntegration',
                $GLOBALS['_fchub_test_registered_integrations']
            );
            self::assertArrayNotHasKey('fluent_cart/payments/subscription_status_changed', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fluent_cart/order_payment_failed', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('template_redirect', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('comments_open', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('wp_nav_menu_objects', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('category_edit_form_fields', $GLOBALS['_fchub_test_actions']);
            self::assertArrayHasKey('fluent_cart/integration/integration_options_plan_id', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('fluent_cart/integration/addons', $GLOBALS['_fchub_test_filters']);
            self::assertArrayHasKey('rest_api_init', $GLOBALS['_fchub_test_actions']);
        }

        public function test_boot_runtime_reconciles_administrator_capability_when_database_is_current(): void
        {
            $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = FCHUB_MEMBERSHIPS_DB_VERSION;
            $GLOBALS['_fchub_test_registered_integrations'] = [];
            $this->installVerifiedSchema();
            $administrator = new class {
                /** @var array<string, bool> */
                private array $capabilities = [];

                /** @var list<string> */
                public array $addedCapabilities = [];

                public function has_cap(string $capability): bool
                {
                    return $this->capabilities[$capability] ?? false;
                }

                public function add_cap(string $capability): void
                {
                    $this->capabilities[$capability] = true;
                    $this->addedCapabilities[] = $capability;
                }
            };
            $GLOBALS['_fchub_test_roles']['administrator'] = $administrator;

            (new FluentCartRuntimeModule())->bootRuntime();

            self::assertSame([], $GLOBALS['_fchub_test_dbdelta'], 'An equal DB version must skip schema migration.');
            self::assertTrue($administrator->has_cap('manage_fchub_memberships'));
            self::assertSame(['manage_fchub_memberships'], $administrator->addedCapabilities);
            self::assertSame(['administrator'], $GLOBALS['_fchub_test_role_lookups']);
        }

        public function test_rest_bootstrap_registers_webhook_operations_without_replacing_existing_routes(): void
        {
            (new FluentCartRuntimeModule())->registerRestRoutes();

            foreach ([
                'fchub-memberships/v1/admin/plans',
                'fchub-memberships/v1/check-access',
                'fchub-memberships/v1/admin/webhooks/health',
                'fchub-memberships/v1/admin/webhooks/deliveries',
                'fchub-memberships/v1/admin/webhooks/deliveries/(?P<id>\d+)/retry',
                'fchub-memberships/v1/admin/webhooks/test',
            ] as $route) {
                self::assertArrayHasKey($route, $GLOBALS['_fchub_test_routes']);
            }
        }

        public function test_boot_runtime_does_not_advance_version_when_schema_verification_fails(): void
        {
            $failures = [];
            $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '1.3.0';
            $GLOBALS['_fchub_test_registered_integrations'] = [];
            add_action(
                'fchub_memberships/migration_failed',
                static function (string $targetVersion, array $postconditionFailures) use (&$failures): void {
                    $failures[] = [$targetVersion, $postconditionFailures];
                }
            );

            (new FluentCartRuntimeModule())->bootRuntime();

            self::assertSame('1.3.0', $GLOBALS['_fchub_test_options']['fchub_memberships_db_version']);
            self::assertSame(FCHUB_MEMBERSHIPS_DB_VERSION, $failures[0][0]);
            self::assertContains('table:plans missing', $failures[0][1]);
            self::assertNotEmpty($GLOBALS['_fchub_test_fc_error_logs']);
            self::assertStringContainsString('table:plans missing', $GLOBALS['_fchub_test_fc_error_logs'][0][1]);
            self::assertArrayHasKey('template_redirect', $GLOBALS['_fchub_test_actions']);
        }

        public function test_equal_version_with_repairable_schema_reruns_and_succeeds(): void
        {
            $failures = [];
            $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = FCHUB_MEMBERSHIPS_DB_VERSION;
            $GLOBALS['_fchub_test_registered_integrations'] = [];
            $this->installVerifiedSchema(true);
            add_action(
                'fchub_memberships/migration_failed',
                static function (string $targetVersion, array $postconditionFailures) use (&$failures): void {
                    $failures[] = [$targetVersion, $postconditionFailures];
                }
            );

            (new FluentCartRuntimeModule())->bootRuntime();

            self::assertNotEmpty($GLOBALS['_fchub_test_dbdelta']);
            self::assertStringContainsString(
                'ADD COLUMN owner_token',
                str_replace('`', '', serialize($GLOBALS['_fchub_test_queries']))
            );
            self::assertSame(FCHUB_MEMBERSHIPS_DB_VERSION, $GLOBALS['_fchub_test_options']['fchub_memberships_db_version']);
            self::assertSame([], $failures);
            self::assertSame([], $GLOBALS['_fchub_test_fc_error_logs']);
            self::assertArrayHasKey('template_redirect', $GLOBALS['_fchub_test_actions']);
        }

        public function test_equal_version_with_unrepaired_schema_signals_specific_failure(): void
        {
            $failures = [];
            $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = FCHUB_MEMBERSHIPS_DB_VERSION;
            $GLOBALS['_fchub_test_registered_integrations'] = [];
            add_action(
                'fchub_memberships/migration_failed',
                static function (string $targetVersion, array $postconditionFailures) use (&$failures): void {
                    $failures[] = [$targetVersion, $postconditionFailures];
                }
            );

            (new FluentCartRuntimeModule())->bootRuntime();

            self::assertNotEmpty($GLOBALS['_fchub_test_dbdelta']);
            self::assertSame(FCHUB_MEMBERSHIPS_DB_VERSION, $failures[0][0]);
            self::assertContains('table:plans missing', $failures[0][1]);
            self::assertNotEmpty($GLOBALS['_fchub_test_fc_error_logs']);
            self::assertStringContainsString('table:plans missing', $GLOBALS['_fchub_test_fc_error_logs'][0][1]);
            self::assertArrayHasKey('template_redirect', $GLOBALS['_fchub_test_actions']);
        }

        #[DataProvider('legacyMappingFailureProvider')]
        public function test_legacy_mapping_write_failure_keeps_database_version_and_skips_backfill(
            string $queryNeedle,
            string $expectedFailure
        ): void
        {
            $failures = [];
            $GLOBALS['_fchub_test_options']['fchub_memberships_db_version'] = '1.3.0';
            $GLOBALS['_fchub_test_registered_integrations'] = [];
            $this->installVerifiedSchema();
            $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static fn(string $query): int|false =>
                str_contains($query, $queryNeedle) ? false : 0;
            add_action(
                'fchub_memberships/migration_failed',
                static function (string $targetVersion, array $postconditionFailures) use (&$failures): void {
                    $failures[] = [$targetVersion, $postconditionFailures];
                }
            );

            (new FluentCartRuntimeModule())->bootRuntime();

            self::assertSame('1.3.0', $GLOBALS['_fchub_test_options']['fchub_memberships_db_version']);
            self::assertContains($expectedFailure, $failures[0][1]);
            self::assertStringNotContainsString(
                "UPDATE wp_fchub_membership_event_locks\n             SET updated_at",
                serialize($GLOBALS['_fchub_test_queries'])
            );
            self::assertArrayHasKey('template_redirect', $GLOBALS['_fchub_test_actions']);
        }

        public static function legacyMappingFailureProvider(): array
        {
            return [
                'success mapping' => [
                    "SET state = 'succeeded'",
                    'event_locks: failed mapping legacy success rows',
                ],
                'failed mapping' => [
                    "SET state = 'failed'",
                    'event_locks: failed mapping legacy failed rows',
                ],
            ];
        }

        private function installVerifiedSchema(bool $repairMissingV4Column = false): void
        {
            $columns = [
                'id', 'title', 'slug', 'description', 'status', 'level', 'includes_plan_ids',
                'restriction_message', 'redirect_url', 'duration_type', 'duration_days', 'trial_days',
                'grace_period_days', 'settings', 'meta', 'scheduled_status', 'scheduled_at', 'created_at',
                'updated_at', 'plan_id', 'provider', 'resource_type', 'resource_id', 'drip_delay_days',
                'drip_type', 'drip_date', 'sort_order', 'user_id', 'source_type', 'source_id', 'feed_id',
                'grant_key', 'starts_at', 'expires_at', 'drip_available_at', 'trial_ends_at', 'source_ids',
                'cancellation_requested_at', 'cancellation_effective_at', 'cancellation_reason', 'renewal_count',
                'event_hash', 'order_id', 'subscription_id', 'trigger_name', 'processed_at', 'result', 'error',
                'state', 'owner_token', 'lease_expires_at', 'attempt_count', 'retryable', 'next_retry_at',
                'completed_at', 'last_error', 'request_key', 'fingerprint', 'response_status', 'response_body',
                'plan_ids', 'protection_mode', 'show_teaser', 'last_valid_at', 'expired_at', 'dispatched_at',
                'grant_id', 'plan_rule_id', 'notify_at', 'sent_at', 'retry_count', 'stat_date', 'active_count',
                'new_count', 'churned_count', 'revenue', 'entity_type', 'entity_id', 'action', 'actor_id',
                'actor_type', 'old_value', 'new_value', 'context', 'feed_scope', 'owner',
                'assignment_provenance', 'lifecycle', 'access_status', 'ended_at', 'end_reason', 'policy', 'edge_id',
                'operation_key', 'desired_action', 'origin_event', 'lease_owner', 'last_error_code',
                'last_error_message', 'eligible_at', 'request_version', 'last_attempt_at', 'last_success_at',
                'lease_token', 'event_id', 'event_type', 'schema_version', 'body', 'occurred_at',
                'destination_url', 'destination_hash', 'response_code', 'error_message', 'next_attempt_at',
                'delivered_at',
            ];
            $indexes = [
                'PRIMARY', 'slug', 'status', 'level', 'plan_provider_type', 'plan_sort', 'grant_key',
                'user_access', 'user_plan', 'source_lookup', 'feed_id', 'status_expires', 'status_drip',
                'idx_trial_ends', 'idx_cancellation_effective', 'idx_renewal_count', 'event_hash',
                'idx_event_lock_state_lease', 'idx_event_lock_completed', 'request_key', 'state_updated',
                'resource_lookup', 'subscription_id', 'pending_notify', 'grant_id', 'user_id', 'idx_retry',
                'date_plan', 'entity_lookup', 'actor_lookup', 'created_at', 'grant_source',
                'entitlement_identity', 'active_resource', 'source_lifecycle', 'plan_feed_lifecycle',
                'lifecycle_expires', 'lifecycle_drip', 'lifecycle_ended', 'plan_access_lifecycle_user',
                'operation_key', 'edge_state',
                'state_due', 'state_lease', 'state_eligible', 'completed_at',
                'status_due', 'status_lease', 'last_success',
                'retention_completed', 'event_id', 'type_occurred', 'event_destination', 'status_next',
            ];
            $repairComplete = !$repairMissingV4Column;
            $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|string|null {
                if (str_contains($query, 'fct_subscriptions')) {
                    return null;
                }
                if (str_contains($query, 'SHOW TABLES LIKE')) {
                    preg_match("/LIKE '([^']+)'/", $query, $matches);
                    return str_replace('\\_', '_', $matches[1] ?? 'present');
                }
                if (str_contains($query, 'information_schema.TABLE_CONSTRAINTS')) {
                    return 1;
                }
                if (str_contains($query, 'GET_LOCK(') || str_contains($query, 'RELEASE_LOCK(')) {
                    return 1;
                }
                return 0;
            };
            $GLOBALS['_fchub_test_wpdb_overrides']['query'] = static function (string $query) use (&$repairComplete): int {
                if (str_contains(str_replace('`', '', $query), 'ADD COLUMN owner_token')) {
                    $repairComplete = true;
                    return 1;
                }

                return 0;
            };
            $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query) use (
                $columns,
                $indexes,
                &$repairComplete
            ): array {
                if (str_contains($query, 'SHOW TABLE STATUS LIKE')) {
                    preg_match("/LIKE '([^']+)'/", $query, $matches);
                    return [[
                        'Name' => str_replace('\\_', '_', $matches[1] ?? 'present'),
                        'Engine' => 'InnoDB',
                    ]];
                }
                if (str_contains($query, 'SHOW COLUMNS FROM')) {
                    $availableColumns = $columns;
                    if (!$repairComplete) {
                        $availableColumns = array_values(array_diff($availableColumns, ['owner_token']));
                    }

                    $rows = array_map(static function (string $column) use ($query): array {
                        $v8Definition = self::v8ColumnDefinition($query, $column);
                        if ($v8Definition !== null) {
                            return ['Field' => $column] + $v8Definition;
                        }
                        $v7Definition = self::v7ColumnDefinition($query, $column);
                        if ($v7Definition !== null) {
                            return ['Field' => $column] + $v7Definition;
                        }
                        $v6Definition = self::v6ColumnDefinition($query, $column);
                        if ($v6Definition !== null) {
                            return ['Field' => $column] + $v6Definition;
                        }
                        $v5Definition = self::v5ColumnDefinition($query, $column);
                        if ($v5Definition !== null) {
                            return ['Field' => $column] + $v5Definition;
                        }

                        $definition = match ($column) {
                            'state' => ['Type' => 'varchar(20)', 'Null' => 'NO', 'Default' => 'processing'],
                            'owner_token' => ['Type' => 'varchar(64)', 'Null' => 'YES', 'Default' => null],
                            'lease_expires_at', 'next_retry_at', 'completed_at' => ['Type' => 'datetime', 'Null' => 'YES', 'Default' => null],
                            'attempt_count' => ['Type' => 'int unsigned', 'Null' => 'NO', 'Default' => '1'],
                            'retryable' => ['Type' => 'tinyint(1)', 'Null' => 'NO', 'Default' => '1'],
                            'updated_at' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null],
                            'last_error' => ['Type' => 'text', 'Null' => 'YES', 'Default' => null],
                            default => ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null],
                        };

                        return ['Field' => $column] + $definition;
                    }, $availableColumns);
                    if (preg_match("/ LIKE '([^']+)'/", $query, $matches)) {
                        $rows = array_values(array_filter(
                            $rows,
                            static fn(array $row): bool => $row['Field'] === $matches[1]
                        ));
                    }

                    return $rows;
                }
                if (str_contains($query, 'SHOW INDEX FROM')) {
                    $definitions = self::indexDefinitions($query);
                    $rows = [];
                    foreach ($indexes as $index) {
                        foreach ($definitions[$index] ?? ['id'] as $position => $column) {
                            $rows[] = [
                                'Key_name' => $index,
                                'Column_name' => $column,
                                'Seq_in_index' => $position + 1,
                                'Non_unique' => in_array($index, ['PRIMARY', 'slug', 'grant_key', 'event_hash', 'request_key', 'date_plan', 'grant_source', 'entitlement_identity', 'operation_key', 'event_id', 'event_destination'], true) ? 0 : 1,
                            ];
                        }
                    }
                    if (preg_match("/Key_name = '([^']+)'/", $query, $matches)) {
                        $rows = array_values(array_filter(
                            $rows,
                            static fn(array $row): bool => $row['Key_name'] === $matches[1]
                        ));
                    }

                    return $rows;
                }
                if (str_contains($query, 'information_schema.REFERENTIAL_CONSTRAINTS')) {
                    return [
                        ['CONSTRAINT_NAME' => 'fk_plan_rules_plan', 'COLUMN_NAME' => 'plan_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_plans', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'CASCADE'],
                        ['CONSTRAINT_NAME' => 'fk_grants_plan', 'COLUMN_NAME' => 'plan_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_plans', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'SET NULL'],
                        ['CONSTRAINT_NAME' => 'fk_drip_grant', 'COLUMN_NAME' => 'grant_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_grants', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'CASCADE'],
                        ['CONSTRAINT_NAME' => 'fk_drip_rule', 'COLUMN_NAME' => 'plan_rule_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_plan_rules', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'CASCADE'],
                        ['CONSTRAINT_NAME' => 'fk_provider_operations_edge', 'COLUMN_NAME' => 'edge_id', 'REFERENCED_TABLE_NAME' => 'wp_fchub_membership_entitlement_edges', 'REFERENCED_COLUMN_NAME' => 'id', 'DELETE_RULE' => 'RESTRICT'],
                    ];
                }
                return [];
            };
        }

        private static function v5ColumnDefinition(string $query, string $column): ?array
        {
            if (!str_contains($query, 'entitlement_edges') && !str_contains($query, 'provider_operations')) {
                return null;
            }

            $definitions = str_contains($query, 'entitlement_edges')
                ? [
                    'id' => ['bigint unsigned', 'NO', null], 'user_id' => ['bigint unsigned', 'NO', null],
                    'provider' => ['varchar(50)', 'NO', null], 'resource_type' => ['varchar(50)', 'NO', null],
                    'resource_id' => ['varchar(100)', 'NO', null], 'plan_id' => ['bigint unsigned', 'NO', '0'],
                    'feed_id' => ['bigint unsigned', 'NO', '0'], 'feed_scope' => ['varchar(20)', 'NO', 'external_unknown'],
                    'source_type' => ['varchar(30)', 'NO', null], 'source_id' => ['bigint unsigned', 'NO', '0'],
                    'owner' => ['varchar(20)', 'NO', 'external_unknown'],
                    'assignment_provenance' => ['varchar(20)', 'NO', 'unknown'],
                    'lifecycle' => ['varchar(20)', 'NO', 'active'],
                    'access_status' => ['varchar(20)', 'NO', 'active'],
                    'starts_at' => ['datetime', 'YES', null],
                    'expires_at' => ['datetime', 'YES', null], 'drip_available_at' => ['datetime', 'YES', null],
                    'ended_at' => ['datetime', 'YES', null], 'end_reason' => ['varchar(191)', 'YES', null],
                    'policy' => ['longtext', 'YES', null], 'created_at' => ['datetime', 'NO', null],
                    'updated_at' => ['datetime', 'NO', null],
                ]
                : [
                    'id' => ['bigint unsigned', 'NO', null], 'edge_id' => ['bigint unsigned', 'NO', null],
                    'operation_key' => ['char(64)', 'NO', null], 'desired_action' => ['varchar(30)', 'NO', null],
                    'origin_event' => ['varchar(100)', 'NO', null], 'state' => ['varchar(20)', 'NO', 'pending'],
                    'lease_owner' => ['varchar(64)', 'YES', null], 'lease_expires_at' => ['datetime', 'YES', null],
                    'attempt_count' => ['int unsigned', 'NO', '0'], 'retryable' => ['tinyint(1)', 'NO', '1'],
                    'next_retry_at' => ['datetime', 'YES', null], 'last_error_code' => ['varchar(100)', 'YES', null],
                    'last_error_message' => ['varchar(500)', 'YES', null], 'eligible_at' => ['datetime', 'YES', null],
                    'created_at' => ['datetime', 'NO', null], 'updated_at' => ['datetime', 'NO', null],
                    'completed_at' => ['datetime', 'YES', null],
                ];

            if (!isset($definitions[$column])) {
                return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
            }

            [$type, $nullability, $default] = $definitions[$column];
            return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
        }

        private static function v6ColumnDefinition(string $query, string $column): ?array
        {
            if (!str_contains($query, 'crm_projection_jobs')) {
                return null;
            }

            $definitions = [
                'user_id' => ['bigint unsigned', 'NO', null],
                'status' => ['varchar(20)', 'NO', 'pending'],
                'request_version' => ['bigint unsigned', 'NO', '1'],
                'lease_owner' => ['varchar(64)', 'YES', null],
                'lease_expires_at' => ['datetime', 'YES', null],
                'attempt_count' => ['int unsigned', 'NO', '0'],
                'next_retry_at' => ['datetime', 'YES', null],
                'last_error_code' => ['varchar(64)', 'YES', null],
                'last_attempt_at' => ['datetime', 'YES', null],
                'last_success_at' => ['datetime', 'YES', null],
                'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
            ];

            if (!isset($definitions[$column])) {
                return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
            }

            [$type, $nullability, $default] = $definitions[$column];
            return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
        }

        private static function v7ColumnDefinition(string $query, string $column): ?array
        {
            if (!str_contains($query, 'mutation_requests')) {
                return null;
            }

            $definitions = [
                'id' => ['bigint unsigned', 'NO', null],
                'request_key' => ['varchar(191)', 'NO', null],
                'fingerprint' => ['char(64)', 'NO', null],
                'user_id' => ['bigint unsigned', 'NO', null],
                'state' => ['varchar(20)', 'NO', 'reserved'],
                'response_status' => ['smallint unsigned', 'YES', null],
                'response_body' => ['longtext', 'YES', null],
                'lease_token' => ['varchar(64)', 'YES', null],
                'lease_expires_at' => ['datetime', 'YES', null],
                'attempt_count' => ['int unsigned', 'NO', '1'],
                'created_at' => ['datetime', 'NO', null],
                'updated_at' => ['datetime', 'NO', null],
                'completed_at' => ['datetime', 'YES', null],
            ];

            if (!isset($definitions[$column])) {
                return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
            }

            [$type, $nullability, $default] = $definitions[$column];
            return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
        }

        private static function v8ColumnDefinition(string $query, string $column): ?array
        {
            if (!str_contains($query, 'webhook_events') && !str_contains($query, 'webhook_deliveries')) {
                return null;
            }

            $definitions = str_contains($query, 'webhook_events')
                ? [
                    'id' => ['bigint unsigned', 'NO', null],
                    'event_id' => ['char(36)', 'NO', null],
                    'event_type' => ['varchar(64)', 'NO', null],
                    'schema_version' => ['varchar(10)', 'NO', '1.0'],
                    'body' => ['longtext', 'NO', null],
                    'occurred_at' => ['datetime', 'NO', null],
                    'created_at' => ['datetime', 'NO', null],
                ]
                : [
                    'id' => ['bigint unsigned', 'NO', null],
                    'event_id' => ['char(36)', 'NO', null],
                    'destination_url' => ['varchar(2048)', 'NO', null],
                    'destination_hash' => ['char(64)', 'NO', null],
                    'status' => ['varchar(20)', 'NO', 'pending'],
                    'attempt_count' => ['smallint unsigned', 'NO', '0'],
                    'lease_owner' => ['varchar(64)', 'YES', null],
                    'lease_expires_at' => ['datetime', 'YES', null],
                    'response_code' => ['smallint unsigned', 'YES', null],
                    'response_body' => ['text', 'YES', null],
                    'error_message' => ['text', 'YES', null],
                    'next_attempt_at' => ['datetime', 'YES', null],
                    'last_attempt_at' => ['datetime', 'YES', null],
                    'delivered_at' => ['datetime', 'YES', null],
                    'created_at' => ['datetime', 'NO', null],
                    'updated_at' => ['datetime', 'NO', null],
                ];

            if (!isset($definitions[$column])) {
                return ['Type' => 'bigint unsigned', 'Null' => 'YES', 'Default' => null];
            }

            [$type, $nullability, $default] = $definitions[$column];
            return ['Type' => $type, 'Null' => $nullability, 'Default' => $default];
        }

        /** @return array<string, list<string>> */
        private static function indexDefinitions(string $query = ''): array
        {
            if (str_contains($query, 'webhook_events')) {
                return [
                    'PRIMARY' => ['id'],
                    'event_id' => ['event_id'],
                    'type_occurred' => ['event_type', 'occurred_at'],
                ];
            }
            if (str_contains($query, 'webhook_deliveries')) {
                return [
                    'PRIMARY' => ['id'],
                    'event_destination' => ['event_id', 'destination_hash'],
                    'status_next' => ['status', 'next_attempt_at'],
                    'status_lease' => ['status', 'lease_expires_at'],
                    'created_at' => ['created_at'],
                ];
            }

            $definitions = [
                'PRIMARY' => ['id'], 'slug' => ['slug'], 'status' => ['status'], 'level' => ['level'],
                'plan_provider_type' => ['plan_id', 'provider', 'resource_type'], 'plan_sort' => ['plan_id', 'sort_order'],
                'grant_key' => ['grant_key'], 'user_access' => ['user_id', 'provider', 'resource_type', 'resource_id', 'status'],
                'user_plan' => ['user_id', 'plan_id', 'status'], 'source_lookup' => ['source_type', 'source_id'],
                'feed_id' => ['feed_id'], 'status_expires' => ['status', 'expires_at'],
                'status_drip' => ['status', 'drip_available_at'], 'idx_trial_ends' => ['trial_ends_at'],
                'idx_cancellation_effective' => ['cancellation_effective_at'],
                'idx_renewal_count' => ['plan_id', 'renewal_count'], 'event_hash' => ['event_hash'],
                'idx_event_lock_state_lease' => ['state', 'lease_expires_at'],
                'idx_event_lock_completed' => ['completed_at'], 'request_key' => ['request_key'],
                'state_updated' => ['state', 'updated_at'], 'resource_lookup' => ['resource_type', 'resource_id'],
                'subscription_id' => ['subscription_id'], 'pending_notify' => ['status', 'notify_at'],
                'grant_id' => ['grant_id'], 'user_id' => ['user_id'],
                'idx_retry' => ['status', 'next_retry_at', 'retry_count'], 'date_plan' => ['stat_date', 'plan_id'],
                'entity_lookup' => ['entity_type', 'entity_id'], 'actor_lookup' => ['actor_id', 'actor_type'],
                'created_at' => ['created_at'], 'grant_source' => ['grant_id', 'source_type', 'source_id'],
                'entitlement_identity' => ['user_id', 'provider', 'resource_type', 'resource_id', 'plan_id', 'feed_id', 'feed_scope', 'source_type', 'source_id'],
                'active_resource' => ['user_id', 'provider', 'resource_type', 'resource_id', 'lifecycle'],
                'source_lifecycle' => ['source_type', 'source_id', 'lifecycle'],
                'plan_feed_lifecycle' => ['plan_id', 'feed_id', 'feed_scope', 'lifecycle'],
                'lifecycle_expires' => ['lifecycle', 'expires_at'], 'lifecycle_drip' => ['lifecycle', 'drip_available_at'],
                'lifecycle_ended' => ['lifecycle', 'ended_at'],
                'plan_access_lifecycle_user' => ['plan_id', 'access_status', 'lifecycle', 'user_id'],
                'operation_key' => ['operation_key'],
                'edge_state' => ['edge_id', 'state'], 'state_due' => ['state', 'retryable', 'next_retry_at'],
                'state_lease' => ['state', 'lease_expires_at'], 'state_eligible' => ['state', 'eligible_at'],
                'completed_at' => ['completed_at'],
                'retention_completed' => ['completed_at', 'state', 'id'],
            ];

            if (str_contains($query, 'crm_projection_jobs')) {
                $definitions['PRIMARY'] = ['user_id'];
                $definitions['status_due'] = ['status', 'next_retry_at'];
                $definitions['status_lease'] = ['status', 'lease_expires_at'];
                $definitions['last_success'] = ['last_success_at'];
            }

            return $definitions;
        }
    }
}
