<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionSourceCutover;
use CartShift\Domain\Transfer\Subscription\SubscriptionSourceCutoverGateway;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionSourceCutoverTest extends PluginTestCase
{
    private string $root;
    protected function setUp(): void { parent::setUp(); $this->root = sys_get_temp_dir() . '/cartshift-source-cutover-' . bin2hex(random_bytes(8)); mkdir($this->root, 0700); }
    protected function tearDown(): void { foreach (glob($this->root . '/*') ?: [] as $file) unlink($file); rmdir($this->root); parent::tearDown(); }

    public function testMarkBeforeActCanResumeAfterReleaseAppliedButReceiptUpdateCrashed(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $gateway = new SourceCutoverGateway();
        $entry = $this->entry();
        $entry['release_state'] = 'marked';
        $entry['pre_renewal_fingerprint'] = str_repeat('3', 64);
        $entry['pre_release_comparison_fingerprint'] = str_repeat('5', 64);
        $entry['previous_requires_manual_renewal'] = false;
        $repository->create($this->evidence([$entry]));
        $gateway->manual = true;
        $gateway->sourceFingerprint = str_repeat('4', 64);

        $result = (new SubscriptionSourceCutover($repository, $gateway))->release(
            'run-task-22', true, '2026-08-10T12:10:00Z',
        );

        self::assertSame(SubscriptionCutoverEvidence::SOURCE_RELEASED, $result->state);
        self::assertSame('released', $result->entries[0]['release_state']);
        self::assertSame(0, $gateway->releaseCalls, 'A resumed release wrote the source twice.');
    }

    public function testMaintenanceAcknowledgementStopsBeforeEvidenceOrSourceRead(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $gateway = new SourceCutoverGateway();
        $this->expectExceptionMessage('source_renewal_maintenance_unconfirmed');
        (new SubscriptionSourceCutover($repository, $gateway))->release('run-task-22', false, '2026-08-10T12:10:00Z');
    }

    public function testMarkedReleaseWithSemanticSourceDriftCannotMasqueradeAsACompletedWrite(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $gateway = new SourceCutoverGateway();
        $entry = $this->entry();
        $entry['release_state'] = 'marked';
        $entry['pre_renewal_fingerprint'] = str_repeat('3', 64);
        $entry['pre_release_comparison_fingerprint'] = str_repeat('5', 64);
        $entry['previous_requires_manual_renewal'] = false;
        $repository->create($this->evidence([$entry]));
        $gateway->manual = true;
        $gateway->sourceFingerprint = str_repeat('4', 64);
        $gateway->comparisonFingerprint = str_repeat('6', 64);

        $this->expectExceptionMessage('subscription_source_release_ambiguous_after_crash:shop-alpha:subscription:31');
        try {
            (new SubscriptionSourceCutover($repository, $gateway))->release(
                'run-task-22',
                true,
                '2026-08-10T12:10:00Z',
            );
        } finally {
            self::assertSame(0, $gateway->releaseCalls);
        }
    }

    public function testRetryOfCompletedSourceReleaseRevalidatesTheLiveSourceWithoutRewritingEvidence(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $gateway = new SourceCutoverGateway();
        $entry = $this->entry();
        $entry['release_state'] = 'released';
        $entry['pre_renewal_fingerprint'] = str_repeat('3', 64);
        $entry['pre_release_comparison_fingerprint'] = str_repeat('5', 64);
        $entry['previous_requires_manual_renewal'] = false;
        $entry['post_source_fingerprint'] = str_repeat('4', 64);
        $entry['post_renewal_fingerprint'] = str_repeat('3', 64);
        $released = new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', SubscriptionCutoverEvidence::SOURCE_RELEASED, [$entry], '2026-08-10T12:00:00Z',
        );
        $repository->create($released);
        $gateway->manual = true;
        $gateway->sourceFingerprint = str_repeat('4', 64);

        $result = (new SubscriptionSourceCutover($repository, $gateway))->release(
            'run-task-22', true, '2026-08-10T12:10:00Z',
        );

        self::assertSame($released->fingerprint(), $result->fingerprint());
        self::assertSame(1, $gateway->inspectCalls);
        self::assertSame(0, $gateway->releaseCalls);
    }

    public function testRetryOfCompletedSourceReleaseStopsOnOwnershipDrift(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $gateway = new SourceCutoverGateway();
        $entry = $this->entry();
        $entry['release_state'] = 'released';
        $entry['pre_renewal_fingerprint'] = str_repeat('3', 64);
        $entry['pre_release_comparison_fingerprint'] = str_repeat('5', 64);
        $entry['previous_requires_manual_renewal'] = false;
        $entry['post_source_fingerprint'] = str_repeat('4', 64);
        $entry['post_renewal_fingerprint'] = str_repeat('3', 64);
        $repository->create(new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', SubscriptionCutoverEvidence::SOURCE_RELEASED, [$entry], '2026-08-10T12:00:00Z',
        ));
        $gateway->manual = false;
        $gateway->sourceFingerprint = str_repeat('4', 64);

        $this->expectExceptionMessage('subscription_source_drift_after_release:shop-alpha:subscription:31');
        (new SubscriptionSourceCutover($repository, $gateway))->release(
            'run-task-22', true, '2026-08-10T12:10:00Z',
        );
    }

    public function testTerminalSubscriptionIsStillRevalidatedBeforeSourceReleaseEvidenceIsSealed(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $gateway = new SourceCutoverGateway();
        $entry = $this->entry();
        $entry['source_release_required'] = false;
        $entry['release_state'] = 'not_required';
        $entry['activation_state'] = 'activated';
        $entry['intended_status'] = 'expired';
        $repository->create($this->evidence([$entry]));
        $gateway->sourceFingerprint = str_repeat('7', 64);

        $this->expectExceptionMessage('subscription_source_drift_without_release:shop-alpha:subscription:31');
        (new SubscriptionSourceCutover($repository, $gateway))->release(
            'run-task-22', true, '2026-08-10T12:10:00Z',
        );
    }

    public function testMarkedEntryProvesThatTheOneWaySourceReleaseHasStarted(): void
    {
        $entry = $this->entry();
        $entry['release_state'] = 'marked';
        $entry['pre_renewal_fingerprint'] = str_repeat('3', 64);
        $entry['pre_release_comparison_fingerprint'] = str_repeat('5', 64);
        $entry['previous_requires_manual_renewal'] = false;

        self::assertTrue($this->evidence([$entry])->releaseStarted());
    }

    public function testPendingEntryKeepsRollbackAvailableBeforeSourceReleaseStarts(): void
    {
        self::assertFalse($this->evidence([$this->entry()])->releaseStarted());
    }

    /** @param list<array<string,mixed>> $entries */
    private function evidence(array $entries): SubscriptionCutoverEvidence
    {
        return new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', SubscriptionCutoverEvidence::PREPARED, $entries, '2026-08-10T12:00:00Z',
        );
    }

    /** @return array<string,mixed> */
    private function entry(): array
    {
        return [
            'source_identity' => 'shop-alpha:subscription:31',
            'source_fingerprint' => str_repeat('1', 64),
            'target_id' => 9031,
            'staged_target_fingerprint' => str_repeat('2', 64),
            'source_release_required' => true,
            'intended_status' => 'active',
            'release_state' => 'pending',
            'activation_state' => 'paused',
        ];
    }
}

final class SourceCutoverGateway implements SubscriptionSourceCutoverGateway
{
    public bool $manual = false;
    public string $sourceFingerprint = '';
    public string $comparisonFingerprint = '';
    public int $releaseCalls = 0;
    public int $inspectCalls = 0;
    public function inspect(SourceIdentity $identity): array
    {
        ++$this->inspectCalls;
        return ['source_fingerprint' => $this->sourceFingerprint ?: str_repeat('1', 64),
            'release_comparison_fingerprint' => $this->comparisonFingerprint ?: str_repeat('5', 64),
            'renewal_fingerprint' => str_repeat('3', 64), 'requires_manual_renewal' => $this->manual];
    }
    public function release(SourceIdentity $identity): array
    {
        ++$this->releaseCalls;
        $this->manual = true;
        $this->sourceFingerprint = str_repeat('4', 64);
        return ['source_fingerprint' => $this->sourceFingerprint, 'renewal_fingerprint' => str_repeat('3', 64),
            'release_comparison_fingerprint' => str_repeat('5', 64),
            'requires_manual_renewal' => true, 'previous_requires_manual_renewal' => false];
    }
}
