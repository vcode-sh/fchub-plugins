<?php

declare(strict_types=1);

namespace CartShift\Http\Controllers;

defined('ABSPATH') || exit;

use CartShift\Core\Container;
use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use WP_REST_Request;
use WP_REST_Response;

final class MigrationController
{
    private const string NAMESPACE = 'cartshift/v1';

    public function __construct(
        private readonly Container $container,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/migrate', [
            'methods'             => 'POST',
            'callback'            => [$this, 'migrate'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/retry', [
            'methods'             => 'POST',
            'callback'            => [$this, 'retry'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'migration_id' => [
                    'type'              => 'string',
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'entity_types' => [
                    'type'    => 'array',
                    'default' => [],
                ],
                'statuses' => [
                    'type'    => 'array',
                    'default' => ['error'],
                ],
                'dry_run' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
                'background' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/migrate/batch', [
            'methods'             => 'POST',
            'callback'            => [$this, 'batch'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/progress', [
            'methods'             => 'GET',
            'callback'            => [$this, 'progress'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/cancel', [
            'methods'             => 'POST',
            'callback'            => [$this, 'cancel'],
            'permission_callback' => [$this, 'checkPermission'],
        ]);

        register_rest_route(self::NAMESPACE, '/reset', [
            'methods'             => 'POST',
            'callback'            => [$this, 'reset'],
            'permission_callback' => [$this, 'checkPermission'],
            'args'                => [
                'force' => [
                    'type'    => 'boolean',
                    'default' => false,
                ],
            ],
        ]);
    }

    /**
     * POST /migrate — initialise a migration run.
     *
     * The first batch always runs inline, so the caller gets immediate proof that
     * the run works and the response carries real counters. What happens next
     * depends on the opt-in `background` parameter:
     *
     *  - background off (default): nothing else is scheduled. The browser drives
     *    the run by calling POST /migrate/batch in a loop, which is what gives
     *    the admin live progress.
     *  - background on: the remaining batches are handed to Action Scheduler and
     *    the run survives the tab being closed. The UI then polls GET /progress.
     *
     * If background was asked for but Action Scheduler is missing, the response
     * says `background: false` and the caller falls back to the foreground loop.
     */
    public function migrate(WP_REST_Request $request): WP_REST_Response
    {
        $entityTypes = $request->get_param('entity_types') ?? [];
        $dryRun = (bool) $request->get_param('dry_run');
        $background = (bool) $request->get_param('background');

        if (empty($entityTypes) || !is_array($entityTypes)) {
            return new WP_REST_Response(
                ['data' => ['message' => 'entity_types is required and must be a non-empty array.']],
                422,
            );
        }

        // M1: Prevent concurrent migrations.
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);
        if ($state->isRunning()) {
            return new WP_REST_Response(
                [
                    'data' => [
                        'message'  => 'A migration is already in progress. Resume it, cancel it, or reset the migration state before starting a new one.',
                        'progress' => $this->progressPayload(),
                    ],
                ],
                409,
            );
        }

        // L1: Whitelist entity types against known constants.
        $entityTypes = self::whitelistEntityTypes($entityTypes);

        if (empty($entityTypes)) {
            return new WP_REST_Response(
                ['data' => ['message' => 'No valid entity types specified.']],
                422,
            );
        }

        $orchestrator = $this->buildOrchestrator();
        $result = $orchestrator->startMigration($entityTypes, $dryRun);

        $result['background'] = false;
        $result['background_available'] = BatchProcessor::isAvailable();

        if ($background && $result['continue']) {
            $migrationId = (string) ($result['migration_id'] ?? '');

            if ($migrationId !== '' && BatchProcessor::isAvailable()) {
                $this->batchProcessor()->scheduleFirst($migrationId);
                $result['background'] = true;
            }
        }

        return new WP_REST_Response(['data' => $result]);
    }

    /**
     * POST /retry — re-run only the records a previous migration did not get right.
     *
     * The alternative, today, is re-running the whole migration and relying on the
     * id map to skip what already worked: correct, but it walks every record in
     * the shop to find the four hundred that failed. Retry asks the log which ids
     * are outstanding and runs those.
     *
     * `statuses` is what makes it usable. The default is `error`, but warnings are
     * frequently the interesting ones — a subscription logged as a warning because
     * its product was not mapped yet is a real gap in the migrated data, and until
     * now there was no way to act on it in bulk.
     *
     * Everything after the first batch is unchanged: a retry run is an ordinary
     * run with a narrower work list, driven by the same POST /migrate/batch loop
     * or the same Action Scheduler queue.
     */
    public function retry(WP_REST_Request $request): WP_REST_Response
    {
        $sourceMigrationId = trim((string) ($request->get_param('migration_id') ?? ''));
        $dryRun = (bool) $request->get_param('dry_run');
        $background = (bool) $request->get_param('background');

        if ($sourceMigrationId === '') {
            return new WP_REST_Response(
                ['data' => ['message' => 'migration_id is required.']],
                422,
            );
        }

        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        // Same guard as /migrate, and for the same reason: two runs sharing one
        // stored state would overwrite each other's offsets and migrate records twice.
        if ($state->isRunning()) {
            return new WP_REST_Response(
                [
                    'data' => [
                        'message'  => 'A migration is already in progress. Resume it, cancel it, or reset the migration state before starting a retry.',
                        'progress' => $this->progressPayload(),
                    ],
                ],
                409,
            );
        }

        /** @var MigrationLogRepository $log */
        $log = $this->container->get(MigrationLogRepository::class);

        // A typo'd id would otherwise start a run with nothing to do and report
        // success, which reads exactly like "there was nothing wrong".
        if (!$log->hasEntries($sourceMigrationId)) {
            return new WP_REST_Response(
                [
                    'data' => [
                        'message'      => sprintf('No log entries found for migration %s. Nothing to retry.', $sourceMigrationId),
                        'migration_id' => $sourceMigrationId,
                    ],
                ],
                404,
            );
        }

        $requestedTypes = $request->get_param('entity_types');
        $requestedTypes = is_array($requestedTypes) ? $requestedTypes : [];

        // Whitelisted exactly as /migrate does. Empty is not an error here: it
        // means "every entity type that has something outstanding", which is the
        // sensible default for a retry and is what the orchestrator resolves.
        $entityTypes = self::whitelistEntityTypes($requestedTypes);

        if ($requestedTypes !== [] && $entityTypes === []) {
            return new WP_REST_Response(
                ['data' => ['message' => 'No valid entity types specified.']],
                422,
            );
        }

        $statuses = self::whitelistStatuses($request->get_param('statuses'));

        if ($statuses === []) {
            return new WP_REST_Response(
                [
                    'data' => [
                        'message'  => 'No retryable statuses specified.',
                        'statuses' => MigrationLogRepository::RETRYABLE_STATUSES,
                    ],
                ],
                422,
            );
        }

        $orchestrator = $this->buildOrchestrator();

        if (!method_exists($orchestrator, 'startRetry')) {
            return new WP_REST_Response(
                [
                    'data' => [
                        'message' => 'Retry is not available in this build — the migration orchestrator does not support it.',
                    ],
                ],
                503,
            );
        }

        $result = $this->invokeRetry($orchestrator, $sourceMigrationId, $entityTypes, $statuses, $dryRun);

        $result['background'] = false;
        $result['background_available'] = BatchProcessor::isAvailable();

        if ($background && ($result['continue'] ?? false)) {
            $migrationId = (string) ($result['migration_id'] ?? '');

            if ($migrationId !== '' && BatchProcessor::isAvailable()) {
                $this->batchProcessor()->scheduleFirst($migrationId);
                $result['background'] = true;
            }
        }

        return new WP_REST_Response(['data' => $result]);
    }

    /**
     * POST /migrate/batch — process the next batch.
     *
     * Held under the shared batch lock so two overlapping requests (a double
     * click, a duplicated tab, a background action landing mid-loop) cannot read
     * the same current_offset and migrate the same records twice.
     */
    public function batch(WP_REST_Request $request): WP_REST_Response
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        if (!$state->isRunning()) {
            return new WP_REST_Response(
                ['data' => ['continue' => false, 'message' => 'No migration is currently running.']],
                422,
            );
        }

        if (!BatchProcessor::acquireLock()) {
            return new WP_REST_Response(
                [
                    'data' => [
                        'continue' => true,
                        'locked'   => true,
                        'message'  => 'Another batch is already being processed. Try again in a moment.',
                        'progress' => $this->progressPayload(),
                    ],
                ],
                409,
            );
        }

        try {
            $orchestrator = $this->buildOrchestrator();
            $result = $orchestrator->processBatch();
        } finally {
            BatchProcessor::releaseLock();
        }

        return new WP_REST_Response(['data' => $result]);
    }

    public function progress(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response(['data' => $this->progressPayload()]);
    }

    public function cancel(WP_REST_Request $request): WP_REST_Response
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        $migrationId = $state->getMigrationId();
        $state->cancel();

        // Cancel any pending Action Scheduler actions for this migration.
        if ($migrationId && BatchProcessor::isAvailable()) {
            $this->batchProcessor()->cancel($migrationId);
        }

        return new WP_REST_Response([
            'data' => ['message' => 'Migration cancellation requested.'],
        ]);
    }

    /**
     * POST /reset — forget the current migration run.
     *
     * Reset and rollback are different tools and it matters which one you reach
     * for. Reset clears the stored state and drops any queued background batches
     * so a new run can start; every record already written to FluentCart, and
     * every id-map row pointing at it, stays exactly where it is. Deleting those
     * records is rollback's job (POST /rollback with the migration_id).
     *
     * The escape hatch for a run whose browser tab was closed — but not a way to
     * stampede a run that is genuinely still working, which needs force=true.
     */
    public function reset(WP_REST_Request $request): WP_REST_Response
    {
        $force = (bool) $request->get_param('force');

        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        $progress = $state->getProgress();
        $previousStatus = (string) ($progress['status'] ?? 'idle');
        $migrationId = $state->getMigrationId();

        if ($previousStatus === 'idle') {
            return new WP_REST_Response([
                'data' => [
                    'message'              => 'There is no migration state to reset.',
                    'cleared'              => false,
                    'cleared_migration_id' => null,
                    'previous_status'      => 'idle',
                    'progress'             => ['status' => 'idle'],
                ],
            ]);
        }

        if (!$force && $previousStatus === 'running' && $this->looksAlive($migrationId)) {
            return new WP_REST_Response(
                [
                    'data' => [
                        'message'         => 'This migration is still alive — a batch is running right now, or background batches are queued. Let it finish, cancel it first, or send force=true to clear the state regardless.',
                        'cleared'         => false,
                        'alive'           => true,
                        'previous_status' => $previousStatus,
                        'progress'        => $this->progressPayload(),
                    ],
                ],
                409,
            );
        }

        if ($migrationId !== null && $migrationId !== '' && BatchProcessor::isAvailable()) {
            $this->batchProcessor()->cancel($migrationId);
        }

        // A dry run's ID-map rows exist only to carry references between its own
        // batches. Forgetting the run has to forget them too, or an abandoned
        // rehearsal leaves rows behind with nothing left to clear them. Real rows
        // are untouched: reset forgets a run, rollback unpicks one.
        $idMap = $this->container->get(IdMapRepository::class);
        $idMap->purgeSimulated();

        $state->reset();

        return new WP_REST_Response([
            'data' => [
                'message'              => $this->resetMessage($previousStatus, $migrationId),
                'cleared'              => true,
                'cleared_migration_id' => $migrationId,
                'previous_status'      => $previousStatus,
                'progress'             => $state->getProgress(),
            ],
        ]);
    }

    public function checkPermission(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Current state plus the two facts the stored state cannot tell you:
     * whether background processing is possible at all, and whether background
     * batches are actually still queued for this run.
     *
     * @return array<string, mixed>
     */
    private function progressPayload(): array
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);

        $progress = $state->getProgress();
        $migrationId = (string) ($progress['migration_id'] ?? '');

        $progress['background_available'] = BatchProcessor::isAvailable();
        $progress['background_pending'] = ($progress['status'] ?? '') === 'running'
            && $this->batchProcessor()->hasPendingActions($migrationId);

        return $progress;
    }

    /**
     * Whether a migration marked 'running' is genuinely still being worked on.
     *
     * Two signals, both live rather than stored: a queued background action, or a
     * batch lock we cannot take because someone is holding it this instant.
     */
    private function looksAlive(?string $migrationId): bool
    {
        if ($migrationId !== null && $migrationId !== '' && $this->batchProcessor()->hasPendingActions($migrationId)) {
            return true;
        }

        if (!BatchProcessor::acquireLock()) {
            return true;
        }

        BatchProcessor::releaseLock();

        return false;
    }

    private function resetMessage(string $previousStatus, ?string $migrationId): string
    {
        $base = sprintf(
            'Migration state cleared (was: %s). Reset only forgets the run — records already written to FluentCart and their id-map entries are untouched.',
            $previousStatus,
        );

        if ($migrationId === null || $migrationId === '') {
            return $base . ' Use rollback if you also want those records deleted.';
        }

        return $base . sprintf(' To delete those records, roll back migration %s.', $migrationId);
    }

    /**
     * Entity types the migration will accept, in the caller's order.
     *
     * Shared by /migrate and /retry so there is one list to keep honest rather
     * than two that drift.
     *
     * @param array<int, mixed> $requested
     *
     * @return list<string>
     */
    public static function whitelistEntityTypes(array $requested): array
    {
        $allowed = [
            Constants::ENTITY_PRODUCT,
            Constants::ENTITY_CUSTOMER,
            Constants::ENTITY_COUPON,
            Constants::ENTITY_ORDER,
            Constants::ENTITY_SUBSCRIPTION,
        ];

        return array_values(array_intersect($requested, $allowed));
    }

    /**
     * Statuses a retry may re-attempt, from whatever the request sent.
     *
     * Accepts a comma-separated string as well as an array — the REST layer will
     * hand over an array, but a hand-rolled curl or the CLI will not.
     *
     * @return list<string>
     */
    public static function whitelistStatuses(mixed $requested): array
    {
        if (is_string($requested)) {
            $requested = explode(',', $requested);
        }

        if (!is_array($requested) || $requested === []) {
            return ['error'];
        }

        $requested = array_map(
            static fn (mixed $status): string => trim((string) $status),
            $requested,
        );

        return array_values(array_intersect(
            array_unique($requested),
            MigrationLogRepository::RETRYABLE_STATUSES,
        ));
    }

    /**
     * Call startRetry() and stamp the response with what the caller asked for.
     *
     * The dry-run flag goes straight through. It used to be offered only if
     * reflection said the signature had room for it, which was a stopgap for a
     * signature that had not grown the parameter yet; it has, so the check is
     * gone. `dry_run` in the response is the resolved value the run is actually
     * using, which is what the UI reads back to decide whether to show the DRY
     * RUN badge and whether to offer finalize.
     *
     * @param list<string> $entityTypes
     * @param list<string> $statuses
     *
     * @return array<string, mixed>
     */
    private function invokeRetry(
        MigrationOrchestrator $orchestrator,
        string $sourceMigrationId,
        array $entityTypes,
        array $statuses,
        bool $dryRun,
    ): array {
        $result = $orchestrator->startRetry($sourceMigrationId, $entityTypes, $statuses, $dryRun);

        $result['retry_of'] ??= $sourceMigrationId;
        $result['statuses'] = $statuses;
        $result['dry_run'] = $dryRun;

        return $result;
    }

    private function batchProcessor(): BatchProcessor
    {
        /** @var BatchProcessor $processor */
        $processor = $this->container->get(BatchProcessor::class);

        return $processor;
    }

    /**
     * Build the MigrationOrchestrator with all registered migrators.
     */
    private function buildOrchestrator(): MigrationOrchestrator
    {
        /** @var MigrationState $state */
        $state = $this->container->get(MigrationState::class);
        /** @var IdMapRepository $idMap */
        $idMap = $this->container->get(IdMapRepository::class);
        /** @var MigrationLogRepository $log */
        $log = $this->container->get(MigrationLogRepository::class);

        $migrators = [
            new ProductMigrator($idMap, $log, $state),
            new CustomerMigrator($idMap, $log, $state),
            new CouponMigrator($idMap, $log, $state),
            new OrderMigrator($idMap, $log, $state),
            new SubscriptionMigrator($idMap, $log, $state),
        ];

        return new MigrationOrchestrator($migrators, $state, $idMap, $log);
    }
}
