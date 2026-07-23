<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Integration\WebhookQueue;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WebhookQueueTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_fchub_test_as_actions'] = [];
        $GLOBALS['_fchub_test_single_events'] = [];
        unset(
            $GLOBALS['_fchub_test_as_has_scheduled_action_override'],
            $GLOBALS['_fchub_test_as_schedule_single_action_override'],
            $GLOBALS['_fchub_test_wp_next_scheduled_override'],
            $GLOBALS['_fchub_test_wp_schedule_single_event_override']
        );
    }

    public function test_action_scheduler_uses_attempt_scoped_unique_groups_and_delivery_only_arguments(): void
    {
        $queue = new WebhookQueue(true);

        self::assertTrue($queue->schedule(42, 1, 1773439200));
        self::assertTrue($queue->schedule(42, 1, 1773439200));
        self::assertTrue($queue->schedule(42, 2, 1773439260));

        self::assertSame([
            [
                1773439200,
                WebhookQueue::HOOK,
                [42],
                'fchub-memberships-webhooks-42-a1',
                true,
                10,
                'pending',
            ],
            [
                1773439260,
                WebhookQueue::HOOK,
                [42],
                'fchub-memberships-webhooks-42-a2',
                true,
                10,
                'pending',
            ],
        ], $GLOBALS['_fchub_test_as_actions']);
    }

    public function test_action_scheduler_ignores_terminal_rows_when_deduplicating(): void
    {
        $GLOBALS['_fchub_test_as_actions'][] = [
            1773439100,
            WebhookQueue::HOOK,
            [42],
            'fchub-memberships-webhooks-42-a1',
            true,
            10,
            'failed',
        ];

        self::assertTrue((new WebhookQueue(true))->schedule(42, 1, 1773439200));
        self::assertCount(2, $GLOBALS['_fchub_test_as_actions']);
        self::assertSame('pending', $GLOBALS['_fchub_test_as_actions'][1][6]);
    }

    public function test_action_scheduler_zero_is_success_only_when_the_exact_attempt_exists_afterward(): void
    {
        $hasChecks = 0;
        $GLOBALS['_fchub_test_as_has_scheduled_action_override'] = static function () use (&$hasChecks): bool {
            $hasChecks++;
            return $hasChecks === 2;
        };
        $GLOBALS['_fchub_test_as_schedule_single_action_override'] = static fn(): int => 0;

        self::assertTrue((new WebhookQueue(true))->schedule(7, 3, 1773439200));
        self::assertSame(2, $hasChecks);

        $GLOBALS['_fchub_test_as_has_scheduled_action_override'] = static fn(): bool => false;
        self::assertFalse((new WebhookQueue(true))->schedule(8, 3, 1773439200));
    }

    public function test_wp_cron_fallback_deduplicates_the_same_delivery_arguments(): void
    {
        $queue = new WebhookQueue(false);

        self::assertTrue($queue->schedule(12, 1, 1773439200));
        self::assertTrue($queue->schedule(12, 1, 1773439200));

        self::assertSame([
            [1773439200, WebhookQueue::HOOK, [12]],
        ], $GLOBALS['_fchub_test_single_events']);
    }

    public function test_wp_cron_failure_is_rechecked_and_then_reported(): void
    {
        $GLOBALS['_fchub_test_wp_schedule_single_event_override'] = static fn(): \WP_Error =>
            new \WP_Error('schedule_failed', 'No cron storage');

        self::assertFalse((new WebhookQueue(false))->schedule(12, 1, 1773439200));
    }

    public function test_wp_cron_false_or_error_succeeds_when_exact_event_appears_after_scheduling(): void
    {
        foreach ([false, new \WP_Error('schedule_failed', 'Raced')] as $scheduleResult) {
            $checks = 0;
            $GLOBALS['_fchub_test_wp_next_scheduled_override'] = static function () use (&$checks): int|false {
                $checks++;
                return $checks === 2 ? 1773439200 : false;
            };
            $GLOBALS['_fchub_test_wp_schedule_single_event_override'] =
                static fn(): bool|\WP_Error => $scheduleResult;

            self::assertTrue((new WebhookQueue(false))->schedule(12, 1, 1773439200));
            self::assertSame(2, $checks);
        }
    }

    #[DataProvider('invalidScheduleProvider')]
    public function test_invalid_schedule_identity_is_rejected(int $deliveryId, int $attempt, int $timestamp): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new WebhookQueue(true))->schedule($deliveryId, $attempt, $timestamp);
    }

    public static function invalidScheduleProvider(): array
    {
        return [
            'delivery' => [0, 1, 1773439200],
            'attempt' => [1, 0, 1773439200],
            'timestamp' => [1, 1, 0],
        ];
    }
}
