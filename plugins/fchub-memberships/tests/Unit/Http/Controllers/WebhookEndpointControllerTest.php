<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Http\Controllers;

use FChubMemberships\Http\Controllers\WebhookEndpointController;
use FChubMemberships\Integration\MembershipSettingsOptionCoordinator;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookEndpointControllerTest extends PluginTestCase
{
    public function test_registers_endpoint_management_routes(): void
    {
        WebhookEndpointController::registerRoutes();

        self::assertSame([
            'fchub-memberships/v1/admin/webhooks/endpoints',
            'fchub-memberships/v1/admin/webhooks/endpoints/(?P<id>[A-Za-z0-9_-]+)/secret',
            'fchub-memberships/v1/admin/webhooks/endpoints/(?P<id>[A-Za-z0-9_-]+)/test',
            'fchub-memberships/v1/admin/webhooks/endpoints/(?P<id>[A-Za-z0-9_-]+)/activate',
            'fchub-memberships/v1/admin/webhooks/endpoints/(?P<id>[A-Za-z0-9_-]+)/pause',
            'fchub-memberships/v1/admin/webhooks/endpoints/(?P<id>[A-Za-z0-9_-]+)',
        ], array_keys($GLOBALS['_fchub_test_routes']));
    }

    public function test_create_generate_test_and_activate_endpoint_without_exposing_the_secret(): void
    {
        $fixture = $this->fixture();

        $created = $fixture['controller']->create(new \WP_REST_Request('POST', '', [
            'name' => 'CRM receiver',
            'url' => 'https://crm.example/webhook',
        ]));
        self::assertSame(201, $created->get_status());
        self::assertSame('draft', $created->get_data()['data']['endpoint']['status']);
        self::assertFalse($created->get_data()['data']['endpoint']['secret_configured']);

        $secret = $fixture['controller']->rotateSecret(new \WP_REST_Request('POST', '', [
            'id' => 'we_endpoint',
        ]));
        self::assertSame('one-time-secret', $secret->get_data()['data']['secret']);
        self::assertStringNotContainsString(
            'one-time-secret',
            serialize($secret->get_data()['data']['endpoint'])
        );

        $beforeTest = $fixture['controller']->activate(new \WP_REST_Request('POST', '', [
            'id' => 'we_endpoint',
        ]));
        self::assertSame(409, $beforeTest->get_status());
        self::assertSame('fchub_webhook_endpoint_test_required', $beforeTest->get_data()['code']);

        $tested = $fixture['controller']->test(new \WP_REST_Request('POST', '', [
            'id' => 'we_endpoint',
        ]));
        self::assertSame(200, $tested->get_status());
        self::assertSame('succeeded', $tested->get_data()['data']['status']);
        self::assertSame([[41, false]], $fixture['worker']->handled);

        $activated = $fixture['controller']->activate(new \WP_REST_Request('POST', '', [
            'id' => 'we_endpoint',
        ]));
        self::assertSame(200, $activated->get_status());
        self::assertSame('active', $activated->get_data()['data']['endpoint']['status']);

        $listed = $fixture['controller']->index(new \WP_REST_Request());
        self::assertCount(1, $listed->get_data()['data']['endpoints']);
        self::assertStringNotContainsString('one-time-secret', serialize($listed->get_data()));
    }

    public function test_duplicate_unsafe_and_missing_endpoints_fail_closed(): void
    {
        $fixture = $this->fixture([
            'webhook_endpoints' => [[
                'id' => 'existing',
                'name' => 'Existing',
                'url' => 'https://crm.example/webhook',
                'secret' => 'secret',
                'status' => 'paused',
            ]],
        ]);

        $duplicate = $fixture['controller']->create(new \WP_REST_Request('POST', '', [
            'name' => 'Duplicate',
            'url' => 'https://crm.example/webhook',
        ]));
        self::assertSame(409, $duplicate->get_status());

        $unsafe = $fixture['controller']->create(new \WP_REST_Request('POST', '', [
            'name' => 'Unsafe',
            'url' => 'http://127.0.0.1/hook',
        ]));
        self::assertSame(422, $unsafe->get_status());

        $missing = $fixture['controller']->pause(new \WP_REST_Request('POST', '', [
            'id' => 'missing',
        ]));
        self::assertSame(404, $missing->get_status());
    }

    public function test_create_enforces_the_existing_endpoint_limit(): void
    {
        $endpoints = [];
        for ($index = 1; $index <= 10; $index++) {
            $endpoints[] = [
                'id' => 'endpoint-' . $index,
                'name' => 'Endpoint ' . $index,
                'url' => "https://receiver-{$index}.example/webhook",
                'secret' => '',
                'status' => 'draft',
            ];
        }
        $fixture = $this->fixture(['webhook_endpoints' => $endpoints]);

        $response = $fixture['controller']->create(new \WP_REST_Request('POST', '', [
            'name' => 'One too many',
            'url' => 'https://receiver-11.example/webhook',
        ]));

        self::assertSame(422, $response->get_status());
        self::assertSame('fchub_webhook_endpoint_limit', $response->get_data()['code']);
        self::assertCount(10, $fixture['settings']()['webhook_endpoints']);
    }

    public function test_pause_and_delete_stop_future_delivery_and_clear_deleted_secret(): void
    {
        $fixture = $this->fixture([
            'webhook_endpoints' => [[
                'id' => 'endpoint-a',
                'name' => 'Endpoint A',
                'url' => 'https://crm.example/webhook',
                'secret' => 'endpoint-secret',
                'status' => 'active',
                'last_test_status' => 'succeeded',
                'last_tested_at' => '2026-07-24 08:00:00',
            ]],
        ]);

        $paused = $fixture['controller']->pause(new \WP_REST_Request('POST', '', [
            'id' => 'endpoint-a',
        ]));
        self::assertSame('paused', $paused->get_data()['data']['endpoint']['status']);

        $deleted = $fixture['controller']->delete(new \WP_REST_Request('DELETE', '', [
            'id' => 'endpoint-a',
        ]));
        self::assertSame(204, $deleted->get_status());
        self::assertSame([], $fixture['controller']->index(new \WP_REST_Request())->get_data()['data']['endpoints']);
        self::assertSame('', $fixture['settings']()['webhook_endpoints'][0]['secret']);
    }

    /** @param array<string, mixed> $initial */
    private function fixture(array $initial = []): array
    {
        $settings = $initial;
        $coordinator = new MembershipSettingsOptionCoordinator(
            static fn(): bool => true,
            static fn(): bool => true,
            static function () use (&$settings): array {
                return $settings;
            },
            static function (array $next) use (&$settings): bool {
                $settings = $next;
                return true;
            }
        );
        $deliveries = new class {
            public function createMany(string $eventId, array $destinations): array
            {
                return [['id' => 41, 'destination_url' => $destinations[0]]];
            }
            public function find(int $id): ?array
            {
                return ['id' => $id, 'status' => 'succeeded', 'destination_url' => 'https://crm.example/webhook'];
            }
        };
        $events = new class {
            public array $created = [];
            public function create(array $event): bool
            {
                $this->created[] = $event;
                return true;
            }
        };
        $worker = new class {
            public array $handled = [];
            public function deliverNow(int $id, bool $allowRetry = true): array
            {
                $this->handled[] = [$id, $allowRetry];
                return ['status' => 'succeeded'];
            }
        };
        $policy = new class {
            public function validate(string $raw): true|\WP_Error
            {
                return str_starts_with($raw, 'https://') && !str_contains($raw, '127.0.0.1')
                    ? true
                    : new \WP_Error('unsafe', 'unsafe');
            }
            public function normalise(string $raw): array
            {
                return [trim($raw)];
            }
        };
        $controller = new WebhookEndpointController(
            $deliveries,
            $events,
            $worker,
            $policy,
            $coordinator,
            new Clock(new \DateTimeImmutable('2026-07-24 08:05:00', new \DateTimeZone('UTC')), new \DateTimeZone('UTC')),
            static fn(): string => 'we_endpoint',
            static fn(): string => 'one-time-secret',
            static fn(): bool => true
        );

        return [
            'controller' => $controller,
            'worker' => $worker,
            'settings' => static function () use (&$settings): array {
                return $settings;
            },
        ];
    }
}
