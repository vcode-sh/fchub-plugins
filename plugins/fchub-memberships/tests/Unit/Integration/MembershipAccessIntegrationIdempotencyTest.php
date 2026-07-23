<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Integration;

use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Event\EventClaimResult;
use FChubMemberships\Domain\Event\EventProcessingOutcome;
use FChubMemberships\Integration\MembershipAccessIntegration;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Support\Clock;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MembershipAccessIntegrationIdempotencyTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureIntegrationBaseClass();
        $this->installPlanRow();
    }

    public function test_process_action_returns_a_small_immutable_typed_outcome(): void
    {
        self::assertTrue(class_exists(EventProcessingOutcome::class));
        self::assertTrue((new \ReflectionClass(EventProcessingOutcome::class))->isReadOnly());
    }

    public function test_legacy_clock_only_constructor_keeps_the_access_service_lazy(): void
    {
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-14 12:30:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );
        $integration = new MembershipAccessIntegration($clock);
        self::assertTrue(property_exists($integration, 'accessGrantService'));
        $property = new \ReflectionProperty($integration, 'accessGrantService');

        self::assertNull($property->getValue($integration));
    }

    public function test_claim_precedes_logging_and_grant_mutation_and_success_follows_the_complete_handler(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $this->installPlanRow($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: true, retryable: false, skipped: false);
        self::assertSame([
            ['owner'],
            ['hash', 91, 'product', 7, 'order_paid_done', 'grant'],
            ['claim', 91, 'product', 7, 'order_paid_done', 'grant', 'owner-a', 300],
            ['plan-read'],
            ['grant', 17, 5],
            ['log', 'Membership access granted'],
            [
                'succeed',
                hash('sha256', 'order:91|scope:product|feed:7|trigger:order_paid_done|mode:grant'),
                'owner-a',
            ],
        ], $timeline);
    }

    public function test_duplicate_delivery_runs_one_mutation_and_logs_a_concise_skip(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [
            EventClaimResult::acquired(),
            EventClaimResult::duplicateSucceeded(),
        ]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $first = $integration->processAction($order, $this->grantEvent());
        $second = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($first, success: true, retryable: false, skipped: false);
        $this->assertOutcome($second, success: true, retryable: false, skipped: true);
        self::assertCount(1, $service->grantCalls);
        self::assertCount(1, $service->succeedCalls);
        self::assertCount(0, $service->failCalls);
        self::assertSame('Membership event skipped', $order->logs[1][0]);
        self::assertStringContainsString('already processed', strtolower($order->logs[1][1]));
        self::assertSame('info', $order->logs[1][2]);
    }

    #[DataProvider('retryableClaimProvider')]
    public function test_retryable_claim_outcomes_do_not_run_or_transition_the_handler(
        EventClaimResult $claim,
        ?string $expectedLogTitle
    ): void {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [$claim]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: false, retryable: true, skipped: true);
        self::assertCount(0, $service->grantCalls);
        self::assertCount(0, $service->succeedCalls);
        self::assertCount(0, $service->failCalls);
        if ($expectedLogTitle === null) {
            self::assertSame([], $order->logs);
        } else {
            self::assertSame($expectedLogTitle, $order->logs[0][0]);
            self::assertSame('warning', $order->logs[0][2]);
        }
    }

    public static function retryableClaimProvider(): array
    {
        return [
            'in progress' => [EventClaimResult::inProgress(), 'Membership event already processing'],
            'retryable failed' => [EventClaimResult::retryableFailed(), null],
        ];
    }

    public function test_claim_exception_is_diagnostic_and_does_not_attempt_a_failure_transition(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, []);
        $service->claimThrowable = new \RuntimeException('Claim storage unavailable');
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome(
            $outcome,
            success: false,
            retryable: true,
            skipped: true,
            error: 'Claim storage unavailable'
        );
        self::assertSame([], $service->failCalls);
        self::assertSame([], $service->grantCalls);
        self::assertStringContainsString('Claim storage unavailable', $order->logs[0][1]);
        self::assertStringContainsString('Claim storage unavailable', $GLOBALS['_fchub_test_fc_error_logs'][0][1]);
    }

    public function test_owner_token_factory_throwable_is_diagnostic_before_hash_claim_or_mutation(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $clock = new Clock(
            new \DateTimeImmutable('2026-03-14 12:30:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );
        $integration = new MembershipAccessIntegration(
            $clock,
            $service,
            static function () use (&$timeline): never {
                $timeline[] = ['owner'];

                throw new \RuntimeException('Owner token source unavailable');
            }
        );
        $order = new IdempotencyOrder($timeline);

        try {
            $outcome = $integration->processAction($order, $this->grantEvent());
        } catch (\Throwable $exception) {
            self::fail(sprintf(
                'The owner token factory throwable escaped processAction(): %s: %s',
                $exception::class,
                $exception->getMessage()
            ));
        }

        $this->assertOutcome(
            $outcome,
            success: false,
            retryable: true,
            skipped: true,
            error: 'Owner token source unavailable'
        );
        self::assertSame([
            ['owner'],
            ['log', 'Membership event owner token factory failed'],
        ], $timeline);
        self::assertSame([], $service->claimCalls);
        self::assertSame([], $service->grantCalls);
        self::assertSame([], $service->revokeCalls);
        self::assertSame([], $service->succeedCalls);
        self::assertSame([], $service->failCalls);
        self::assertStringContainsString('owner token factory', strtolower(serialize($order->logs)));
        self::assertStringContainsString('RuntimeException', serialize($order->logs));
        self::assertStringContainsString('Owner token source unavailable', serialize($order->logs));
        self::assertStringContainsString('owner token factory', strtolower(serialize($GLOBALS['_fchub_test_fc_error_logs'])));
        self::assertStringContainsString('RuntimeException', serialize($GLOBALS['_fchub_test_fc_error_logs']));
        self::assertStringContainsString(
            'Owner token source unavailable',
            serialize($GLOBALS['_fchub_test_fc_error_logs'])
        );
        self::assertStringNotContainsString('owner-a', serialize([$order->logs, $GLOBALS['_fchub_test_fc_error_logs']]));
    }

    public function test_terminal_claim_failure_does_not_run_or_transition_the_handler(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::terminalFailed()]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: false, retryable: false, skipped: true);
        self::assertCount(0, $service->grantCalls);
        self::assertCount(0, $service->succeedCalls);
        self::assertCount(0, $service->failCalls);
        self::assertSame([], $order->logs);
    }

    public function test_grant_and_revoke_for_the_same_order_use_separate_modes_and_each_run_once(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [
            EventClaimResult::acquired(),
            EventClaimResult::acquired(),
        ]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $this->installActiveOrderGrant();

        $grant = $integration->processAction($order, $this->grantEvent());
        $revoke = $integration->processAction($order, $this->revokeEvent());

        $this->assertOutcome($grant, success: true, retryable: false, skipped: false);
        $this->assertOutcome($revoke, success: true, retryable: false, skipped: false);
        self::assertCount(1, $service->grantCalls);
        self::assertCount(1, $service->revokeCalls);
        self::assertSame(['grant', 'revoke'], array_column($service->claimCalls, 'mode'));
        self::assertNotSame($service->succeedCalls[0][0], $service->succeedCalls[1][0]);
        self::assertSame('product', $service->grantCalls[0][2]['feed_scope']);
        self::assertSame(7, $service->grantCalls[0][2]['feed_id']);
        self::assertSame('order', $service->revokeCalls[0][2]['source_type']);
        self::assertSame(91, $service->revokeCalls[0][2]['source_id']);
        self::assertSame('product', $service->revokeCalls[0][2]['feed_scope']);
        self::assertSame(7, $service->revokeCalls[0][2]['feed_id']);
        self::assertMatchesRegularExpression('/^membership:grant:[a-f0-9]{64}$/', $service->grantCalls[0][2]['origin_event']);
        self::assertMatchesRegularExpression('/^membership:revoke:[a-f0-9]{64}$/', $service->revokeCalls[0][2]['origin_event']);
    }

    public function test_equal_numeric_product_and_global_feed_ids_do_not_collide(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [
            EventClaimResult::acquired(),
            EventClaimResult::acquired(),
        ]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $product = $this->grantEvent();
        $global = array_merge($this->grantEvent(), ['scope' => 'global']);

        $productOutcome = $integration->processAction($order, $product);
        $globalOutcome = $integration->processAction($order, $global);

        $this->assertOutcome($productOutcome, success: true, retryable: false, skipped: false);
        $this->assertOutcome($globalOutcome, success: true, retryable: false, skipped: false);
        self::assertSame(['product', 'global'], array_column($service->claimCalls, 'scope'));
        self::assertSame([7, 7], array_column($service->claimCalls, 'integration_id'));
        self::assertNotSame($service->succeedCalls[0][0], $service->succeedCalls[1][0]);
    }

    public function test_trigger_and_owner_are_validated_by_characters_at_their_storage_boundaries(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $owner = str_repeat('ę', 64);
        $integration = $this->integration($service, $timeline, $owner);
        $order = new IdempotencyOrder($timeline);
        $event = $this->grantEvent();
        $event['trigger'] = str_repeat('ż', 100);

        $outcome = $integration->processAction($order, $event);

        $this->assertOutcome($outcome, success: true, retryable: false, skipped: false);
        self::assertSame($owner, $service->claimCalls[0]['owner_token']);
        self::assertSame($event['trigger'], $service->claimCalls[0]['trigger']);
    }

    public function test_normal_return_provider_failure_is_failed_retryably_and_the_next_delivery_retries(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [
            EventClaimResult::acquired(),
            EventClaimResult::acquired(),
        ]);
        $service->grantResults = [
            [
                'created' => 0,
                'updated' => 0,
                'total' => 1,
                'failed' => 1,
                'errors' => [['message' => 'Provider unavailable']],
            ],
            ['created' => 1, 'updated' => 0, 'total' => 1, 'failed' => 0, 'errors' => []],
        ];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $first = $integration->processAction($order, $this->grantEvent());
        $second = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($first, success: false, retryable: true, skipped: false, error: 'Provider unavailable');
        $this->assertOutcome($second, success: true, retryable: false, skipped: false);
        self::assertCount(2, $service->grantCalls);
        self::assertSame('Provider unavailable', $service->failCalls[0][2]);
        self::assertTrue($service->failCalls[0][3]);
        self::assertCount(1, $service->succeedCalls);
    }

    public function test_explicit_unsuccessful_grant_result_is_failed_even_without_a_failed_counter(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->grantResults = [[
            'success' => false,
            'created' => 0,
            'updated' => 0,
            'total' => 1,
            'failed' => 0,
            'errors' => [['message' => 'Provider refused the grant']],
        ]];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome(
            $outcome,
            success: false,
            retryable: true,
            skipped: false,
            error: 'Provider refused the grant'
        );
        self::assertSame('Provider refused the grant', $service->failCalls[0][2]);
        self::assertSame([], $service->succeedCalls);
    }

    public function test_downgrade_block_without_failures_is_a_handled_skip_and_succeeds_the_lock(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->grantResults = [[
            'created' => 0,
            'updated' => 0,
            'total' => 0,
            'failed' => 0,
            'blocked' => true,
            'reason' => 'downgrade_blocked',
        ]];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: true, retryable: false, skipped: true);
        self::assertCount(1, $service->succeedCalls);
        self::assertSame([], $service->failCalls);
    }

    public function test_blocked_transition_with_failures_remains_retryable(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->grantResults = [[
            'success' => false,
            'created' => 0,
            'updated' => 0,
            'total' => 1,
            'failed' => 1,
            'blocked' => true,
            'reason' => 'upgrade_revoke_failed',
            'errors' => [['message' => 'Old plan revoke failed']],
        ]];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome(
            $outcome,
            success: false,
            retryable: true,
            skipped: false,
            error: 'Old plan revoke failed'
        );
        self::assertTrue($service->failCalls[0][3]);
        self::assertSame([], $service->succeedCalls);
    }

    #[DataProvider('failedRevokeResultProvider')]
    public function test_partial_or_unsuccessful_revoke_result_is_failed_retryably(
        array $revokeResult,
        string $expectedLogType
    ): void {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->revokeResults = [$revokeResult];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $this->installActiveOrderGrant();

        $outcome = $integration->processAction($order, $this->revokeEvent());

        $this->assertOutcome(
            $outcome,
            success: false,
            retryable: true,
            skipped: false,
            error: 'Provider revoke failed'
        );
        self::assertTrue($service->failCalls[0][3]);
        self::assertSame([], $service->succeedCalls);
        self::assertSame($expectedLogType, $order->logs[0][2]);
    }

    public static function failedRevokeResultProvider(): array
    {
        return [
            'partial revoke' => [[
                'success' => false,
                'partial' => true,
                'revoked' => 1,
                'grace_started' => 0,
                'failed' => 1,
                'errors' => [['message' => 'Provider revoke failed']],
            ], 'warning'],
            'explicit unsuccessful revoke' => [[
                'success' => false,
                'partial' => false,
                'revoked' => 0,
                'grace_started' => 0,
                'failed' => 0,
                'errors' => [['message' => 'Provider revoke failed']],
            ], 'error'],
        ];
    }

    public function test_revoke_retry_reaches_canonical_service_after_legacy_mirror_is_gone_and_completes(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [
            EventClaimResult::acquired(),
            EventClaimResult::acquired(),
        ]);
        $service->revokeResults = [
            [
                'success' => false,
                'revoked' => 1,
                'grace_started' => 0,
                'pending' => 1,
                'failed' => 0,
                'errors' => [],
            ],
            [
                'success' => true,
                'revoked' => 1,
                'grace_started' => 0,
                'pending' => 0,
                'failed' => 0,
                'errors' => [],
            ],
        ];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $pending = $integration->processAction($order, $this->revokeEvent());
        $applied = $integration->processAction($order, $this->revokeEvent());

        $this->assertOutcome($pending, success: false, retryable: true, skipped: false);
        $this->assertOutcome($applied, success: true, retryable: false, skipped: false);
        self::assertCount(2, $service->revokeCalls);
        self::assertCount(1, $service->failCalls);
        self::assertCount(1, $service->succeedCalls);
    }

    public function test_revoke_retry_reaches_canonical_service_after_legacy_mirror_is_gone_and_stays_terminal(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [
            EventClaimResult::acquired(),
            EventClaimResult::acquired(),
        ]);
        $service->revokeResults = [
            [
                'success' => false,
                'revoked' => 1,
                'grace_started' => 0,
                'pending' => 1,
                'failed' => 0,
                'errors' => [],
            ],
            [
                'success' => false,
                'revoked' => 1,
                'grace_started' => 0,
                'pending' => 0,
                'failed' => 1,
                'errors' => [['message' => 'Provider revoke failed terminally.']],
            ],
        ];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $pending = $integration->processAction($order, $this->revokeEvent());
        $terminal = $integration->processAction($order, $this->revokeEvent());

        $this->assertOutcome($pending, success: false, retryable: true, skipped: false);
        $this->assertOutcome($terminal, success: false, retryable: true, skipped: false);
        self::assertCount(2, $service->revokeCalls);
        self::assertCount(2, $service->failCalls);
        self::assertSame([], $service->succeedCalls);
    }

    public function test_missing_plan_id_is_terminally_failed_and_finalises_the_acquired_lock(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $event = $this->grantEvent();
        $event['feed']['plan_id'] = 0;

        $outcome = $integration->processAction($order, $event);

        $this->assertOutcome($outcome, success: false, retryable: false, skipped: false, error: 'plan ID');
        self::assertFalse($service->failCalls[0][3]);
        self::assertSame([], $service->succeedCalls);
    }

    public function test_missing_plan_is_terminally_failed_and_finalises_the_acquired_lock(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): null => null;

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: false, retryable: false, skipped: false, error: 'not found');
        self::assertFalse($service->failCalls[0][3]);
        self::assertSame([], $service->succeedCalls);
    }

    public function test_unresolved_user_is_terminally_failed_and_finalises_the_acquired_lock(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $order->user_id = null;
        $order->customer_email = null;
        $event = $this->grantEvent();
        $event['feed']['auto_create_user'] = 'no';

        $outcome = $integration->processAction($order, $event);

        $this->assertOutcome($outcome, success: false, retryable: false, skipped: false, error: 'No user');
        self::assertFalse($service->failCalls[0][3]);
        self::assertSame([], $service->succeedCalls);
    }

    public function test_exact_feed_refund_does_not_read_mutable_current_feed_cancellation_policy(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $event = $this->revokeEvent();
        $event['feed']['cancel_behavior'] = 'wait_validity';

        $outcome = $integration->processAction($order, $event);

        $this->assertOutcome($outcome, success: true, retryable: false, skipped: false);
        self::assertCount(1, $service->succeedCalls);
        self::assertSame([], $service->failCalls);
        self::assertCount(1, $service->revokeCalls);
    }

    public function test_revoke_with_no_active_grants_is_a_handled_skip_and_succeeds_the_lock(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->revokeResults = [[
            'success' => true,
            'revoked' => 0,
            'grace_started' => 0,
            'pending' => 0,
            'failed' => 0,
            'errors' => [],
        ]];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->revokeEvent());

        $this->assertOutcome($outcome, success: true, retryable: false, skipped: true);
        self::assertCount(1, $service->succeedCalls);
        self::assertSame([], $service->failCalls);
        self::assertCount(1, $service->revokeCalls);
    }

    public function test_throwable_failure_uses_only_the_stable_retryable_error_contract(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->grantThrowable = new \RuntimeException('Provider exploded');
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome(
            $outcome,
            success: false,
            retryable: true,
            skipped: false,
            error: 'Membership lifecycle processing failed.'
        );
        self::assertSame('Membership lifecycle processing failed.', $service->failCalls[0][2]);
        self::assertTrue($service->failCalls[0][3]);
        self::assertStringNotContainsString('Provider exploded', serialize([$outcome, $service->failCalls, $order->logs]));
        self::assertCount(0, $service->succeedCalls);
    }

    public function test_failed_success_transition_becomes_terminal_to_avoid_replaying_completed_effects(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->succeedResult = false;
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: false, retryable: false, skipped: false, error: 'completion');
        self::assertCount(1, $service->succeedCalls);
        self::assertCount(1, $service->failCalls);
        self::assertFalse($service->failCalls[0][3]);
        self::assertStringContainsString('lock', strtolower($order->logs[1][0]));
        self::assertStringContainsString('completion', strtolower($GLOBALS['_fchub_test_fc_error_logs'][0][1]));
    }

    public function test_completion_transition_throw_preserves_stage_and_throwable_diagnostics(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->succeedThrowable = new \RuntimeException('Completion storage exploded');
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: false, retryable: false, skipped: false, error: 'completion');
        self::assertCount(1, $service->grantCalls);
        self::assertCount(1, $service->succeedCalls);
        self::assertCount(1, $service->failCalls);
        self::assertFalse($service->failCalls[0][3]);
        $orderDiagnostic = serialize($order->logs);
        $systemDiagnostic = serialize($GLOBALS['_fchub_test_fc_error_logs']);
        self::assertStringContainsString('succeedEventLock', $orderDiagnostic);
        self::assertStringContainsString('RuntimeException', $orderDiagnostic);
        self::assertStringContainsString('Completion storage exploded', $orderDiagnostic);
        self::assertStringContainsString('succeedEventLock', $systemDiagnostic);
        self::assertStringContainsString('RuntimeException', $systemDiagnostic);
        self::assertStringContainsString('Completion storage exploded', $systemDiagnostic);
        self::assertStringNotContainsString('owner-a', serialize([$orderDiagnostic, $systemDiagnostic]));
    }

    public function test_failed_failure_transition_preserves_the_provider_error_and_stays_retryable(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->failResult = false;
        $service->grantResults = [[
            'created' => 0,
            'updated' => 0,
            'total' => 1,
            'failed' => 1,
            'errors' => [['message' => 'Provider unavailable']],
        ]];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: false, retryable: true, skipped: false, error: 'Provider unavailable');
        self::assertCount(1, $service->failCalls);
        self::assertStringContainsString('lock', strtolower($order->logs[1][0]));
        self::assertStringContainsString('Provider unavailable', $GLOBALS['_fchub_test_fc_error_logs'][0][1]);
    }

    public function test_failure_transition_throw_preserves_provider_outcome_and_throwable_diagnostics(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $service->failThrowable = new \DomainException('Failure storage exploded');
        $service->grantResults = [[
            'created' => 0,
            'updated' => 0,
            'total' => 1,
            'failed' => 1,
            'errors' => [['message' => 'Provider unavailable']],
        ]];
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);

        $outcome = $integration->processAction($order, $this->grantEvent());

        $this->assertOutcome($outcome, success: false, retryable: true, skipped: false, error: 'Provider unavailable');
        self::assertCount(1, $service->failCalls);
        self::assertSame('Provider unavailable', $service->failCalls[0][2]);
        self::assertTrue($service->failCalls[0][3]);
        $orderDiagnostic = serialize($order->logs);
        $systemDiagnostic = serialize($GLOBALS['_fchub_test_fc_error_logs']);
        self::assertStringContainsString('failEventLock', $orderDiagnostic);
        self::assertStringContainsString('DomainException', $orderDiagnostic);
        self::assertStringContainsString('Failure storage exploded', $orderDiagnostic);
        self::assertStringContainsString('Provider unavailable', $orderDiagnostic);
        self::assertStringContainsString('failEventLock', $systemDiagnostic);
        self::assertStringContainsString('DomainException', $systemDiagnostic);
        self::assertStringContainsString('Failure storage exploded', $systemDiagnostic);
        self::assertStringContainsString('Provider unavailable', $systemDiagnostic);
        self::assertStringNotContainsString('owner-a', serialize([$orderDiagnostic, $systemDiagnostic]));
    }

    #[DataProvider('invalidIdentifierProvider')]
    public function test_invalid_identifiers_fail_terminally_before_claim_logging_or_mutation(
        object $order,
        array $eventData,
        mixed $ownerToken
    ): void {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline, $ownerToken);

        $outcome = $integration->processAction($order, $eventData);

        $this->assertOutcome($outcome, success: false, retryable: false, skipped: true);
        self::assertSame([], $service->claimCalls);
        self::assertSame([], $service->grantCalls);
        self::assertSame([], $service->revokeCalls);
        self::assertSame([], $order->logs ?? []);
    }

    public static function invalidIdentifierProvider(): array
    {
        return [
            'invalid order id' => [new \stdClass(), self::eventFixture(), 'owner-a'],
            'non-positive order id' => [
                (object) ['id' => 0, 'user_id' => 17, 'logs' => []],
                self::eventFixture(),
                'owner-a',
            ],
            'missing integration id despite legacy feed id' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                array_merge(self::eventFixture(), ['integration_id' => null, 'feed_id' => 7]),
                'owner-a',
            ],
            'invalid integration id' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                array_merge(self::eventFixture(), ['integration_id' => 0]),
                'owner-a',
            ],
            'missing scope' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                array_merge(self::eventFixture(), ['scope' => null]),
                'owner-a',
            ],
            'invalid scope' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                array_merge(self::eventFixture(), ['scope' => 'site']),
                'owner-a',
            ],
            'missing trigger' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                array_merge(self::eventFixture(), ['trigger' => '']),
                'owner-a',
            ],
            'oversized trigger' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                array_merge(self::eventFixture(), ['trigger' => str_repeat('ż', 101)]),
                'owner-a',
            ],
            'invalid owner token' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                self::eventFixture(),
                '',
            ],
            'oversized owner token' => [
                (object) ['id' => 91, 'user_id' => 17, 'logs' => []],
                self::eventFixture(),
                str_repeat('ę', 65),
            ],
        ];
    }

    public function test_subscription_renewal_is_prepared_elsewhere_and_never_claimed_or_mutated_here(): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $order = new IdempotencyOrder($timeline);
        $event = $this->grantEvent();
        $event['trigger'] = 'subscription_renewed';
        $event['event_data'] = [
            'subscription' => (object) ['id' => 88],
            'order' => (object) ['id' => 1201],
        ];

        $outcome = $integration->processAction($order, $event);

        $this->assertOutcome($outcome, success: true, retryable: false, skipped: true);
        self::assertSame([], $service->claimCalls);
        self::assertSame([], $service->grantCalls);
        self::assertSame([], $service->revokeCalls);
        self::assertSame([], $timeline, 'Subscription renewal must not resolve an owner, hash, claim, log, or mutate here.');
    }

    #[DataProvider('subscriptionLifecycleTriggerProvider')]
    public function test_every_subscription_lifecycle_trigger_is_observer_only_in_the_integration(string $trigger): void
    {
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $event = $this->grantEvent();
        $event['trigger'] = $trigger;

        $outcome = $integration->processAction(new IdempotencyOrder($timeline), $event);

        $this->assertOutcome($outcome, success: true, retryable: false, skipped: true);
        self::assertSame([], $timeline);
        self::assertSame([], $service->grantCalls);
        self::assertSame([], $service->revokeCalls);
    }

    public static function subscriptionLifecycleTriggerProvider(): array
    {
        return array_map(static fn(string $trigger): array => [$trigger], [
            'subscription_activated',
            'subscription_reactivated',
            'subscription_renewed',
            'subscription_canceled',
            'subscription_eot',
            'subscription_expired_validity',
        ]);
    }

    public function test_paid_creation_snapshots_normalised_feed_lifecycle_policy_onto_grant_context(): void
    {
        $this->installPlanRow(planOverrides: ['duration_type' => 'subscription_mirror']);
        $timeline = [];
        $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
        $integration = $this->integration($service, $timeline);
        $event = $this->grantEvent();
        $event['feed']['cancel_behavior'] = 'immediate';
        $event['feed']['grace_period_days'] = '7';
        $event['feed']['validity_mode'] = 'mirror_subscription';
        $event['event_data']['subscription'] = (object) ['id' => 88];

        $integration->processAction(new IdempotencyOrder($timeline), $event);

        self::assertSame([
            'cancel_behavior' => 'immediate',
            'grace_period_days' => 7,
            'validity_mode' => 'mirror_subscription',
            'subscription_id' => 88,
        ], $service->grantCalls[0][2]['policy']);
    }

    public function test_paid_creation_snapshots_effective_finite_policy_after_plan_precedence(): void
    {
        foreach ([
            [
                ['duration_type' => 'subscription_mirror'],
                ['validity_mode' => 'fixed_duration', 'validity_days' => 14],
                ['validity_mode' => 'fixed_duration', 'validity_days' => 14],
            ],
            [
                ['duration_type' => 'fixed_days', 'duration_days' => 30],
                ['validity_mode' => 'lifetime'],
                ['validity_mode' => 'fixed_duration', 'validity_days' => 30],
            ],
            [
                ['duration_type' => 'fixed_anchor', 'meta' => wp_json_encode(['billing_anchor_day' => 12])],
                ['validity_mode' => 'lifetime'],
                ['validity_mode' => 'anchor_billing', 'billing_anchor_day' => 12],
            ],
        ] as [$plan, $feed, $expected]) {
            $this->installPlanRow(planOverrides: $plan);
            $timeline = [];
            $service = new IdempotencyAccessGrantService($timeline, [EventClaimResult::acquired()]);
            $integration = $this->integration($service, $timeline);
            $event = $this->grantEvent();
            $event['feed'] = array_merge($event['feed'], $feed);

            $integration->processAction(new IdempotencyOrder($timeline), $event);

            self::assertSame($expected, array_intersect_key(
                $service->grantCalls[0][2]['policy'],
                array_flip(array_keys($expected))
            ));
        }
    }

    private function integration(
        IdempotencyAccessGrantService $service,
        array &$timeline,
        mixed $ownerToken = 'owner-a'
    ): MembershipAccessIntegration {
        $constructor = new \ReflectionMethod(MembershipAccessIntegration::class, '__construct');
        self::assertGreaterThanOrEqual(
            3,
            $constructor->getNumberOfParameters(),
            'Task 5 requires compatible AccessGrantService and owner-token-factory injection.'
        );

        $clock = new Clock(
            new \DateTimeImmutable('2026-03-14 12:30:00', new \DateTimeZone('UTC')),
            new \DateTimeZone('UTC')
        );

        return new MembershipAccessIntegration(
            $clock,
            $service,
            static function () use (&$timeline, $ownerToken): mixed {
                $timeline[] = ['owner'];

                return $ownerToken;
            }
        );
    }

    private function grantEvent(): array
    {
        return self::eventFixture();
    }

    private function revokeEvent(): array
    {
        return array_merge(self::eventFixture(), [
            'is_revoke_hook' => 'yes',
            'feed' => array_merge(self::eventFixture()['feed'], ['cancel_behavior' => 'immediate']),
        ]);
    }

    private static function eventFixture(): array
    {
        return [
            'trigger' => 'order_paid_done',
            'scope' => 'product',
            'integration_id' => 7,
            'is_revoke_hook' => 'no',
            'feed' => [
                'plan_id' => 5,
                'validity_mode' => 'lifetime',
                'auto_create_user' => 'yes',
            ],
        ];
    }

    private function assertOutcome(
        mixed $outcome,
        bool $success,
        bool $retryable,
        bool $skipped,
        ?string $error = null
    ): void {
        self::assertInstanceOf(EventProcessingOutcome::class, $outcome);
        self::assertSame($success, $outcome->success);
        self::assertSame($retryable, $outcome->retryable);
        self::assertSame($skipped, $outcome->skipped);
        if ($error !== null) {
            self::assertStringContainsString($error, (string) $outcome->error);
        }
    }

    private function installPlanRow(?array &$timeline = null, array $planOverrides = []): void
    {
        PlanRepository::clearCache();
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static function (string $query) use (&$timeline, $planOverrides): ?array {
            if (!str_contains($query, 'fchub_membership_plans')) {
                return null;
            }
            if ($timeline !== null) {
                $timeline[] = ['plan-read'];
            }

            return array_merge([
                'id' => 5,
                'title' => 'Gold',
                'slug' => 'gold',
                'level' => 1,
                'status' => 'active',
                'duration_type' => 'lifetime',
                'duration_days' => null,
                'trial_days' => 0,
                'grace_period_days' => 0,
                'includes_plan_ids' => '[]',
                'settings' => '{}',
                'meta' => '{}',
            ], $planOverrides);
        };
    }

    private function installActiveOrderGrant(): void
    {
        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): null => null;
        $GLOBALS['_fchub_test_wpdb_overrides']['get_results'] = static function (string $query): array {
            if (!str_contains($query, 'fchub_membership_grants')) {
                return [];
            }

            return [[
                'id' => 301,
                'user_id' => 17,
                'plan_id' => 5,
                'source_type' => 'order',
                'source_id' => 91,
                'source_ids' => '[91]',
                'feed_id' => 7,
                'status' => 'active',
                'meta' => '{}',
            ]];
        };
    }

    private function ensureIntegrationBaseClass(): void
    {
        if (!class_exists('FluentCart\\App\\Modules\\Integrations\\BaseIntegrationManager')) {
            eval('namespace FluentCart\\App\\Modules\\Integrations; class BaseIntegrationManager { public $description; public $logo; public $category; public $scopes; public $hasGlobalMenu; public $disableGlobalSettings; public function __construct(...$args) {} }');
        }
        if (!class_exists('FluentCart\\Framework\\Support\\Arr')) {
            eval('namespace FluentCart\\Framework\\Support; class Arr { public static function get($value, $key, $default = null) { foreach (explode(".", (string) $key) as $segment) { if (is_array($value) && array_key_exists($segment, $value)) { $value = $value[$segment]; continue; } if (is_object($value) && isset($value->{$segment})) { $value = $value->{$segment}; continue; } return $default; } return $value; } }');
        }
    }
}

final class IdempotencyAccessGrantService extends AccessGrantService
{
    /** @var list<array<string, mixed>> */
    public array $claimCalls = [];
    /** @var list<array{string, string}> */
    public array $succeedCalls = [];
    /** @var list<array{string, string, string, bool}> */
    public array $failCalls = [];
    public array $grantCalls = [];
    public array $revokeCalls = [];
    public array $grantResults = [];
    public array $revokeResults = [];
    public bool $succeedResult = true;
    public bool $failResult = true;
    public ?\Throwable $claimThrowable = null;
    public ?\Throwable $grantThrowable = null;
    public ?\Throwable $succeedThrowable = null;
    public ?\Throwable $failThrowable = null;

    private array $timeline;

    /** @param list<EventClaimResult> $claimResults */
    public function __construct(array &$timeline, private array $claimResults)
    {
        $this->timeline =& $timeline;
    }

    public function orderEventHash(
        int $orderId,
        string $scope,
        int $integrationId,
        string $trigger,
        string $mode
    ): string {
        $this->timeline[] = ['hash', $orderId, $scope, $integrationId, $trigger, $mode];

        return hash(
            'sha256',
            "order:{$orderId}|scope:{$scope}|feed:{$integrationId}|trigger:{$trigger}|mode:{$mode}"
        );
    }

    public function claimOrderEvent(
        int $orderId,
        string $scope,
        int $integrationId,
        string $trigger,
        string $mode,
        string $ownerToken,
        int $leaseSeconds = 300
    ): EventClaimResult {
        $this->claimCalls[] = [
            'order_id' => $orderId,
            'scope' => $scope,
            'integration_id' => $integrationId,
            'trigger' => $trigger,
            'mode' => $mode,
            'owner_token' => $ownerToken,
            'lease_seconds' => $leaseSeconds,
        ];
        $this->timeline[] = [
            'claim',
            $orderId,
            $scope,
            $integrationId,
            $trigger,
            $mode,
            $ownerToken,
            $leaseSeconds,
        ];
        if ($this->claimThrowable !== null) {
            throw $this->claimThrowable;
        }

        return array_shift($this->claimResults) ?? EventClaimResult::terminalFailed();
    }

    public function succeedEventLock(string $eventHash, string $ownerToken): bool
    {
        $this->succeedCalls[] = [$eventHash, $ownerToken];
        $this->timeline[] = ['succeed', $eventHash, $ownerToken];
        if ($this->succeedThrowable !== null) {
            throw $this->succeedThrowable;
        }

        return $this->succeedResult;
    }

    public function failEventLock(
        string $eventHash,
        string $ownerToken,
        string $error,
        bool $retryable = true
    ): bool {
        $this->failCalls[] = [$eventHash, $ownerToken, $error, $retryable];
        $this->timeline[] = ['fail', $eventHash, $ownerToken, $error, $retryable];
        if ($this->failThrowable !== null) {
            throw $this->failThrowable;
        }

        return $this->failResult;
    }

    public function grantPlan(int $userId, int $planId, array $context = []): array
    {
        $this->grantCalls[] = [$userId, $planId, $context];
        $this->timeline[] = ['grant', $userId, $planId];
        if ($this->grantThrowable !== null) {
            throw $this->grantThrowable;
        }

        return array_shift($this->grantResults)
            ?? ['created' => 1, 'updated' => 0, 'total' => 1, 'failed' => 0, 'errors' => []];
    }

    public function revokePlan(int $userId, int $planId, array $context = []): array
    {
        $this->revokeCalls[] = [$userId, $planId, $context];
        $this->timeline[] = ['revoke', $userId, $planId];

        return array_shift($this->revokeResults)
            ?? ['success' => true, 'revoked' => 1, 'grace_started' => 0, 'failed' => 0, 'errors' => []];
    }
}

final class IdempotencyOrder
{
    public int $id = 91;
    public ?int $user_id = 17;
    public ?string $customer_email = 'member@example.com';
    /** @var list<array{string, string, string, string}> */
    public array $logs = [];

    private array $timeline;

    public function __construct(array &$timeline)
    {
        $this->timeline =& $timeline;
    }

    public function addLog(string $title, string $description, string $type, string $module): void
    {
        $this->logs[] = [$title, $description, $type, $module];
        $this->timeline[] = ['log', $title];
    }
}
