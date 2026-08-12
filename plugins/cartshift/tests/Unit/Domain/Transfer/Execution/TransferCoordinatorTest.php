<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\CatalogueActivator;
use CartShift\Domain\Transfer\Execution\CatalogueStatusChange;
use CartShift\Domain\Transfer\Execution\FilesystemSagaRepository;
use CartShift\Domain\Transfer\Execution\ReceiptExporter;
use CartShift\Domain\Transfer\Execution\RollbackPlanner;
use CartShift\Domain\Transfer\Execution\RollbackPlan;
use CartShift\Domain\Transfer\Execution\RollbackTargetGateway;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TargetStateInspector;
use CartShift\Domain\Transfer\Execution\TransferCoordinator;
use CartShift\Domain\Transfer\Execution\TransferJournal;
use CartShift\Domain\Transfer\Execution\TransferPlan;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Execution\TransferRunBoundary;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\Execution\SubscriptionCompletionGate;
use CartShift\Domain\Transfer\Execution\CommittedRollbackRecovery;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordReconciler;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\ReconciliationResult;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;
use CartShift\Tests\Unit\PluginTestCase;
use CartShift\Support\DatabaseTransaction;

final class TransferCoordinatorTest extends PluginTestCase
{
    public function testCrashAfterCommitResumesFromJournalWithoutWritingTargetAgain(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '41'), [
            'dependencies' => [],
            'name' => 'Private fixture value',
        ]);
        $prepared = PreparedTransferFixture::make();
        $journal = new MemoryTransferJournal();
        $writer = new RecordingWriter();
        $reconciler = new RecordingReconciler();
        $exporter = new FailingOnceReceiptExporter();
        $coordinator = new TransferCoordinator(
            $journal,
            $exporter,
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => $writer],
            ['product' => $reconciler],
        );

        try {
            $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(), 'worker-a', 300);
            self::fail('The injected post-commit receipt export crash was ignored.');
        } catch (\RuntimeException $exception) {
            self::assertSame('receipt_export_interrupted', $exception->getMessage());
        }

        self::assertSame(1, $writer->calls);
        self::assertSame(TransferRunState::Interrupted, $journal->state($prepared->runId));
        self::assertCount(1, $journal->pendingReceipts($prepared->runId));
        self::assertSame(0, $exporter->successfulExports);

        $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(), 'worker-a', 300);

        self::assertSame(1, $writer->calls, 'A committed target graph was written twice.');
        self::assertSame(1, $reconciler->calls, 'Retry did not independently verify the committed target graph.');
        self::assertSame(1, $exporter->successfulExports);
        self::assertSame([], $journal->pendingReceipts($prepared->runId));
        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
        self::assertSame(2, $journal->attempt($prepared->runId));
    }

    public function testFingerprintDriftBlocksBeforeWriterAndLeavesPreparedDescriptorUnchanged(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'customer', '9'), [
            'dependencies' => [],
        ]);
        $prepared = PreparedTransferFixture::make();
        $journal = new MemoryTransferJournal();
        $writer = new RecordingWriter();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState->withTargetHash(str_repeat('f', 64))),
            ['customer' => $writer],
            ['customer' => new RecordingReconciler()],
        );

        try {
            $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(), 'worker-a', 300);
            self::fail('Changed target state was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertSame('prepared_transfer_fingerprint_changed:target', $exception->getMessage());
        }

        self::assertSame(0, $writer->calls);
        self::assertSame(TransferRunState::Prepared, $journal->state($prepared->runId));

        $this->expectExceptionMessage('prepared_transfer_fingerprint_changed:target');
        $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(), 'worker-a', 300);
    }

    public function testEverySealedFingerprintFieldIsComparedBeforeTheFirstWrite(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'customer', '19'), ['dependencies' => []]);
        $prepared = PreparedTransferFixture::make();

        foreach (array_keys($prepared->targetState->toArray()) as $field) {
            $current = $prepared->targetState->toArray();
            $current[$field] = str_repeat('f', 64);
            $journal = new MemoryTransferJournal();
            $writer = new RecordingWriter();
            $coordinator = new TransferCoordinator(
                $journal,
                new FailingOnceReceiptExporter(false),
                new OpenRunBoundary(),
                new FixedTargetStateInspector(TargetStateFingerprint::fromArray($current)),
                ['customer' => $writer],
                ['customer' => new RecordingReconciler()],
            );

            try {
                $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(), 'worker-a', 300);
                self::fail('Changed ' . $field . ' fingerprint was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('prepared_transfer_fingerprint_changed:' . $field, $exception->getMessage());
            }

            self::assertSame(0, $writer->calls, $field . ' drift reached a writer.');
            self::assertSame(TransferRunState::Prepared, $journal->state($prepared->runId));
        }
    }

    public function testPromotionDoesNotPublishAndCompletionRequiresExplicitCatalogueDecision(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '71'), [
            'dependencies' => [],
        ]);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $record->privateContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$record], $decisions);
        $journal = new MemoryTransferJournal();
        $activator = new RecordingCatalogueActivator();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter()],
            ['product' => new RecordingReconciler()],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);

        self::assertSame([], $activator->activated, 'Promotion published the catalogue as a side effect.');
        try {
            $coordinator->completeWithoutActivation($prepared, 'worker-a', 300, new AllowCompletionGate());
            self::fail('Completion silently left an unapproved draft catalogue.');
        } catch (\RuntimeException $exception) {
            self::assertSame('catalogue_activation_or_leave_draft_acceptance_required', $exception->getMessage());
        }

        $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);
        try {
            $coordinator->completeAfterActivation($prepared, 'worker-a', 300, $activator, new RejectCompletionGate());
            self::fail('Completion ignored unreconciled subscription ownership evidence.');
        } catch (\RuntimeException $exception) {
            self::assertSame('subscription_cutover_evidence_not_reconciled', $exception->getMessage());
        }
        self::assertSame(TransferRunState::CatalogueActivating, $journal->state($prepared->runId));
        $coordinator->completeAfterActivation($prepared, 'worker-a', 300, $activator, new AllowCompletionGate());

        self::assertSame([$record->identity->canonical()], $activator->activated);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));
        self::assertCount(2, $journal->receipts($prepared->runId));
    }

    public function testCatalogueApprovalRemainsBoundToSourceWhenPackagingRewritesPrivatePayload(): void
    {
        $source = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '72'), [
            'dependencies' => [],
            'asset_locator' => 'https://source.example/product.jpg',
        ]);
        $record = RecordEnvelope::forPackagedPayload($source, [
            'dependencies' => [],
            'asset_locator' => 'package://assets/product.jpg',
        ]);
        self::assertNotSame($record->sourceContentDigest, $record->privateContentDigest);

        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $record->sourceContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$record], $decisions);
        $journal = new MemoryTransferJournal();
        $activator = new RecordingCatalogueActivator();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter()],
            ['product' => new RecordingReconciler()],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);

        self::assertSame([$record->identity->canonical()], $activator->activated);
    }

    public function testCatalogueActivationPublishesOnlyProductsExplicitlyApprovedForPublication(): void
    {
        $published = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '73'), [
            'dependencies' => [],
        ]);
        $draft = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '74'), [
            'dependencies' => [],
        ]);
        $decisions = TransferDecisionSet::fromArray([
            [
                'identity' => $published->identity->canonical(),
                'action' => 'activate_catalogue',
                'source_fingerprint' => $published->sourceContentDigest,
                'target_status' => 'publish',
                'operator' => 'owner',
                'reason' => 'Approved for storefront publication.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
            [
                'identity' => $draft->identity->canonical(),
                'action' => 'leave_catalogue_draft',
                'source_fingerprint' => $draft->sourceContentDigest,
                'target_status' => 'draft',
                'operator' => 'owner',
                'reason' => 'Keep the source draft away from the storefront.',
                'decided_at' => '2026-08-10T12:00:00Z',
            ],
        ]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$published, $draft], $decisions);
        $journal = new MemoryTransferJournal();
        $activator = new RecordingCatalogueActivator();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter()],
            ['product' => new RecordingReconciler()],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);

        self::assertSame([$published->identity->canonical()], $activator->activated);
    }

    public function testCatalogueStatusReceiptRetainsTheProductGraphNeededForStorefrontReconciliation(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '75'), [
            'dependencies' => [],
        ]);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $record->sourceContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$record], $decisions);
        $journal = new MemoryTransferJournal();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new MappedRecordingWriter()],
            ['product' => new RecordingReconciler()],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $productReceipt = $journal->receipts($prepared->runId)[0];
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->activateCatalogue($plan, 'worker-a', 300, new RecordingCatalogueActivator());
        $statusReceipts = array_values(array_filter(
            $journal->receipts($prepared->runId),
            static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'catalogue_status',
        ));

        self::assertCount(1, $statusReceipts);
        self::assertSame($productReceipt->targetIds, $statusReceipts[0]->targetIds);
    }

    public function testEveryPostStagePhaseCanRecoverAfterALongRunningCutoverExpiresTheLease(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'customer', '73'), [
            'dependencies' => [],
        ]);
        $base = PreparedTransferFixture::make();
        $prepared = new PreparedTransfer(
            $base->runId,
            $base->packagePath,
            $base->packageHash,
            $base->targetState,
            $base->executionContext,
            $base->blockingFindings,
            true,
            $base->createdAtUtc,
            $base->sourceKey,
            $base->generation,
        );
        $plan = $this->plan($prepared, [$record]);
        $journal = new MemoryTransferJournal();
        $boundary = new RecordingRunBoundary();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            $boundary,
            new FixedTargetStateInspector($prepared->targetState),
            ['customer' => new RecordingWriter()],
            ['customer' => new RecordingReconciler()],
        );
        $recovery = str_repeat('7', 64);

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $boundary->failRenewal = true;
        $coordinator->reconcile($plan, 'worker-a', 300, $recovery);
        $coordinator->promote($prepared, 'worker-a', 300, $recovery);
        $coordinator->prepareSubscriptionCutover($prepared, 'worker-a', 300, static fn (): string => 'prepared', $recovery);
        $coordinator->activateSubscriptions($prepared, 'worker-a', 300, static fn (): string => 'activated', $recovery);
        $coordinator->completeWithoutActivation($prepared, 'worker-a', 300, new AllowCompletionGate(), $recovery);

        self::assertSame(array_fill(0, 5, $recovery), $boundary->recoveries);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));
    }

    public function testLeaseRecoveryNeverMasksADatabaseFailureAsAnExpiredLease(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'customer', '74'), [
            'dependencies' => [],
        ]);
        $prepared = PreparedTransferFixture::make();
        $plan = $this->plan($prepared, [$record]);
        $journal = new MemoryTransferJournal();
        $boundary = new RecordingRunBoundary();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            $boundary,
            new FixedTargetStateInspector($prepared->targetState),
            ['customer' => new RecordingWriter()],
            ['customer' => new RecordingReconciler()],
        );
        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $boundary->failRenewal = true;
        $boundary->renewalFailure = 'transfer_lease_database_failure';

        try {
            $coordinator->reconcile($plan, 'worker-a', 300, str_repeat('6', 64));
            self::fail('A database failure was treated as recoverable lease expiry.');
        } catch (\RuntimeException $exception) {
            self::assertSame('transfer_lease_database_failure', $exception->getMessage());
        }

        self::assertSame([], $boundary->recoveries);
        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
    }

    public function testCompletedDescriptorCanBeReconciledAgainWithoutWritingOrChangingState(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '72'), [
            'dependencies' => [],
        ]);
        $base = PreparedTransferFixture::make();
        $prepared = new PreparedTransfer(
            $base->runId,
            $base->packagePath,
            $base->packageHash,
            $base->targetState,
            $base->executionContext,
            $base->blockingFindings,
            true,
            $base->createdAtUtc,
            $base->sourceKey,
            $base->generation,
        );
        $plan = $this->plan($prepared, [$record]);
        $journal = new MemoryTransferJournal();
        $writer = new RecordingWriter();
        $reconciler = new RecordingReconciler();
        $boundary = new RepeatVerificationBoundary();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            $boundary,
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => $writer],
            ['product' => $reconciler],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->completeWithoutActivation($prepared, 'worker-a', 300, new AllowCompletionGate());

        self::assertSame(1, $writer->calls);
        self::assertSame(1, $reconciler->calls);
        $coordinator->reconcile($plan, 'repeat-verifier', 300);

        self::assertSame(1, $writer->calls, 'Repeat verification wrote the completed target again.');
        self::assertSame(2, $reconciler->calls, 'Repeat verification trusted receipts without reading the target.');
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));
        self::assertCount(1, $journal->receipts($prepared->runId));
        self::assertSame(2, $boundary->acquires);
        self::assertSame(2, $boundary->releases);

        $reconciler->matches = false;
        try {
            $coordinator->reconcile($plan, 'repeat-verifier', 300);
            self::fail('Repeat verification accepted target drift against its immutable receipt.');
        } catch (\RuntimeException $exception) {
            self::assertSame('target_reconciliation_failed:repeat_mismatch', $exception->getMessage());
        }
        self::assertSame(1, $writer->calls);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));
        self::assertSame(3, $boundary->acquires);
        self::assertSame(3, $boundary->releases, 'A failed repeat verification leaked the target lease.');
    }

    public function testCompletedActivatedCatalogueReconciliationRequiresTheMatchingStatusReceipt(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '73'), [
            'dependencies' => [],
        ]);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $record->sourceContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$record], $decisions);
        $journal = new MemoryTransferJournal();
        $reconciler = new ActivatedRepeatReconciler();
        $activator = new RecordingCatalogueActivator();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new RepeatVerificationBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter()],
            ['product' => $reconciler],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);
        $coordinator->completeAfterActivation($prepared, 'worker-a', 300, $activator, new AllowCompletionGate());

        $coordinator->reconcile($plan, 'repeat-verifier', 300, null, $activator);

        self::assertSame([null, 'publish'], $reconciler->approvedProductStatuses);
        self::assertSame(1, $activator->receiptFingerprintCalls);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));

        try {
            $coordinator->reconcile($plan, 'repeat-verifier', 300, null, new RecordingCatalogueActivator(null, true));
            self::fail('Repeat verification ignored catalogue status drift.');
        } catch (\RuntimeException $exception) {
            self::assertSame('catalogue_activation_target_drift:' . $record->identity->canonical(), $exception->getMessage());
        }
        self::assertSame([null, 'publish'], $reconciler->approvedProductStatuses);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));
    }

    public function testCompletedPublishedProductCannotFallBackToItsDraftReceiptWhenFinalisationEvidenceIsMissing(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '731'), [
            'dependencies' => [],
        ]);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $record->sourceContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$record], $decisions);
        $journal = new MemoryTransferJournal();
        $activator = new RecordingCatalogueActivator();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new RepeatVerificationBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter()],
            ['product' => new RecordingReconciler()],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);
        $coordinator->completeAfterActivation($prepared, 'worker-a', 300, $activator, new AllowCompletionGate());
        $journal->removeReceipt('catalogue_status', $record->identity->canonical());

        $this->expectExceptionMessage('catalogue_activation_receipt_missing:' . $record->identity->canonical());
        $coordinator->reconcile($plan, 'repeat-verifier', 300, null, $activator);
    }

    public function testCompletedSubscriptionReconciliationUsesFinalCutoverEvidenceInsteadOfThePausedStageReceipt(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'subscription', '74'), [
            'dependencies' => [],
        ]);
        $base = PreparedTransferFixture::make();
        $prepared = new PreparedTransfer(
            $base->runId,
            $base->packagePath,
            $base->packageHash,
            $base->targetState,
            $base->executionContext,
            $base->blockingFindings,
            true,
            $base->createdAtUtc,
            $base->sourceKey,
            $base->generation,
        );
        $plan = $this->plan($prepared, [$record]);
        $journal = new MemoryTransferJournal();
        $writer = new RecordingWriter();
        $reconciler = new RecordingReconciler();
        $gate = new RecordingFinalSubscriptionGate();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new RepeatVerificationBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['subscription' => $writer],
            ['subscription' => $reconciler],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->completeWithoutActivation($prepared, 'worker-a', 300, $gate);

        $reconciler->matches = false;
        $coordinator->reconcile($plan, 'repeat-verifier', 300, null, null, $gate);

        self::assertSame(1, $writer->calls);
        self::assertSame(1, $reconciler->calls, 'Repeat verification compared final active state with the stale paused-stage fingerprint.');
        self::assertSame(2, $gate->calls);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));

        $gate->reject = true;
        try {
            $coordinator->reconcile($plan, 'repeat-verifier', 300, null, null, $gate);
            self::fail('Repeat verification ignored subscription cutover drift.');
        } catch (\RuntimeException $exception) {
            self::assertSame('subscription_cutover_target_drift:' . $record->identity->canonical(), $exception->getMessage());
        }
        self::assertSame(1, $reconciler->calls);
        self::assertSame(3, $gate->calls);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));
    }

    public function testCompletedRepeatComposesCatalogueAndSubscriptionFinalisationEvidence(): void
    {
        $product = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '75'), [
            'dependencies' => [],
        ]);
        $subscription = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'subscription', '76'), [
            'dependencies' => [],
        ]);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $product->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $product->sourceContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$subscription, $product], $decisions);
        $journal = new MemoryTransferJournal();
        $productReconciler = new ActivatedRepeatReconciler();
        $subscriptionReconciler = new RecordingReconciler();
        $activator = new RecordingCatalogueActivator();
        $subscriptions = new RecordingFinalSubscriptionGate();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new RepeatVerificationBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter(), 'subscription' => new RecordingWriter()],
            ['product' => $productReconciler, 'subscription' => $subscriptionReconciler],
        );

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);
        $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);
        $coordinator->completeAfterActivation($prepared, 'worker-a', 300, $activator, $subscriptions);

        $coordinator->reconcile($plan, 'repeat-verifier', 300, null, $activator, $subscriptions);

        self::assertSame([null, 'publish'], $productReconciler->approvedProductStatuses);
        self::assertSame(1, $subscriptionReconciler->calls, 'Repeat verification compared final subscription state with its paused-stage receipt.');
        self::assertSame(2, $subscriptions->calls);
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));

        try {
            $coordinator->reconcile(
                $plan,
                'repeat-verifier',
                300,
                null,
                new RecordingCatalogueActivator(null, true),
                $subscriptions,
            );
            self::fail('Completed repeat verification ignored catalogue finalisation drift.');
        } catch (\RuntimeException $exception) {
            self::assertSame('catalogue_activation_target_drift:' . $product->identity->canonical(), $exception->getMessage());
        }

        $subscriptions->reject = true;
        try {
            $coordinator->reconcile($plan, 'repeat-verifier', 300, null, $activator, $subscriptions);
            self::fail('Completed repeat verification ignored subscription finalisation drift.');
        } catch (\RuntimeException $exception) {
            self::assertSame('subscription_cutover_target_drift:' . $subscription->identity->canonical(), $exception->getMessage());
        }
        self::assertSame(TransferRunState::Completed, $journal->state($prepared->runId));
    }

    public function testFailedPartialStageRollsBackOnlyTheJournaledCreatedGraph(): void
    {
        $product = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '81'), ['dependencies' => []]);
        $order = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'order', '82'), [
            'dependencies' => [$product->identity->canonical()],
        ]);
        $prepared = PreparedTransferFixture::make();
        $plan = $this->plan($prepared, [$order, $product]);
        $journal = new MemoryTransferJournal();
        $boundary = new RecordingRunBoundary(failRenewal: true);
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            $boundary,
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter(), 'order' => new ThrowingWriter()],
            ['product' => new RecordingReconciler(), 'order' => new RecordingReconciler()],
        );

        try {
            $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
            self::fail('Injected order failure did not fail the run.');
        } catch (\RuntimeException $exception) {
            self::assertSame('injected_target_write_failure', $exception->getMessage());
        }
        self::assertSame(TransferRunState::Failed, $journal->state($prepared->runId));
        self::assertCount(1, $journal->receipts($prepared->runId));

        $gateway = new CoordinatorRollbackGateway(['shop-alpha:product:81' => str_repeat('a', 64)]);
        $planner = new RollbackPlanner();
        $rollback = $planner->plan($prepared->runId, 1, $journal->receipts($prepared->runId), $gateway);
        $recovery = str_repeat('8', 64);
        $coordinator->rollback($prepared, 'worker-a', 300, $rollback, $planner, $gateway, $recovery);

        self::assertSame(['shop-alpha:product:81'], $gateway->deleted);
        self::assertSame([$recovery], $boundary->recoveries);
        self::assertSame(TransferRunState::RolledBack, $journal->state($prepared->runId));
    }

    public function testCommittedRollbackWithFailedFilesystemCleanupResumesWithoutExpectingDeletedTargets(): void
    {
        $prepared = PreparedTransferFixture::make();
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '991'), ['dependencies' => []]);
        $receipt = new TransferReceipt(
            $prepared->runId, 'product', $record->identity->canonical(), 1, $record->privateContentDigest,
            'created', ['primary' => 1991], null, str_repeat('a', 64), 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
        $journal = new MemoryTransferJournal();
        $journal->start($prepared);
        $journal->transition($prepared->runId, TransferRunState::Prepared, TransferRunState::Staging);
        $journal->commitReceipt($receipt);
        $journal->transition($prepared->runId, TransferRunState::Staging, TransferRunState::Failed);
        $gateway = new PostCommitRollbackGateway([$receipt->sourceIdentity => $receipt->afterFingerprint]);
        $plan = (new RollbackPlanner())->plan($prepared->runId, 1, [$receipt], $gateway);
        $coordinator = new TransferCoordinator(
            $journal, new FailingOnceReceiptExporter(false), new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState), [], [],
        );

        try {
            $coordinator->rollback($prepared, 'worker-a', 300, $plan, new RollbackPlanner(), $gateway);
            self::fail('Injected post-commit cleanup failure was hidden.');
        } catch (\RuntimeException $exception) {
            self::assertSame('rollback_filesystem_finalisation_interrupted', $exception->getMessage());
        }
        self::assertSame(TransferRunState::RollingBack, $journal->state($prepared->runId));
        self::assertSame(1, $gateway->deletes);

        $coordinator->rollback($prepared, 'worker-a', 300, $plan, new RollbackPlanner(), $gateway);
        self::assertSame(TransferRunState::RolledBack, $journal->state($prepared->runId));
        self::assertSame(1, $gateway->deletes, 'Retry attempted to delete an already committed target again.');
        self::assertSame(2, $gateway->recoveryCalls);
    }

    public function testCommittedRollbackAlwaysRunsFinalFilesystemCleanupBeforeRolledBackState(): void
    {
        $prepared = PreparedTransferFixture::make();
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '992'), ['dependencies' => []]);
        $receipt = new TransferReceipt(
            $prepared->runId, 'product', $record->identity->canonical(), 1, $record->privateContentDigest,
            'created', ['primary' => 1992], null, str_repeat('a', 64), 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
        $journal = new MemoryTransferJournal();
        $journal->start($prepared);
        $journal->transition($prepared->runId, TransferRunState::Prepared, TransferRunState::Staging);
        $journal->commitReceipt($receipt);
        $journal->transition($prepared->runId, TransferRunState::Staging, TransferRunState::Failed);
        $gateway = new RecordingCommittedRollbackGateway([$receipt->sourceIdentity => $receipt->afterFingerprint]);
        $plan = (new RollbackPlanner())->plan($prepared->runId, 1, [$receipt], $gateway);
        $coordinator = new TransferCoordinator(
            $journal, new FailingOnceReceiptExporter(false), new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState), [], [],
        );

        $coordinator->rollback($prepared, 'worker-a', 300, $plan, new RollbackPlanner(), $gateway);

        self::assertSame(1, $gateway->recoveryCalls);
        self::assertSame(TransferRunState::RolledBack, $journal->state($prepared->runId));
    }

    public function testBatchSizeStopsSchedulingWithoutWeakeningRecordAtomicity(): void
    {
        $first = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '91'), ['dependencies' => []]);
        $second = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '92'), ['dependencies' => []]);
        $prepared = PreparedTransferFixture::make();
        $journal = new MemoryTransferJournal();
        $writer = new RecordingWriter();
        $reconciler = new RecordingReconciler();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => $writer],
            ['product' => $reconciler],
        );
        $plan = $this->plan($prepared, [$second, $first]);

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300, batchSize: 1);

        self::assertSame(1, $writer->calls);
        self::assertSame(TransferRunState::Staging, $journal->state($prepared->runId));

        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300, batchSize: 1);

        self::assertSame(2, $writer->calls);
        self::assertSame(1, $reconciler->calls, 'The earlier committed batch was trusted without an independent read.');
        self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
        self::assertCount(2, $journal->receipts($prepared->runId));
    }

    public function testCatalogueReceiptExportCrashResumesWithoutPublishingTwice(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '93'), ['dependencies' => []]);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $record->privateContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
        $prepared = PreparedTransferFixture::make($decisions->fingerprint());
        $plan = TransferPlan::build($prepared, [$record], $decisions);
        $journal = new MemoryTransferJournal();
        $exporter = new FailingNthReceiptExporter(2);
        $boundary = new RecordingRunBoundary();
        $activator = new RecordingCatalogueActivator();
        $coordinator = new TransferCoordinator(
            $journal,
            $exporter,
            $boundary,
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter()],
            ['product' => new RecordingReconciler()],
        );
        $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
        $coordinator->reconcile($plan, 'worker-a', 300);
        $coordinator->promote($prepared, 'worker-a', 300);

        try {
            $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);
            self::fail('The injected catalogue receipt export crash was ignored.');
        } catch (\RuntimeException $exception) {
            self::assertSame('receipt_export_interrupted', $exception->getMessage());
        }

        self::assertSame(TransferRunState::Interrupted, $journal->state($prepared->runId));
        self::assertSame(TransferRunState::CatalogueActivating, $journal->interruptedFrom($prepared->runId));
        self::assertSame([$record->identity->canonical()], $activator->activated);

        $recoveryHash = str_repeat('9', 64);
        $coordinator->activateCatalogue($plan, 'worker-b', 300, $activator, $recoveryHash);

        self::assertSame([$record->identity->canonical()], $activator->activated, 'A committed catalogue status was applied twice.');
        self::assertSame(1, $activator->receiptFingerprintCalls);
        self::assertSame([], $boundary->recoveries, 'A still-owned lease was forcibly recovered instead of renewed.');
        self::assertSame([], $journal->pendingReceipts($prepared->runId));
        self::assertSame(TransferRunState::CatalogueActivating, $journal->state($prepared->runId));
    }

    public function testCatalogueFailureRestoresEarlierStatusesOnlyAfterACompleteDriftPreflight(): void
    {
        $records = [
            RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '94'), ['dependencies' => []]),
            RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '95'), ['dependencies' => []]),
        ];
        $decisionRows = array_map(static fn (RecordEnvelope $record): array => [
            'identity' => $record->identity->canonical(),
            'action' => 'activate_catalogue',
            'source_fingerprint' => $record->privateContentDigest,
            'target_status' => 'publish',
            'operator' => 'owner',
            'reason' => 'Approved for storefront publication.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ], $records);
        $decisions = TransferDecisionSet::fromArray($decisionRows);

        foreach ([false, true] as $drift) {
            $prepared = PreparedTransferFixture::make($decisions->fingerprint());
            $plan = TransferPlan::build($prepared, $records, $decisions);
            $journal = new MemoryTransferJournal();
            $activator = new RecordingCatalogueActivator(2, $drift);
            $coordinator = new TransferCoordinator(
                $journal,
                new FailingOnceReceiptExporter(false),
                new OpenRunBoundary(),
                new FixedTargetStateInspector($prepared->targetState),
                ['product' => new RecordingWriter()],
                ['product' => new RecordingReconciler()],
            );
            $coordinator->stage($plan, $this->stageContext(), 'worker-a', 300);
            $coordinator->reconcile($plan, 'worker-a', 300);
            $coordinator->promote($prepared, 'worker-a', 300);

            try {
                $coordinator->activateCatalogue($plan, 'worker-a', 300, $activator);
                self::fail('The injected second-product activation failure was ignored.');
            } catch (\RuntimeException $exception) {
                self::assertSame(
                    $drift ? 'catalogue_activation_restore_conflict:' . $records[0]->identity->canonical() : 'injected_catalogue_activation_failure',
                    $exception->getMessage(),
                );
            }

            self::assertSame(
                $drift ? [] : [$records[0]->identity->canonical()],
                $activator->restored,
                'A drifted activation was partially restored or a clean activation was left public.',
            );
            self::assertSame(TransferRunState::Failed, $journal->state($prepared->runId));
        }
    }

    public function testPreparedGenerationMustMatchStageContextAndEveryReceipt(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '96'), ['dependencies' => []]);
        $prepared = PreparedTransferFixture::make(generation: 2);
        $journal = new MemoryTransferJournal();
        $writer = new RecordingWriter();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => $writer],
            ['product' => new RecordingReconciler()],
        );

        try {
            $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(), 'worker-a', 300);
            self::fail('A generation-one writer context entered a generation-two run.');
        } catch (\RuntimeException $exception) {
            self::assertSame('stage_context_generation_changed', $exception->getMessage());
        }
        self::assertSame(0, $writer->calls);

        $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(2), 'worker-a', 300);

        self::assertSame(2, $journal->receipts($prepared->runId)[0]->generation);
    }

    public function testCommittedReceiptReferencesEveryDurableFilesystemSagaOperation(): void
    {
        $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '97'), ['dependencies' => []]);
        $prepared = PreparedTransferFixture::make();
        $operations = [str_repeat('1', 64), str_repeat('2', 64)];
        $journal = new MemoryTransferJournal();
        $coordinator = new TransferCoordinator(
            $journal,
            new FailingOnceReceiptExporter(false),
            new OpenRunBoundary(),
            new FixedTargetStateInspector($prepared->targetState),
            ['product' => new RecordingWriter($operations)],
            ['product' => new RecordingReconciler()],
        );

        $coordinator->stage($this->plan($prepared, [$record]), $this->stageContext(), 'worker-a', 300);

        self::assertSame($operations, $journal->receipts($prepared->runId)[0]->filesystemOperationIds);
    }

    public function testCrashDuringPostCommitFilesystemFinalisationResumesWithoutWritingTargetAgain(): void
    {
        $root = sys_get_temp_dir() . '/cartshift-coordinator-saga-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        try {
            $record = RecordEnvelope::forPayload(1, new SourceIdentity('shop-alpha', 'product', '98'), ['dependencies' => []]);
            $prepared = PreparedTransferFixture::make();
            $journal = new MemoryTransferJournal();
            $saga = new FilesystemSagaRepository($root);
            $target = $root . '/committed-asset.bin';
            $writer = new PostCommitCrashWriter($saga, $target);
            $exporter = new FailingOnceReceiptExporter(false);
            $reconciler = new RecordingReconciler();
            $coordinator = new TransferCoordinator(
                $journal,
                $exporter,
                new OpenRunBoundary(),
                new FixedTargetStateInspector($prepared->targetState),
                ['product' => $writer],
                ['product' => $reconciler],
            );
            $context = new StageContext($root, $prepared->runId, str_repeat('2', 64), filesystemSaga: $saga);

            try {
                $coordinator->stage($this->plan($prepared, [$record]), $context, 'worker-a', 300);
                self::fail('The injected process loss after SQL commit was ignored.');
            } catch (\RuntimeException $exception) {
                self::assertSame('filesystem_finalisation_interrupted', $exception->getMessage());
            }

            $receipt = $journal->receipts($prepared->runId)[0];
            self::assertSame(1, $writer->calls);
            self::assertSame(TransferRunState::Interrupted, $journal->state($prepared->runId));
            self::assertSame('pending', $saga->state($prepared->runId, $receipt->filesystemOperationIds[0]));
            self::assertSame(0, $exporter->successfulExports, 'A receipt was exported before its filesystem operations became final.');

            $coordinator->stage($this->plan($prepared, [$record]), $context, 'worker-a', 300);

            self::assertSame(1, $writer->calls, 'Retry duplicated a target graph already committed with its journal receipt.');
            self::assertSame(1, $reconciler->calls, 'Retry trusted the committed graph without an independent target read.');
            self::assertSame('final', $saga->state($prepared->runId, $receipt->filesystemOperationIds[0]));
            self::assertSame(1, $exporter->successfulExports);
            self::assertSame(TransferRunState::Staged, $journal->state($prepared->runId));
        } finally {
            $this->removeTree($root);
        }
    }

    private function stageContext(int $generation = 1): StageContext
    {
        return new StageContext(sys_get_temp_dir(), 'run-task-22', str_repeat('2', 64), generation: $generation);
    }

    /** @param list<RecordEnvelope> $records */
    private function plan(PreparedTransfer $prepared, array $records): TransferPlan
    {
        return TransferPlan::build($prepared, $records, TransferDecisionSet::empty());
    }

    private function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}

final class PreparedTransferFixture
{
    public static function make(?string $decisionHash = null, int $generation = 1): PreparedTransfer
    {
        return new PreparedTransfer(
            'run-task-22',
            '/srv/private/cartshift-transfer-v2-shop-alpha-package',
            str_repeat('1', 64),
            new TargetStateFingerprint(
                str_repeat('1', 64),
                $decisionHash ?? TransferDecisionSet::empty()->fingerprint(),
                str_repeat('3', 64),
                str_repeat('4', 64),
                str_repeat('5', 64),
                str_repeat('6', 64),
                str_repeat('7', 64),
            ),
            'rehearsal',
            [],
            false,
            '2026-08-10T12:00:00Z',
            'shop-alpha',
            $generation,
        );
    }
}

final class RecordingWriter implements RecordWriter
{
    public int $calls = 0;

    /** @param list<string> $filesystemOperations */
    public function __construct(private readonly array $filesystemOperations = []) {}

    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        ++$this->calls;
        return new StageResult(900 + $this->calls, [], [], [], str_repeat('a', 64), false, $this->filesystemOperations);
    }
}

final class MappedRecordingWriter implements RecordWriter
{
    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        return new StageResult(901, [91], [701], [], str_repeat('a', 64), false, [], [
            'shop-alpha:media_asset:701' => 701,
            'shop-alpha:product:75' => 901,
            'shop-alpha:product:75:variation:751' => 91,
        ]);
    }
}

final class ThrowingWriter implements RecordWriter
{
    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        throw new \RuntimeException('injected_target_write_failure');
    }
}

final class PostCommitCrashWriter implements RecordWriter
{
    public int $calls = 0;

    public function __construct(
        private readonly FilesystemSagaRepository $saga,
        private readonly string $target,
    ) {}

    public function stage(RecordEnvelope $record, StageContext $context): StageResult
    {
        ++$this->calls;
        $contents = "target bytes committed with the database\n";
        $operation = $this->saga->begin(
            $context->migrationId,
            $context->generation,
            'download',
            hash('sha256', $contents),
            strlen($contents),
            basename($this->target),
            $this->target,
        );
        file_put_contents($this->target, $contents);
        DatabaseTransaction::afterCommit(static function (): void {
            throw new \RuntimeException('injected finalisation process loss');
        });
        return new StageResult(998, [], [], [], str_repeat('a', 64), false, [$operation]);
    }
}

final class RecordingReconciler implements RecordReconciler
{
    public int $calls = 0;
    public bool $matches = true;

    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult
    {
        ++$this->calls;
        return new ReconciliationResult(
            $this->matches,
            $this->matches ? $context->expectedAfterFingerprint : str_repeat('f', 64),
            $this->matches ? [] : ['repeat_mismatch'],
        );
    }
}

final class ActivatedRepeatReconciler implements RecordReconciler
{
    /** @var list<?string> */
    public array $approvedProductStatuses = [];

    public function reconcile(RecordEnvelope $record, ReconcileContext $context): ReconciliationResult
    {
        $approvedStatus = $context->approvedProductStatus;
        $this->approvedProductStatuses[] = $approvedStatus;
        $matches = count($this->approvedProductStatuses) === 1 || $approvedStatus === 'publish';

        return new ReconciliationResult(
            $matches,
            $matches ? $context->expectedAfterFingerprint : str_repeat('f', 64),
            $matches ? [] : ['approved_catalogue_status_missing'],
        );
    }
}

class OpenRunBoundary implements TransferRunBoundary
{
    public function acquire(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void {}
    public function renew(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void {}
    public function recover(string $targetFingerprint, string $holderId, string $descriptorHash, string $recoveryEvidenceHash, int $ttl): void {}
    public function release(string $targetFingerprint, string $holderId, string $descriptorHash): void {}
    public function criticalSection(string $targetFingerprint, string $holderId, string $descriptorHash, callable $mutation): mixed { return $mutation(); }
}

final class RepeatVerificationBoundary extends OpenRunBoundary
{
    public int $acquires = 0;
    public int $releases = 0;

    public function acquire(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void
    {
        ++$this->acquires;
    }

    public function release(string $targetFingerprint, string $holderId, string $descriptorHash): void
    {
        ++$this->releases;
    }
}

final class RecordingRunBoundary extends OpenRunBoundary
{
    /** @var list<string> */
    public array $recoveries = [];
    public string $renewalFailure = 'transfer_lease_renewal_conflict';

    public function __construct(public bool $failRenewal = false) {}

    public function renew(string $targetFingerprint, string $holderId, string $descriptorHash, int $ttl): void
    {
        if ($this->failRenewal) throw new \RuntimeException($this->renewalFailure);
    }

    public function recover(string $targetFingerprint, string $holderId, string $descriptorHash, string $recoveryEvidenceHash, int $ttl): void
    {
        $this->recoveries[] = $recoveryEvidenceHash;
    }
}

final readonly class FixedTargetStateInspector implements TargetStateInspector
{
    public function __construct(private TargetStateFingerprint $state) {}
    public function inspect(): TargetStateFingerprint { return $this->state; }
}

final class FailingOnceReceiptExporter implements ReceiptExporter
{
    public int $successfulExports = 0;

    public function __construct(private bool $failFirst = true) {}

    public function export(TransferReceipt $receipt): void
    {
        if ($this->failFirst) {
            $this->failFirst = false;
            throw new \RuntimeException('filesystem unavailable');
        }
        ++$this->successfulExports;
    }
}

final class FailingNthReceiptExporter implements ReceiptExporter
{
    private int $calls = 0;

    public function __construct(private readonly int $failureCall) {}

    public function export(TransferReceipt $receipt): void
    {
        if (++$this->calls === $this->failureCall) {
            throw new \RuntimeException('filesystem unavailable');
        }
    }
}

final class MemoryTransferJournal implements TransferJournal
{
    /** @var array<string, array{prepared: PreparedTransfer, state: TransferRunState, resume: ?TransferRunState, attempt: int}> */
    private array $runs = [];
    /** @var array<string, TransferReceipt> */
    private array $receipts = [];
    /** @var array<string, bool> */
    private array $exported = [];

    public function start(PreparedTransfer $prepared): void
    {
        $this->runs[$prepared->runId] ??= ['prepared' => $prepared, 'state' => TransferRunState::Prepared, 'resume' => null, 'attempt' => 0];
    }

    public function prepared(string $runId): PreparedTransfer { return $this->runs[$runId]['prepared']; }
    public function state(string $runId): TransferRunState { return $this->runs[$runId]['state']; }
    public function attempt(string $runId): int { return $this->runs[$runId]['attempt']; }
    public function generation(string $runId): int { return $this->runs[$runId]['prepared']->generation; }
    public function interruptedFrom(string $runId): ?TransferRunState { return $this->runs[$runId]['resume']; }
    public function failedFrom(string $runId): ?TransferRunState { return $this->state($runId) === TransferRunState::Failed ? $this->runs[$runId]['resume'] : null; }

    public function transition(string $runId, TransferRunState $expected, TransferRunState $next, bool $newAttempt = false): void
    {
        if ($this->state($runId) !== $expected) throw new \RuntimeException('transfer_run_transition_conflict');
        if ($expected === TransferRunState::Interrupted && $this->runs[$runId]['resume'] !== $next) throw new \RuntimeException('transfer_run_interrupted_phase_mismatch');
        $previousResume = $this->runs[$runId]['resume'];
        $this->runs[$runId]['state'] = $next;
        $this->runs[$runId]['resume'] = match (true) {
            $next === TransferRunState::Interrupted => $expected,
            $next === TransferRunState::Failed && $expected === TransferRunState::Interrupted => $previousResume,
            $next === TransferRunState::Failed => $expected,
            default => null,
        };
        if ($newAttempt) ++$this->runs[$runId]['attempt'];
    }

    public function successfulReceipt(string $runId, RecordEnvelope $record, int $generation): ?TransferReceipt
    {
        return $this->receipts[$record->identity->entityType . '|' . $record->identity->canonical()] ?? null;
    }

    public function commitReceipt(TransferReceipt $receipt): void
    {
        $key = $receipt->recordKind . '|' . $receipt->sourceIdentity;
        $this->receipts[$key] = $receipt;
        $this->exported[$key] = false;
    }

    public function pendingReceipts(string $runId): array
    {
        return array_values(array_filter($this->receipts, fn (TransferReceipt $receipt): bool => !$this->exported[$receipt->recordKind . '|' . $receipt->sourceIdentity]));
    }

    public function markReceiptExported(TransferReceipt $receipt): void { $this->exported[$receipt->recordKind . '|' . $receipt->sourceIdentity] = true; }
    public function receipts(string $runId): array { return array_values($this->receipts); }
    public function removeReceipt(string $recordKind, string $sourceIdentity): void
    {
        unset($this->receipts[$recordKind . '|' . $sourceIdentity]);
    }
    public function markRecordRolledBack(TransferReceipt $receipt): void {}
    public function markCatalogueStatusRestored(TransferReceipt $receipt): void {}
}

final class RecordingCatalogueActivator implements CatalogueActivator
{
    /** @var list<string> */
    public array $activated = [];
    /** @var list<string> */
    public array $restored = [];
    public int $receiptFingerprintCalls = 0;

    public function __construct(
        private readonly ?int $failOnActivation = null,
        private readonly bool $driftReceipts = false,
    ) {}

    public function activate(TransferReceipt $productReceipt, string $approvedStatus): CatalogueStatusChange
    {
        $this->activated[] = $productReceipt->sourceIdentity;
        if ($this->failOnActivation === count($this->activated)) {
            throw new \RuntimeException('injected_catalogue_activation_failure');
        }
        return new CatalogueStatusChange(
            $productReceipt->sourceIdentity,
            $productReceipt->targetIds['primary'],
            'draft',
            $approvedStatus,
            str_repeat('d', 64),
            str_repeat('e', 64),
        );
    }

    public function fingerprint(CatalogueStatusChange $change): string { return $change->afterFingerprint; }
    public function fingerprintReceipt(TransferReceipt $receipt): string
    {
        ++$this->receiptFingerprintCalls;
        return $this->driftReceipts ? str_repeat('f', 64) : $receipt->afterFingerprint;
    }
    public function restore(CatalogueStatusChange $change): void {}
    public function restoreReceipt(TransferReceipt $receipt): void { $this->restored[] = $receipt->sourceIdentity; }
    public function storefrontAndCartReconcile(array $changes): bool { return $changes !== []; }
}

final class AllowCompletionGate implements SubscriptionCompletionGate
{
    public function assertReady(PreparedTransfer $prepared, array $receipts): void {}
}

final class RejectCompletionGate implements SubscriptionCompletionGate
{
    public function assertReady(PreparedTransfer $prepared, array $receipts): void
    {
        throw new \RuntimeException('subscription_cutover_evidence_not_reconciled');
    }
}

final class RecordingFinalSubscriptionGate implements SubscriptionCompletionGate
{
    public int $calls = 0;
    public bool $reject = false;

    public function assertReady(PreparedTransfer $prepared, array $receipts): void
    {
        ++$this->calls;
        $subscription = array_values(array_filter(
            $receipts,
            static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'subscription',
        ));
        if (count($subscription) !== 1) {
            throw new \RuntimeException('subscription_cutover_receipt_coverage_changed');
        }
        if ($this->reject) {
            throw new \RuntimeException('subscription_cutover_target_drift:' . $subscription[0]->sourceIdentity);
        }
    }
}

final class CoordinatorRollbackGateway implements RollbackTargetGateway
{
    /** @var list<string> */
    public array $deleted = [];

    /** @param array<string, string|null> $fingerprints */
    public function __construct(private array $fingerprints) {}
    public function fingerprint(TransferReceipt $receipt): ?string { return $this->fingerprints[$receipt->sourceIdentity] ?? null; }
    public function delete(TransferReceipt $receipt): void
    {
        $this->deleted[] = $receipt->sourceIdentity;
        $this->fingerprints[$receipt->sourceIdentity] = null;
    }
}

final class PostCommitRollbackGateway implements RollbackTargetGateway, CommittedRollbackRecovery
{
    public int $deletes = 0;
    public int $recoveryCalls = 0;
    /** @param array<string,string|null> $fingerprints */
    public function __construct(private array $fingerprints) {}
    public function fingerprint(TransferReceipt $receipt): ?string { return $this->fingerprints[$receipt->sourceIdentity] ?? null; }
    public function delete(TransferReceipt $receipt): void
    {
        ++$this->deletes;
        $this->fingerprints[$receipt->sourceIdentity] = null;
        DatabaseTransaction::afterCommit(static fn (): never => throw new \RuntimeException('injected quarantine cleanup crash'));
    }
    public function completeCommittedRollback(RollbackPlan $plan): void
    {
        ++$this->recoveryCalls;
        if ($this->recoveryCalls === 1) throw new \RuntimeException('injected recovery crash');
        foreach ($plan->deletions as $item) {
            if ($this->fingerprint($item['receipt']) !== null) throw new \RuntimeException('target unexpectedly present');
        }
    }
}

final class RecordingCommittedRollbackGateway implements RollbackTargetGateway, CommittedRollbackRecovery
{
    public int $recoveryCalls = 0;
    /** @param array<string,string|null> $fingerprints */
    public function __construct(private array $fingerprints) {}
    public function fingerprint(TransferReceipt $receipt): ?string { return $this->fingerprints[$receipt->sourceIdentity] ?? null; }
    public function delete(TransferReceipt $receipt): void { $this->fingerprints[$receipt->sourceIdentity] = null; }
    public function completeCommittedRollback(RollbackPlan $plan): void
    {
        ++$this->recoveryCalls;
        foreach ($plan->deletions as $item) {
            if ($this->fingerprint($item['receipt']) !== null) throw new \RuntimeException('target unexpectedly present');
        }
    }
}
