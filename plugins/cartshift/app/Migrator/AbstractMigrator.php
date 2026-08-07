<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;

/**
 * Shared plumbing for entity migrators.
 *
 * The batch loop lives in MigrationOrchestrator::processBatch(), not here — both the
 * REST controller and the WP-CLI command drive it from there. This class supplies the
 * per-record contract (fetchBatch/processRecord/validateRecord) and the odds and ends
 * every migrator needs.
 */
abstract class AbstractMigrator implements MigratorInterface
{
    // Counter surface for subclasses. The orchestrator keeps its own tallies; these are
    // here for migrators that want to track their own.

    /** @var int Running counter of processed records */
    protected int $processed = 0;

    /** @var int Running counter of skipped records */
    protected int $skipped = 0;

    /** @var int Running counter of error records */
    protected int $errors = 0;

    /** Explicit scope override, for callers that must not read or write state. */
    private ?MigrationScope $scopeOverride = null;

    private ?ScopeResolver $scopeResolver = null;

    public function __construct(
        protected readonly IdMapRepository $idMap,
        protected readonly MigrationLogRepository $log,
        protected readonly MigrationState $migrationState,
        protected readonly int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {}

    /**
     * The migration ID every log row and ID-map row this migrator writes is stamped with.
     *
     * Read from MigrationState on every use, never held. A migrator used to be
     * handed the ID at construction, which meant a caller could — and the REST
     * controller and the WP-CLI command both did — build its migrators before
     * MigrationOrchestrator::startMigration() called MigrationState::start(),
     * which mints a fresh UUID. The first batch then wrote its ID-map and log
     * rows under an ID that appeared in no state anywhere: rollback could not see
     * those records, and retry could not see those failures.
     *
     * The fix is not to reorder the construction. It is to leave the migrator
     * with nothing to be stale about: MigrationState owns the ID, there is no
     * second copy, and a divergence between them is no longer a thing that can be
     * expressed. An empty string is the honest answer when no run is in flight —
     * the preflight controller builds migrators purely to count rows.
     */
    protected function migrationId(): string
    {
        return $this->migrationState->getMigrationId() ?? '';
    }

    /**
     * Migrate under this scope instead of the one in state.
     *
     * For /preview, which counts what a scope *would* migrate without starting
     * anything. A real run never calls this: it reads the scope from
     * MigrationState, the same way it reads the migration ID, and that is what
     * makes retry and resume reuse the scope with no extra code.
     */
    #[\Override]
    public function useScope(MigrationScope $scope): void
    {
        $this->scopeOverride = $scope;
        $this->scopeResolver = null;
    }

    /**
     * The scope governing this migrator, read fresh from state on every use.
     *
     * Never latched, for the same reason migrationId() is not: later batches
     * run in fresh requests, and a copy taken at construction would be a second
     * source of truth waiting to disagree with the first.
     */
    protected function scope(): MigrationScope
    {
        return $this->scopeOverride ?? $this->migrationState->getScope();
    }

    /**
     * The resolver for this request. Memoised because the closure costs three
     * queries and a batch asks for it several times; not cached beyond the
     * request, because a stale closure is a silently widened migration.
     */
    protected function scopeResolver(): ScopeResolver
    {
        return $this->scopeResolver ??= new ScopeResolver($this->scope());
    }

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

    /**
     * Default no-op dry-run initialisation.
     *
     * Concrete, not abstract: a migrator whose initialize() creates nothing has
     * nothing to simulate, and an abstract method here would be a fatal error for
     * every third-party migrator written against an earlier version.
     */
    #[\Override]
    public function initializeSimulated(): void
    {
        // No-op by default.
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
     * Fetch the next batch of WC records after the given cursor.
     *
     * @return mixed[]
     */
    #[\Override]
    abstract public function fetchBatch(string|int|null $cursor, int $limit): array;

    /**
     * Hydrate exactly these WC records, for a retry run.
     *
     * Concrete rather than abstract, and the difference matters on upgrade. An
     * abstract method added to a base class is a fatal error for every existing
     * subclass — the whole site, not one migrator — the moment the plugin
     * updates. Every migrator shipped here overrides this, so the default costs
     * them nothing; it exists so that a third-party migrator written against an
     * earlier version keeps loading.
     *
     * It throws rather than returning []. Returning [] would be the wrong kind
     * of graceful: to the orchestrator an empty result is indistinguishable from
     * "nothing left to retry", so the user would click Retry, watch zero records
     * be attempted, and conclude their data was fine all along. A quiet lie is
     * worse than a loud failure. Throwing fails the run with a sentence that
     * says exactly which migrator is at fault, and the orchestrator logs it as
     * `migration_aborted`.
     *
     * An empty ID list is not an error — there is genuinely nothing to do — so
     * that case returns before the throw.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return mixed[]
     *
     * @throws RecordMigrationException Always, unless the ID list is empty.
     */
    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        if ($wcIds === []) {
            return [];
        }

        throw new RecordMigrationException(
            sprintf(
                'Cannot retry %d %s record(s): %s does not implement fetchByIds().',
                count($wcIds),
                $this->getEntityType(),
                static::class,
            ),
            MigrationErrorCode::MigrationAborted,
        );
    }

    /**
     * Deduplicate a list of retry IDs into positive integers, order preserved.
     *
     * The log stores IDs as strings, and a retry list arrives from a query that
     * has already made them DISTINCT — but the list also passes through a REST
     * request and a filter or two on its way here, so neither is worth trusting.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return list<int>
     */
    protected static function normalizeIntIds(array $wcIds): array
    {
        $ids = [];

        foreach ($wcIds as $raw) {
            if (!is_int($raw) && !is_string($raw)) {
                continue;
            }

            $id = (int) $raw;

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The cursor value reached by having handed out this record.
     *
     * The default is the record's own source ID, which is what every
     * `WHERE id > :cursor ORDER BY id ASC` migrator wants. Numeric IDs are
     * returned as ints so the persisted cursor round-trips through
     * update_option()/get_option() without turning into a string mid-run.
     */
    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        $id = $this->getRecordId($record);

        return ctype_digit($id) ? (int) $id : $id;
    }

    /**
     * Count the ID-map rows already recorded for this entity type.
     *
     * A COUNT query, deliberately: IdMapRepository's bulk getter loads every
     * mapping row into memory, which is precisely what a 200k-order store
     * cannot afford. This class does not own IdMapRepository, so the count is
     * issued here against the same table that repository writes to.
     *
     * Simulated rows are excluded unconditionally. This number is what the UI
     * shows as "already migrated", and a dry run migrates nothing.
     *
     * @see \CartShift\Storage\IdMapRepository
     */
    #[\Override]
    public function migratedCount(): int
    {
        global $wpdb;

        $types = $this->migratedEntityTypes();

        if ($types === []) {
            return 0;
        }

        $table = $wpdb->prefix . 'cartshift_id_map';
        $placeholders = implode(', ', array_fill(0, count($types), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE entity_type IN ({$placeholders}) AND is_simulated = 0",
            ...$types,
        ));
    }

    /**
     * The ID-map entity types migratedCount() should tally.
     *
     * One entity type for most migrators. Customers are the exception — they
     * write both `customer` and `guest_customer` rows.
     *
     * @return list<string>
     */
    protected function migratedEntityTypes(): array
    {
        return [$this->getEntityType()];
    }

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
     *
     * `$code` is additive metadata and trails the existing parameters, so every
     * caller that predates it keeps working. It never replaces `$message`: the
     * message carries the specifics — which SKU, which coupon code, which ID —
     * and the code carries the class of thing that went wrong, which is the part
     * a UI can group four thousand rows by.
     *
     * @see \CartShift\Support\Enums\MigrationErrorCode
     */
    protected function writeLog(
        string|int $wcId,
        string $status,
        string $message = '',
        MigrationErrorCode|string|null $code = null,
    ): void {
        $this->log->write(
            $this->migrationId(),
            $this->getEntityType(),
            $wcId,
            $status,
            $message,
            null,
            $code,
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
