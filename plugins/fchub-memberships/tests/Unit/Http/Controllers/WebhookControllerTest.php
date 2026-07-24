<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\WebhookController;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookControllerTest extends PluginTestCase
{
    public function test_registers_exact_routes_with_manage_options_permission_and_frozen_arguments(): void
    {
        WebhookController::registerRoutes();

        self::assertSame([
            'fchub-memberships/v1/admin/webhooks/health',
            'fchub-memberships/v1/admin/webhooks/deliveries',
            'fchub-memberships/v1/admin/webhooks/deliveries/(?P<id>\d+)/retry',
            'fchub-memberships/v1/admin/webhooks/deliveries/(?P<id>\d+)/cancel',
            'fchub-memberships/v1/admin/webhooks/test',
        ], array_keys($GLOBALS['_fchub_test_routes']));
        self::assertSame(
            ['page', 'per_page', 'status'],
            array_keys($GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/webhooks/deliveries']['args'])
        );
        self::assertSame(
            ['id'],
            array_keys($GLOBALS['_fchub_test_routes']['fchub-memberships/v1/admin/webhooks/deliveries/(?P<id>\d+)/retry']['args'])
        );

        $GLOBALS['_fchub_test_current_user_caps'] = [
            'manage_options' => false,
            'manage_fchub_memberships' => true,
        ];
        self::assertFalse(WebhookController::permission(new \WP_REST_Request()));
        self::assertContains('manage_options', $GLOBALS['_fchub_test_current_user_can_checks']);
    }

    public function test_health_is_flat_redacted_and_reports_degraded_from_retry_state(): void
    {
        $fixture = $this->fixture();
        $fixture['deliveries']->summary = [
            'pending' => 2,
            'processing' => 1,
            'retrying' => 3,
            'succeeded' => 8,
            'failed' => 4,
            'active' => 6,
            'last_success_at' => '2026-07-23 10:00:00',
        ];

        $response = $fixture['controller']->healthResponse();

        self::assertSame(200, $response->get_status());
        self::assertSame([
            'status' => 'degraded',
            'pending_count' => 2,
            'processing_count' => 1,
            'retrying_count' => 3,
            'succeeded_count' => 8,
            'failed_count' => 4,
            'last_success_at' => '2026-07-23 10:00:00',
        ], $response->get_data()['data']);
        self::assertStringNotContainsString('secret-sentinel', serialize($response->get_data()));
        self::assertStringNotContainsString('hooks.example.com', serialize($response->get_data()));
    }

    public function test_health_configuration_states_are_off_needs_setup_and_ready(): void
    {
        $off = $this->fixture(['webhook_enabled' => 'no']);
        self::assertSame('off', $off['controller']->healthResponse()->get_data()['data']['status']);

        $needsSetup = $this->fixture(['webhook_enabled' => 'yes', 'webhook_urls' => '', 'webhook_secret' => '']);
        self::assertSame('needs_setup', $needsSetup['controller']->healthResponse()->get_data()['data']['status']);

        $ready = $this->fixture();
        self::assertSame('ready', $ready['controller']->healthResponse()->get_data()['data']['status']);
    }

    public function test_health_uses_active_endpoint_records_as_the_current_configuration(): void
    {
        $fixture = $this->fixture([
            'webhook_endpoints' => [[
                'id' => 'endpoint-a',
                'name' => 'Endpoint A',
                'url' => 'https://hooks.example.com/a',
                'secret' => 'endpoint-secret',
                'status' => 'active',
            ]],
        ]);

        self::assertSame('ready', $fixture['controller']->healthResponse()->get_data()['data']['status']);
    }

    public function test_history_returns_only_the_explicit_public_projection(): void
    {
        $fixture = $this->fixture();
        $fixture['deliveries']->recent = [[
            'id' => 17,
            'event_id' => '8f14b86a-b3ec-4fe5-a8f7-8a01b694bf15',
            'event_type' => 'grant_created',
            'destination_url' => 'https://hooks.example.com/a?b=1',
            'status' => 'failed',
            'attempt_count' => 7,
            'response_code' => 500,
            'error_message' => '<b>' . str_repeat('ż', 400) . '</b>',
            'next_attempt_at' => null,
            'last_attempt_at' => '2026-07-23 09:00:00',
            'delivered_at' => null,
            'created_at' => '2026-07-22 09:00:00',
            'updated_at' => '2026-07-23 09:00:00',
            'occurred_at' => '2026-07-22 08:00:00',
            'response_body' => 'body-sentinel',
            'destination_hash' => 'hash-sentinel',
            'lease_owner' => 'lease-sentinel',
            'lease_expires_at' => '2026-07-23 09:05:00',
        ]];
        $request = new \WP_REST_Request('GET', '', ['page' => 2, 'per_page' => 25, 'status' => 'failed']);

        $response = $fixture['controller']->deliveriesResponse($request);
        $data = $response->get_data()['data'];
        $delivery = $data['deliveries'][0];

        self::assertSame([2, 25, 'failed'], $fixture['deliveries']->recentFilters);
        self::assertSame([
            'id', 'event_id', 'event_type', 'destination_url', 'status', 'attempt_count',
            'response_code', 'error_message', 'next_attempt_at',
            'last_attempt_at', 'delivered_at', 'created_at', 'updated_at',
        ], array_keys($delivery));
        self::assertLessThanOrEqual(500, strlen($delivery['error_message']));
        self::assertStringNotContainsString('<b>', $delivery['error_message']);
        self::assertStringNotContainsString('body-sentinel', serialize($data));
        self::assertStringNotContainsString('hash-sentinel', serialize($data));
        self::assertStringNotContainsString('lease-sentinel', serialize($data));
    }

    public function test_durable_operations_fail_closed_with_a_redacted_503(): void
    {
        $notReady = $this->fixture(readiness: false);
        foreach ([
            $notReady['controller']->healthResponse(),
            $notReady['controller']->deliveriesResponse(new \WP_REST_Request()),
            $notReady['controller']->retryDelivery(new \WP_REST_Request('POST', '', ['id' => 9])),
            $notReady['controller']->testDelivery(new \WP_REST_Request()),
        ] as $response) {
            self::assertSame(503, $response->get_status());
            self::assertSame('fchub_webhook_storage_unavailable', $response->get_data()['code']);
        }

        $broken = $this->fixture();
        $broken['deliveries']->throw = true;
        $response = $broken['controller']->healthResponse();
        self::assertSame(503, $response->get_status());
        self::assertStringNotContainsString('database-sentinel', serialize($response->get_data()));
    }

    public function test_manual_retry_returns_frozen_missing_and_status_conflicts(): void
    {
        $fixture = $this->fixture();
        $fixture['deliveries']->found = null;
        $missing = $fixture['controller']->retryDelivery(new \WP_REST_Request('POST', '', ['id' => 9]));
        self::assertSame(404, $missing->get_status());
        self::assertSame('fchub_webhook_delivery_not_found', $missing->get_data()['code']);

        foreach (['succeeded', 'processing'] as $status) {
            $fixture = $this->fixture();
            $fixture['deliveries']->found = ['id' => 9, 'status' => $status];
            $conflict = $fixture['controller']->retryDelivery(new \WP_REST_Request('POST', '', ['id' => 9]));
            self::assertSame(409, $conflict->get_status());
            self::assertSame('fchub_webhook_retry_not_allowed', $conflict->get_data()['code']);
            self::assertSame(['status' => $status], $conflict->get_data()['data']);
        }
    }

    public function test_manual_retry_resets_and_schedules_attempt_one_inside_the_settings_lock(): void
    {
        $fixture = $this->fixture();
        $fixture['deliveries']->found = ['id' => 9, 'status' => 'failed'];

        $response = $fixture['controller']->retryDelivery(new \WP_REST_Request('POST', '', ['id' => 9]));

        self::assertSame(202, $response->get_status());
        self::assertSame(['id' => 9, 'status' => 'pending'], $response->get_data()['data']);
        self::assertSame([9], $fixture['deliveries']->resetIds);
        self::assertSame([[9, 1, 1_800_000_000]], $fixture['queue']->scheduled);
        self::assertSame(['lock', 'find', 'reset', 'schedule', 'release'], $fixture['order']);
    }

    public function test_manual_retry_distinguishes_stale_cas_schedule_failure_and_storage_failure(): void
    {
        $stale = $this->fixture();
        $stale['deliveries']->found = ['id' => 9, 'status' => 'failed'];
        $stale['deliveries']->reset = false;
        $stale['deliveries']->afterReset = ['id' => 9, 'status' => 'processing'];
        $conflict = $stale['controller']->retryDelivery(new \WP_REST_Request('POST', '', ['id' => 9]));
        self::assertSame('fchub_webhook_retry_not_allowed', $conflict->get_data()['code']);
        self::assertSame(['status' => 'processing'], $conflict->get_data()['data']);

        $schedule = $this->fixture();
        $schedule['deliveries']->found = ['id' => 9, 'status' => 'failed'];
        $schedule['queue']->result = false;
        $failedSchedule = $schedule['controller']->retryDelivery(new \WP_REST_Request('POST', '', ['id' => 9]));
        self::assertSame(503, $failedSchedule->get_status());
        self::assertSame('fchub_webhook_retry_schedule_failed', $failedSchedule->get_data()['code']);

        $storage = $this->fixture();
        $storage['deliveries']->throw = true;
        $unavailable = $storage['controller']->retryDelivery(new \WP_REST_Request('POST', '', ['id' => 9]));
        self::assertSame('fchub_webhook_storage_unavailable', $unavailable->get_data()['code']);
        self::assertStringNotContainsString('database-sentinel', serialize($unavailable->get_data()));
    }

    public function test_cancel_stops_a_pending_or_retrying_delivery_and_unschedules_it(): void
    {
        foreach (['pending', 'retrying'] as $status) {
            $fixture = $this->fixture();
            $fixture['deliveries']->found = ['id' => 9, 'status' => $status];

            $response = $fixture['controller']->cancelDelivery(
                new \WP_REST_Request('POST', '', ['id' => 9])
            );

            self::assertSame(200, $response->get_status());
            self::assertSame(['id' => 9, 'status' => 'cancelled'], $response->get_data()['data']);
            self::assertSame([9], $fixture['deliveries']->cancelledIds);
            self::assertSame([9], $fixture['queue']->cancelled);
        }
    }

    public function test_production_test_persists_under_lock_then_uses_worker_after_release_and_continues_failures(): void
    {
        $fixture = $this->fixture([
            'webhook_enabled' => 'no',
            'webhook_urls' => "https://hooks.example.com/one\nhttps://hooks.example.com/two\nhttps://hooks.example.com/three",
            'webhook_secret' => 'secret-sentinel',
        ]);
        $fixture['deliveries']->created = [
            ['id' => 31, 'destination_url' => 'https://hooks.example.com/one'],
            ['id' => 32, 'destination_url' => 'https://hooks.example.com/two'],
            ['id' => 33, 'destination_url' => 'https://hooks.example.com/three'],
        ];
        $fixture['worker']->results = [31 => ['status' => 'succeeded'], 32 => ['status' => 'retrying']];
        $fixture['worker']->throwIds = [33];
        $fixture['deliveries']->foundById = [
            31 => ['id' => 31, 'status' => 'succeeded', 'destination_url' => 'https://hooks.example.com/one'],
            32 => ['id' => 32, 'status' => 'retrying', 'destination_url' => 'https://hooks.example.com/two'],
            33 => ['id' => 33, 'status' => 'pending', 'destination_url' => 'https://hooks.example.com/three'],
        ];

        $response = $fixture['controller']->testDelivery(new \WP_REST_Request());
        $data = $response->get_data()['data'];

        self::assertSame(200, $response->get_status());
        self::assertFalse($data['success']);
        self::assertSame('00000000-0000-4000-8000-000000000000', $data['event_id']);
        self::assertSame(['succeeded', 'retrying', 'pending'], array_column($data['results'], 'status'));
        self::assertSame([31, 32, 33], $fixture['worker']->handled);
        self::assertSame([], $fixture['queue']->scheduled);
        self::assertSame(
            ['lock', 'event', 'deliveries', 'release', 'worker:31', 'find', 'worker:32', 'find', 'worker:33', 'find'],
            $fixture['order']
        );
        self::assertStringNotContainsString('secret-sentinel', serialize($data));
        self::assertStringNotContainsString('body', implode(',', array_keys($data['results'][0])));
        self::assertSame('test', $fixture['events']->created[0]['event_type']);
        $envelope = json_decode($fixture['events']->created[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('test', $envelope['event_type']);
        self::assertSame('00000000-0000-4000-8000-000000000000', $envelope['id']);
    }

    public function test_production_test_requires_safe_destinations_and_stored_secret(): void
    {
        foreach ([
            ['webhook_urls' => '', 'webhook_secret' => 'secret'],
            ['webhook_urls' => 'https://hooks.example.com/one', 'webhook_secret' => ''],
        ] as $settings) {
            $fixture = $this->fixture($settings);
            $response = $fixture['controller']->testDelivery(new \WP_REST_Request());
            self::assertSame(422, $response->get_status());
            self::assertSame('fchub_webhook_not_ready', $response->get_data()['code']);
            self::assertSame([], $fixture['events']->created);
        }
    }

    /** @return array<string, mixed> */
    private function fixture(?array $settings = null, bool $readiness = true): array
    {
        $settings ??= [
            'webhook_enabled' => 'yes',
            'webhook_urls' => 'https://hooks.example.com/one',
            'webhook_secret' => 'secret-sentinel',
        ];
        $order = [];
        $deliveries = new class($order) {
            public array $summary = ['pending' => 0, 'processing' => 0, 'retrying' => 0, 'succeeded' => 0, 'failed' => 0, 'active' => 0, 'last_success_at' => null];
            public array $recent = [];
            public array $recentFilters = [];
            public ?array $found = ['id' => 9, 'status' => 'failed'];
            public ?array $afterReset = null;
            public array $foundById = [];
            public bool $reset = true;
            public bool $throw = false;
            public array $resetIds = [];
            public array $created = [];
            public array $cancelledIds = [];
            private int $findCount = 0;
            public function __construct(private array &$order) {}
            public function summary(): array { if ($this->throw) throw new \RuntimeException('database-sentinel'); return $this->summary; }
            public function recent(array $filters): array { if ($this->throw) throw new \RuntimeException('database-sentinel'); $this->recentFilters = [$filters['page'], $filters['per_page'], $filters['status']]; return $this->recent; }
            public function find(int $id): ?array { $this->order[] = 'find'; if ($this->throw) throw new \RuntimeException('database-sentinel'); if (array_key_exists($id, $this->foundById)) return $this->foundById[$id]; $this->findCount++; return $this->findCount > 1 && $this->afterReset !== null ? $this->afterReset : $this->found; }
            public function resetForManualRetry(int $id): bool { $this->order[] = 'reset'; $this->resetIds[] = $id; if ($this->throw) throw new \RuntimeException('database-sentinel'); return $this->reset; }
            public function cancel(int $id): bool { $this->cancelledIds[] = $id; return true; }
            public function createMany(string $eventId, array $destinations): array { $this->order[] = 'deliveries'; return $this->created; }
        };
        $events = new class($order) {
            public array $created = [];
            public function __construct(private array &$order) {}
            public function create(array $event): bool { $this->order[] = 'event'; $this->created[] = $event; return true; }
        };
        $queue = new class($order) {
            public array $scheduled = [];
            public bool $result = true;
            public array $cancelled = [];
            public function __construct(private array &$order) {}
            public function schedule(int $id, int $attempt, int $timestamp): bool { $this->order[] = 'schedule'; $this->scheduled[] = func_get_args(); return $this->result; }
            public function cancel(int $id): bool { $this->cancelled[] = $id; return true; }
        };
        $worker = new class($order) {
            public array $results = [];
            public array $throwIds = [];
            public array $handled = [];
            public function __construct(private array &$order) {}
            public function deliverNow(int $id): array { $this->order[] = 'worker:' . $id; $this->handled[] = $id; if (in_array($id, $this->throwIds, true)) throw new \RuntimeException('worker-sentinel'); return $this->results[$id] ?? ['status' => 'succeeded']; }
        };
        $policy = new class {
            public function validate(string $raw): true|\WP_Error { return trim($raw) === '' ? new \WP_Error('invalid', 'invalid') : true; }
            public function normalise(string $raw): array { return array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: []))); }
        };
        $coordinator = new MembershipSettingsOptionCoordinator(
            static function () use (&$order): bool { $order[] = 'lock'; return true; },
            static function () use (&$order): void { $order[] = 'release'; },
            static fn(): array => $settings,
            static fn(array $next): bool => true
        );
        $controller = new WebhookController(
            $deliveries,
            $events,
            $queue,
            $worker,
            $policy,
            $coordinator,
            new \FChubMemberships\Support\Clock(new \DateTimeImmutable('@1800000000'), new \DateTimeZone('UTC')),
            static fn(): bool => $readiness
        );

        return [
            'controller' => $controller,
            'deliveries' => $deliveries,
            'events' => $events,
            'queue' => $queue,
            'worker' => $worker,
            'order' => &$order,
        ];
    }
}
