<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Execution\FilesystemSagaRepository;
use CartShift\Domain\Transfer\Execution\LoadedRollbackTargetGateway;
use CartShift\Domain\Transfer\Execution\LoadedTargetTransferPipeline;
use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\RollbackPlan;
use CartShift\Domain\Transfer\Execution\RollbackPlanner;
use CartShift\Domain\Transfer\Execution\RollbackPlanRepository;
use CartShift\Domain\Transfer\Execution\TransferJournalRepository;
use CartShift\Domain\Transfer\Execution\TransferRunState;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverEvidenceRepository;
use CartShift\Domain\Transfer\Subscription\SubscriptionRollbackGate;

defined('ABSPATH') || exit;

/** Builds, previews, seals and dispatches a rollback for a failed guided rehearsal. */
final readonly class GuidedRollback
{
    /** @var null|\Closure(): RollbackPlan */
    private ?\Closure $plan;

    /** @var null|\Closure(array<string, mixed>): array<string, mixed> */
    private ?\Closure $execute;

    /**
     * @param null|callable(): RollbackPlan $plan
     * @param null|callable(array<string, mixed>): array<string, mixed> $execute
     */
    public function __construct(
        private string $workspace,
        private GuidedRunState $state,
        ?callable $plan = null,
        ?callable $execute = null,
    ) {
        $this->plan = $plan === null ? null : $plan(...);
        $this->execute = $execute === null ? null : $execute(...);
    }

    /** @return array{safe:bool,deletion_count:int,source_identities:list<string>,conflicts:list<string>,confirm:string} */
    public function preview(): array
    {
        $plan = $this->currentPlan();

        return [
            'safe' => $plan->safe,
            'deletion_count' => count($plan->deletions),
            'source_identities' => array_column($plan->deletions, 'source_identity'),
            'conflicts' => $plan->conflicts,
            'confirm' => $plan->fingerprint(),
        ];
    }

    /** @return array<string, mixed> */
    public function execute(string $confirmation): array
    {
        return $this->executeSealed($this->seal($confirmation));
    }

    /** @return array{rollback_plan:string,rollback_plan_fingerprint:string,lease_recovery:string,deletion_count:int} */
    public function seal(string $confirmation): array
    {
        $plan = $this->currentPlan();
        if (!$plan->safe) {
            throw new \RuntimeException('guided_rollback_conflicted');
        }
        if (!hash_equals($plan->fingerprint(), $confirmation)) {
            throw new \RuntimeException('guided_rollback_confirmation_changed');
        }
        $path = (new RollbackPlanRepository($this->workspace))->save($plan);
        $recovery = hash_file('sha256', $path);
        if (!is_string($recovery)) {
            throw new \RuntimeException('guided_rollback_recovery_evidence_failed');
        }

        return [
            'rollback_plan' => $path,
            'rollback_plan_fingerprint' => $plan->fingerprint(),
            'lease_recovery' => $recovery,
            'deletion_count' => count($plan->deletions),
        ];
    }

    /** @param array<string,mixed> $sealed @return array<string,mixed> */
    public function executeSealed(array $sealed): array
    {
        $this->assertAvailable();
        $path = $sealed['rollback_plan'] ?? null;
        $fingerprint = $sealed['rollback_plan_fingerprint'] ?? null;
        $recovery = $sealed['lease_recovery'] ?? null;
        if (!is_string($path) || !is_string($fingerprint) || !is_string($recovery)) {
            throw new \RuntimeException('guided_rollback_evidence_invalid');
        }
        $plan = (new RollbackPlanRepository($this->workspace))->get($path);
        $recoveryCurrent = hash_file('sha256', $path);
        if (!hash_equals($plan->fingerprint(), $fingerprint)
            || !is_string($recoveryCurrent)
            || !hash_equals($recovery, $recoveryCurrent)
            || $plan->runId !== $this->state->evidence->descriptor) {
            throw new \RuntimeException('guided_rollback_evidence_invalid');
        }
        $input = [
            'command' => 'rollback',
            'package' => $this->state->evidence->packagePath,
            'descriptor' => $this->state->evidence->descriptor,
            'confirm' => $this->state->evidence->selectionFingerprint,
            'execution_context' => 'guided',
            'rollback_plan' => $path,
            'rollback_plan_fingerprint' => $fingerprint,
            'lease_recovery' => $recovery,
        ];
        if ($this->execute !== null) {
            return ($this->execute)($input);
        }
        $prepared = (new PreparedTransferRepository($this->workspace))->get((string) $this->state->evidence->descriptor);
        $journal = new TransferJournalRepository(new PreparedTransferRepository($this->workspace));
        $pipeline = LoadedTargetTransferPipeline::create();
        $pipeline->prepareGuidedRollback($prepared, $journal, $this->workspace);
        $state = $journal->state($prepared->runId);
        if ($state === TransferRunState::RolledBack) {
            return ['state' => GuidedRunState::ROLLED_BACK];
        }
        if (!in_array($state, [TransferRunState::Failed, TransferRunState::RollingBack], true)) {
            throw new \RuntimeException('guided_rollback_unavailable');
        }
        return $pipeline($input);
    }

    private function currentPlan(): RollbackPlan
    {
        $this->assertAvailable();
        $plan = $this->plan !== null ? ($this->plan)() : $this->loadedPlan();
        if ($plan->runId !== $this->state->evidence->descriptor) {
            throw new \RuntimeException('guided_rollback_descriptor_changed');
        }

        return $plan;
    }

    private function loadedPlan(): RollbackPlan
    {
        $descriptors = new PreparedTransferRepository($this->workspace);
        $prepared = $descriptors->get((string) $this->state->evidence->descriptor);
        $journal = new TransferJournalRepository($descriptors);
        $gateway = new LoadedRollbackTargetGateway(
            $prepared->sourceKey,
            new FilesystemSagaRepository($this->workspace),
        );

        return (new RollbackPlanner())->plan(
            $prepared->runId,
            $prepared->generation,
            $journal->receipts($prepared->runId),
            $gateway,
        );
    }

    private function assertAvailable(): void
    {
        if (!in_array($this->state->phase, [GuidedRunState::FAILED, GuidedRunState::ROLLING_BACK], true)
            || $this->state->evidence->selectionFingerprint === null
            || $this->state->evidence->packagePath === null
            || $this->state->evidence->descriptor === null) {
            throw new \RuntimeException('guided_rollback_unavailable');
        }
        if ($this->state->includesSubscriptions) {
            (new SubscriptionRollbackGate(new SubscriptionCutoverEvidenceRepository($this->workspace)))
                ->assertAllowed($this->state->evidence->descriptor);
        }
    }
}
