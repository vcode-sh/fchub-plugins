<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Core\Container;
use FChubMemberships\Domain\Drip\DripScheduleService;
use FChubMemberships\Domain\ProviderOperationOutcome;
use FChubMemberships\Domain\ProviderOperationWorker;
use FChubMemberships\Integration\WebhookQueue;
use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
use FChubMemberships\Integration\FluentCrmSync;
use FChubMemberships\Storage\DripScheduleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\MutationRequestRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class InfrastructureModuleTest extends PluginTestCase
{
    public function test_register_adds_expected_hooks(): void
    {
        $module = new InfrastructureModule();
        $module->register(new Container());

        self::assertArrayHasKey('cron_schedules', $GLOBALS['_fchub_test_filters']);
        self::assertArrayHasKey('fchub_memberships_validity_check', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('fchub_memberships/grant_resumed', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('fchub_memberships_send_email', $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey(WebhookQueue::HOOK, $GLOBALS['_fchub_test_actions']);
        self::assertArrayHasKey('admin_notices', $GLOBALS['_fchub_test_actions']);
    }

    public function test_register_routes_action_scheduler_and_five_minute_recovery_to_same_worker(): void
    {
        $worker = new class extends ProviderOperationWorker {
            public array $processed = [];
            public int $recoveryLimit = 0;

            public function __construct()
            {
            }

            public function process(int $operationId): ProviderOperationOutcome
            {
                $this->processed[] = $operationId;
                return ProviderOperationOutcome::applied();
            }

            public function recoverDue(int $limit = 50): array
            {
                $this->recoveryLimit = $limit;
                return [];
            }
        };
        $crm = new class extends FluentCrmSync {
            public int $recoveryLimit = 0;

            public function __construct()
            {
            }

            public function recoverDue(int $limit = 50): int
            {
                $this->recoveryLimit = $limit;
                return 0;
            }
        };
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = ['fluentcrm_enabled' => 'yes'];
        $module = new InfrastructureModule(null, $worker, null, $crm);
        $module->register(new Container());

        self::assertArrayHasKey(ProviderOperationWorker::HOOK, $GLOBALS['_fchub_test_actions']);
        do_action(ProviderOperationWorker::HOOK, 91);
        do_action('fchub_memberships_validity_check');

        self::assertSame([91], $worker->processed);
        self::assertSame(50, $worker->recoveryLimit);
        self::assertSame(50, $crm->recoveryLimit);
        $validityHooks = $GLOBALS['_fchub_test_action_registrations']['fchub_memberships_validity_check'];
        self::assertSame([5, 6, 7, 10], array_column($validityHooks, 'priority'));
        self::assertSame(
            1,
            $GLOBALS['_fchub_test_action_registrations'][ProviderOperationWorker::HOOK][0]['accepted_args']
        );
    }

    public function test_resume_hook_releases_deferred_notification_for_next_processing_run(): void
    {
        $dripRepo = new class extends DripScheduleRepository {
            public string $status = 'deferred';

            public function releaseDeferredForGrant(int $grantId): int
            {
                if ($grantId !== 10 || $this->status !== 'deferred') {
                    return 0;
                }

                $this->status = 'pending';
                return 1;
            }

            public function getPendingNotifications(int $limit = 50): array
            {
                return $this->status === 'pending'
                    ? [['id' => 1, 'grant_id' => 10, 'user_id' => 21, 'retry_count' => 0, 'plan_rule_id' => 91]]
                    : [];
            }

            public function markSent(int $id): bool
            {
                $this->status = 'sent';
                return true;
            }

            public function getByGrantId(int $grantId): array
            {
                return [];
            }
        };
        $grantRepo = new class extends GrantRepository {
            public function find(int $id): ?array
            {
                return ['id' => $id, 'user_id' => 21, 'status' => 'active', 'meta' => [], 'plan_id' => 5];
            }
        };
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'email_drip_unlocked' => 'no',
        ];
        $module = new InfrastructureModule($dripRepo);
        $module->register(new Container());

        do_action('fchub_memberships/grant_resumed', ['id' => 10]);
        $processed = (new DripScheduleService($dripRepo, $grantRepo))->processNotifications();

        self::assertSame('sent', $dripRepo->status);
        self::assertSame(1, $processed);
    }

    public function test_schedule_and_clear_recurring_events_cover_all_plugin_jobs(): void
    {
        InfrastructureModule::scheduleRecurringEvents(static fn(): bool => true);
        InfrastructureModule::clearRecurringEvents();

        self::assertCount(9, $GLOBALS['_fchub_test_scheduled_events']);
        self::assertCount(9, $GLOBALS['_fchub_test_cleared_events']);
    }

    public function test_reconciliation_schedules_at_most_one_hundred_with_frozen_attempt_selection(): void
    {
        $deliveries = new class {
            public array $calls = [];
            public function retryableDue(string $now, int $limit): array
            {
                $this->calls[] = [$now, $limit];
                return [
                    [
                        'id' => 10,
                        'destination_url' => 'https://one.example/webhook',
                        'status' => 'pending',
                        'attempt_count' => 0,
                    ],
                    [
                        'id' => 11,
                        'destination_url' => 'https://two.example/webhook',
                        'status' => 'retrying',
                        'attempt_count' => 3,
                    ],
                    [
                        'id' => 12,
                        'destination_url' => 'https://three.example/webhook',
                        'status' => 'processing',
                        'attempt_count' => 4,
                    ],
                ];
            }
            public function purge(string $successCutoff, string $failureCutoff): int { return 0; }
        };
        $queue = new class {
            public array $scheduled = [];
            public function schedule(int $id, int $attempt, int $timestamp): bool
            {
                $this->scheduled[] = func_get_args(); return true;
            }
        };
        $clock = new \FChubMemberships\Support\Clock(
            new \DateTimeImmutable('2026-07-23T12:00:00+00:00'),
            new \DateTimeZone('UTC')
        );
        $GLOBALS['_fchub_test_options']['fchub_memberships_settings'] = [
            'webhook_endpoints' => array_map(
                static fn(string $url, int $index): array => [
                    'id' => 'endpoint-' . $index,
                    'url' => $url,
                    'secret' => 'secret-' . $index,
                    'status' => 'active',
                ],
                [
                    'https://one.example/webhook',
                    'https://two.example/webhook',
                    'https://three.example/webhook',
                ],
                [1, 2, 3],
            ),
        ];
        $module = new InfrastructureModule(null, null, null, null, null, null, $deliveries, null, $queue, $clock);

        $module->reconcileWebhookDeliveries();

        self::assertSame([['2026-07-23 12:00:00', 100]], $deliveries->calls);
        self::assertSame(
            [[10, 1, 1_784_808_000], [11, 4, 1_784_808_000], [12, 4, 1_784_808_000]],
            $queue->scheduled
        );
    }

    public function test_cleanup_uses_thirty_day_success_and_ninety_day_failure_and_orphan_retention(): void
    {
        $deliveries = new class {
            public array $calls = [];
            public function purge(string $successCutoff, string $failureCutoff): int
            {
                $this->calls[] = [$successCutoff, $failureCutoff]; return 2;
            }
        };
        $events = new class {
            public array $calls = [];
            public function deleteOrphansBefore(string $cutoff): int { $this->calls[] = $cutoff; return 1; }
        };
        $clock = new \FChubMemberships\Support\Clock(
            new \DateTimeImmutable('2026-07-23T12:00:00+00:00'),
            new \DateTimeZone('UTC')
        );
        $module = new InfrastructureModule(null, null, null, null, null, null, $deliveries, $events, null, $clock);

        $module->cleanupWebhookDeliveries();

        self::assertSame([['2026-06-23 12:00:00', '2026-04-24 12:00:00']], $deliveries->calls);
        self::assertSame(['2026-04-24 12:00:00'], $events->calls);
    }

    public function test_upgrade_repair_schedules_webhook_jobs_only_after_readiness_passes(): void
    {
        $notReady = new InfrastructureModule(null, null, null, null, null, null, null, null, null, null, static fn(): bool => false);
        $notReady->repairWebhookSchedules();
        self::assertSame([], $GLOBALS['_fchub_test_scheduled_events']);

        $ready = new InfrastructureModule(null, null, null, null, null, null, null, null, null, null, static fn(): bool => true);
        $ready->repairWebhookSchedules();

        self::assertCount(2, $GLOBALS['_fchub_test_scheduled_events']);
        self::assertSame(
            ['fchub_memberships_webhook_reconcile', 'fchub_memberships_webhook_cleanup'],
            array_column($GLOBALS['_fchub_test_scheduled_events'], 2)
        );
    }

    public function test_orphan_reconciliation_and_cleanup_failures_are_caught_and_redacted(): void
    {
        $deliveries = new class {
            public function retryableDue(string $now, int $limit): array { throw new \RuntimeException('secret database details'); }
            public function purge(string $successCutoff, string $failureCutoff): int { throw new \RuntimeException('private row'); }
        };
        $events = new class {
            public function deleteOrphansBefore(string $cutoff): int { throw new \RuntimeException('private event'); }
        };
        $module = new InfrastructureModule(null, null, null, null, null, null, $deliveries, $events);

        $module->reconcileWebhookDeliveries();
        $module->cleanupWebhookDeliveries();

        $logs = serialize($GLOBALS['_fchub_test_fc_error_logs']);
        self::assertStringNotContainsString('secret database details', $logs);
        self::assertStringNotContainsString('private row', $logs);
        self::assertStringNotContainsString('private event', $logs);
    }

    public function test_validity_recovery_purges_at_most_one_hundred_thirty_day_terminal_receipts_at_priority_seven(): void
    {
        $repository = new class extends MutationRequestRepository {
            public array $calls = [];

            public function __construct()
            {
            }

            public function purgeTerminalOlderThan(int $days = 30, int $limit = 100): int
            {
                $this->calls[] = [$days, $limit];
                return 12;
            }
        };
        $module = new InfrastructureModule(null, null, null, null, $repository);
        $module->register(new Container());

        do_action('fchub_memberships_validity_check');

        self::assertSame([[30, 100]], $repository->calls);
        self::assertSame(
            [5, 6, 7, 10],
            array_column($GLOBALS['_fchub_test_action_registrations']['fchub_memberships_validity_check'], 'priority')
        );
    }

    public function test_receipt_purge_failure_is_caught_without_blocking_validity_recovery(): void
    {
        $repository = new class extends MutationRequestRepository {
            public function __construct()
            {
            }

            public function purgeTerminalOlderThan(int $days = 30, int $limit = 100): int
            {
                throw new \RuntimeException('database details must not leak');
            }
        };
        $module = new InfrastructureModule(null, null, null, null, $repository);
        $module->register(new Container());

        do_action('fchub_memberships_validity_check');

        self::assertNotEmpty($GLOBALS['_fchub_test_fc_error_logs']);
        self::assertStringNotContainsString(
            'database details',
            serialize($GLOBALS['_fchub_test_fc_error_logs'])
        );
    }
}
