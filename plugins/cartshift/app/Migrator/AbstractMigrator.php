<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;

abstract class AbstractMigrator implements MigratorInterface
{
    /** @var int Running counter of processed records */
    protected int $processed = 0;

    /** @var int Running counter of skipped records */
    protected int $skipped = 0;

    /** @var int Running counter of error records */
    protected int $errors = 0;

    public function __construct(
        protected readonly IdMapRepository $idMap,
        protected readonly MigrationLogRepository $log,
        protected readonly MigrationState $migrationState,
        protected readonly string $migrationId,
        protected readonly int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {}

    #[\Override]
    public function entityType(): string
    {
        return $this->getEntityType();
    }

    #[\Override]
    public function count(): int
    {
        return $this->countTotal();
    }

    /**
     * Default no-op initialisation. Override in subclasses for pre-migration setup.
     */
    #[\Override]
    public function initialize(): void
    {
        // No-op by default.
    }

    #[\Override]
    public function run(): void
    {
        $isDryRun = $this->migrationState->isDryRun();

        // In dry-run mode, skip initialize() — it creates categories, brands, attributes.
        if (!$isDryRun) {
            $this->initialize();
        }

        $effectiveBatchSize = (int) apply_filters(
            'cartshift/migration/batch_size',
            $this->batchSize,
            $this->getEntityType(),
        );

        $total = $this->countTotal();

        $this->migrationState->updateProgress(
            $this->getEntityType(),
            0,
            $total,
            0,
            0,
        );

        if ($total === 0) {
            $this->migrationState->completeEntity($this->getEntityType());
            return;
        }

        $offset = 0;
        $batchNumber = 0;

        while (true) {
            if ($this->shouldCancel()) {
                break;
            }

            $batch = $this->fetchBatch($offset, $effectiveBatchSize);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $record) {
                if ($this->shouldCancel()) {
                    break 2;
                }

                if ($isDryRun) {
                    $this->processDryRunRecord($record);
                } else {
                    $this->processRealRecord($record);
                }

                $this->migrationState->updateProgress(
                    $this->getEntityType(),
                    $this->processed,
                    $total,
                    $this->skipped,
                    $this->errors,
                );
            }

            $offset += count($batch);
            $batchNumber++;

            // Flush object cache every 5 batches to prevent memory exhaustion.
            if ($batchNumber % 5 === 0) {
                wp_cache_flush();
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            if (count($batch) < $effectiveBatchSize) {
                break;
            }
        }

        // F7: Only mark completed if migration wasn't cancelled mid-batch.
        if ($this->shouldCancel()) {
            $this->migrationState->setCancelled($this->getEntityType());
        } else {
            $this->migrationState->completeEntity($this->getEntityType());
        }
    }

    /**
     * Process a single record during a real (non-dry-run) migration.
     * Wraps processRecord() in a transaction with error handling.
     */
    private function processRealRecord(mixed $record): void
    {
        // A2: Transaction wrapping prevents partial data on per-record failures.
        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $result = $this->processRecord($record);

            if ($result === false) {
                $wpdb->query('COMMIT');
                $this->skipped++;
            } else {
                $wpdb->query('COMMIT');
                $this->processed++;

                /** @see 'cartshift/migration/record_migrated' */
                do_action(
                    'cartshift/migration/record_migrated',
                    $this->getEntityType(),
                    $this->getRecordId($record),
                    $result,
                    $this->migrationId,
                );
            }
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            $this->errors++;
            $wcId = $this->getRecordId($record);
            $this->log->write(
                $this->migrationId,
                $this->getEntityType(),
                $wcId,
                'error',
                $e->getMessage(),
            );
        }
    }

    /**
     * Process a single record during a dry-run migration.
     * Validates data mapping without creating any FC records.
     */
    private function processDryRunRecord(mixed $record): void
    {
        try {
            $result = $this->validateRecord($record);

            if ($result) {
                $this->processed++;
            } else {
                $this->skipped++;
            }
        } catch (\Throwable $e) {
            $this->errors++;
            $wcId = $this->getRecordId($record);
            $this->log->write(
                $this->migrationId,
                $this->getEntityType(),
                $wcId,
                'error',
                sprintf('dry-run validation failed: %s', $e->getMessage()),
            );
        }
    }

    /**
     * Validate a single record without creating any FC records.
     *
     * Default implementation: logs what would be created and returns true.
     * Override in subclasses for entity-specific validation.
     *
     * @return bool True if the record is valid and would be created, false to skip.
     */
    #[\Override]
    public function validateRecord(mixed $record): bool
    {
        $wcId = $this->getRecordId($record);

        $this->writeLog(
            $wcId,
            'dry-run',
            sprintf('dry-run: would create %s from WC #%s', $this->getEntityType(), $wcId),
        );

        return true;
    }

    /**
     * The entity type constant this migrator handles.
     */
    abstract protected function getEntityType(): string;

    /**
     * Return the total number of WC records to migrate.
     */
    abstract protected function countTotal(): int;

    /**
     * Fetch a batch of WC records at the given offset.
     *
     * @return mixed[]
     */
    #[\Override]
    abstract public function fetchBatch(int $offset, int $limit): array;

    /**
     * Process a single WC record. Return false to mark as skipped.
     */
    #[\Override]
    abstract public function processRecord(mixed $record): int|false;

    /**
     * Get the WC ID from a record (for logging).
     */
    #[\Override]
    public function getRecordId(mixed $record): string
    {
        if (is_object($record) && method_exists($record, 'get_id')) {
            return (string) $record->get_id();
        }
        if (is_object($record) && property_exists($record, 'ID')) {
            return (string) $record->ID;
        }
        if (is_object($record) && property_exists($record, 'id')) {
            return (string) $record->id;
        }

        return '0';
    }

    /**
     * Convenience: write a log entry via the repository.
     */
    protected function writeLog(string|int $wcId, string $status, string $message = ''): void
    {
        $this->log->write(
            $this->migrationId,
            $this->getEntityType(),
            $wcId,
            $status,
            $message,
        );
    }

    /**
     * Check if the migration has been cancelled.
     */
    protected function shouldCancel(): bool
    {
        return $this->migrationState->isCancelled();
    }
}
