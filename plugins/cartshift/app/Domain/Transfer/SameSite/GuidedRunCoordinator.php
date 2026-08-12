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

    /**
     * @param callable(GuidedRunState): GuidedRunPlan $plan
     * @param callable(GuidedStep): array<string, mixed> $runStep
     * @param callable(): GuidedRunState $initialState
     */
    public function __construct(
        private GuidedRunStateRepository $repository,
        callable $plan,
        callable $runStep,
        callable $initialState,
    ) {
        $this->plan = $plan(...);
        $this->runStep = $runStep(...);
        $this->initialState = $initialState(...);
    }

    public function start(): GuidedRunState
    {
        return $this->repository->transaction(function (?GuidedRunState $current): GuidedRunState {
            if ($current === null || in_array(
                $current->phase,
                [GuidedRunState::CANCELLED, GuidedRunState::ROLLED_BACK],
                true,
            ) || $current->canRestart()) {
                $current = ($this->initialState)();
            }

            return $this->advanceOne($current);
        });
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

    private function advanceOne(GuidedRunState $state): GuidedRunState
    {
        if ($state->isTerminal() || $state->phase === GuidedRunState::AWAITING_DECISIONS) {
            return $state;
        }
        $steps = ($this->plan)($state)->steps();
        $step = $steps[$state->nextStep] ?? null;
        if (!$step instanceof GuidedStep) {
            throw new \LogicException('guided_run_step_missing');
        }
        try {
            $result = ($this->runStep)($step);

            return $state->afterStep($step->verb, $result, count($steps));
        } catch (\Throwable $failure) {
            return $state->afterFailure($step->verb, $failure);
        }
    }
}
