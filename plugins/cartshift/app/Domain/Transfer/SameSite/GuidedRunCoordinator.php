<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

defined('ABSPATH') || exit;

/** Advances exactly one durable guided step per request. */
final readonly class GuidedRunCoordinator
{
    /** @var \Closure(GuidedRunState): GuidedRunPlan */
    private \Closure $plan;

    /** @var \Closure(GuidedStep): array<string, mixed> */
    private \Closure $runStep;

    /** @var \Closure(): GuidedRunState */
    private \Closure $initialState;

    /** @var null|\Closure(GuidedRunState): bool */
    private ?\Closure $resumeForward;

    /**
     * @param callable(GuidedRunState): GuidedRunPlan $plan
     * @param callable(GuidedStep): array<string, mixed> $runStep
     * @param callable(): GuidedRunState $initialState
     * @param null|callable(GuidedRunState): bool $resumeForward
     */
    public function __construct(
        private GuidedRunStateRepository $repository,
        callable $plan,
        callable $runStep,
        callable $initialState,
        ?callable $resumeForward = null,
    ) {
        $this->plan = $plan(...);
        $this->runStep = $runStep(...);
        $this->initialState = $initialState(...);
        $this->resumeForward = $resumeForward === null ? null : $resumeForward(...);
    }

    public function start(bool $renewalsPaused = false): GuidedRunState
    {
        return $this->repository->transaction(function (?GuidedRunState $current) use ($renewalsPaused): GuidedRunState {
            $confirmedPause = false;
            if ($current === null || in_array(
                $current->phase,
                [GuidedRunState::CANCELLED, GuidedRunState::ROLLED_BACK],
                true,
            ) || $current->canRestart() || $current->canReplaceBeforeTarget()) {
                $current = ($this->initialState)();
            } elseif ($current->phase === GuidedRunState::FAILED
                && $this->resumeForward !== null
                && ($this->resumeForward)($current)) {
                $current = $current->resumeForward();
                $confirmedPause = true;
            }
            if ($renewalsPaused) {
                if ($current->phase !== GuidedRunState::AWAITING_RENEWAL_PAUSE) {
                    throw new \LogicException('guided_renewal_pause_confirmation_unexpected');
                }
                $current = $current->afterRenewalsPaused();
                $confirmedPause = true;
            }

            return $this->advanceOne($current, $confirmedPause);
        });
    }

    public function confirmRenewalsPaused(): GuidedRunState
    {
        return $this->start(true);
    }

    /** @param array<string, mixed> $acceptance */
    public function recordDecisionAcceptance(array $acceptance): GuidedRunState
    {
        return $this->repository->transaction(function (?GuidedRunState $state) use ($acceptance): GuidedRunState {
            if (!$state instanceof GuidedRunState) {
                throw new \RuntimeException('guided_run_missing');
            }
            $steps = ($this->plan)($state)->steps();

            return $state->afterDecisionAcceptance($acceptance, count($steps));
        });
    }

    public function cancel(): GuidedRunState
    {
        return $this->repository->transaction(function (?GuidedRunState $state): GuidedRunState {
            if (!$state instanceof GuidedRunState) {
                throw new \RuntimeException('guided_run_missing');
            }

            return $state->cancel();
        });
    }

    private function advanceOne(GuidedRunState $state, bool $renewalsPaused = false): GuidedRunState
    {
        if ($state->isTerminal() || in_array($state->phase, [
            GuidedRunState::AWAITING_DECISIONS,
            GuidedRunState::AWAITING_RENEWAL_PAUSE,
        ], true)) {
            return $state;
        }
        $steps = ($this->plan)($state)->steps();
        $step = $steps[$state->nextStep] ?? null;
        if (!$step instanceof GuidedStep) {
            throw new \LogicException('guided_run_step_missing');
        }
        if ($step->verb === 'release-subscription-source' && $renewalsPaused) {
            $step = new GuidedStep(
                $step->verb,
                $step->arguments + ['renewals-paused' => true],
                $step->pending,
            );
        }
        try {
            $result = ($this->runStep)($step);

            return $state->afterStep($step->verb, $result, count($steps));
        } catch (\Throwable $failure) {
            return $state->afterFailure($step->verb, $failure);
        }
    }
}
