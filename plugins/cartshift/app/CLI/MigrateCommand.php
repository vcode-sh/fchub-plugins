<?php

declare(strict_types=1);

namespace CartShift\CLI;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Domain\Migration\MigrationRollback;
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
        \WP_CLI::add_command('cartshift rollback', [self::class, 'rollback']);
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
     * ## EXAMPLES
     *
     *     wp cartshift migrate
     *     wp cartshift migrate --entities=product,customer
     *     wp cartshift migrate --batch-size=100 --dry-run
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

        if ($batchSize < 1) {
            \WP_CLI::error('Batch size must be at least 1.');
        }

        if (empty($entityTypes)) {
            \WP_CLI::error('No valid entity types specified.');
        }

        $idMap = new IdMapRepository();
        $log = new MigrationLogRepository();
        $state = new MigrationState();

        if ($state->isRunning()) {
            \WP_CLI::error('A migration is already in progress. Run `wp cartshift status` for details.');
        }

        if ($dryRun) {
            \WP_CLI::log('Dry run — no records will be created.');
        }

        \WP_CLI::log(sprintf(
            'Starting migration for: %s (batch size: %d)',
            implode(', ', $entityTypes),
            $batchSize,
        ));

        add_filter('cartshift/migration/batch_size', fn(): int => $batchSize, 99);

        $migrationId = wp_generate_uuid4();
        $migrators = self::buildMigrators($entityTypes, $idMap, $log, $state, $migrationId, $batchSize);

        $orchestrator = new MigrationOrchestrator($migrators, $state, $idMap, $log);

        $result = $orchestrator->startMigration($entityTypes, $dryRun);

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
            \WP_CLI::warning(sprintf('Migration failed: %s', $progress['error'] ?? 'Unknown error'));
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
                'Migration complete. %d migrated, %d skipped in %ss.',
                $totalMigrated,
                $totalSkipped,
                $elapsed,
            ));
        }
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
        string $migrationId,
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
            $migrators[] = new $class($idMap, $log, $state, $migrationId, $batchSize);
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
