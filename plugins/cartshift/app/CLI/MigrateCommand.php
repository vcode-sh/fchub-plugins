<?php

declare(strict_types=1);

namespace CartShift\CLI;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Domain\Migration\MigrationRollback;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;

final class MigrateCommand
{
    /**
     * Default entity migration order — dependencies first.
     *
     * @var string[]
     */
    private const array DEFAULT_ENTITY_ORDER = [
        Constants::ENTITY_PRODUCT,
        Constants::ENTITY_CUSTOMER,
        Constants::ENTITY_COUPON,
        Constants::ENTITY_ORDER,
        Constants::ENTITY_SUBSCRIPTION,
    ];

    /**
     * Register all CartShift WP-CLI commands.
     */
    public static function register(): void
    {
        \WP_CLI::add_command('cartshift migrate', [self::class, 'migrate']);
        \WP_CLI::add_command('cartshift retry', [self::class, 'retry']);
        \WP_CLI::add_command('cartshift rollback', [self::class, 'rollback']);
        \WP_CLI::add_command('cartshift reset', [self::class, 'reset']);
        \WP_CLI::add_command('cartshift status', [self::class, 'status']);
        \WP_CLI::add_command('cartshift log', [self::class, 'log']);
        \WP_CLI::add_command('cartshift finalize', [self::class, 'finalize']);
    }

    /**
     * Run the WooCommerce to FluentCart migration.
     *
     * ## OPTIONS
     *
     * [--entities=<entities>]
     * : Comma-separated list of entity types to migrate.
     * ---
     * default: all
     * ---
     *
     * [--batch-size=<size>]
     * : Number of records to process per batch.
     * ---
     * default: 50
     * ---
     *
     * [--dry-run]
     * : Log what would happen without creating any records.
     *
     * [--background]
     * : Hand the remaining batches to Action Scheduler and return immediately.
     * The run then continues without this process. Track it with
     * `wp cartshift status`. Note that --batch-size only applies to the first
     * batch, which still runs here; background batches use the site default.
     *
     * [--since=<date>]
     * : Only migrate records touched on or after this date (Y-m-d). Combining
     * this with --products or --customers is a contradiction and refused.
     *
     * [--products=<ids>]
     * : Comma-separated WooCommerce product IDs to migrate explicitly. Orders
     * and customers are not pulled in automatically — pair with a scope that
     * needs them, or accept that only the named products travel.
     *
     * [--customers=<ids>]
     * : Comma-separated WooCommerce customer IDs to migrate explicitly, along
     * with their orders and subscriptions.
     *
     * ## EXAMPLES
     *
     *     wp cartshift migrate
     *     wp cartshift migrate --entities=product,customer
     *     wp cartshift migrate --batch-size=100 --dry-run
     *     wp cartshift migrate --background
     *     wp cartshift migrate --since=2024-01-01
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function migrate(array $args, array $assocArgs): void
    {
        $startTime = microtime(true);

        $entityTypes = self::resolveEntityTypes($assocArgs);
        $batchSize = (int) ($assocArgs['batch-size'] ?? Constants::DEFAULT_BATCH_SIZE);
        $dryRun = \WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false);
        $background = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'background', false);

        if ($batchSize < 1) {
            \WP_CLI::error('Batch size must be at least 1.');
        }

        if (empty($entityTypes)) {
            \WP_CLI::error('No valid entity types specified.');
        }

        if ($background && !BatchProcessor::isAvailable()) {
            \WP_CLI::error(
                'Background processing needs Action Scheduler, which ships with both WooCommerce and FluentCart. '
                . 'Neither appears to be loaded, so there is nothing to queue batches with.',
            );
        }

        if (self::scopeFlagsContradict($assocArgs)) {
            \WP_CLI::error(
                '--since cannot be combined with --products or --customers — pick a date-based '
                . 'scope or an explicit one, not both.',
            );

            return;
        }

        $scope = self::resolveScope($assocArgs);

        // Checked here, before a migration id exists, so a refusal leaves no
        // half-started run behind. Refusing is the point: truncating a closure
        // would migrate a subset of what the owner confirmed.
        if ((new ScopeResolver($scope))->exceedsClosureLimit()) {
            \WP_CLI::error(
                'Selection is too large — narrow it (fewer products or customers, or --since instead), '
                . 'then try again. Nothing was migrated.',
            );

            return;
        }

        $idMap = new IdMapRepository();
        $log = new MigrationLogRepository();
        $state = new MigrationState();

        if ($state->isRunning()) {
            \WP_CLI::error(
                'A migration is already in progress. Run `wp cartshift status` for details, '
                . 'or `wp cartshift reset` if the run is stale.',
            );
        }

        if ($dryRun) {
            \WP_CLI::log('Dry run — no records will be created.');
        }

        if ($background && isset($assocArgs['batch-size'])) {
            \WP_CLI::warning('--batch-size applies to the first batch only; background batches use the site default.');
        }

        \WP_CLI::log(sprintf(
            'Starting migration for: %s (batch size: %d)',
            implode(', ', $entityTypes),
            $batchSize,
        ));

        add_filter('cartshift/migration/batch_size', fn(): int => $batchSize, 99);

        $migrators = self::buildMigrators($entityTypes, $idMap, $log, $state, $batchSize);

        $orchestrator = new MigrationOrchestrator($migrators, $state, $idMap, $log);

        $result = $orchestrator->startMigration($entityTypes, $dryRun, $scope);

        if ($background) {
            self::handoffToBackground($state, $result, $startTime);

            return;
        }

        self::driveForeground($orchestrator, $state, $result, $entityTypes, $startTime);
    }

    /**
     * Drive a run to completion in this process, with progress bars and a summary.
     *
     * Shared by `migrate` and `retry` because they differ only in how the first
     * batch was kicked off — after that both are the same loop over
     * processBatch(), and two copies of a hundred lines of progress-bar
     * bookkeeping is two places for the counters to drift apart.
     *
     * @param array<string, mixed> $result      Result of the first batch.
     * @param string[]             $entityTypes Entity types to report on.
     */
    private static function driveForeground(
        MigrationOrchestrator $orchestrator,
        MigrationState $state,
        array $result,
        array $entityTypes,
        float $startTime,
        string $label = 'Migration',
    ): void {
        /** @var array<string, \cli\progress\Bar|null> $progressBars */
        $progressBars = [];
        /** @var array<string, int> $barProcessed — tracks ticked count per entity */
        $barProcessed = [];
        $currentEntity = null;

        while ($result['continue']) {
            $entityType = $result['entity_type'] ?? null;

            // Start a new progress bar when switching entities.
            if ($entityType !== null && $entityType !== $currentEntity) {
                // Finish previous bar if any.
                if ($currentEntity !== null && isset($progressBars[$currentEntity])) {
                    $progressBars[$currentEntity]->finish();
                }

                $total = $result['total'] > 0 ? $result['total'] : 1;
                $progressBars[$entityType] = \WP_CLI\Utils\make_progress_bar(
                    sprintf('Migrating %s', $entityType),
                    $total,
                );
                $barProcessed[$entityType] = 0;
                $currentEntity = $entityType;
            }

            $result = $orchestrator->processBatch();

            // Tick progress bar to current processed count.
            if ($currentEntity !== null && isset($progressBars[$currentEntity])) {
                $entityData = $result['entities'][$currentEntity] ?? [];
                $processed = ($entityData['processed'] ?? 0) + ($entityData['skipped'] ?? 0) + ($entityData['errors'] ?? 0);
                $bar = $progressBars[$currentEntity];

                // WP-CLI progress bar uses tick() — track processed count externally.
                $diff = $processed - $barProcessed[$currentEntity];
                if ($diff > 0) {
                    $bar->tick($diff);
                    $barProcessed[$currentEntity] = $processed;
                }

                // Finish bar when entity is completed.
                $entityStatus = $entityData['status'] ?? '';
                if ($entityStatus === 'completed') {
                    $bar->finish();
                }
            }
        }

        // Finish any remaining open progress bar.
        if ($currentEntity !== null && isset($progressBars[$currentEntity])) {
            $progressBars[$currentEntity]->finish();
        }

        $elapsed = round(microtime(true) - $startTime, 2);

        // Check final status.
        $progress = $state->getProgress();
        $finalStatus = $progress['status'] ?? 'unknown';

        if ($finalStatus === 'failed') {
            \WP_CLI::warning(sprintf('%s failed: %s', $label, $progress['error'] ?? 'Unknown error'));
        }

        // Build per-entity summary table.
        $tableData = [];
        $entities = $progress['entities'] ?? [];
        $totalMigrated = 0;
        $totalSkipped = 0;
        $totalErrors = 0;

        foreach ($entityTypes as $type) {
            $entity = $entities[$type] ?? [];
            $migrated = $entity['processed'] ?? 0;
            $skipped = $entity['skipped'] ?? 0;
            $errors = $entity['errors'] ?? 0;
            $total = $entity['total'] ?? 0;

            $totalMigrated += $migrated;
            $totalSkipped += $skipped;
            $totalErrors += $errors;

            $tableData[] = [
                'Entity'   => $type,
                'Total'    => $total,
                'Migrated' => $migrated,
                'Skipped'  => $skipped,
                'Errors'   => $errors,
                'Status'   => $entity['status'] ?? 'unknown',
            ];
        }

        \WP_CLI::log('');
        \WP_CLI\Utils\format_items('table', $tableData, ['Entity', 'Total', 'Migrated', 'Skipped', 'Errors', 'Status']);

        \WP_CLI::log('');
        \WP_CLI::log(sprintf('Migration ID: %s', $progress['migration_id'] ?? 'N/A'));
        \WP_CLI::log(sprintf('Total time: %ss', $elapsed));

        if ($totalErrors > 0) {
            \WP_CLI::warning(sprintf(
                'Completed with %d error(s). Run `wp cartshift log --status=error` to inspect.',
                $totalErrors,
            ));
        } else {
            \WP_CLI::success(sprintf(
                '%s complete. %d migrated, %d skipped in %ss.',
                $label,
                $totalMigrated,
                $totalSkipped,
                $elapsed,
            ));
        }
    }

    /**
     * Re-run only the records a previous migration did not get right.
     *
     * Reads the migration log for the ids still outstanding and runs those,
     * rather than re-walking the whole shop and relying on the id map to skip
     * what already worked.
     *
     * A record logged as an error and later migrated successfully is not
     * retried — its error row is still in the log, but re-running it would
     * create a second copy.
     *
     * ## OPTIONS
     *
     * --migration=<id>
     * : The migration ID to retry records from.
     *
     * [--entities=<entities>]
     * : Comma-separated entity types to retry. Defaults to every type with
     * something outstanding.
     * ---
     * default: all
     * ---
     *
     * [--statuses=<statuses>]
     * : Comma-separated statuses to re-attempt. Warnings are usually worth
     * including — a subscription warned about an unmapped product is a real gap
     * in the migrated data.
     * ---
     * default: error
     * ---
     *
     * [--batch-size=<size>]
     * : Number of records to process per batch.
     * ---
     * default: 50
     * ---
     *
     * [--dry-run]
     * : Log what would happen without creating any records.
     *
     * [--background]
     * : Hand the remaining batches to Action Scheduler and return immediately.
     *
     * ## EXAMPLES
     *
     *     wp cartshift retry --migration=abc-123
     *     wp cartshift retry --migration=abc-123 --statuses=error,warning
     *     wp cartshift retry --migration=abc-123 --entities=order,subscription --dry-run
     *
     * Every WP_CLI::error() below is followed by an explicit return. WP-CLI's
     * own error() exits, so those returns are unreachable in production — they
     * are there because the test stub does not exit, and without them a refused
     * command would carry on and reach the orchestrator anyway. Belt and braces
     * on a guard is cheaper than a guard that only works outside the tests.
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function retry(array $args, array $assocArgs): void
    {
        $startTime = microtime(true);

        $sourceMigrationId = self::resolveRetrySource($assocArgs);

        if ($sourceMigrationId === '') {
            \WP_CLI::error('A migration ID is required. Pass --migration=<id>.');

            return;
        }

        $entityTypes = self::resolveEntityTypes($assocArgs);
        $statuses = self::resolveStatuses($assocArgs);
        $batchSize = (int) ($assocArgs['batch-size'] ?? Constants::DEFAULT_BATCH_SIZE);
        $dryRun = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'dry-run', false);
        $background = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'background', false);

        if ($batchSize < 1) {
            \WP_CLI::error('Batch size must be at least 1.');

            return;
        }

        if (empty($entityTypes)) {
            \WP_CLI::error('No valid entity types specified.');

            return;
        }

        if (empty($statuses)) {
            \WP_CLI::error(sprintf(
                'No valid statuses specified. Retryable statuses are: %s.',
                implode(', ', MigrationLogRepository::RETRYABLE_STATUSES),
            ));

            return;
        }

        if ($background && !BatchProcessor::isAvailable()) {
            \WP_CLI::error(
                'Background processing needs Action Scheduler, which ships with both WooCommerce and FluentCart. '
                . 'Neither appears to be loaded, so there is nothing to queue batches with.',
            );

            return;
        }

        $idMap = new IdMapRepository();
        $log = new MigrationLogRepository();
        $state = new MigrationState();

        if ($state->isRunning()) {
            \WP_CLI::error(
                'A migration is already in progress. Run `wp cartshift status` for details, '
                . 'or `wp cartshift reset` if the run is stale.',
            );

            return;
        }

        // A typo'd id would otherwise start a run with nothing to do and then
        // report success, which reads exactly like "there was nothing wrong".
        if (!$log->hasEntries($sourceMigrationId)) {
            \WP_CLI::error(sprintf('No log entries found for migration ID: %s', $sourceMigrationId));

            return;
        }

        if ($dryRun) {
            \WP_CLI::log('Dry run — no records will be created.');
        }

        \WP_CLI::log(sprintf(
            'Retrying %s from migration %s (statuses: %s, batch size: %d)',
            implode(', ', $entityTypes),
            $sourceMigrationId,
            implode(', ', $statuses),
            $batchSize,
        ));

        add_filter('cartshift/migration/batch_size', fn(): int => $batchSize, 99);

        $migrators = self::buildMigrators($entityTypes, $idMap, $log, $state, $batchSize);

        $orchestrator = new MigrationOrchestrator($migrators, $state, $idMap, $log);

        if (!method_exists($orchestrator, 'startRetry')) {
            \WP_CLI::error('Retry is not available in this build — the migration orchestrator does not support it.');

            return;
        }

        $result = $orchestrator->startRetry($sourceMigrationId, $entityTypes, $statuses, $dryRun);

        if ($background) {
            self::handoffToBackground($state, $result, $startTime);

            return;
        }

        self::driveForeground($orchestrator, $state, $result, $entityTypes, $startTime, 'Retry');
    }

    /**
     * Roll back a previous migration.
     *
     * ## OPTIONS
     *
     * <migration_id>
     * : The migration ID to roll back.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp cartshift rollback abc-123-def
     *     wp cartshift rollback abc-123-def --yes
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function rollback(array $args, array $assocArgs): void
    {
        if (empty($args[0])) {
            \WP_CLI::error('Migration ID is required.');
        }

        $migrationId = $args[0];

        $idMap = new IdMapRepository();
        $log = new MigrationLogRepository();

        // Show stats before confirming.
        $stats = $log->getStats($migrationId);

        if ($stats['total'] === 0) {
            \WP_CLI::error(sprintf('No log entries found for migration ID: %s', $migrationId));
        }

        $skipConfirm = \WP_CLI\Utils\get_flag_value($assocArgs, 'yes', false);

        if (!$skipConfirm) {
            \WP_CLI::log(sprintf('Migration: %s', $migrationId));
            \WP_CLI::log(sprintf('  Success: %d', $stats['success'] ?? 0));
            \WP_CLI::log(sprintf('  Skipped: %d', $stats['skipped'] ?? 0));
            \WP_CLI::log(sprintf('  Errors:  %d', $stats['error'] ?? 0));
            \WP_CLI::log('');
            \WP_CLI::confirm('Are you sure you want to roll back this migration?');
        }

        $rollback = new MigrationRollback($idMap, $log);

        \WP_CLI::log('Rolling back...');

        $deletedCounts = $rollback->rollback($migrationId);

        if (empty($deletedCounts)) {
            \WP_CLI::warning('No records were deleted. The migration may have already been rolled back.');
            return;
        }

        \WP_CLI::log('');
        \WP_CLI::log('Deleted records:');

        foreach ($deletedCounts as $entityType => $count) {
            \WP_CLI::log(sprintf('  %s: %d', $entityType, $count));
        }

        $total = array_sum($deletedCounts);
        \WP_CLI::success(sprintf('Rollback complete. %d record(s) deleted.', $total));
    }

    /**
     * Clear the stored migration state so a new run can start.
     *
     * Reset is not rollback. Reset forgets the run and drops any queued
     * background batches; every record already written to FluentCart, and every
     * id-map row pointing at it, stays put. Use `wp cartshift rollback <id>` when
     * you want those records gone.
     *
     * The usual reason to reach for this: a browser tab drove a migration, the
     * tab went away, and the state is stuck on 'running' forever.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Reset even when the migration still looks alive (a batch running right
     * now, or background batches queued).
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp cartshift reset
     *     wp cartshift reset --force --yes
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function reset(array $args, array $assocArgs): void
    {
        $force = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'force', false);
        $skipConfirm = (bool) \WP_CLI\Utils\get_flag_value($assocArgs, 'yes', false);

        $state = new MigrationState();
        $progress = $state->getProgress();
        $previousStatus = (string) ($progress['status'] ?? 'idle');

        if ($previousStatus === 'idle') {
            \WP_CLI::success('There is no migration state to reset.');

            return;
        }

        $migrationId = $state->getMigrationId();
        $processor = self::makeBatchProcessor($state);

        if (!$force && $previousStatus === 'running' && self::looksAlive($processor, $migrationId)) {
            \WP_CLI::error(
                'This migration is still alive — a batch is running right now, or background batches are queued. '
                . 'Let it finish, cancel it with `wp cartshift status` first, or pass --force.',
            );
        }

        \WP_CLI::log(sprintf('Status: %s', $previousStatus));
        \WP_CLI::log(sprintf('Migration ID: %s', $migrationId ?? 'n/a'));
        \WP_CLI::log('');
        \WP_CLI::log(
            'Reset clears the state only. Migrated records and id-map entries are kept; '
            . 'a dry run\'s simulated id-map rows are discarded.',
        );

        if (!$skipConfirm) {
            \WP_CLI::confirm('Clear the stored migration state?');
        }

        if ($migrationId !== null && $migrationId !== '' && BatchProcessor::isAvailable()) {
            $processor->cancel($migrationId);
            \WP_CLI::log('Pending background batches cancelled.');
        }

        // A dry run's ID-map rows exist only to carry references between its own
        // batches. Forgetting the run has to forget them too. Real rows are
        // untouched — reset forgets a run, rollback unpicks one.
        (new IdMapRepository())->purgeSimulated();

        $state->reset();

        \WP_CLI::success(sprintf(
            'Migration state cleared (was: %s). To delete the migrated records, run `wp cartshift rollback %s`.',
            $previousStatus,
            $migrationId ?? '<migration-id>',
        ));
    }

    /**
     * Show the current migration status.
     *
     * ## EXAMPLES
     *
     *     wp cartshift status
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function status(array $args, array $assocArgs): void
    {
        $state = new MigrationState();
        $progress = $state->getProgress();

        if ($progress['status'] === 'idle') {
            \WP_CLI::line('No migration in progress.');
            return;
        }

        \WP_CLI::line(sprintf('Status: %s', $progress['status']));

        if (!empty($progress['migration_id'])) {
            \WP_CLI::line(sprintf('Migration ID: %s', $progress['migration_id']));
        }

        if (!empty($progress['dry_run'])) {
            \WP_CLI::line('Mode: dry run');
        }

        if (!empty($progress['started_at'])) {
            \WP_CLI::line(sprintf('Started at: %s', $progress['started_at']));
        }

        if (!empty($progress['completed_at'])) {
            \WP_CLI::line(sprintf('Completed at: %s', $progress['completed_at']));
        }

        if (!empty($progress['error'])) {
            \WP_CLI::warning(sprintf('Error: %s', $progress['error']));
        }

        if (!empty($progress['entities']) && is_array($progress['entities'])) {
            \WP_CLI::line('');

            $entityTypes = $progress['entity_types'] ?? array_keys($progress['entities']);
            $currentIndex = $progress['current_entity_index'] ?? 0;
            $currentOffset = $progress['current_offset'] ?? 0;

            foreach ($progress['entities'] as $type => $entity) {
                $processed = ($entity['processed'] ?? 0) + ($entity['skipped'] ?? 0) + ($entity['errors'] ?? 0);
                $total = $entity['total'] ?? 0;
                $pct = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
                $status = $entity['status'] ?? 'unknown';

                // Mark the current entity being processed.
                $marker = '';
                if ($progress['status'] === 'running' && isset($entityTypes[$currentIndex]) && $entityTypes[$currentIndex] === $type) {
                    $marker = ' <--';
                }

                \WP_CLI::line(sprintf(
                    '  %s: %s — %d/%d (%.1f%%) — %d migrated, %d skipped, %d errors%s',
                    $type,
                    $status,
                    $processed,
                    $total,
                    $pct,
                    $entity['processed'] ?? 0,
                    $entity['skipped'] ?? 0,
                    $entity['errors'] ?? 0,
                    $marker,
                ));
            }

            if ($progress['status'] === 'running') {
                $currentType = $entityTypes[$currentIndex] ?? null;
                if ($currentType !== null) {
                    \WP_CLI::line('');
                    \WP_CLI::line(sprintf('Currently processing: %s (offset: %d)', $currentType, $currentOffset));
                }
            }
        }
    }

    /**
     * View migration log entries.
     *
     * ## OPTIONS
     *
     * [--migration-id=<id>]
     * : Filter by migration ID.
     *
     * [--status=<status>]
     * : Filter by status (success, error, skipped, rollback).
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     * ---
     *
     * [--per-page=<n>]
     * : Number of entries per page.
     * ---
     * default: 50
     * ---
     *
     * ## EXAMPLES
     *
     *     wp cartshift log
     *     wp cartshift log --migration-id=abc-123 --status=error
     *     wp cartshift log --format=json --per-page=100
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function log(array $args, array $assocArgs): void
    {
        $migrationId = $assocArgs['migration-id'] ?? null;
        $status = $assocArgs['status'] ?? null;
        $format = $assocArgs['format'] ?? 'table';
        $perPage = (int) ($assocArgs['per-page'] ?? 50);

        if ($perPage < 1) {
            $perPage = 50;
        }

        $logRepo = new MigrationLogRepository();

        $result = $logRepo->getPaginated(
            migrationId: $migrationId,
            page: 1,
            perPage: $perPage,
            status: $status,
        );

        $entries = $result['data'];

        if (empty($entries)) {
            \WP_CLI::log('No log entries found.');
            return;
        }

        // Flatten entries for table display — strip 'details' column for readability.
        $rows = array_map(fn(array $entry): array => [
            'id'            => $entry['id'],
            'migration_id'  => substr($entry['migration_id'] ?? '', 0, 8),
            'entity_type'   => $entry['entity_type'] ?? '',
            'wc_id'         => $entry['wc_id'] ?? '',
            'status'        => $entry['status'] ?? '',
            'message'       => $entry['message'] ?? '',
            'created_at'    => $entry['created_at'] ?? '',
        ], $entries);

        $fields = ['id', 'migration_id', 'entity_type', 'wc_id', 'status', 'message', 'created_at'];

        \WP_CLI\Utils\format_items($format, $rows, $fields);

        \WP_CLI::log(sprintf(
            'Showing %d of %d entries.',
            count($entries),
            $result['total'],
        ));
    }

    /**
     * Finalize a completed migration (recalculate customer stats, flush caches).
     *
     * ## OPTIONS
     *
     * [--migration-id=<id>]
     * : The migration ID to finalize. Defaults to the last completed migration.
     *
     * ## EXAMPLES
     *
     *     wp cartshift finalize
     *     wp cartshift finalize --migration-id=abc-123
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function finalize(array $args, array $assocArgs): void
    {
        $state = new MigrationState();
        $progress = $state->getProgress();

        $migrationId = $assocArgs['migration-id'] ?? ($progress['migration_id'] ?? null);

        if ($migrationId === null) {
            \WP_CLI::error('No migration ID found. Specify one with --migration-id.');
        }

        if ($progress['status'] === 'running') {
            \WP_CLI::error('Migration is still running. Wait for it to complete before finalizing.');
        }

        $idMap = new IdMapRepository();

        // 1. Recalculate customer purchase stats.
        $customerMappings = $idMap->getAllByEntityType(Constants::ENTITY_CUSTOMER, $migrationId);
        $guestMappings = $idMap->getAllByEntityType(Constants::ENTITY_GUEST_CUSTOMER, $migrationId);
        $allCustomers = array_merge($customerMappings, $guestMappings);

        if (!empty($allCustomers)) {
            $bar = \WP_CLI\Utils\make_progress_bar('Recalculating customer stats', count($allCustomers));

            foreach ($allCustomers as $mapping) {
                self::recalculateCustomerStats((int) $mapping->fc_id);
                $bar->tick();
            }

            $bar->finish();
        } else {
            \WP_CLI::log('No customers to recalculate.');
        }

        // 2. Flush object cache.
        wp_cache_flush();
        \WP_CLI::log('Object cache flushed.');

        // 3. Flush rewrite rules.
        flush_rewrite_rules();
        \WP_CLI::log('Rewrite rules flushed.');

        \WP_CLI::success(sprintf(
            'Finalization complete. %d customer(s) recalculated.',
            count($allCustomers),
        ));
    }

    /**
     * Queue the rest of the run on Action Scheduler and return.
     *
     * The first batch has already been processed inline by startMigration(), so
     * the operator sees straight away whether the run is viable before walking
     * away from it.
     *
     * @param array<string, mixed> $result First batch result.
     */
    private static function handoffToBackground(MigrationState $state, array $result, float $startTime): void
    {
        $migrationId = $state->getMigrationId();
        $elapsed = round(microtime(true) - $startTime, 2);

        if (!$result['continue'] || $migrationId === null || $migrationId === '') {
            \WP_CLI::success(sprintf(
                'Migration finished during the first batch — nothing left to queue (%ss).',
                $elapsed,
            ));

            return;
        }

        self::makeBatchProcessor($state)->scheduleFirst($migrationId);

        \WP_CLI::success(sprintf(
            'First batch done in %ss. Remaining batches queued on Action Scheduler for migration %s. '
            . 'Track it with `wp cartshift status`, stop it with `wp cartshift reset --force`.',
            $elapsed,
            $migrationId,
        ));
    }

    /**
     * Build a BatchProcessor for scheduling and cancelling background batches.
     *
     * The orchestrator factory mirrors MigrationModule: a fresh orchestrator per
     * invocation. The migrators read the migration ID from state at write time,
     * so nothing here has to be built in a particular order. It is not used when
     * this instance only schedules or cancels — the action itself is handled by
     * the instance registered at plugin boot.
     */
    private static function makeBatchProcessor(MigrationState $state): BatchProcessor
    {
        $factory = static function () use ($state): MigrationOrchestrator {
            $idMap = new IdMapRepository();
            $log = new MigrationLogRepository();
            return new MigrationOrchestrator(
                self::buildMigrators(
                    self::DEFAULT_ENTITY_ORDER,
                    $idMap,
                    $log,
                    $state,
                    Constants::DEFAULT_BATCH_SIZE,
                ),
                $state,
                $idMap,
                $log,
            );
        };

        return new BatchProcessor($factory, $state);
    }

    /**
     * Whether a migration marked 'running' is genuinely still being worked on.
     */
    private static function looksAlive(BatchProcessor $processor, ?string $migrationId): bool
    {
        if ($migrationId !== null && $migrationId !== '' && $processor->hasPendingActions($migrationId)) {
            return true;
        }

        if (!BatchProcessor::acquireLock()) {
            return true;
        }

        BatchProcessor::releaseLock();

        return false;
    }

    /**
     * Whether --since was combined with --products or --customers.
     *
     * A contradiction, not a preference to silently resolve — one says "this
     * date onward", the other says "only these", and honouring one over the
     * other would migrate something the operator did not ask for. Checked
     * ahead of resolveScope() so migrate() can refuse and return before doing
     * anything else, the same shape as every other pre-flight guard here.
     *
     * @param array<string, mixed> $assocArgs
     */
    private static function scopeFlagsContradict(array $assocArgs): bool
    {
        $since = $assocArgs['since'] ?? null;
        $products = $assocArgs['products'] ?? '';
        $customers = $assocArgs['customers'] ?? '';

        return $since !== null && ($products !== '' || $customers !== '');
    }

    /**
     * Build a MigrationScope from --since, --products and --customers.
     *
     * Assumes scopeFlagsContradict() has already been checked by the caller.
     *
     * @param array<string, mixed> $assocArgs
     */
    private static function resolveScope(array $assocArgs): MigrationScope
    {
        // Read directly rather than through WP_CLI\Utils\get_flag_value():
        // --since takes a value, and every other value-bearing option in this
        // command (--batch-size, --statuses, --entities) is read the same way.
        $since = $assocArgs['since'] ?? null;
        $products = $assocArgs['products'] ?? '';
        $customers = $assocArgs['customers'] ?? '';

        return MigrationScope::fromArray([
            'mode'         => self::resolveScopeMode($assocArgs),
            'since'        => $since,
            'product_ids'  => self::csvIds((string) $products),
            'customer_ids' => self::csvIds((string) $customers),
        ]);
    }

    /**
     * `explicit` when --products or --customers is present, `since` when
     * --since is, `everything` otherwise.
     *
     * @param array<string, mixed> $assocArgs
     */
    private static function resolveScopeMode(array $assocArgs): string
    {
        $products = $assocArgs['products'] ?? '';
        $customers = $assocArgs['customers'] ?? '';

        if ($products !== '' || $customers !== '') {
            return MigrationScope::MODE_EXPLICIT;
        }

        if (($assocArgs['since'] ?? null) !== null) {
            return MigrationScope::MODE_SINCE;
        }

        return MigrationScope::MODE_EVERYTHING;
    }

    /**
     * A comma-separated list of IDs from a CLI flag, as a list of ints.
     *
     * MigrationScope::fromArray() does its own normalising (positive-only,
     * deduped, sorted), so this only has to split the string.
     *
     * @return list<int>
     */
    private static function csvIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        return array_map(static fn (string $id): int => (int) trim($id), explode(',', $raw));
    }

    /**
     * Resolve which entity types to migrate from CLI arguments.
     *
     * @param string[] $assocArgs
     * @return string[]
     */
    private static function resolveEntityTypes(array $assocArgs): array
    {
        $raw = $assocArgs['entities'] ?? 'all';

        if ($raw === 'all') {
            return self::DEFAULT_ENTITY_ORDER;
        }

        $requested = array_map('trim', explode(',', $raw));
        $valid = [];

        foreach ($requested as $type) {
            if (in_array($type, self::DEFAULT_ENTITY_ORDER, true)) {
                $valid[] = $type;
            } else {
                \WP_CLI::warning(sprintf('Unknown entity type: %s (skipping)', $type));
            }
        }

        return $valid;
    }

    /**
     * The migration id a retry is sourced from.
     *
     * `--migration` is the documented spelling; `--migration-id` is accepted
     * because that is what `wp cartshift log` and `wp cartshift finalize` use and
     * nobody should have to remember which command wants which.
     *
     * @param array<string, mixed> $assocArgs
     */
    private static function resolveRetrySource(array $assocArgs): string
    {
        $raw = $assocArgs['migration'] ?? $assocArgs['migration-id'] ?? '';

        return is_scalar($raw) ? trim((string) $raw) : '';
    }

    /**
     * Statuses a retry should re-attempt, from --statuses.
     *
     * Unknown or non-retryable values are dropped with a warning rather than
     * silently ignored: asking to retry successes is a misunderstanding worth
     * correcting, not a typo to paper over.
     *
     * @param array<string, mixed> $assocArgs
     *
     * @return list<string>
     */
    private static function resolveStatuses(array $assocArgs): array
    {
        $raw = $assocArgs['statuses'] ?? 'error';

        if (!is_scalar($raw)) {
            return ['error'];
        }

        $requested = array_filter(array_map('trim', explode(',', (string) $raw)));
        $valid = [];

        foreach ($requested as $status) {
            if (in_array($status, MigrationLogRepository::RETRYABLE_STATUSES, true)) {
                $valid[] = $status;

                continue;
            }

            \WP_CLI::warning(sprintf('Not a retryable status: %s (skipping)', $status));
        }

        return array_values(array_unique($valid));
    }

    /**
     * Build migrator instances for the requested entity types.
     *
     * @param string[] $entityTypes
     * @return \CartShift\Domain\Migration\Contracts\MigratorInterface[]
     */
    private static function buildMigrators(
        array $entityTypes,
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $state,
        int $batchSize,
    ): array {
        $map = [
            Constants::ENTITY_PRODUCT      => ProductMigrator::class,
            Constants::ENTITY_CUSTOMER     => CustomerMigrator::class,
            Constants::ENTITY_COUPON       => CouponMigrator::class,
            Constants::ENTITY_ORDER        => OrderMigrator::class,
            Constants::ENTITY_SUBSCRIPTION => SubscriptionMigrator::class,
        ];

        $migrators = [];

        foreach ($entityTypes as $type) {
            if (!isset($map[$type])) {
                continue;
            }

            $class = $map[$type];
            $migrators[] = new $class($idMap, $log, $state, $batchSize);
        }

        return $migrators;
    }

    /**
     * Recalculate a single FluentCart customer's purchase stats.
     *
     * Mirrors FluentCart's Customer::recountStat() logic:
     * - purchase_count: number of paid orders
     * - purchase_value: JSON object keyed by currency (e.g. {"USD": 12300})
     * - ltv: lifetime value (total_paid - total_refund, only positive)
     * - aov: average order value (ltv / purchase_count)
     * - first_purchase_date / last_purchase_date: from order created_at
     */
    private static function recalculateCustomerStats(int $fcCustomerId): void
    {
        global $wpdb;

        $prefix = $wpdb->prefix;

        // Fetch all paid orders for this customer.
        $orders = $wpdb->get_results($wpdb->prepare(
            "SELECT currency, total_paid, total_refund, created_at
             FROM {$prefix}fct_orders
             WHERE customer_id = %d
               AND payment_status IN ('paid', 'partially_refunded')",
            $fcCustomerId,
        ));

        if (empty($orders)) {
            return;
        }

        $purchaseCount = count($orders);
        $purchaseValueByCurrency = [];
        $ltv = 0;
        $firstDate = null;
        $lastDate = null;

        foreach ($orders as $order) {
            $currency = strtoupper($order->currency ?: 'USD');
            $netPaid = (int) $order->total_paid - (int) $order->total_refund;

            if (!isset($purchaseValueByCurrency[$currency])) {
                $purchaseValueByCurrency[$currency] = 0;
            }
            $purchaseValueByCurrency[$currency] += (int) $order->total_paid;

            if ($netPaid > 0) {
                $ltv += $netPaid;
            }

            if ($firstDate === null || $order->created_at < $firstDate) {
                $firstDate = $order->created_at;
            }
            if ($lastDate === null || $order->created_at > $lastDate) {
                $lastDate = $order->created_at;
            }
        }

        $aov = $purchaseCount > 0 ? (int) round($ltv / $purchaseCount) : 0;

        $wpdb->update(
            $prefix . 'fct_customers',
            [
                'purchase_count'      => $purchaseCount,
                'purchase_value'      => wp_json_encode($purchaseValueByCurrency),
                'ltv'                 => $ltv,
                'aov'                 => $aov,
                'first_purchase_date' => $firstDate,
                'last_purchase_date'  => $lastDate,
            ],
            ['id' => $fcCustomerId],
            ['%d', '%s', '%d', '%d', '%s', '%s'],
            ['%d'],
        );
    }
}
