<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\CustomerReconciler;
use CartShift\Domain\Transfer\Customer\CustomerWriter;
use CartShift\Domain\Transfer\Customer\LoadedFluentCartCustomerGateway;
use CartShift\Domain\Transfer\Identity\TargetClaimRepository;
use CartShift\Domain\Transfer\Order\FluentCartOrderWriter;
use CartShift\Domain\Transfer\Order\LoadedFluentCartOrderGateway;
use CartShift\Domain\Transfer\Order\OrderReconciler;
use CartShift\Domain\Transfer\Package\TransferPackageReader;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Product\FluentCartDownloadStager;
use CartShift\Domain\Transfer\Product\FluentCartProductWriter;
use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ProductReconciler;
use CartShift\Domain\Transfer\Product\WordPressMediaStager;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\Subscription\FluentCartSubscriptionWriter;
use CartShift\Domain\Transfer\Subscription\LoadedFluentCartSubscriptionGateway;
use CartShift\Domain\Transfer\Subscription\SubscriptionReconciler;
use CartShift\Domain\Transfer\Subscription\LoadedSubscriptionCompletionGate;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverPreparer;
use CartShift\Domain\Transfer\Subscription\SubscriptionTargetCutover;
use CartShift\Domain\Transfer\Subscription\SubscriptionRollbackGate;
use CartShift\Domain\Transfer\TransferLease;
use CartShift\Domain\Transfer\TransferLock;
use CartShift\Domain\Transfer\TransferRunGuard;
use CartShift\Storage\IdMapRepository;

defined('ABSPATH') || exit;

/** Production composition for descriptor-bound target commands. */
final class LoadedTargetTransferPipeline
{
    public static function create(): self { return new self(); }

    /** Reconciles a safe pre-release guided failure with the rollback state machine. */
    public function prepareGuidedRollback(
        PreparedTransfer $prepared,
        TransferJournalRepository $journal,
        string $private,
    ): void {
        if ($prepared->executionContext !== 'guided') {
            throw new \RuntimeException('guided_rollback_execution_context_changed');
        }
        $state = $journal->state($prepared->runId);
        if ($state !== TransferRunState::Promoted) {
            return;
        }
        $hasSubscriptions = array_filter(
            $journal->receipts($prepared->runId),
            static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'subscription',
        ) !== [];
        if ($hasSubscriptions) {
            (new SubscriptionRollbackGate(new SubscriptionCutoverEvidenceRepository($private)))
                ->assertAllowed($prepared->runId);
        }
        $journal->transition($prepared->runId, TransferRunState::Promoted, TransferRunState::Failed);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function __invoke(array $input): array
    {
        $private = ConfiguredTransferEvidence::privateDirectory();
        $descriptor = (string) ($input['descriptor'] ?? '');
        $prepared = (new PreparedTransferRepository($private))->get($descriptor);
        $journal = new TransferJournalRepository(new PreparedTransferRepository($private));
        $command = (string) ($input['command'] ?? 'status');
        if ($command === 'status') {
            return $this->status($prepared, $journal, $private);
        }

        $package = realpath((string) ($input['package'] ?? ''));
        if ($package === false || !is_dir($package) || is_link((string) ($input['package'] ?? ''))
            || $package !== realpath($prepared->packagePath)) {
            throw new \RuntimeException('prepared_transfer_package_path_changed');
        }
        if (($input['confirm'] ?? null) !== $prepared->targetState->selectionHash
            || ($input['execution_context'] ?? null) !== $prepared->executionContext) {
            throw new \RuntimeException('prepared_transfer_confirmation_changed');
        }
        if ($prepared->executionContext === 'cutover') {
            ConfiguredTransferEvidence::assertCutoverApproval((string) ($input['cutover_approval'] ?? ''))
                ->assertPrepared($prepared);
        } elseif (($input['cutover_approval'] ?? '') !== '') {
            throw new \RuntimeException('rehearsal_cutover_approval_unexpected');
        }
        $prepared->assertUnblocked();

        $validator = new TransferPackageValidator();
        $reader = new TransferPackageReader($package, $validator);
        $manifest = $reader->manifest();
        if (!hash_equals($prepared->packageHash, hash('sha256', $manifest->canonicalJson()))
            || !hash_equals($prepared->targetState->selectionHash, $manifest->selectionFingerprint)
            || $prepared->sourceKey !== $manifest->sourceKey) {
            throw new \RuntimeException('prepared_transfer_package_changed');
        }
        $decisions = (new PreparedDecisionSetRepository($private))->get($descriptor);
        if (!hash_equals($prepared->targetState->decisionHash, $decisions->fingerprint())) {
            throw new \RuntimeException('prepared_transfer_decision_changed');
        }
        $records = iterator_to_array($reader->records(), false);
        $plan = TransferPlan::build($prepared, $records, $decisions);
        $baseline = (new PreparedTargetBaselineRepository($private))->get($descriptor);
        if (!hash_equals($prepared->targetState->targetHash, $baseline->fingerprint())) {
            throw new \RuntimeException('prepared_transfer_target_baseline_changed');
        }

        [$coordinator, $stageContext] = $this->components($private, $prepared, $manifest->sourceRuntimeFingerprint, $records, $decisions, $baseline);
        // WP-CLI starts a fresh process for each lifecycle command. The lease
        // therefore belongs to the explicitly configured operator, not a PID
        // that is guaranteed to disappear before reconcile or promote.
        $holder = 'operator:' . ConfiguredTransferEvidence::operatorId() . ':' . $prepared->runId;
        $ttl = 300;
        $recovery = isset($input['lease_recovery']) ? (string) $input['lease_recovery'] : null;
        if ($prepared->executionContext === 'cutover' && $command !== 'rollback' && $recovery !== null
            && !hash_equals((string) $input['cutover_approval'], $recovery)) {
            throw new \RuntimeException('cutover_lease_recovery_evidence_changed');
        }
        $subscriptionCompletion = new LoadedSubscriptionCompletionGate(
            new SubscriptionCutoverEvidenceRepository($private),
            new LoadedFluentCartSubscriptionGateway(),
        );
        match ($command) {
            'stage' => $coordinator->stage($plan, $stageContext, $holder, $ttl, $recovery, isset($input['batch_size']) ? (int) $input['batch_size'] : null),
            'reconcile' => $coordinator->reconcile(
                $plan,
                $holder,
                $ttl,
                $recovery,
                new LoadedCatalogueActivator($prepared->sourceKey),
                $subscriptionCompletion,
            ),
            'promote' => $coordinator->promote($prepared, $holder, $ttl, $recovery),
            'prepare-subscription-cutover' => $coordinator->prepareSubscriptionCutover(
                $prepared,
                $holder,
                $ttl,
                function (array $receipts) use ($prepared, $records, $decisions, $private, $manifest): void {
                    $evidence = (new SubscriptionCutoverPreparer())->prepare(
                        $prepared, $records, $decisions, $receipts,
                        $manifest->sourceInstanceFingerprint, $manifest->sourceRuntimeFingerprint,
                        gmdate('Y-m-d\TH:i:s\Z'),
                    );
                    (new SubscriptionCutoverEvidenceRepository($private))->createPreparedIfPresent($evidence);
                },
                $recovery,
            ),
            'activate-subscriptions' => $coordinator->activateSubscriptions(
                $prepared,
                $holder,
                $ttl,
                fn (): mixed => (new SubscriptionTargetCutover(
                    new SubscriptionCutoverEvidenceRepository($private),
                    new LoadedFluentCartSubscriptionGateway(),
                ))->activateAndReconcile($prepared->runId, gmdate('Y-m-d\TH:i:s\Z')),
                $recovery,
            ),
            'activate-catalogue' => $coordinator->activateCatalogue($plan, $holder, $ttl, new LoadedCatalogueActivator($prepared->sourceKey), $recovery),
            'complete' => $prepared->leaveDraftAccepted
                ? $coordinator->completeWithoutActivation($prepared, $holder, $ttl, $subscriptionCompletion, $recovery)
                : $coordinator->completeAfterActivation($prepared, $holder, $ttl, new LoadedCatalogueActivator($prepared->sourceKey), $subscriptionCompletion, $recovery),
            'rollback' => $this->rollback($coordinator, $journal, $prepared, $holder, $ttl, $private, $input, $recovery),
            default => throw new \InvalidArgumentException('target_transfer_command_invalid'),
        };

        return $this->status($prepared, $journal, $private) + ['command' => $command];
    }

    /**
     * @param list<\CartShift\Domain\Transfer\RecordEnvelope> $records
     * @return array{TransferCoordinator,StageContext}
     */
    private function components(
        string $private,
        PreparedTransfer $prepared,
        string $sourceRuntimeFingerprint,
        array $records,
        \CartShift\Domain\Transfer\Decision\TransferDecisionSet $decisions,
        PreparedTargetBaseline $baseline,
    ): array {
        $maps = new IdMapRepository($prepared->sourceKey);
        $plans = LoadedTargetRecordPlanFactory::create($decisions, $maps, $prepared->packagePath, $records, $prepared->createdAtUtc);
        $productGateway = new LoadedFluentCartProductGateway();
        $productReconciler = new ProductReconciler($productGateway, $maps);
        $customerGateway = new LoadedFluentCartCustomerGateway();
        $customerReconciler = new CustomerReconciler();
        $orderGateway = new LoadedFluentCartOrderGateway();
        $orderReconciler = new OrderReconciler($orderGateway, $maps);
        $subscriptionGateway = new LoadedFluentCartSubscriptionGateway();
        $subscriptionReconciler = new SubscriptionReconciler($subscriptionGateway, $maps);
        $claims = new TargetClaimRepository();
        $uploads = wp_get_upload_dir();
        $uploadRoot = is_string($uploads['basedir'] ?? null) ? (string) $uploads['basedir'] : '';
        if ($uploadRoot === '' || !is_dir($uploadRoot) || !is_writable($uploadRoot)) {
            throw new \RuntimeException('target_upload_directory_unavailable');
        }
        $filesystemSaga = new FilesystemSagaRepository($private);
        $productWriter = new FluentCartProductWriter(
            $productGateway,
            $maps,
            $productReconciler,
            new WordPressMediaStager($uploadRoot),
            new FluentCartDownloadStager($uploadRoot . '/fluent-cart', 'local'),
        );
        $writers = [
            'product' => new ProductEnvelopeWriter($plans, $productWriter),
            'customer' => new CustomerEnvelopeWriter($plans, new CustomerWriter($customerGateway, $maps, $customerReconciler)),
            'order' => new OrderEnvelopeWriter($plans, new FluentCartOrderWriter($orderGateway, $maps, $orderReconciler, $claims)),
            'subscription' => new SubscriptionEnvelopeWriter($plans, new FluentCartSubscriptionWriter($subscriptionGateway, $maps, $claims, $subscriptionReconciler)),
        ];
        $reconcilers = [
            'product' => new ProductEnvelopeReconciler($plans, $productReconciler),
            'customer' => new CustomerEnvelopeReconciler($plans, $customerGateway, $maps, $customerReconciler),
            'order' => new OrderEnvelopeReconciler($plans, $orderReconciler),
            'subscription' => new SubscriptionEnvelopeReconciler($plans, $subscriptionReconciler),
        ];
        $targetState = new LoadedTargetStateInspector(
            $prepared->targetState->packageHash,
            $prepared->targetState->decisionHash,
            $prepared->targetState->selectionHash,
            $baseline,
            $prepared->runId,
            new TransferRuntimeProbe(),
            new LoadedTargetSettingsInspector(),
            new LoadedPreparedTargetBaselineProbe(),
            $prepared->targetState->compatibilityHash,
            $prepared->targetState->settingsHash,
            $prepared->targetState->gatewayHash,
        );
        $coordinator = new TransferCoordinator(
            new TransferJournalRepository(new PreparedTransferRepository($private)),
            new TransferReceiptRepository($private),
            new TransferRunGuard(new TransferLock(), new TransferLease()),
            $targetState,
            $writers,
            $reconcilers,
        );
        return [$coordinator, new StageContext(
            $prepared->packagePath,
            $prepared->runId,
            $sourceRuntimeFingerprint,
            [],
            $prepared->generation,
            $filesystemSaga,
        )];
    }

    /** @param array<string,mixed> $input */
    private function rollback(
        TransferCoordinator $coordinator,
        TransferJournal $journal,
        PreparedTransfer $prepared,
        string $holder,
        int $ttl,
        string $private,
        array $input,
        ?string $recoveryEvidenceHash,
    ): void
    {
        $state = $journal->state($prepared->runId);
        $hasSubscriptions = array_filter(
            $journal->receipts($prepared->runId),
            static fn (TransferReceipt $receipt): bool => $receipt->recordKind === 'subscription',
        ) !== [];
        if ($hasSubscriptions) {
            $failedFrom = $state === TransferRunState::Failed ? $journal->failedFrom($prepared->runId) : null;
            $missingIsAmbiguous = $state === TransferRunState::Failed
                && !in_array($failedFrom, [TransferRunState::Staging, TransferRunState::Reconciling], true)
                && !($prepared->executionContext === 'guided' && $failedFrom === TransferRunState::Promoted);
            (new SubscriptionRollbackGate(new SubscriptionCutoverEvidenceRepository($private)))
                ->assertAllowed($prepared->runId, $missingIsAmbiguous);
        }
        $path = (string) ($input['rollback_plan'] ?? '');
        $approved = (new RollbackPlanRepository(dirname($path)))->get($path);
        if (!hash_equals($approved->fingerprint(), (string) ($input['rollback_plan_fingerprint'] ?? ''))) {
            throw new \RuntimeException('rollback_plan_fingerprint_changed');
        }
        $gateway = new LoadedRollbackTargetGateway($prepared->sourceKey, new FilesystemSagaRepository($private));
        $coordinator->rollback($prepared, $holder, $ttl, $approved, new RollbackPlanner(), $gateway, $recoveryEvidenceHash);
    }

    /** @return array<string,mixed> */
    private function status(PreparedTransfer $prepared, TransferJournal $journal, string $private): array
    {
        try {
            $state = $journal->state($prepared->runId);
            $receipts = $journal->receipts($prepared->runId);
            $attempt = $journal->attempt($prepared->runId);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== 'transfer_run_missing_or_duplicate') {
                throw $exception;
            }
            $state = TransferRunState::Prepared;
            $receipts = [];
            $attempt = 0;
        }
        $counts = [];
        foreach ($receipts as $receipt) {
            $counts[$receipt->recordKind] = ($counts[$receipt->recordKind] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        $subscriptionCutover = null;
        $subscriptionReleaseRequired = false;
        if (($counts['subscription'] ?? 0) > 0) {
            try {
                $evidence = (new SubscriptionCutoverEvidenceRepository($private))->get($prepared->runId);
                $subscriptionCutover = $evidence->state;
                $subscriptionReleaseRequired = $evidence->requiresSourceRelease();
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() !== 'subscription_cutover_evidence_missing') throw $exception;
            }
        }
        return [
            'descriptor' => $prepared->runId,
            'state' => $state->value,
            'attempt' => $attempt,
            'generation' => $prepared->generation,
            'receipt_counts' => $counts,
            'subscription_cutover_required' => ($counts['subscription'] ?? 0) > 0,
            'subscription_release_required' => $subscriptionReleaseRequired,
            'subscription_cutover_state' => $subscriptionCutover,
            'next_legal_actions' => $this->nextActions($state, $prepared->leaveDraftAccepted, ($counts['subscription'] ?? 0) > 0, $subscriptionCutover),
        ];
    }

    /** @return list<string> */
    private function nextActions(TransferRunState $state, bool $leaveDraft, bool $hasSubscriptions, ?string $subscriptionCutover): array
    {
        if ($hasSubscriptions && in_array($state, [TransferRunState::Promoted, TransferRunState::CatalogueActivating], true)) {
            if ($subscriptionCutover === null) return ['prepare-subscription-cutover'];
            if ($subscriptionCutover === \CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence::PREPARED) return ['release-subscription-source'];
            if (in_array($subscriptionCutover, [
                \CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence::SOURCE_RELEASED,
                \CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidence::TARGET_ACTIVATED,
            ], true)) return ['activate-subscriptions'];
        }
        return match ($state) {
            TransferRunState::Prepared, TransferRunState::Staging, TransferRunState::Interrupted => ['stage'],
            TransferRunState::Staged => ['reconcile'],
            TransferRunState::Reconciled => ['promote'],
            TransferRunState::Promoted => $leaveDraft ? ['complete'] : ['activate-catalogue'],
            TransferRunState::CatalogueActivating => ['complete'],
            TransferRunState::Failed => ['rollback'],
            default => [],
        };
    }
}
