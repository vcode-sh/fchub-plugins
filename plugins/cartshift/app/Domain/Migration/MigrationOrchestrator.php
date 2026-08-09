<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\Contracts\HasErrorCode;
use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\DatabaseTransaction;
use CartShift\Support\Enums\MigrationErrorCode;

final class MigrationOrchestrator
{
    /**
     * Where the retry plan lives.
     *
     * Its own option rather than a key inside the migration state, because the
     * state array is owned elsewhere and a retry is the only thing that needs
     * this. The plan records which run is being retried and the exact ID list
     * per entity type, so a retry resumes across requests the way a normal run
     * resumes from its cursor.
     */
    private const string RETRY_OPTION = 'cartshift_migration_retry';

    /**
     * Deliberately far above any plausible FluentCart id, so a simulated mapping
     * that somehow escaped its `is_simulated = 1` realm is obvious in a log
     * rather than mistaken for a real FluentCart record.
     *
     * ProductMigrator mints its own simulated variation IDs from a separate,
     * further-away base (see ProductMigrator::SIMULATED_VARIATION_BASE) so the
     * two ranges cannot collide even on a store large enough to validate
     * hundreds of thousands of records in one dry run.
     */
    private static int $simulatedId = 900000000;

    /**
     * In-request copy of the retry plan option.
     *
     * @var array{migration_id: string, retry_of: string, ids: array<string, list<string>>}|null
     */
    private array|null $retryPlan = null;

    /** Whether $retryPlan holds a real read. Distinguishes "no plan" from "not read yet". */
    private bool $retryPlanLoaded = false;

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
     * The migration ID is minted here, by MigrationState::start(), and the first
     * batch runs before this method returns. Nothing may hold a migration ID
     * across that line: migrators read theirs from MigrationState at the moment
     * they write, which is what stops the first batch filing its rows under an
     * ID no state has ever heard of. See AbstractMigrator::migrationId().
     *
     * @param string[] $entityTypes Entity types to migrate (e.g. ['products', 'customers']).
     * @return array{continue: bool, migration_id: string, entity_type: string|null, offset: int, total: int, processed: int}
     */
    public function startMigration(array $entityTypes, bool $dryRun = false, ?MigrationScope $scope = null): array
    {
        /** @see 'cartshift/migration/entity_types' */
        $entityTypes = apply_filters('cartshift/migration/entity_types', $entityTypes);

        $this->state->start($entityTypes, $dryRun, $scope);
        $this->idMap->setSimulating($dryRun);

        if ($dryRun) {
            // A rehearsal starts from a clean slate. Rows an abandoned earlier dry
            // run left behind would answer for records this one has not looked at.
            $this->idMap->purgeSimulated();
        }

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
     * Start a run that re-attempts the records a previous run did not migrate.
     *
     * A retry is a migration in its own right, not an addendum to the one it
     * repairs: it seeds a fresh migration ID, writes its own log rows and its
     * own ID-map rows, and can therefore be rolled back or retried again on its
     * own terms. `retry_of` records where its work list came from and is the
     * only link back.
     *
     * The work list is pulled once, up front, from the source run's log — the
     * distinct WC IDs that ended in one of `$statuses`. Everything after that is
     * index pagination through that list. Deliberately not keyset pagination
     * against the source tables: the list is defined by what failed, which has
     * no relationship to where those rows sit in wp_posts, and any ordering
     * imposed on it would be a fiction.
     *
     * `$dryRun` is asked for, never inherited. A dry retry rehearses the repair —
     * it re-reads the failed records, re-runs the validation, writes dry-run log
     * rows and creates nothing — which is exactly what you want before letting a
     * fix loose on four thousand orders. What it must never do is come from the
     * source run: a dry run creates nothing, so it has nothing to repair, and
     * retrying one "for real" by inheriting its flag would be a silent promotion
     * from rehearsal to performance.
     *
     * @param string[] $entityTypes Entity types to retry; empty means every one
     *                              this orchestrator knows about.
     * @param string[] $statuses    Log statuses that count as retryable.
     * @param bool     $dryRun      Validate the work list without writing anything.
     *
     * @return array<string, mixed> The same shape startMigration() returns, plus `retry_of`.
     */
    public function startRetry(
        string $sourceMigrationId,
        array $entityTypes = [],
        array $statuses = ['error'],
        bool $dryRun = false,
    ): array {
        $statuses = array_values(array_filter(
            array_map(static fn (mixed $status): string => is_string($status) ? $status : '', $statuses),
            static fn (string $status): bool => $status !== '',
        ));

        if ($statuses === []) {
            $statuses = ['error'];
        }

        if ($entityTypes === []) {
            $entityTypes = array_map(
                static fn (MigratorInterface $migrator): string => $migrator->entityType(),
                $this->migrators,
            );
        }

        /** @see 'cartshift/migration/entity_types' */
        $entityTypes = array_values(apply_filters('cartshift/migration/entity_types', $entityTypes));

        $ids = [];

        foreach ($entityTypes as $entityType) {
            $ids[$entityType] = $this->retryableIds($sourceMigrationId, $entityType, $statuses);
        }

        // Captured before start() mints fresh state. A retry re-runs an
        // explicit ID list, so the scope does not gate anything here — but a
        // retry whose state reads "everything" lies to the next resume and to
        // the next person reading the run.
        $carriedScope = $this->state->getScope();

        $this->state->start($entityTypes, $dryRun, $carriedScope);
        $this->idMap->setSimulating($dryRun);

        if ($dryRun) {
            // Same clean-slate rule as startMigration(): a dry retry rehearses a
            // repair, and must not inherit an abandoned rehearsal's answers.
            $this->idMap->purgeSimulated();
        }

        $migrationId = (string) $this->state->getMigrationId();

        $this->rememberRetryPlan([
            'migration_id' => $migrationId,
            'retry_of'     => $sourceMigrationId,
            'ids'          => $ids,
        ]);

        /** @see 'cartshift/migration/started' */
        do_action('cartshift/migration/started', $migrationId, $entityTypes, $dryRun);

        /** @see 'cartshift/migration/retry_started' */
        do_action('cartshift/migration/retry_started', $migrationId, $sourceMigrationId, $ids, $statuses);

        // The total is the size of the work list, not the size of the source
        // table — a retry of nine failed orders is nine of nine, not nine of
        // forty thousand.
        foreach ($entityTypes as $entityType) {
            $this->state->updateProgress($entityType, 0, count($ids[$entityType] ?? []));
        }

        return $this->processBatch();
    }

    /**
     * The WC IDs a previous run left in one of the given statuses.
     *
     * Guarded rather than called outright: getRetryableIds() lives in a
     * repository this class does not own and may not have landed yet. An empty
     * list plus a log line beats a fatal, and beats a silent empty retry that
     * reads as "nothing failed".
     *
     * @param string[] $statuses
     *
     * @return list<string>
     */
    private function retryableIds(string $sourceMigrationId, string $entityType, array $statuses): array
    {
        if (!method_exists($this->log, 'getRetryableIds')) {
            $this->log->write(
                $sourceMigrationId,
                $entityType,
                0,
                'warning',
                'Cannot build a retry list: MigrationLogRepository::getRetryableIds() is not available in this build.',
            );

            return [];
        }

        $ids = $this->log->getRetryableIds($sourceMigrationId, $entityType, $statuses);

        return array_values(array_map(
            static fn (mixed $id): string => (string) $id,
            is_array($ids) ? $ids : [],
        ));
    }

    /**
     * Persist the retry plan and keep the in-request copy in step with it.
     *
     * @param array{migration_id: string, retry_of: string, ids: array<string, list<string>>} $plan
     */
    private function rememberRetryPlan(array $plan): void
    {
        update_option(self::RETRY_OPTION, $plan, false);

        $this->retryPlan = $plan;
        $this->retryPlanLoaded = true;
    }

    /**
     * The retry plan governing the run currently in state, or null.
     *
     * Keyed on the migration ID rather than merely existing, so a plan left
     * behind by a finished retry cannot bleed into the next ordinary run and
     * quietly restrict it to a stale ID list.
     *
     * @return array{migration_id: string, retry_of: string, ids: array<string, list<string>>}|null
     */
    private function retryPlan(): array|null
    {
        if (!$this->retryPlanLoaded) {
            $stored = get_option(self::RETRY_OPTION, null);

            $this->retryPlan = is_array($stored) ? $stored : null;
            $this->retryPlanLoaded = true;
        }

        $plan = $this->retryPlan;

        if ($plan === null) {
            return null;
        }

        $migrationId = $this->state->getMigrationId();

        if ($migrationId === null || ($plan['migration_id'] ?? null) !== $migrationId) {
            return null;
        }

        return $plan;
    }

    /**
     * The retry work list for one entity type, or null when this is not a retry.
     *
     * Null and [] mean different things here: null is "run normally", [] is
     * "this is a retry and this entity type has nothing to repair".
     *
     * @return list<string>|null
     */
    private function retryIdsFor(string $entityType): array|null
    {
        $plan = $this->retryPlan();

        if ($plan === null) {
            return null;
        }

        $ids = $plan['ids'][$entityType] ?? [];

        return is_array($ids) ? array_values($ids) : [];
    }

    /**
     * The migration this run is repairing, or null when it is not a retry.
     */
    private function retryOf(): string|null
    {
        $plan = $this->retryPlan();

        return $plan !== null ? (string) $plan['retry_of'] : null;
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

        // Derived from state on every batch, never latched. processBatch() runs in a
        // fresh request for later batches — the REST batch loop and Action Scheduler
        // both rebuild the orchestrator from scratch — so the flag set in
        // startMigration() does not survive past the first batch; and because the
        // repository is a container singleton, a one-way switch would be a leak
        // waiting to happen in the other direction.
        $this->idMap->setSimulating($this->state->isDryRun());

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
            $this->completeRun($migrationId);

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

        $isDryRun = $this->state->isDryRun();

        try {
            /** @see 'cartshift/migration/entity_started' */
            if ($offset === 0) {
                // initialize() creates categories, brands, attributes and shipping
                // classes, so a dry run must not call it. It gets the read-only
                // counterpart instead: the same maps, resolved from what already
                // exists in FluentCart, with synthetic IDs standing in for what does
                // not. Skipping both — which is what this used to do — left
                // ENTITY_CATEGORY empty for the whole run, so every coupon carrying
                // a category restriction was reported as would-be-disabled whether
                // it would be or not.
                if ($isDryRun) {
                    $migrator->initializeSimulated();
                } else {
                    $migrator->initialize();
                }

                do_action('cartshift/migration/entity_started', $currentType, $migrationId);
            }

            // Retry runs paginate a fixed list of IDs by index. Keyset ordering is
            // meaningless there — the IDs were chosen by what failed, not by where
            // they sit in the source table — so the cursor machinery is bypassed
            // entirely rather than fed a cursor it cannot honour.
            $retryIds = $this->retryIdsFor($currentType);
            $isRetry = $retryIds !== null;
            $slice = [];

            if ($isRetry) {
                $slice = array_slice($retryIds, $offset, $batchSize);

                if ($slice === []) {
                    return $this->finishEntity($currentType, $migrationId, count($entityTypes));
                }

                $cursor = null;
                $batch = $migrator->fetchByIds($slice);
            } else {
                $cursor = $this->resolveCursor($currentType, $offset, $migrationId);

                $batch = $migrator->fetchBatch($cursor, $batchSize);
            }

            if (empty($batch)) {
                if ($isRetry) {
                    // Every ID in this slice has gone from WooCommerce since the run
                    // that failed on it. Step over the slice rather than ending the
                    // entity: the list is finite and indexed, so the entity still
                    // terminates, and the IDs after this slice still get their turn.
                    $this->state->advanceOffset(count($slice));

                    return $this->buildResult(true);
                }

                // An empty batch is now the *only* end-of-entity signal. Under keyset
                // pagination a short batch no longer means "no more rows" — the
                // customer migrator hands back a short batch at its registered/guest
                // boundary, and any migrator that hydrates IDs can drop a row — so
                // stopping on one would silently truncate the migration.
                return $this->finishEntity($currentType, $migrationId, count($entityTypes));
            }

            $entityState = $this->state->getCurrent()['entities'][$currentType] ?? [];
            $processed = $entityState['processed'] ?? 0;
            $skipped = $entityState['skipped'] ?? 0;
            $errors = $entityState['errors'] ?? 0;
            $total = $isRetry
                ? count($retryIds)
                : ($entityState['total'] ?? $migrator->count());

            foreach ($batch as $record) {
                if ($this->state->isCancelled()) {
                    $this->state->setCancelled($currentType);

                    return $this->buildCancelledResult();
                }

                if ($isDryRun) {
                    // Dry-run: validate without creating records or transactions.
                    try {
                        $result = $migrator->validateRecord($record);

                        if ($result) {
                            $processed++;
                            $this->idMap->store(
                                $migrator->entityType(),
                                $migrator->getRecordId($record),
                                self::nextSimulatedId(),
                                (string) $migrationId,
                                true,
                            );
                        } else {
                            $skipped++;
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        $wcId = $migrator->getRecordId($record);
                        $this->log->write(
                            $migrationId,
                            $currentType,
                            $wcId,
                            'error',
                            sprintf('dry-run validation failed: %s', $e->getMessage()),
                            null,
                            MigrationErrorCode::DryRunValidationFailed,
                        );
                    }
                } else {
                    // A2: Transaction wrapping prevents partial data on per-record failures.
                    //
                    // THE BOUNDARY IS THIS ONE, and it is the only one. Through
                    // `DatabaseTransaction` an inner `begin()` — the subscription
                    // writer's — joins this transaction instead of implicitly
                    // committing it, so everything `processRecord()` does,
                    // INCLUDING the order/item/transaction history
                    // `SubscriptionMigrator::linkHistory()` writes after the
                    // destination row exists, is inside it and is undone
                    // together.
                    DatabaseTransaction::begin();

                    try {
                        $result = $migrator->processRecord($record);

                        if ($result === false) {
                            DatabaseTransaction::commit();
                            $skipped++;
                        } else {
                            DatabaseTransaction::commit();
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
                        DatabaseTransaction::rollback();
                        $errors++;
                        $wcId = $migrator->getRecordId($record);
                        $this->log->write(
                            $migrationId,
                            $currentType,
                            $wcId,
                            'error',
                            $e->getMessage(),
                            null,
                            self::errorCodeFor($e),
                        );
                    }
                }
            }

            $this->state->updateProgress(
                $currentType,
                $processed,
                $total,
                $skipped,
                $this->reconciledErrors((string) $migrationId, $currentType, $errors),
            );

            // A retry advances by the slice it asked for, not by the records that
            // came back. fetchByIds() drops IDs that no longer resolve, so advancing
            // by the batch would leave the missing ones in front of the index for
            // ever and the entity would never finish.
            $this->state->advanceOffset($isRetry ? count($slice) : count($batch));

            if (!$isRetry) {
                // Advance the cursor to the last record this batch handed out. Doing it
                // after the loop means a fatal mid-batch replays the batch — harmless,
                // because every processRecord() short-circuits on an ID-map hit.
                $nextCursor = $migrator->cursorFor($batch[array_key_last($batch)]);
                $this->state->setEntityCursor($currentType, $nextCursor);

                // Belt and braces. A cursor that fails to move would spin this entity
                // for ever; keyset ordering makes that impossible, so if it happens the
                // migrator is broken and stopping is the only safe answer.
                if ($cursor !== null && $nextCursor === $cursor) {
                    $this->log->write(
                        (string) $migrationId,
                        $currentType,
                        0,
                        'warning',
                        sprintf(
                            'Cursor did not advance past "%s"; stopping this entity to avoid an endless loop.',
                            (string) $cursor,
                        ),
                    );

                    return $this->finishEntity($currentType, $migrationId, count($entityTypes));
                }
            }

            // Drop the in-process cache every 5 batches (250 records) to prevent memory
            // exhaustion. Deliberately NOT wp_cache_flush(): with a persistent object
            // cache that would wipe Redis/Memcached for the entire site, over and over,
            // while the migration runs. Only the per-request array needs clearing.
            $newOffset = $this->state->getCurrentOffset();
            if ($newOffset > 0 && intdiv($newOffset, $batchSize) % 5 === 0) {
                self::flushRuntimeCache();

                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
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
                null,
                MigrationErrorCode::MigrationAborted,
            );

            /** @see 'cartshift/migration/failed' */
            do_action('cartshift/migration/failed', $migrationId, $e);

            return $this->buildResult(false);
        }
    }

    /**
     * The error count for this entity, reconciled against what the log actually
     * holds.
     *
     * The loop above counts an error when a record *throws*, and every throw it
     * catches writes a log row — so for years the two agreed and nobody had to
     * think about it. They stop agreeing the moment something fails without
     * throwing. `$wpdb` is exactly that: a refused write sets
     * `$wpdb->last_error`, returns false, and lets the caller carry on. A live
     * run wrote ten `Unknown column 'item_count'` rows and reported
     * `order 10 10 0 0 completed` followed by `Success: Migration complete`.
     * Told a run succeeded, nobody goes looking in a PHP error log — which is
     * how that column survived for the whole life of the feature.
     *
     * So the log is the authority on how many things went wrong, and the counter
     * follows it rather than the other way round. That also puts the progress
     * table in agreement with the retry panel, which has always taken its error
     * count from the log's own stats: what the run reports and what a retry will
     * re-attempt are now the same set.
     *
     * Reconciled per batch, not once at the end. A run of forty thousand orders
     * reports itself every few seconds, and a number that is wrong for twenty
     * minutes and right at the end is a number nobody can act on while there is
     * still time to stop.
     *
     * `max()` rather than a straight replacement, because the one failure this
     * cannot see is the log insert itself failing — there is no channel for
     * reporting that a log write was refused. If that ever happens the loop's
     * own tally is the higher, truer number, and it wins.
     */
    private function reconciledErrors(string $migrationId, string $entityType, int $counted): int
    {
        if ($migrationId === '') {
            return $counted;
        }

        return max($counted, $this->log->countErrors($migrationId, $entityType));
    }

    /**
     * Work out where in the source table this entity should resume.
     *
     * A run that was already in flight when cursors landed has no cursor key at
     * all. Restarting that entity from the beginning is the safe reading: every
     * processRecord() short-circuits on an ID-map hit, so the re-read costs a
     * pass of cheap skips rather than duplicated data. Guessing a cursor from
     * the old offset, by contrast, would skip rows outright.
     */
    private function resolveCursor(string $entityType, int $offset, ?string $migrationId): string|int|null
    {
        if ($this->state->hasEntityCursor($entityType)) {
            return $this->state->getEntityCursor($entityType);
        }

        if ($offset > 0) {
            $this->log->write(
                (string) $migrationId,
                $entityType,
                0,
                'warning',
                sprintf(
                    'No keyset cursor found at offset %d (run started before cursor pagination). '
                    . 'Restarting this entity from the beginning; already-migrated records are skipped via the ID map.',
                    $offset,
                ),
            );
        }

        // Write the key so the decision is taken exactly once per entity.
        $this->state->setEntityCursor($entityType, null);

        return null;
    }

    /**
     * Close off the current entity and move to the next, completing the run if
     * this was the last one.
     *
     * @return array{continue: bool, migration_id: string|null, entity_type: string|null, offset: int, total: int, processed: int}
     */
    private function finishEntity(string $currentType, ?string $migrationId, int $entityCount): array
    {
        $this->state->completeEntity($currentType);

        /** @see 'cartshift/migration/entity_completed' */
        do_action('cartshift/migration/entity_completed', $currentType, $migrationId);

        $this->state->advanceEntity();

        $hasMore = $this->state->getCurrentEntityIndex() < $entityCount;

        if (!$hasMore) {
            $this->completeRun($migrationId);
        }

        return $this->buildResult($hasMore);
    }

    /**
     * Close the run off.
     *
     * A finished dry run takes its simulated ID-map rows with it. They existed to
     * carry references from one request's batch to the next; once the run is over
     * they are dead weight, and dead weight that a later dry run would otherwise
     * inherit. Purged before the completion hook so nothing a listener does can
     * see the rehearsal's rows.
     */
    private function completeRun(?string $migrationId): void
    {
        $wasDryRun = $this->state->isDryRun();

        $this->state->complete();

        if ($wasDryRun) {
            $this->idMap->purgeSimulated();
        }

        /** @see 'cartshift/migration/completed' */
        do_action('cartshift/migration/completed', $migrationId);
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
     * Clear the in-process object cache without nuking a shared persistent cache.
     *
     * wp_cache_flush_runtime() landed in WordPress 6.0. Core's non-persistent
     * implementation aliases it to wp_cache_flush(), which is harmless because
     * that cache is per-request anyway. Drop-ins (Redis, Memcached) override it
     * to clear only their local array and leave the shared server untouched —
     * which is the whole point. Fall back only where the function is absent.
     */
    public static function flushRuntimeCache(): void
    {
        if (function_exists('wp_cache_flush_runtime')) {
            wp_cache_flush_runtime();

            return;
        }

        wp_cache_flush();
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

        // Once every entity is done $currentType is null, and null is not a usable
        // array key — PHP 8.5 deprecates coercing it to ''. Guard instead of casting.
        $entityData = $currentType !== null
            ? ($state['entities'][$currentType] ?? [])
            : [];

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
            // Additive keys. Nothing existing may be removed or renamed — the REST
            // controller, the WP-CLI command and the Vue UI all read this shape.
            'cursor'        => $currentType !== null ? ($state['cursors'][$currentType] ?? null) : null,
            'migrated'      => $currentType !== null ? $this->migratedCountFor($currentType) : 0,
            // The run this one is repairing, or null for an ordinary migration.
            // Always present so a caller can read it without knowing which kind
            // of run it asked for.
            'retry_of'      => $this->retryOf(),
        ];
    }

    /**
     * How many records of this entity type are already in the ID map.
     *
     * One indexed COUNT per batch result, so the UI can say "1,204 of 5,000
     * already migrated" without the orchestrator holding any mapping rows.
     */
    private function migratedCountFor(string $entityType): int
    {
        $migrators = $this->resolveMigrators([$entityType]);

        if ($migrators === []) {
            return 0;
        }

        try {
            return $migrators[0]->migratedCount();
        } catch (\Throwable) {
            // A progress nicety must never take a migration down with it.
            return 0;
        }
    }

    /**
     * The code to log for an exception a record threw.
     *
     * A migrator that already knows why it failed says so by throwing something
     * that carries the reason; everything else is genuinely unexpected, and
     * saying so is more honest than guessing.
     *
     * @see HasErrorCode
     */
    private static function errorCodeFor(\Throwable $e): MigrationErrorCode
    {
        return $e instanceof HasErrorCode
            ? $e->errorCode()
            : MigrationErrorCode::UnexpectedException;
    }

    /**
     * The next synthetic FluentCart ID for a dry-run's in-memory ID map.
     *
     * @see self::$simulatedId
     */
    private static function nextSimulatedId(): int
    {
        return ++self::$simulatedId;
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
