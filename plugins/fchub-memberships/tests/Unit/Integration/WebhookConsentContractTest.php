<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Http\Controllers\WebhookController;
use FChubMemberships\Http\Controllers\WebhookEndpointController;
use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class WebhookConsentContractTest extends PluginTestCase
{
    public function test_webhook_administration_requires_the_memberships_capability(): void
    {
        $GLOBALS['_fchub_test_current_user_can'] = false;

        self::assertFalse(WebhookEndpointController::permission(new \WP_REST_Request()));
        self::assertFalse(WebhookController::permission(new \WP_REST_Request()));
        self::assertSame(
            ['manage_fchub_memberships', 'manage_fchub_memberships'],
            $GLOBALS['_fchub_test_current_user_can_checks'],
        );
    }

    public function test_reconciliation_schedules_only_deliveries_for_currently_active_endpoints(): void
    {
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_endpoints' => [
                [
                    'id' => 'active',
                    'url' => 'https://active.example/webhook',
                    'secret' => 'active-secret',
                    'status' => 'active',
                ],
                [
                    'id' => 'paused',
                    'url' => 'https://paused.example/webhook',
                    'secret' => 'paused-secret',
                    'status' => 'paused',
                ],
            ],
        ];
        $deliveries = new class {
            public array $cancelled = [];

            public function retryableDue(string $now, int $limit): array
            {
                return [
                    [
                        'id' => 31,
                        'destination_url' => 'https://active.example/webhook',
                        'status' => 'retrying',
                        'attempt_count' => 1,
                    ],
                    [
                        'id' => 32,
                        'destination_url' => 'https://paused.example/webhook',
                        'status' => 'retrying',
                        'attempt_count' => 1,
                    ],
                ];
            }

            public function cancel(int $id): bool
            {
                $this->cancelled[] = $id;
                return true;
            }
        };
        $queue = new class {
            public array $scheduled = [];

            public function schedule(int $id, int $attempt, int $timestamp): bool
            {
                $this->scheduled[] = [$id, $attempt, $timestamp];
                return true;
            }
        };
        $clock = new Clock(
            new \DateTimeImmutable('2026-07-26T12:00:00+00:00'),
            new \DateTimeZone('UTC'),
        );
        $module = new InfrastructureModule(
            webhookDeliveryRepository: $deliveries,
            webhookQueue: $queue,
            clock: $clock,
        );

        $module->reconcileWebhookDeliveries();

        self::assertSame([[31, 2, 1_785_067_200]], $queue->scheduled);
        self::assertSame([32], $deliveries->cancelled);
        self::assertSame([], $GLOBALS['_fchub_test_safe_remote_posts'] ?? []);
        self::assertSame([], $GLOBALS['_fchub_test_remote_posts'] ?? []);
    }
}
