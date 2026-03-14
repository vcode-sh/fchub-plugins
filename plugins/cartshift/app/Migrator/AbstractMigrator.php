<?php

namespace CartShift\Migrator;

defined('ABSPATH') or die;

use CartShift\State\MigrationState;
use CartShift\State\IdMap;

abstract class AbstractMigrator
{
    /** @var int Records per batch */
    protected int $batchSize = 50;

    /** @var string Entity type identifier (products, customers, orders, etc.) */
    protected string $entityType = '';

    /** @var MigrationState */
    protected MigrationState $migrationState;

    /** @var IdMap */
    protected IdMap $idMap;

    /** @var string UUID of the current migration run */
    protected string $migrationId;

    /** @var int Running counter of processed records */
    protected int $processed = 0;

    /** @var int Running counter of skipped records */
    protected int $skipped = 0;

    /** @var int Running counter of error records */
    protected int $errors = 0;

    /** @var bool When true, validate records without writing to FC */
    protected bool $dryRun = false;

    public function __construct(MigrationState $state, IdMap $idMap, string $migrationId, bool $dryRun = false)
    {
        $this->migrationState = $state;
        $this->idMap = $idMap;
        $this->migrationId = $migrationId;
        $this->dryRun = $dryRun;
    }

    /**
     * Whether this is a dry run (validation only, no writes).
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * Main migration loop.
     */
    public function run(): void
    {
        $total = $this->countTotal();

        $this->migrationState->updateProgress(
            $this->entityType,
            0,
            $total,
            0,
            0
        );

        if ($total === 0) {
            $this->migrationState->completeEntity($this->entityType);
            return;
        }

        $page = 1;

        while (true) {
            if ($this->shouldCancel()) {
                break;
            }

            $batch = $this->fetchBatch($page);

            if (empty($batch)) {
                break;
            }

            foreach ($batch as $record) {
                if ($this->shouldCancel()) {
                    break 2;
                }

                try {
                    $result = $this->processRecord($record);

                    if ($result === false) {
                        $this->skipped++;
                    } else {
                        $this->processed++;
                    }
                } catch (\Throwable $e) {
                    $this->errors++;
                    $wcId = $this->getRecordId($record);
                    $this->log($wcId, 'error', $e->getMessage());
                }

                $this->migrationState->updateProgress(
                    $this->entityType,
                    $this->processed,
                    $total,
                    $this->skipped,
                    $this->errors
                );
            }

            $page++;

            // Safety: if batch is smaller than batch size, we are done.
            if (count($batch) < $this->batchSize) {
                break;
            }
        }

        $this->migrationState->completeEntity($this->entityType);
    }

    /**
     * Return the total number of WC records to migrate.
     */
    abstract protected function countTotal(): int;

    /**
     * Fetch a batch of WC records for the given page.
     *
     * @return array
     */
    abstract protected function fetchBatch(int $page): array;

    /**
     * Process a single WC record. Return false to mark as skipped.
     *
     * @param mixed $record
     * @return bool|int False if skipped, otherwise the FC id.
     */
    abstract protected function processRecord($record);

    /**
     * Get the WC ID from a record (for logging).
     */
    protected function getRecordId($record): int
    {
        if (is_object($record) && method_exists($record, 'get_id')) {
            return $record->get_id();
        }
        if (is_object($record) && property_exists($record, 'ID')) {
            return $record->ID;
        }
        if (is_object($record) && property_exists($record, 'id')) {
            return $record->id;
        }
        return 0;
    }

    /**
     * Write an entry to the migration log table.
     */
    protected function log(int $wcId, string $status, string $message = ''): void
    {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'cartshift_migration_log',
            [
                'migration_id' => $this->migrationId,
                'entity_type'  => $this->entityType,
                'wc_id'        => $wcId,
                'status'       => $status,
                'message'      => $message,
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    /**
     * Check if the migration has been cancelled.
     */
    protected function shouldCancel(): bool
    {
        $state = $this->migrationState->getCurrent();
        return $state && $state['status'] === 'cancelled';
    }
}
