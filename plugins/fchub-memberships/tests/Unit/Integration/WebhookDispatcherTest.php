<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookDispatcher;
use FChubMemberships\Integration\WebhookEndpointPolicy;
use FChubMemberships\Integration\WebhookQueue;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookDispatcherTest extends PluginTestCase
{
    private const EVENT_ID = '00000000-0000-4000-8000-000000000000';

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_as_actions'] = [];
        $GLOBALS['_fchub_test_single_events'] = [];
        $GLOBALS['_fchub_test_uuid_queue'] = [];
        unset(
            $GLOBALS['_fchub_test_as_has_scheduled_action_override'],
            $GLOBALS['_fchub_test_as_schedule_single_action_override']
        );
        $GLOBALS['_fchub_test_users'][21] = (object) [
            'ID' => 21,
            'display_name' => 'Alice Example',
            'user_email' => 'alice@example.com',
            'cookie' => 'cookie-sentinel',
        ];
    }

    public function test_registers_all_public_lifecycle_hooks_even_when_webhooks_are_disabled(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'no',
            'webhook_urls' => '',
        ];

        (new WebhookDispatcher(endpointPolicy: $this->endpointPolicy()))->register();

        foreach ([
            'fchub_memberships/grant_created' => 3,
            'fchub_memberships/grant_revoked' => 4,
            'fchub_memberships/grant_expired' => 1,
            'fchub_memberships/grant_paused' => 2,
            'fchub_memberships/grant_resumed' => 1,
        ] as $hook => $acceptedArgs) {
            self::assertArrayHasKey($hook, $GLOBALS['_fchub_test_actions']);
            self::assertSame(
                20,
                $GLOBALS['_fchub_test_action_registrations'][$hook][0]['priority']
            );
            self::assertSame(
                $acceptedArgs,
                $GLOBALS['_fchub_test_action_registrations'][$hook][0]['accepted_args']
            );
        }
    }

    public function test_loads_fresh_settings_and_persists_every_delivery_before_scheduling_outside_the_lock(): void
    {
        $events = [];
        $deliveries = [];
        $timeline = [];
        $this->installDurableDatabase($events, $deliveries, $timeline);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_enabled' => 'no',
            'webhook_urls' => '',
        ];
        $dispatcher = new WebhookDispatcher(endpointPolicy: $this->endpointPolicy());
        $dispatcher->register();

        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $this->readySettings(
            "https://example.com/hook\nhttps://example.com/hook\nhttps://example.com/second"
        );
        $GLOBALS['_fchub_test_as_schedule_single_action_override'] = static function (
            int $timestamp,
            string $hook,
            array $args,
            string $group,
            bool $unique,
            int $priority
        ) use (&$timeline): int {
            $timeline[] = 'schedule:' . $args[0];
            $GLOBALS['_fchub_test_as_actions'][] = [$timestamp, $hook, $args, $group, $unique, $priority];
            return count($GLOBALS['_fchub_test_as_actions']);
        };

        $dispatcher->onGrantCreated(21, 5, [
            'source_type' => 'order',
            'source_id' => 77,
            'webhook_secret' => 'context-secret-sentinel',
        ]);

        self::assertCount(1, $events);
        self::assertCount(2, $deliveries);
        $deliveryIds = array_column($deliveries, 'id');
        self::assertSame([
            'lock:acquire',
            'event',
            'delivery:' . $deliveryIds[0],
            'delivery:' . $deliveryIds[1],
            'lock:release',
            'schedule:' . $deliveryIds[0],
            'schedule:' . $deliveryIds[1],
        ], $timeline);
        self::assertSame(
            ['https://example.com/hook', 'https://example.com/second'],
            array_column($deliveries, 'destination_url')
        );
        self::assertSame(['pending', 'pending'], array_column($deliveries, 'status'));
        self::assertSame([
            [$deliveryIds[0]],
            [$deliveryIds[1]],
        ], array_column($GLOBALS['_fchub_test_as_actions'], 2));
        foreach ($GLOBALS['_fchub_test_as_actions'] as $action) {
            self::assertCount(1, $action[2]);
            self::assertIsInt($action[2][0]);
            self::assertStringNotContainsString('example.com', serialize($action));
            self::assertStringNotContainsString('secret', serialize($action));
            self::assertStringNotContainsString('grant_created', serialize($action));
        }
    }

    public function test_disabled_and_needs_setup_settings_fail_closed_at_event_time(): void
    {
        $events = [];
        $deliveries = [];
        $timeline = [];
        $this->installDurableDatabase($events, $deliveries, $timeline);
        $dispatcher = new WebhookDispatcher(endpointPolicy: $this->endpointPolicy());

        foreach ([
            ['webhook_enabled' => 'no', 'webhook_urls' => 'https://example.com/hook', 'webhook_secret' => 'secret'],
            ['webhook_enabled' => 'yes', 'webhook_urls' => 'https://example.com/hook'],
            ['webhook_enabled' => 'yes', 'webhook_urls' => 'not-a-url', 'webhook_secret' => 'secret'],
            ['webhook_enabled' => 'yes', 'webhook_urls' => '', 'webhook_secret' => 'secret'],
        ] as $settings) {
            $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $settings;
            $dispatcher->dispatch('grant_created', ['user' => ['id' => 21]]);
        }

        self::assertSame([], $events);
        self::assertSame([], $deliveries);
        self::assertSame([], $GLOBALS['_fchub_test_as_actions']);
    }

    public function test_projects_all_five_events_without_recursive_credentials_and_normalises_typed_expiry(): void
    {
        $events = [];
        $deliveries = [];
        $timeline = [];
        $this->installDurableDatabase($events, $deliveries, $timeline);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $this->readySettings();
        $GLOBALS['_fchub_test_uuid_queue'] = [
            '00000000-0000-4000-8000-000000000001',
            '00000000-0000-4000-8000-000000000002',
            '00000000-0000-4000-8000-000000000003',
            '00000000-0000-4000-8000-000000000004',
            '00000000-0000-4000-8000-000000000005',
        ];
        $dispatcher = new WebhookDispatcher(endpointPolicy: $this->endpointPolicy());

        $dispatcher->onGrantCreated(21, 5, [
            'source_type' => 'order',
            'source_id' => 77,
            'access_api_key' => 'access-key-sentinel',
        ]);
        $dispatcher->onGrantRevoked([
            ['id' => 1, 'application_password' => 'application-password-sentinel'],
        ], 5, 21, 'Canceled');
        $dispatcher->onGrantExpired([
            'id' => 3,
            'user_id' => 21,
            'plan_id' => 5,
            'lifecycle' => 'ended',
            'source_type' => 'subscription',
            'created_at' => '2026-03-01 00:00:00',
            'expires_at' => '2026-03-20 00:00:00',
            'owner' => 'fchub',
            'policy' => ['nonce' => 'nonce-sentinel'],
        ]);
        $dispatcher->onGrantPaused($this->legacyGrant('paused'), 'Payment overdue');
        $dispatcher->onGrantResumed($this->legacyGrant('active'));

        self::assertCount(5, $events);
        $bodies = [];
        foreach ($events as $event) {
            $body = json_decode($event['body'], true, flags: JSON_THROW_ON_ERROR);
            $bodies[$body['event_type']] = $body;
            self::assertSame(
                ['id', 'schema_version', 'event_type', 'occurred_at', 'site_url', 'data'],
                array_keys($body)
            );
            foreach ([
                'access-key-sentinel',
                'application-password-sentinel',
                'cookie-sentinel',
                'nonce-sentinel',
                'webhook-secret-sentinel',
            ] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $event['body']);
            }
        }

        self::assertSame(['user', 'plan', 'context'], array_keys($bodies['grant_created']['data']));
        self::assertSame(
            ['source_type' => 'order', 'source_id' => 77],
            $bodies['grant_created']['data']['context']
        );
        self::assertSame(
            ['user', 'plan', 'reason', 'grants_affected'],
            array_keys($bodies['grant_revoked']['data'])
        );
        self::assertSame(1, $bodies['grant_revoked']['data']['grants_affected']);
        self::assertSame(
            ['id', 'status', 'source_type', 'created_at', 'expires_at'],
            array_keys($bodies['grant_expired']['data']['grant'])
        );
        self::assertSame('expired', $bodies['grant_expired']['data']['grant']['status']);
        self::assertSame('paused', $bodies['grant_paused']['data']['grant']['status']);
        self::assertSame('active', $bodies['grant_resumed']['data']['grant']['status']);
    }

    public function test_scheduler_failures_leave_every_persisted_delivery_pending_and_continue(): void
    {
        $events = [];
        $deliveries = [];
        $timeline = [];
        $this->installDurableDatabase($events, $deliveries, $timeline);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $this->readySettings(
            "https://example.com/one\nhttps://example.com/two"
        );
        $scheduled = [];
        $GLOBALS['_fchub_test_as_has_scheduled_action_override'] = static fn(): bool => false;
        $GLOBALS['_fchub_test_as_schedule_single_action_override'] = static function (
            int $timestamp,
            string $hook,
            array $args
        ) use (&$scheduled): int {
            $scheduled[] = $args[0];
            return 0;
        };

        (new WebhookDispatcher(endpointPolicy: $this->endpointPolicy()))->dispatch(
            'grant_created',
            ['user' => ['id' => 21]]
        );

        self::assertSame(array_column($deliveries, 'id'), $scheduled);
        self::assertSame(['pending', 'pending'], array_column($deliveries, 'status'));
        self::assertSame([0, 0], array_column($deliveries, 'attempt_count'));
    }

    public function test_scheduler_exception_does_not_block_later_pending_deliveries_or_leak_context(): void
    {
        $events = [];
        $deliveries = [];
        $timeline = [];
        $this->installDurableDatabase($events, $deliveries, $timeline);
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $this->readySettings(
            "https://example.com/one\nhttps://example.com/two"
        );
        $scheduled = [];
        $GLOBALS['_fchub_test_as_has_scheduled_action_override'] = static fn(): bool => false;
        $GLOBALS['_fchub_test_as_schedule_single_action_override'] = static function (
            int $timestamp,
            string $hook,
            array $args
        ) use (&$scheduled): int {
            $scheduled[] = [$timestamp, $hook, $args];
            if (count($scheduled) === 1) {
                throw new \RuntimeException(
                    'https://example.com/one webhook-secret-sentinel body-sentinel'
                );
            }

            return 1;
        };

        (new WebhookDispatcher(endpointPolicy: $this->endpointPolicy()))->dispatch(
            'grant_created',
            ['message' => 'body-sentinel']
        );

        self::assertSame(
            array_column($deliveries, 'id'),
            array_map(static fn(array $action): int => $action[2][0], $scheduled)
        );
        self::assertSame(['pending', 'pending'], array_column($deliveries, 'status'));
        self::assertSame([0, 0], array_column($deliveries, 'attempt_count'));
        $logs = serialize([
            $GLOBALS['_fchub_test_fc_logs'],
            $GLOBALS['_fchub_test_fc_error_logs'],
        ]);
        self::assertStringNotContainsString('example.com', $logs);
        self::assertStringNotContainsString('webhook-secret-sentinel', $logs);
        self::assertStringNotContainsString('body-sentinel', $logs);
    }

    public function test_send_test_keeps_the_legacy_direct_response_until_task_six(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = $this->readySettings(
            "https://example.com/one\nhttps://example.com/two"
        );

        $result = (new WebhookDispatcher(endpointPolicy: $this->endpointPolicy()))->sendTest();

        self::assertTrue($result['success']);
        self::assertCount(2, $result['results']);
        self::assertCount(2, $GLOBALS['_fchub_test_remote_posts']);
    }

    private function endpointPolicy(): WebhookEndpointPolicy
    {
        return new WebhookEndpointPolicy(
            'production',
            static fn(): array => ['93.184.216.34']
        );
    }

    /** @return array<string, string> */
    private function readySettings(string $urls = 'https://example.com/hook'): array
    {
        return [
            'webhook_enabled' => 'yes',
            'webhook_urls' => $urls,
            'webhook_secret' => 'webhook-secret-sentinel',
        ];
    }

    /** @return array<string, mixed> */
    private function legacyGrant(string $status): array
    {
        return [
            'id' => 4,
            'user_id' => 21,
            'plan_id' => 5,
            'status' => $status,
            'source_type' => 'manual',
            'created_at' => '2026-03-01 00:00:00',
            'expires_at' => '2026-03-20 00:00:00',
            'webhook_secret' => 'webhook-secret-sentinel',
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $events
     * @param list<array<string, mixed>> $deliveries
     * @param list<string> $timeline
     */
    private function installDurableDatabase(array &$events, array &$deliveries, array &$timeline): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query) use (
            &$events,
            &$timeline
        ): string|int|null {
            if (str_contains($query, 'GET_LOCK(')) {
                $timeline[] = 'lock:acquire';
                return 1;
            }
            if (str_contains($query, 'RELEASE_LOCK(')) {
                $timeline[] = 'lock:release';
                return 1;
            }
            if (str_contains($query, 'FROM wp_options')) {
                return serialize($GLOBALS['_fchub_test_options']['fchub_memberships_settings'] ?? []);
            }
            if (str_contains($query, 'webhook_events')) {
                preg_match("/event_id = '([^']+)'/", $query, $matches);
                $eventId = $matches[1] ?? '';
                return isset($events[$eventId]) ? $eventId : null;
            }

            return 0;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (
            string $table,
            array $data,
            \wpdb $wpdb
        ) use (&$events, &$deliveries, &$timeline): int|false {
            if (str_ends_with($table, 'webhook_events')) {
                if (isset($events[$data['event_id']])) {
                    return false;
                }
                $events[$data['event_id']] = ['id' => $wpdb->insert_id] + $data;
                $timeline[] = 'event';
                return 1;
            }
            if (str_ends_with($table, 'webhook_deliveries')) {
                foreach ($deliveries as $delivery) {
                    if ($delivery['event_id'] === $data['event_id']
                        && $delivery['destination_hash'] === $data['destination_hash']
                    ) {
                        return false;
                    }
                }
                $row = ['id' => $wpdb->insert_id] + $data;
                $deliveries[] = $row;
                $timeline[] = 'delivery:' . $row['id'];
                return 1;
            }

            return 1;
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (
            &$events,
            &$deliveries
        ): ?array {
            if (str_contains($query, 'wp_fchub_membership_plans')) {
                return [
                    'id' => 5,
                    'title' => 'Gold Plan',
                    'slug' => 'gold-plan',
                    'description' => '',
                    'status' => 'active',
                    'level' => 0,
                    'duration_type' => 'lifetime',
                    'duration_days' => null,
                    'trial_days' => 0,
                    'grace_period_days' => 0,
                    'includes_plan_ids' => '[]',
                    'restriction_message' => null,
                    'redirect_url' => null,
                    'settings' => wp_json_encode(['webhook_secret' => 'plan-secret-sentinel']),
                    'meta' => wp_json_encode(['nonce' => 'plan-nonce-sentinel']),
                    'created_at' => '2026-01-01 00:00:00',
                    'updated_at' => '2026-01-01 00:00:00',
                ];
            }
            if (str_contains($query, 'webhook_events')) {
                preg_match("/event_id = '([^']+)'/", $query, $matches);
                return $events[$matches[1] ?? ''] ?? null;
            }
            if (str_contains($query, 'webhook_deliveries')) {
                preg_match("/event_id = '([^']+)'/", $query, $eventMatches);
                preg_match("/destination_hash = '([^']+)'/", $query, $hashMatches);
                foreach ($deliveries as $delivery) {
                    if ($delivery['event_id'] === ($eventMatches[1] ?? '')
                        && $delivery['destination_hash'] === ($hashMatches[1] ?? '')
                    ) {
                        return $delivery;
                    }
                }
            }

            return null;
        };
    }
}
