<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Subscription\LoadedSubscriptionCompletionGate;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionTargetFingerprint;
use CartShift\Domain\Transfer\Subscription\SubscriptionTargetGateway;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedSubscriptionCompletionGateTest extends PluginTestCase
{
    private string $root;
    protected function setUp(): void { parent::setUp(); $this->root = sys_get_temp_dir() . '/cartshift-completion-' . bin2hex(random_bytes(8)); mkdir($this->root, 0700); }
    protected function tearDown(): void { foreach (glob($this->root . '/*') ?: [] as $file) unlink($file); rmdir($this->root); parent::tearDown(); }

    public function testExactReconciledEvidencePassesAndIndependentTargetDriftStops(): void
    {
        $prepared = $this->prepared();
        $receipt = new TransferReceipt(
            $prepared->runId, 'subscription', 'shop-alpha:subscription:31', 1,
            str_repeat('1', 64), 'created', ['primary' => 9031], null, str_repeat('2', 64), 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
        $snapshot = ['subscription' => ['id' => 9031, 'status' => 'active', 'updated_at' => 'later'], 'transaction_links' => [], 'meta' => []];
        $activated = CanonicalJson::fingerprint(SubscriptionTargetFingerprint::normalise($snapshot));
        $evidence = new SubscriptionCutoverEvidence(
            $prepared->runId, $prepared->sourceKey, $prepared->packageHash,
            $prepared->targetState->decisionHash, $prepared->targetState->selectionHash,
            str_repeat('7', 64), str_repeat('8', 64),
            'rehearsal', SubscriptionCutoverEvidence::RECONCILED, [[
                'source_identity' => $receipt->sourceIdentity,
                'source_fingerprint' => $receipt->sourceFingerprint,
                'target_id' => 9031,
                'staged_target_fingerprint' => $receipt->afterFingerprint,
                'source_release_required' => true,
                'intended_status' => 'active',
                'release_state' => 'released',
                'activation_state' => 'reconciled',
                'pre_renewal_fingerprint' => str_repeat('3', 64),
                'pre_release_comparison_fingerprint' => str_repeat('4', 64),
                'previous_requires_manual_renewal' => false,
                'post_source_fingerprint' => str_repeat('5', 64),
                'post_renewal_fingerprint' => str_repeat('3', 64),
                'activated_target_fingerprint' => $activated,
            ]], '2026-08-10T12:10:00Z',
        );
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $repository->create($evidence);
        $gateway = new CompletionGateway($snapshot);
        $gate = new LoadedSubscriptionCompletionGate($repository, $gateway);
        $gate->assertReady($prepared, [$receipt]);
        self::assertSame(1, $gateway->snapshots);

        $gateway->snapshot['subscription']['status'] = 'paused';
        $this->expectExceptionMessage('subscription_cutover_target_drift');
        $gate->assertReady($prepared, [$receipt]);
    }

    private function prepared(): PreparedTransfer
    {
        $target = new TargetStateFingerprint(
            str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64),
            str_repeat('4', 64), str_repeat('5', 64), str_repeat('6', 64), str_repeat('7', 64),
        );
        return new PreparedTransfer(
            'run-task-22', '/srv/private/package', str_repeat('1', 64), $target,
            'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha',
        );
    }
}

final class CompletionGateway implements SubscriptionTargetGateway
{
    public int $snapshots = 0;
    public function __construct(public array $snapshot) {}
    public function create(array $row): int { throw new \LogicException(); }
    public function exists(int $subscriptionId): bool { return true; }
    public function snapshot(int $subscriptionId): array { ++$this->snapshots; return $this->snapshot; }
    public function linkTransaction(int $transactionId, int $subscriptionId, string $orderType): void { throw new \LogicException(); }
    public function writeCorrection(int $subscriptionId, string $key, int $value): void { throw new \LogicException(); }
}
