<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\RollbackPlan;
use CartShift\Domain\Transfer\SameSite\GuidedRollback;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedRollbackTest extends PluginTestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir() . '/cartshift-guided-rollback-' . bin2hex(random_bytes(8));
        mkdir($this->workspace, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workspace . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->workspace);
        parent::tearDown();
    }

    public function testPreviewNamesOnlyReceiptOwnedTargetsAndTheConfirmation(): void
    {
        $rollback = new GuidedRollback(
            $this->workspace,
            $this->failedState(),
            static fn (): RollbackPlan => new RollbackPlan(
                'tr-491f7178d619ae327139ae2e',
                1,
                [],
                [],
                true,
            ),
            static fn (): array => throw new \LogicException('Preview must not execute.'),
        );

        $preview = $rollback->preview();

        self::assertTrue($preview['safe']);
        self::assertSame(0, $preview['deletion_count']);
        self::assertSame([], $preview['source_identities']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $preview['confirm']);
        self::assertArrayNotHasKey('path', $preview);
    }

    public function testExecutionRecomputesThePlanAndRefusesAStaleConfirmation(): void
    {
        $executed = 0;
        $rollback = new GuidedRollback(
            $this->workspace,
            $this->failedState(),
            static fn (): RollbackPlan => new RollbackPlan(
                'tr-491f7178d619ae327139ae2e',
                1,
                [],
                [],
                true,
            ),
            static function () use (&$executed): array {
                ++$executed;

                return [];
            },
        );

        $this->expectExceptionMessage('guided_rollback_confirmation_changed');
        try {
            $rollback->execute(str_repeat('f', 64));
        } finally {
            self::assertSame(0, $executed);
            self::assertSame([], glob($this->workspace . '/rollback-*.json') ?: []);
        }
    }

    public function testExecutionUsesTheSealedPlanAsLeaseRecoveryEvidence(): void
    {
        $input = [];
        $rollback = new GuidedRollback(
            $this->workspace,
            $this->failedState(),
            static fn (): RollbackPlan => new RollbackPlan(
                'tr-491f7178d619ae327139ae2e',
                1,
                [],
                [],
                true,
            ),
            static function (array $payload) use (&$input): array {
                $input = $payload;

                return ['state' => 'rolled_back'];
            },
        );
        $preview = $rollback->preview();

        $sealed = $rollback->seal($preview['confirm']);
        $result = $rollback->executeSealed($sealed);

        self::assertSame('rollback', $input['command']);
        self::assertSame('guided', $input['execution_context']);
        self::assertSame($preview['confirm'], $input['rollback_plan_fingerprint']);
        self::assertSame(hash_file('sha256', $input['rollback_plan']), $input['lease_recovery']);
        self::assertSame(0, $sealed['deletion_count']);
        self::assertSame('rolled_back', $result['state']);
    }

    public function testRollbackIsUnavailableAfterWooCommerceRenewalOwnershipWasReleased(): void
    {
        (new SubscriptionCutoverEvidenceRepository($this->workspace))->create(new SubscriptionCutoverEvidence(
            'tr-491f7178d619ae327139ae2e',
            'site-0123456789abcdef',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
            str_repeat('e', 64),
            'guided',
            SubscriptionCutoverEvidence::SOURCE_RELEASED,
            [[
                'source_identity' => 'site-0123456789abcdef:subscription:31',
                'source_fingerprint' => str_repeat('1', 64),
                'target_id' => 9031,
                'staged_target_fingerprint' => str_repeat('2', 64),
                'source_release_required' => true,
                'intended_status' => 'active',
                'release_state' => 'released',
                'activation_state' => 'paused',
                'pre_renewal_fingerprint' => str_repeat('3', 64),
                'pre_release_comparison_fingerprint' => str_repeat('4', 64),
                'previous_requires_manual_renewal' => false,
                'post_source_fingerprint' => str_repeat('5', 64),
                'post_renewal_fingerprint' => str_repeat('3', 64),
            ]],
            '2026-08-12T12:00:00Z',
        ));
        $planCalls = 0;
        $rollback = new GuidedRollback(
            $this->workspace,
            $this->failedState(true),
            static function () use (&$planCalls): RollbackPlan {
                ++$planCalls;
                return new RollbackPlan('tr-491f7178d619ae327139ae2e', 1, [], [], true);
            },
        );

        $this->expectExceptionMessage('rollback_blocked_after_subscription_source_release');
        try {
            $rollback->preview();
        } finally {
            self::assertSame(0, $planCalls);
        }
    }

    private function failedState(bool $subscriptions = false): GuidedRunState
    {
        return GuidedRunState::start(
            'site-0123456789abcdef',
            'wp-user:1',
            '2026-08-12T12:00:00Z',
            $subscriptions,
        )
            ->afterStep('audit', ['selection_fingerprint' => str_repeat('a', 64)], 12)
            ->afterStep('export', ['path' => $this->workspace . '/package'], 12)
            ->afterStep('prepare', ['descriptor' => 'tr-491f7178d619ae327139ae2e'], 12)
            ->afterFailure('stage', new \RuntimeException('target_product_assessment_blocked'));
    }
}
