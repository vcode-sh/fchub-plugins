<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordReconciler;
use CartShift\Domain\Transfer\RecordWriter;
use CartShift\Domain\Transfer\ReconcileContext;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\DatabaseAfterCommitException;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final readonly class TransferCoordinator
{
    /**
     * @param array<string, RecordWriter> $writers
     * @param array<string, RecordReconciler> $reconcilers
     */
    public function __construct(
        private TransferJournal $journal,
        private ReceiptExporter $receiptExporter,
        private TransferRunBoundary $boundary,
        private TargetStateInspector $targetState,
        private array $writers,
        private array $reconcilers,
    ) {
    }

    public function stage(
        TransferPlan $plan,
        StageContext $context,
        string $holderId,
        int $leaseTtl,
        ?string $recoveryEvidenceHash = null,
        ?int $batchSize = null,
    ): void {
        if ($leaseTtl <= 0 || ($batchSize !== null && $batchSize <= 0)) {
            throw new \InvalidArgumentException('Stage lease TTL and batch size must be positive.');
        }
        $prepared = $plan->prepared;
        if (!hash_equals($prepared->runId, $context->migrationId)) {
            throw new \RuntimeException('stage_context_run_id_changed');
        }
        if ($prepared->generation !== $context->generation) {
            throw new \RuntimeException('stage_context_generation_changed');
        }
        $prepared->assertUnblocked();
        $this->journal->start($prepared);
        $this->assertSameDescriptor($prepared);
        $state = $this->journal->state($prepared->runId);
        if ($state === TransferRunState::Failed) {
            throw new \RuntimeException('transfer_run_failed_terminal');
        }

        $targetHash = $prepared->targetState->targetHash;
        $descriptorHash = $prepared->descriptorHash();
        if ($state === TransferRunState::Prepared) {
            $this->boundary->acquire($targetHash, $holderId, $descriptorHash, $leaseTtl);
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Prepared, TransferRunState::Staging, true);
            });
        } elseif ($state === TransferRunState::Interrupted) {
            $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::Staging, true);
            });
        } elseif ($state === TransferRunState::Staging) {
            $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
            if ($recoveryEvidenceHash !== null) {
                $this->checked($prepared, $holderId, function () use ($prepared): void {
                    $this->journal->transition($prepared->runId, TransferRunState::Staging, TransferRunState::Interrupted);
                    $this->journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::Staging, true);
                });
            }
        } else {
            throw new \RuntimeException('transfer_run_not_stageable:' . $state->value);
        }

        try {
            $this->flushPending($prepared, $context, $holderId);
            $sequence = 0;
            $scheduled = 0;
            foreach ($plan->records as $record) {
                ++$sequence;
                if ($this->stageRecord($prepared, $record, $context, $holderId, $sequence)) {
                    ++$scheduled;
                    if ($batchSize !== null && $scheduled >= $batchSize) {
                        break;
                    }
                }
            }
            if ($this->allRecordsStaged($prepared, $plan)) {
                $this->checked($prepared, $holderId, function () use ($prepared): void {
                    $this->journal->transition($prepared->runId, TransferRunState::Staging, TransferRunState::Staged);
                });
            }
        } catch (FilesystemFinalisationInterrupted $exception) {
            $this->interruptFrom($prepared, $holderId, TransferRunState::Staging);
            throw new \RuntimeException('filesystem_finalisation_interrupted', 0, $exception);
        } catch (ReceiptExportInterrupted $exception) {
            $this->interruptFrom($prepared, $holderId, TransferRunState::Staging);
            throw new \RuntimeException('receipt_export_interrupted', 0, $exception);
        } catch (\Throwable $exception) {
            if ($this->journal->state($prepared->runId) === TransferRunState::Staging) {
                $this->failFrom($prepared, $holderId, TransferRunState::Staging);
            }
            throw $exception;
        }
    }

    public function reconcile(
        TransferPlan $plan,
        string $holderId,
        int $leaseTtl,
        ?string $recoveryEvidenceHash = null,
        ?CatalogueActivator $catalogueActivator = null,
        ?SubscriptionCompletionGate $subscriptionCompletion = null,
    ): void {
        $prepared = $plan->prepared;
        $this->assertSameDescriptor($prepared);
        $state = $this->journal->state($prepared->runId);
        if ($state === TransferRunState::Staged) {
            $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Staged, TransferRunState::Reconciling);
            });
        } elseif ($state === TransferRunState::Interrupted) {
            $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::Reconciling, true);
            });
        } elseif ($state === TransferRunState::Reconciling && $recoveryEvidenceHash !== null) {
            $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Reconciling, TransferRunState::Interrupted);
                $this->journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::Reconciling, true);
            });
        } elseif ($state === TransferRunState::Completed) {
            $targetHash = $prepared->targetState->targetHash;
            $descriptorHash = $prepared->descriptorHash();
            $this->boundary->acquire($targetHash, $holderId, $descriptorHash, $leaseTtl);
            try {
                $allReceipts = $this->journal->receipts($prepared->runId);
                $subscriptionReceipts = array_values(array_filter(
                    $allReceipts,
                    static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'subscription',
                ));
                if ($subscriptionReceipts !== []) {
                    if ($subscriptionCompletion === null) {
                        throw new \RuntimeException('subscription_completion_reconciler_unavailable');
                    }
                    $this->checked(
                        $prepared,
                        $holderId,
                        fn (): mixed => $subscriptionCompletion->assertReady($prepared, $allReceipts),
                    );
                }
                foreach ($plan->records as $record) {
                    $receipt = $this->journal->successfulReceipt($prepared->runId, $record, $prepared->generation);
                    if ($receipt === null) {
                        throw new \RuntimeException('reconciliation_receipt_missing');
                    }
                    if ($receipt->recordKind === 'subscription') {
                        continue;
                    }
                    $approvedProductStatus = null;
                    if ($receipt->recordKind === 'product') {
                        $statusReceipt = $this->catalogueReceipt($prepared->runId, $receipt);
                        $catalogueDecision = $plan->decisions->for($record->identity)['action'] ?? null;
                        if ($catalogueDecision === 'activate_catalogue' && $statusReceipt === null) {
                            throw new \RuntimeException('catalogue_activation_receipt_missing:' . $receipt->sourceIdentity);
                        }
                        if ($catalogueDecision === 'leave_catalogue_draft' && $statusReceipt !== null) {
                            throw new \RuntimeException('catalogue_activation_receipt_unexpected:' . $receipt->sourceIdentity);
                        }
                        if ($statusReceipt !== null) {
                            if ($catalogueActivator === null) {
                                throw new \RuntimeException('catalogue_activation_reconciler_unavailable');
                            }
                            if (!hash_equals($statusReceipt->afterFingerprint, $catalogueActivator->fingerprintReceipt($statusReceipt))) {
                                throw new \RuntimeException('catalogue_activation_target_drift:' . $statusReceipt->sourceIdentity);
                            }
                            $approvedProductStatus = 'publish';
                        }
                    }
                    $this->checked(
                        $prepared,
                        $holderId,
                        fn (): mixed => $this->reconcileReceipt($record, $receipt, $approvedProductStatus),
                    );
                }
            } finally {
                $this->boundary->release($targetHash, $holderId, $descriptorHash);
            }
            return;
        } else {
            throw new \RuntimeException('transfer_run_not_reconcilable:' . $state->value);
        }
        try {
            foreach ($plan->records as $record) {
                $receipt = $this->journal->successfulReceipt($prepared->runId, $record, $prepared->generation);
                if ($receipt === null) throw new \RuntimeException('reconciliation_receipt_missing');
                $this->reconcileReceipt($record, $receipt);
            }
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Reconciling, TransferRunState::Reconciled);
            });
        } catch (\Throwable $exception) {
            $this->failFrom($prepared, $holderId, TransferRunState::Reconciling);
            throw $exception;
        }
    }

    public function promote(PreparedTransfer $prepared, string $holderId, int $leaseTtl, ?string $recoveryEvidenceHash = null): void
    {
        $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
        $this->checked($prepared, $holderId, function () use ($prepared): void {
            $this->journal->transition($prepared->runId, TransferRunState::Reconciled, TransferRunState::Promoted);
        });
    }

    public function prepareSubscriptionCutover(
        PreparedTransfer $prepared,
        string $holderId,
        int $leaseTtl,
        callable $prepare,
        ?string $recoveryEvidenceHash = null,
    ): mixed
    {
        $this->assertSameDescriptor($prepared);
        if ($this->journal->state($prepared->runId) !== TransferRunState::Promoted) {
            throw new \RuntimeException('transfer_run_not_subscription_cutover_preparable');
        }
        $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
        return $this->checked($prepared, $holderId, fn (): mixed => $prepare($this->journal->receipts($prepared->runId)));
    }

    public function activateSubscriptions(
        PreparedTransfer $prepared,
        string $holderId,
        int $leaseTtl,
        callable $activate,
        ?string $recoveryEvidenceHash = null,
    ): mixed
    {
        $this->assertSameDescriptor($prepared);
        if (!in_array($this->journal->state($prepared->runId), [TransferRunState::Promoted, TransferRunState::CatalogueActivating], true)) {
            throw new \RuntimeException('transfer_run_not_subscription_activatable');
        }
        $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
        return $this->checked($prepared, $holderId, $activate);
    }

    public function completeWithoutActivation(
        PreparedTransfer $prepared,
        string $holderId,
        int $leaseTtl,
        SubscriptionCompletionGate $subscriptions,
        ?string $recoveryEvidenceHash = null,
    ): void {
        if (!$prepared->leaveDraftAccepted) throw new \RuntimeException('catalogue_activation_or_leave_draft_acceptance_required');
        $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
        $subscriptions->assertReady($prepared, $this->journal->receipts($prepared->runId));
        $this->checked($prepared, $holderId, function () use ($prepared): void {
            $this->journal->transition($prepared->runId, TransferRunState::Promoted, TransferRunState::Completed);
        });
        $this->boundary->release($prepared->targetState->targetHash, $holderId, $prepared->descriptorHash());
    }

    public function activateCatalogue(
        TransferPlan $plan,
        string $holderId,
        int $leaseTtl,
        CatalogueActivator $activator,
        ?string $recoveryEvidenceHash = null,
    ): void
    {
        $prepared = $plan->prepared;
        $this->assertSameDescriptor($prepared);
        $prepared->assertUnblocked();
        $targetHash = $prepared->targetState->targetHash;
        $descriptorHash = $prepared->descriptorHash();
        $state = $this->journal->state($prepared->runId);
        if ($state === TransferRunState::Promoted) {
            $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Promoted, TransferRunState::CatalogueActivating);
            });
        } elseif ($state === TransferRunState::Interrupted) {
            $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::CatalogueActivating, true);
            });
        } elseif ($state === TransferRunState::CatalogueActivating) {
            if ($recoveryEvidenceHash !== null) {
                $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
                $this->checked($prepared, $holderId, function () use ($prepared): void {
                    $this->journal->transition($prepared->runId, TransferRunState::CatalogueActivating, TransferRunState::Interrupted);
                    $this->journal->transition($prepared->runId, TransferRunState::Interrupted, TransferRunState::CatalogueActivating, true);
                });
            } else {
                $this->boundary->renew($targetHash, $holderId, $descriptorHash, $leaseTtl);
            }
        } else {
            throw new \RuntimeException('transfer_run_not_catalogue_activatable:' . $state->value);
        }
        $products = array_values(array_filter(
            $this->journal->receipts($prepared->runId),
            static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'product' && $receipt->action === 'created',
        ));
        $recordsByIdentity = [];
        foreach ($plan->records as $record) {
            $recordsByIdentity[$record->identity->canonical()] = $record;
        }
        $approved = [];
        foreach ($products as $receipt) {
            $identity = \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($receipt->sourceIdentity);
            $decision = $plan->decisions->for($identity);
            $record = $recordsByIdentity[$receipt->sourceIdentity] ?? null;
            if (!$record instanceof RecordEnvelope
                || !hash_equals($record->privateContentDigest, $receipt->sourceFingerprint)
                || !hash_equals($record->sourceContentDigest, (string) ($decision['source_fingerprint'] ?? ''))) {
                throw new \RuntimeException('catalogue_visibility_decision_missing_or_stale:' . $receipt->sourceIdentity);
            }
            if (($decision['action'] ?? null) === 'leave_catalogue_draft'
                && ($decision['target_status'] ?? null) === 'draft') {
                continue;
            }
            if (($decision['action'] ?? null) !== 'activate_catalogue'
                || ($decision['target_status'] ?? null) !== 'publish') {
                throw new \RuntimeException('catalogue_visibility_decision_missing_or_stale:' . $receipt->sourceIdentity);
            }
            $approved[] = [$receipt, 'publish'];
        }
        if ($products === []) throw new \RuntimeException('catalogue_activation_has_no_created_products');
        $changes = [];
        try {
            $this->flushPendingReceipts($prepared, $holderId);
            foreach ($approved as [$productReceipt, $status]) {
                $existing = $this->catalogueReceipt($prepared->runId, $productReceipt);
                if ($existing !== null) {
                    $actual = $activator->fingerprintReceipt($existing);
                    if (!hash_equals($existing->afterFingerprint, $actual)) {
                        throw new \RuntimeException('catalogue_activation_target_drift:' . $existing->sourceIdentity);
                    }
                    continue;
                }
                $this->checked($prepared, $holderId, function () use ($prepared, $productReceipt, $status, $activator, &$changes): void {
                    DatabaseTransaction::begin();
                    try {
                        $change = $activator->activate($productReceipt, $status);
                        $actual = $activator->fingerprint($change);
                        if (!hash_equals($change->afterFingerprint, $actual)) {
                            throw new \RuntimeException('catalogue_activation_reconciliation_failed:' . $change->sourceIdentity);
                        }
                        $receipt = new TransferReceipt(
                            $prepared->runId,
                            'catalogue_status',
                            $change->sourceIdentity,
                            $productReceipt->generation,
                            $productReceipt->sourceFingerprint,
                            'catalogue_status',
                            $productReceipt->targetIds,
                            $change->beforeFingerprint,
                            $change->afterFingerprint,
                            1_000_000 + $productReceipt->sequence,
                            $this->now(),
                            $this->now(),
                        );
                        $this->journal->commitReceipt($receipt);
                        DatabaseTransaction::commit();
                        $changes[] = [$change, $receipt];
                    } catch (\Throwable $exception) {
                        DatabaseTransaction::rollback($exception);
                        throw $exception;
                    }
                    $this->exportReceipt($receipt);
                });
            }
        } catch (ReceiptExportInterrupted $exception) {
            $this->interruptFrom($prepared, $holderId, TransferRunState::CatalogueActivating);
            throw new \RuntimeException('receipt_export_interrupted', 0, $exception);
        } catch (\Throwable $exception) {
            $statusReceipts = $this->catalogueReceipts($prepared->runId);
            $conflicts = array_values(array_filter(array_map(
                static fn (TransferReceipt $receipt): ?string => hash_equals($receipt->afterFingerprint, $activator->fingerprintReceipt($receipt))
                    ? null
                    : $receipt->sourceIdentity,
                $statusReceipts,
            )));
            if ($conflicts === []) {
                foreach (array_reverse($statusReceipts) as $receipt) {
                    DatabaseTransaction::begin();
                    try {
                        $activator->restoreReceipt($receipt);
                        $this->journal->markCatalogueStatusRestored($receipt);
                        DatabaseTransaction::commit();
                    } catch (\Throwable $restoreException) {
                        DatabaseTransaction::rollback($restoreException);
                        $conflicts[] = $receipt->sourceIdentity;
                        break;
                    }
                }
            }
            $this->failFrom($prepared, $holderId, TransferRunState::CatalogueActivating);
            if ($conflicts !== []) {
                throw new \RuntimeException('catalogue_activation_restore_conflict:' . implode(',', $conflicts), 0, $exception);
            }
            throw $exception;
        }
    }

    public function completeAfterActivation(
        PreparedTransfer $prepared,
        string $holderId,
        int $leaseTtl,
        CatalogueActivator $activator,
        SubscriptionCompletionGate $subscriptions,
        ?string $recoveryEvidenceHash = null,
    ): void
    {
        $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
        $statusReceipts = array_values(array_filter(
            $this->journal->receipts($prepared->runId),
            static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'catalogue_status',
        ));
        if ($statusReceipts === [] || !$activator->storefrontAndCartReconcile($statusReceipts)) {
            throw new \RuntimeException('catalogue_storefront_cart_reconciliation_failed');
        }
        $subscriptions->assertReady($prepared, $this->journal->receipts($prepared->runId));
        $this->checked($prepared, $holderId, function () use ($prepared): void {
            $this->journal->transition($prepared->runId, TransferRunState::CatalogueActivating, TransferRunState::Completed);
        });
        $this->boundary->release($prepared->targetState->targetHash, $holderId, $prepared->descriptorHash());
    }

    public function rollback(
        PreparedTransfer $prepared,
        string $holderId,
        int $leaseTtl,
        RollbackPlan $approvedPlan,
        RollbackPlanner $planner,
        RollbackTargetGateway $gateway,
        ?string $recoveryEvidenceHash = null,
    ): void {
        $this->assertSameDescriptor($prepared);
        $state = $this->journal->state($prepared->runId);
        if (!in_array($state, [TransferRunState::Failed, TransferRunState::RollingBack], true)) {
            throw new \RuntimeException('transfer_run_not_rollbackable');
        }
        $this->renewOrRecover($prepared, $holderId, $leaseTtl, $recoveryEvidenceHash);
        try {
            if ($state === TransferRunState::RollingBack) {
                if (!$gateway instanceof CommittedRollbackRecovery) {
                    throw new \RuntimeException('rollback_committed_recovery_unavailable');
                }
                $this->checked($prepared, $holderId, fn (): mixed => $gateway->completeCommittedRollback($approvedPlan));
                $this->checked($prepared, $holderId, function () use ($prepared): void {
                    $this->journal->transition($prepared->runId, TransferRunState::RollingBack, TransferRunState::RolledBack);
                });
                $this->boundary->release($prepared->targetState->targetHash, $holderId, $prepared->descriptorHash());
                return;
            }
            $this->checked($prepared, $holderId, function () use ($prepared, $approvedPlan, $planner, $gateway): void {
                $this->journal->transition($prepared->runId, TransferRunState::Failed, TransferRunState::RollingBack);
                $current = $planner->plan($prepared->runId, $prepared->generation, $this->journal->receipts($prepared->runId), $gateway);
                if (!hash_equals($approvedPlan->fingerprint(), $current->fingerprint())) {
                    throw new \RuntimeException('rollback_plan_fingerprint_changed');
                }
                DatabaseTransaction::begin();
                try {
                    $planner->execute(
                        $current,
                        $gateway,
                        fn (TransferReceipt $receipt): mixed => $this->journal->markRecordRolledBack($receipt),
                    );
                    DatabaseTransaction::commit();
                } catch (DatabaseAfterCommitException $exception) {
                    if (!$gateway instanceof CommittedRollbackRecovery) {
                        throw new \RuntimeException('rollback_filesystem_finalisation_interrupted', 0, $exception);
                    }
                    try {
                        $gateway->completeCommittedRollback($approvedPlan);
                    } catch (\Throwable $recovery) {
                        throw new \RuntimeException('rollback_filesystem_finalisation_interrupted', 0, $recovery);
                    }
                } catch (\Throwable $exception) {
                    DatabaseTransaction::rollback($exception);
                    throw $exception;
                }
                if ($gateway instanceof CommittedRollbackRecovery) {
                    try {
                        $gateway->completeCommittedRollback($approvedPlan);
                    } catch (\Throwable $exception) {
                        throw new \RuntimeException('rollback_filesystem_finalisation_interrupted', 0, $exception);
                    }
                }
            });
            $this->checked($prepared, $holderId, function () use ($prepared): void {
                $this->journal->transition($prepared->runId, TransferRunState::RollingBack, TransferRunState::RolledBack);
            });
            $this->boundary->release($prepared->targetState->targetHash, $holderId, $prepared->descriptorHash());
        } catch (\Throwable $exception) {
            if ($this->journal->state($prepared->runId) === TransferRunState::RollingBack
                && !str_starts_with($exception->getMessage(), 'rollback_filesystem_finalisation_interrupted')) {
                $this->failFrom($prepared, $holderId, TransferRunState::RollingBack);
            }
            throw $exception;
        }
    }

    private function renewOrRecover(
        PreparedTransfer $prepared,
        string $holderId,
        int $leaseTtl,
        ?string $recoveryEvidenceHash,
    ): void {
        try {
            $this->boundary->renew(
                $prepared->targetState->targetHash,
                $holderId,
                $prepared->descriptorHash(),
                $leaseTtl,
            );
        } catch (\RuntimeException $exception) {
            if ($recoveryEvidenceHash === null || $exception->getMessage() !== 'transfer_lease_renewal_conflict') {
                throw $exception;
            }
            $this->boundary->recover(
                $prepared->targetState->targetHash,
                $holderId,
                $prepared->descriptorHash(),
                $recoveryEvidenceHash,
                $leaseTtl,
            );
        }
    }

    public function state(string $runId): TransferRunState
    {
        return $this->journal->state($runId);
    }

    private function stageRecord(PreparedTransfer $prepared, RecordEnvelope $record, StageContext $context, string $holderId, int $sequence): bool
    {
        return $this->checked($prepared, $holderId, function () use ($prepared, $record, $context, $sequence): bool {
            $existing = $this->journal->successfulReceipt($prepared->runId, $record, $prepared->generation);
            if ($existing !== null) {
                if (!hash_equals($record->privateContentDigest, $existing->sourceFingerprint)) {
                    throw new \RuntimeException('receipt_source_fingerprint_changed');
                }
                $this->reconcileReceipt($record, $existing);
                return false;
            }
            $kind = $record->identity->entityType;
            $writer = $this->writers[$kind] ?? null;
            if (!$writer instanceof RecordWriter) throw new \RuntimeException('record_writer_unavailable:' . $kind);
            $started = $this->now();
            DatabaseTransaction::begin();
            try {
                $result = $writer->stage($record, $context);
                $targetIds = ['primary' => $result->targetId];
                foreach ($result->sourceTargetIds as $canonical => $id) $targetIds[$canonical] = $id;
                foreach ($result->variationIds as $index => $id) $targetIds['variation_' . $index] = $id;
                foreach ($result->mediaIds as $index => $id) $targetIds['media_' . $index] = $id;
                foreach ($result->downloadIds as $index => $id) $targetIds['download_' . $index] = $id;
                $receipt = new TransferReceipt(
                    $prepared->runId,
                    $kind,
                    $record->identity->canonical(),
                    $prepared->generation,
                    $record->privateContentDigest,
                    $result->reused ? 'reused' : 'created',
                    $targetIds,
                    $result->reused ? $result->targetFingerprint : null,
                    $result->targetFingerprint,
                    $sequence,
                    $started,
                    $this->now(),
                    $result->filesystemOperationIds,
                );
                $this->journal->commitReceipt($receipt);
                DatabaseTransaction::commit();
            } catch (DatabaseAfterCommitException $exception) {
                throw new FilesystemFinalisationInterrupted($exception->getMessage(), 0, $exception);
            } catch (\Throwable $exception) {
                DatabaseTransaction::rollback($exception);
                throw $exception;
            }
            $this->exportReceipt($receipt);
            return true;
        });
    }

    private function allRecordsStaged(PreparedTransfer $prepared, TransferPlan $plan): bool
    {
        foreach ($plan->records as $record) {
            if ($this->journal->successfulReceipt($prepared->runId, $record, $prepared->generation) === null) {
                return false;
            }
        }
        return true;
    }

    private function catalogueReceipt(string $runId, TransferReceipt $productReceipt): ?TransferReceipt
    {
        foreach ($this->catalogueReceipts($runId) as $receipt) {
            if ($receipt->sourceIdentity !== $productReceipt->sourceIdentity) {
                continue;
            }
            if ($receipt->generation !== $productReceipt->generation
                || $receipt->sourceFingerprint !== $productReceipt->sourceFingerprint
                || $receipt->targetIds['primary'] !== $productReceipt->targetIds['primary']) {
                throw new \RuntimeException('catalogue_activation_receipt_conflict:' . $receipt->sourceIdentity);
            }
            return $receipt;
        }
        return null;
    }

    /** @return list<TransferReceipt> */
    private function catalogueReceipts(string $runId): array
    {
        return array_values(array_filter(
            $this->journal->receipts($runId),
            static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'catalogue_status',
        ));
    }

    private function reconcileReceipt(
        RecordEnvelope $record,
        TransferReceipt $receipt,
        ?string $approvedProductStatus = null,
    ): void
    {
        $reconciler = $this->reconcilers[$record->identity->entityType] ?? null;
        if (!$reconciler instanceof RecordReconciler) throw new \RuntimeException('record_reconciler_unavailable:' . $record->identity->entityType);
        $result = $reconciler->reconcile($record, new ReconcileContext(
            $receipt->targetIds,
            $receipt->afterFingerprint,
            $receipt->runId,
            $receipt->generation,
            $approvedProductStatus,
        ));
        if (!$result->matches || !hash_equals($receipt->afterFingerprint, $result->actualFingerprint)) {
            throw new \RuntimeException('target_reconciliation_failed:' . implode(',', $result->failures));
        }
    }

    private function flushPending(PreparedTransfer $prepared, StageContext $context, string $holderId): void
    {
        foreach ($this->journal->pendingReceipts($prepared->runId) as $receipt) {
            $this->checked($prepared, $holderId, function () use ($prepared, $context, $receipt): void {
                if ($receipt->filesystemOperationIds !== []) {
                    if (!$context->filesystemSaga instanceof FilesystemSagaRepository) {
                        throw new FilesystemFinalisationInterrupted('filesystem_saga_repository_unavailable');
                    }
                    try {
                        foreach ($receipt->filesystemOperationIds as $operationId) {
                            $context->filesystemSaga->finalisePending($prepared->runId, $operationId);
                        }
                    } catch (\Throwable $exception) {
                        throw new FilesystemFinalisationInterrupted($exception->getMessage(), 0, $exception);
                    }
                }
                $this->exportReceipt($receipt);
            });
        }
    }

    private function flushPendingReceipts(PreparedTransfer $prepared, string $holderId): void
    {
        foreach ($this->journal->pendingReceipts($prepared->runId) as $receipt) {
            $this->checked($prepared, $holderId, fn (): mixed => $this->exportReceipt($receipt));
        }
    }

    private function exportReceipt(TransferReceipt $receipt): void
    {
        try {
            $this->receiptExporter->export($receipt);
            $this->journal->markReceiptExported($receipt);
        } catch (\Throwable $exception) {
            throw new ReceiptExportInterrupted($exception->getMessage(), 0, $exception);
        }
    }

    private function checked(PreparedTransfer $prepared, string $holderId, callable $operation): mixed
    {
        return $this->boundary->criticalSection(
            $prepared->targetState->targetHash,
            $holderId,
            $prepared->descriptorHash(),
            function () use ($prepared, $operation): mixed {
                $prepared->assertCurrent($this->targetState->inspect());
                return $operation();
            },
        );
    }

    private function interruptFrom(PreparedTransfer $prepared, string $holderId, TransferRunState $from): void
    {
        $this->boundary->criticalSection(
            $prepared->targetState->targetHash,
            $holderId,
            $prepared->descriptorHash(),
            fn (): mixed => $this->journal->transition($prepared->runId, $from, TransferRunState::Interrupted),
        );
    }

    private function failFrom(PreparedTransfer $prepared, string $holderId, TransferRunState $from): void
    {
        $this->boundary->criticalSection(
            $prepared->targetState->targetHash,
            $holderId,
            $prepared->descriptorHash(),
            fn (): mixed => $this->journal->transition($prepared->runId, $from, TransferRunState::Failed),
        );
    }

    private function assertSameDescriptor(PreparedTransfer $prepared): void
    {
        $stored = $this->journal->prepared($prepared->runId);
        if (!hash_equals($stored->descriptorHash(), $prepared->descriptorHash())) {
            throw new \RuntimeException('prepared_transfer_descriptor_changed');
        }
    }

    private function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

final class ReceiptExportInterrupted extends \RuntimeException
{
}

final class FilesystemFinalisationInterrupted extends \RuntimeException
{
}
