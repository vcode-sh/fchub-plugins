<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;

final class MigrationOrchestrator
{
    /**
     * @param MigratorInterface[] $migrators
     */
    public function __construct(
        private readonly array $migrators,
        private readonly MigrationState $state,
        private readonly IdMapRepository $idMap,
        private readonly MigrationLogRepository $log,
    ) {
    }

    /**
     * Initialise migration state and process the first batch.
     *
     * @param string[] $entityTypes Entity types to migrate (e.g. ['products', 'customers']).
     * @return array{continue: bool, migration_id: string, entity_type: string|null, offset: int, total: int, processed: int}
     */
    public function startMigration(array $entityTypes, bool $dryRun = false): array
    {
        /** @see 'cartshift/migration/entity_types' */
        $entityTypes = apply_filters('cartshift/migration/entity_types', $entityTypes);

        $this->state->start($entityTypes, $dryRun);

        $migrationId = $this->state->getMigrationId();

        /** @see 'cartshift/migration/started' */
        do_action('cartshift/migration/started', $migrationId, $entityTypes, $dryRun);

        // Initialise entity totals so the progress UI has counts from the start.
        foreach ($this->resolveMigrators($entityTypes) as $migrator) {
            $total = $migrator->count();
            $this->state->updateProgress($migrator->entityType(), 0, $total);
        }

        return $this->processBatch();
    }

    /**
     * Process one batch of the current entity type.
     *
     * Reads current_entity_index and current_offset from state,
     * processes up to DEFAULT_BATCH_SIZE records, advances state,
     * and returns whether there is more work.
     *
     * @return array{continue: bool, migration_id: string|null, entity_type: string|null, offset: int, total: int, processed: int}
     */
    public function processBatch(): array
    {
        @set_time_limit(0);

        if ($this->state->isCancelled()) {
            return $this->buildCancelledResult();
        }

        if (!$this->state->isRunning()) {
            return $this->buildResult(false);
        }

        $entityTypes = $this->state->getEntityTypes();
        $entityIndex = $this->state->getCurrentEntityIndex();
        $migrationId = $this->state->getMigrationId();

        // All entities processed.
        if ($entityIndex >= count($entityTypes)) {
            $this->state->complete();

            /** @see 'cartshift/migration/completed' */
            do_action('cartshift/migration/completed', $migrationId);

            return $this->buildResult(false);
        }

        $currentType = $entityTypes[$entityIndex];
        $migrators = $this->resolveMigrators([$currentType]);

        if (empty($migrators)) {
            // Unknown entity type — skip to next.
            $this->state->advanceEntity();

            return $this->buildResult(true);
        }

        $migrator = $migrators[0];
        $offset = $this->state->getCurrentOffset();

        $batchSize = (int) apply_filters(
            'cartshift/migration/batch_size',
            Constants::DEFAULT_BATCH_SIZE,
            $currentType,
        );

        try {
            /** @see 'cartshift/migration/entity_started' */
            if ($offset === 0) {
                $migrator->initialize();
                do_action('cartshift/migration/entity_started', $currentType, $migrationId);
            }

            $batch = $migrator->fetchBatch($offset, $batchSize);

            if (empty($batch)) {
                // Entity is done.
                $this->state->completeEntity($currentType);

                /** @see 'cartshift/migration/entity_completed' */
                do_action('cartshift/migration/entity_completed', $currentType, $migrationId);

                $this->state->advanceEntity();

                // Check if there are more entities.
                $nextIndex = $this->state->getCurrentEntityIndex();

                return $this->buildResult($nextIndex < count($entityTypes));
            }

            $entityState = $this->state->getCurrent()['entities'][$currentType] ?? [];
            $processed = $entityState['processed'] ?? 0;
            $skipped = $entityState['skipped'] ?? 0;
            $errors = $entityState['errors'] ?? 0;
            $total = $entityState['total'] ?? $migrator->count();

            global $wpdb;

            foreach ($batch as $record) {
                if ($this->state->isCancelled()) {
                    $this->state->setCancelled($currentType);

                    return $this->buildCancelledResult();
                }

                // A2: Transaction wrapping prevents partial data on per-record failures.
                $wpdb->query('START TRANSACTION');

                try {
                    $result = $migrator->processRecord($record);

                    if ($result === false) {
                        $wpdb->query('COMMIT');
                        $skipped++;
                    } else {
                        $wpdb->query('COMMIT');
                        $processed++;

                        /** @see 'cartshift/migration/record_migrated' */
                        do_action(
                            'cartshift/migration/record_migrated',
                            $currentType,
                            $migrator->getRecordId($record),
                            $result,
                            $migrationId,
                        );
                    }
                } catch (\Throwable $e) {
                    $wpdb->query('ROLLBACK');
                    $errors++;
                    $wcId = $migrator->getRecordId($record);
                    $this->log->write(
                        $migrationId,
                        $currentType,
                        $wcId,
                        'error',
                        $e->getMessage(),
                    );
                }
            }

            $this->state->updateProgress($currentType, $processed, $total, $skipped, $errors);
            $this->state->advanceOffset(count($batch));

            // Flush object cache every 5 batches (250 records) to prevent memory exhaustion.
            $newOffset = $this->state->getCurrentOffset();
            if ($newOffset > 0 && ($newOffset / $batchSize) % 5 === 0) {
                wp_cache_flush();
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }

            // If batch was smaller than batch size, entity is done.
            if (count($batch) < $batchSize) {
                $this->state->completeEntity($currentType);

                /** @see 'cartshift/migration/entity_completed' */
                do_action('cartshift/migration/entity_completed', $currentType, $migrationId);

                $this->state->advanceEntity();

                $nextIndex = $this->state->getCurrentEntityIndex();

                return $this->buildResult($nextIndex < count($entityTypes));
            }

            return $this->buildResult(true);
        } catch (\Throwable $e) {
            $this->state->setFailed($e->getMessage());

            $this->log->write(
                $migrationId,
                'orchestrator',
                0,
                'error',
                $e->getMessage(),
            );

            /** @see 'cartshift/migration/failed' */
            do_action('cartshift/migration/failed', $migrationId, $e);

            return $this->buildResult(false);
        }
    }

    /**
     * Get current migration progress from state.
     *
     * @return array<string, mixed>
     */
    public function getProgress(): array
    {
        return $this->state->getProgress();
    }

    /**
     * Cancel the running migration.
     */
    public function cancel(): void
    {
        $this->state->cancel();
    }

    /**
     * F7: Build a result for a cancelled migration — status='cancelled', continue=false.
     *
     * @return array{continue: bool, migration_id: string|null, entity_type: string|null, offset: int, total: int, processed: int}
     */
    private function buildCancelledResult(): array
    {
        $result = $this->buildResult(false);
        $result['status'] = 'cancelled';

        return $result;
    }

    /**
     * Build a standardised batch result array.
     *
     * @return array{continue: bool, migration_id: string|null, entity_type: string|null, offset: int, total: int, processed: int}
     */
    private function buildResult(bool $continue): array
    {
        $state = $this->state->getCurrent();
        $entityTypes = $state['entity_types'] ?? [];
        $entityIndex = $state['current_entity_index'] ?? 0;
        $currentType = $entityTypes[$entityIndex] ?? null;
        $entityData = $state['entities'][$currentType] ?? [];

        return [
            'continue'      => $continue,
            'migration_id'  => $state['migration_id'] ?? null,
            'status'        => $state['status'] ?? 'idle',
            'entity_type'   => $currentType,
            'entity_index'  => $entityIndex,
            'entity_count'  => count($entityTypes),
            'offset'        => $state['current_offset'] ?? 0,
            'total'         => $entityData['total'] ?? 0,
            'processed'     => $entityData['processed'] ?? 0,
            'entities'      => $state['entities'] ?? [],
        ];
    }

    /**
     * Filter and order migrators that match the requested entity types.
     *
     * @param string[] $entityTypes
     * @return MigratorInterface[]
     */
    private function resolveMigrators(array $entityTypes): array
    {
        $resolved = [];

        foreach ($this->migrators as $migrator) {
            if (in_array($migrator->entityType(), $entityTypes, true)) {
                $resolved[] = $migrator;
            }
        }

        return $resolved;
    }
}
