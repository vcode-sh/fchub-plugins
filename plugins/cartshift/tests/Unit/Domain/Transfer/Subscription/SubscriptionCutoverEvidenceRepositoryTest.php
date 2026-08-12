<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionCutoverEvidenceRepositoryTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-cutover-evidence-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testPreparedRetryReturnsTheOriginalEvidenceDespiteAClockChange(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $first = $repository->createPreparedIdempotently($this->evidence('2026-08-10T12:00:00Z'));
        $retry = $repository->createPreparedIdempotently($this->evidence('2026-08-10T12:05:00Z'));

        self::assertSame('2026-08-10T12:00:00Z', $retry->updatedAtUtc);
        self::assertSame($first->fingerprint(), $retry->fingerprint());
    }

    public function testPreparedRetryRefusesAChangedTargetReceipt(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $repository->createPreparedIdempotently($this->evidence('2026-08-10T12:00:00Z'));
        $changed = $this->evidence('2026-08-10T12:05:00Z', 9032);

        $this->expectExceptionMessage('subscription_cutover_evidence_conflict');
        $repository->createPreparedIdempotently($changed);
    }

    public function testReplaceUsesCompareAndSwapRatherThanAcceptingAStaleSnapshot(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $before = $this->evidence('2026-08-10T12:00:00Z');
        $repository->create($before);
        $first = $this->evidence('2026-08-10T12:01:00Z');
        $repository->replace($before, $first);

        $this->expectExceptionMessage('subscription_cutover_evidence_concurrent_change');
        $repository->replace($before, $this->evidence('2026-08-10T12:02:00Z'));
    }

    public function testRunIdCannotEscapeThePrivateEvidenceDirectory(): void
    {
        $repository = new SubscriptionCutoverEvidenceRepository($this->root);
        $this->expectExceptionMessage('subscription_cutover_evidence_run_invalid');
        $repository->get('../outside');
    }

    public function testEvidenceRejectsDuplicateTargetsAndUnknownEntryFields(): void
    {
        $first = $this->evidence('2026-08-10T12:00:00Z')->entries[0];
        $second = $first;
        $second['source_identity'] = 'shop-alpha:subscription:32';
        foreach ([
            [$first, $second],
            [$first + ['convenient_note' => 'trust me']],
            [$first + ['previous_requires_manual_renewal' => true]],
        ] as $entries) {
            try {
                new SubscriptionCutoverEvidence(
                    'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
                    str_repeat('d', 64), str_repeat('e', 64),
                    'rehearsal', SubscriptionCutoverEvidence::PREPARED, $entries, '2026-08-10T12:00:00Z',
                );
                self::fail('Malformed cutover evidence was accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('subscription_cutover_evidence_entry_invalid', $exception->getMessage());
            }
        }
    }

    public function testSealedSourceReleaseStateCannotContainAPendingEntry(): void
    {
        $this->expectExceptionMessage('subscription_cutover_evidence_entry_invalid');
        $prepared = $this->evidence('2026-08-10T12:00:00Z');
        new SubscriptionCutoverEvidence(
            $prepared->runId, $prepared->sourceKey, $prepared->packageHash, $prepared->decisionHash,
            $prepared->selectionHash, $prepared->sourceInstanceFingerprint, $prepared->sourceRuntimeFingerprint,
            $prepared->executionContext, SubscriptionCutoverEvidence::SOURCE_RELEASED,
            $prepared->entries, $prepared->updatedAtUtc,
        );
    }

    public function testReleasedEntryRequiresEveryMarkBeforeActFingerprint(): void
    {
        $entry = $this->evidence('2026-08-10T12:00:00Z')->entries[0];
        $entry['release_state'] = 'released';
        $this->expectExceptionMessage('subscription_cutover_evidence_entry_invalid');
        new SubscriptionCutoverEvidence(
            'run-task-22', 'shop-alpha', str_repeat('a', 64), str_repeat('b', 64), str_repeat('c', 64),
            str_repeat('d', 64), str_repeat('e', 64),
            'rehearsal', SubscriptionCutoverEvidence::SOURCE_RELEASED, [$entry], '2026-08-10T12:00:00Z',
        );
    }

    private function evidence(string $updatedAtUtc, int $targetId = 9031): SubscriptionCutoverEvidence
    {
        return new SubscriptionCutoverEvidence(
            'run-task-22',
            'shop-alpha',
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            str_repeat('d', 64),
            str_repeat('e', 64),
            'rehearsal',
            SubscriptionCutoverEvidence::PREPARED,
            [[
                'source_identity' => 'shop-alpha:subscription:31',
                'source_fingerprint' => str_repeat('1', 64),
                'target_id' => $targetId,
                'staged_target_fingerprint' => str_repeat('2', 64),
                'source_release_required' => true,
                'intended_status' => 'active',
                'release_state' => 'pending',
                'activation_state' => 'paused',
            ]],
            $updatedAtUtc,
        );
    }
}
