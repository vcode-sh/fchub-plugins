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
        $this->initialize();

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

                try {
                    $result = $this->processRecord($record);

                    if ($result === false) {
                        $this->skipped++;
                    } else {
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

                $this->migrationState->updateProgress(
                    $this->getEntityType(),
                    $this->processed,
                    $total,
                    $this->skipped,
                    $this->errors,
                );
            }

            $offset += count($batch);

            if (count($batch) < $effectiveBatchSize) {
                break;
            }
        }

        $this->migrationState->completeEntity($this->getEntityType());
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
