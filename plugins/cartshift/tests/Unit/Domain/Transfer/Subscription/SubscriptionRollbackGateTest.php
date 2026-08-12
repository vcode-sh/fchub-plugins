<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionRollbackGate;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionRollbackGateTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-subscription-rollback-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) unlink($file);
        rmdir($this->root);
        parent::tearDown();
    }

    public function testMissingOrUntouchedPreparationDoesNotInventACompletedCutover(): void
    {
        $gate = new SubscriptionRollbackGate(new SubscriptionCutoverEvidenceRepository($this->root));
        $gate->assertAllowed('run-missing-22');
        self::assertTrue(true);

        $this->repository()->create($this->evidence('pending', SubscriptionCutoverEvidence::PREPARED));
        $gate->assertAllowed('run-task-22');
        self::assertTrue(true);
    }

    public function testMarkBeforeActEvidenceBlocksRollbackBecauseSourceMutationIsAmbiguous(): void
    {
        $this->repository()->create($this->evidence('marked', SubscriptionCutoverEvidence::PREPARED));
        $this->expectExceptionMessage('rollback_blocked_after_subscription_source_release:run-task-22');
        (new SubscriptionRollbackGate($this->repository()))->assertAllowed('run-task-22');
    }

    public function testMissingEvidenceBlocksWhenTheFailedPhaseCouldFollowSourceRelease(): void
    {
        $this->expectExceptionMessage('rollback_blocked_subscription_cutover_evidence_missing:run-missing-22');
        (new SubscriptionRollbackGate($this->repository()))->assertAllowed('run-missing-22', true);
    }

    public function testReleasedOwnershipBlocksRollbackEvenBeforeTargetActivation(): void
    {
        $this->repository()->create($this->evidence('released', SubscriptionCutoverEvidence::SOURCE_RELEASED));
        $this->expectExceptionMessage('rollback_blocked_after_subscription_source_release:run-task-22');
        (new SubscriptionRollbackGate($this->repository()))->assertAllowed('run-task-22');
    }

    private function repository(): SubscriptionCutoverEvidenceRepository
    {
        return new SubscriptionCutoverEvidenceRepository($this->root);
    }

    private function evidence(string $releaseState, string $state): SubscriptionCutoverEvidence
    {
        $entry = [
            'source_identity' => 'shop-alpha:subscription:31',
            'source_fingerprint' => str_repeat('1', 64),
            'target_id' => 9031,
            'staged_target_fingerprint' => str_repeat('2', 64),
            'source_release_required' => true,
            'intended_status' => 'active',
            'release_state' => $releaseState,
            'activation_state' => 'paused',
        ];
        if (in_array($releaseState, ['marked', 'released'], true)) {
            $entry += [
                'pre_renewal_fingerprint' => str_repeat('3', 64),
                'pre_release_comparison_fingerprint' => str_repeat('4', 64),
                'previous_requires_manual_renewal' => false,
            ];
        }
        if ($releaseState === 'released') {
            $entry += [
                'post_source_fingerprint' => str_repeat('5', 64),
                'post_renewal_fingerprint' => str_repeat('3', 64),
            ];
        }
        return new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', $state, [$entry], '2026-08-10T12:00:00Z',
        );
    }
}
