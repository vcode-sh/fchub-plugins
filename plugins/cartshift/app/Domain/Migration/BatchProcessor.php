<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Domain\Transfer\TransferLock;
use CartShift\Domain\Transfer\Legacy\LegacyCommandPolicy;
use CartShift\State\MigrationState;

/**
 * Background batch runner and the single source of truth for batch mutual exclusion.
 *
 * Two callers drive a migration: the REST endpoint POST /migrate/batch (foreground,
 * driven by the browser) and this class's Action Scheduler handler (background).
 * Both funnel through the same advisory lock so a batch is never processed twice
 * from the same offset.
 */
final class BatchProcessor
{
    private const string HOOK = 'cartshift/migration/process_batch';
    private const string GROUP = 'cartshift';

    /** Seconds to wait before retrying a background batch that lost the lock race. */
    private const int LOCK_RETRY_DELAY = 10;

    private static ?TransferLock $activeLock = null;

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

    public static function hookName(): string
    {
        return self::HOOK;
    }

    /**
     * Called by Action Scheduler to process one batch.
     *
     * Guards against stale or cancelled migrations before processing, then takes
     * the batch lock so it can never overlap a foreground REST batch.
     */
    public function handleBatch(string $migrationId): void
    {
        // The legacy action carries only a migration ID. It cannot present the
        // v2 prepared descriptor, active lease, generation or mutex context, so
        // it is structurally incapable of resuming a transfer safely.
        (new LegacyCommandPolicy())->refusal('action:' . self::HOOK);
        return;

        if (!$this->state->isRunning() || $this->state->getMigrationId() !== $migrationId) {
            return;
        }

        if (!self::acquireLock()) {
            // Someone else is mid-batch. Come back shortly rather than replaying
            // the same offset behind their back.
            $this->scheduleNext($migrationId, self::LOCK_RETRY_DELAY);

            return;
        }

        try {
            $orchestrator = ($this->orchestratorFactory)();
            $result = $orchestrator->processBatch();
        } finally {
            self::releaseLock();
        }

        if ($result['continue']) {
            $this->scheduleNext($migrationId);
        }
    }

    /**
     * Schedule the first batch via Action Scheduler.
     */
    public function scheduleFirst(string $migrationId): void
    {
        (new LegacyCommandPolicy())->refusal('action:' . self::HOOK);
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
     * Whether a background batch is still queued for this migration.
     *
     * This is the honest answer to "is anything still working on it?" — far more
     * useful than the stored status, which stays 'running' forever if the process
     * driving it went away.
     */
    public function hasPendingActions(string $migrationId): bool
    {
        if ($migrationId === '' || !function_exists('as_has_scheduled_action')) {
            return false;
        }

        return (bool) as_has_scheduled_action(self::HOOK, [$migrationId], self::GROUP);
    }

    /**
     * Check whether Action Scheduler is available.
     */
    public static function isAvailable(): bool
    {
        return function_exists('as_schedule_single_action');
    }

    /**
     * Try to take the batch lock without waiting.
     *
     * Uses a MySQL advisory lock rather than a transient on purpose: GET_LOCK is
     * held by the database connection, so a PHP fatal, a request timeout or a
     * killed worker releases it the moment the connection drops. A transient
     * would need a TTL guess and would leave the migration wedged for exactly as
     * long as that guess was wrong. The timeout is 0, so a well-behaved single
     * client loop never waits and never deadlocks.
     */
    public static function acquireLock(): bool
    {
        if (self::$activeLock !== null) {
            return false;
        }

        $lock = new TransferLock();

        try {
            $lock->acquireTargetMutex(self::targetFingerprint());
        } catch (\RuntimeException) {
            return false;
        }

        self::$activeLock = $lock;

        return true;
    }

    /**
     * Release the batch lock. Safe to call when the lock is not held.
     */
    public static function releaseLock(): void
    {
        if (self::$activeLock === null) {
            return;
        }

        self::$activeLock->release();
        self::$activeLock = null;
    }

    /**
     * Advisory lock name, scoped to this database and table prefix.
     *
     * MySQL advisory locks are server-wide, not per-schema, so two WordPress
     * installs sharing a database server must not share a lock name. Hashed to
     * stay inside the 64-character limit.
     */
    public static function lockName(): string
    {
        return TransferLock::nameFor(self::targetFingerprint());
    }

    public static function targetFingerprint(): string
    {
        global $wpdb;

        $scope = (defined('DB_NAME') ? (string) DB_NAME : '')
            . '|' . (is_object($wpdb) ? (string) $wpdb->prefix : '');

        return hash('sha256', $scope);
    }

    /**
     * Schedule the next batch action.
     */
    private function scheduleNext(string $migrationId, int $delay = 0): void
    {
        if (self::isAvailable()) {
            as_schedule_single_action(time() + $delay, self::HOOK, [$migrationId], self::GROUP);
        }
    }
}
