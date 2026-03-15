<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\State\MigrationState;

final class BatchProcessor
{
    private const string HOOK = 'cartshift/migration/process_batch';
    private const string GROUP = 'cartshift';

    /**
     * @param \Closure(): MigrationOrchestrator $orchestratorFactory Builds a fresh orchestrator with current-state migrators.
     */
    public function __construct(
        private readonly \Closure $orchestratorFactory,
        private readonly MigrationState $state,
    ) {
    }

    /**
     * Register the Action Scheduler hook for background batch processing.
     */
    public function register(): void
    {
        add_action(self::HOOK, [$this, 'handleBatch']);
    }

    /**
     * Called by Action Scheduler to process one batch.
     *
     * Guards against stale or cancelled migrations before processing.
     */
    public function handleBatch(string $migrationId): void
    {
        if (!$this->state->isRunning() || $this->state->getMigrationId() !== $migrationId) {
            return;
        }

        $orchestrator = ($this->orchestratorFactory)();
        $result = $orchestrator->processBatch();

        if ($result['continue']) {
            $this->scheduleNext($migrationId);
        }
    }

    /**
     * Schedule the first batch via Action Scheduler.
     */
    public function scheduleFirst(string $migrationId): void
    {
        $this->scheduleNext($migrationId);
    }

    /**
     * Cancel all pending Action Scheduler actions for a migration.
     */
    public function cancel(string $migrationId): void
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::HOOK, [$migrationId], self::GROUP);
        }
    }

    /**
     * Check whether Action Scheduler is available.
     */
    public static function isAvailable(): bool
    {
        return function_exists('as_schedule_single_action');
    }

    /**
     * Schedule the next batch action.
     */
    private function scheduleNext(string $migrationId): void
    {
        if (self::isAvailable()) {
            as_schedule_single_action(time(), self::HOOK, [$migrationId], self::GROUP);
        }
    }
}
