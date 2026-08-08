<?php

declare(strict_types=1);

namespace CartShift\State;

defined('ABSPATH') || exit;

use CartShift\Domain\Scope\MigrationScope;

final class MigrationState
{
    private const string OPTION_KEY = 'cartshift_migration_state';

    /**
     * In-request copy of the option, or null when nothing has been read yet.
     *
     * A single processBatch() call reads the state a dozen-plus times — entity types,
     * index, offset, migration id, dry-run flag, per-entity counters — and the option
     * cannot change under it except by cancellation, which isCancelled() reads
     * separately. See $memoLoaded for why null alone is not a "not loaded" marker.
     *
     * @var array<string, mixed>|null
     */
    private array|null $memo = null;

    /** Whether $memo holds a real read. Distinguishes "no state" from "not read yet". */
    private bool $memoLoaded = false;

    /**
     * Start a new migration run.
     *
     * @param string[] $entityTypes Entity types to migrate.
     * @return array<string, mixed> The new migration state.
     */
    public function start(array $entityTypes, bool $dryRun = false, ?MigrationScope $scope = null): array
    {
        $state = [
            'migration_id'         => wp_generate_uuid4(),
            'status'               => 'running',
            'dry_run'              => $dryRun,
            'started_at'           => gmdate('Y-m-d H:i:s'),
            'completed_at'         => null,
            'entity_types'         => array_values($entityTypes),
            // What the owner confirmed. Read back by every later batch, by a
            // resume, and by a retry — a run that forgot its scope between
            // requests would silently widen, which is the one failure mode
            // selective migration cannot have.
            'scope'                => ($scope ?? MigrationScope::everything())->toArray(),
            'current_entity_index' => 0,
            'current_offset'       => 0,
            'cursors'              => [],
            'entities'             => [],
        ];

        foreach ($entityTypes as $type) {
            $state['entities'][$type] = [
                'status'    => 'pending',
                'total'     => 0,
                'processed' => 0,
                'skipped'   => 0,
                'errors'    => 0,
            ];
        }

        $this->persist($state);

        return $state;
    }

    /**
     * Update progress for a specific entity type.
     */
    public function updateProgress(string $entity, int $processed, int $total, int $skipped = 0, int $errors = 0): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['entities'][$entity] = [
            'status'    => 'running',
            'total'     => $total,
            'processed' => $processed,
            'skipped'   => $skipped,
            'errors'    => $errors,
        ];

        $this->persist($state);
    }

    /**
     * Mark an entity type as completed.
     */
    public function completeEntity(string $entity): void
    {
        $state = $this->getCurrent();
        if (!$state || !isset($state['entities'][$entity])) {
            return;
        }

        $state['entities'][$entity]['status'] = 'completed';
        $this->persist($state);
    }

    /**
     * Mark the entire migration as completed.
     */
    public function complete(): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['status'] = 'completed';
        $state['completed_at'] = gmdate('Y-m-d H:i:s');

        $this->persist($state);
    }

    /**
     * Cancel the running migration.
     */
    public function cancel(): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['status'] = 'cancelled';
        $state['completed_at'] = gmdate('Y-m-d H:i:s');

        $this->persist($state);
    }

    /**
     * F7: Mark a specific entity type as cancelled (not completed).
     */
    public function setCancelled(string $entity): void
    {
        $state = $this->getCurrent();
        if (!$state || !isset($state['entities'][$entity])) {
            return;
        }

        $state['entities'][$entity]['status'] = 'cancelled';
        $this->persist($state);
    }

    /**
     * Check whether the migration has been cancelled.
     *
     * Deliberately bypasses the memo. Cancellation is the one state change that
     * arrives from a *different* PHP request — the UI fires its own REST call while a
     * batch is mid-flight — so a cached read here would let the batch grind on to the
     * end of the entity after the user hit cancel. The fresh value is written back
     * into the memo, so a cancellation observed here is visible to every later reader
     * in this request too.
     */
    public function isCancelled(): bool
    {
        $state = $this->readFresh();

        return $state !== null && $state['status'] === 'cancelled';
    }

    /**
     * Mark the migration as failed with an error message.
     */
    public function setFailed(string $message): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['status'] = 'failed';
        $state['error'] = $message;
        $state['completed_at'] = gmdate('Y-m-d H:i:s');

        $this->persist($state);
    }

    /**
     * Get the current entity index in the batch sequence.
     */
    public function getCurrentEntityIndex(): int
    {
        $state = $this->getCurrent();

        return $state['current_entity_index'] ?? 0;
    }

    /**
     * How many records of the current entity have been handed to the batch loop.
     *
     * Since keyset pagination replaced LIMIT/OFFSET this is a progress counter
     * and nothing more — it no longer drives any query. The UI and the CLI both
     * read it, so it stays monotonically increasing and resets per entity.
     */
    public function getCurrentOffset(): int
    {
        $state = $this->getCurrent();

        return $state['current_offset'] ?? 0;
    }

    /**
     * The persisted keyset cursor for an entity type, or null at the start.
     *
     * Note the difference between "null cursor" and "no cursor": a run started
     * before cursors existed has no key at all, which hasEntityCursor() reports
     * and the orchestrator treats as "restart this entity".
     */
    public function getEntityCursor(string $entity): string|int|null
    {
        $state = $this->getCurrent();
        $cursor = $state['cursors'][$entity] ?? null;

        return is_string($cursor) || is_int($cursor) ? $cursor : null;
    }

    /**
     * Whether a cursor has ever been written for this entity in this run.
     */
    public function hasEntityCursor(string $entity): bool
    {
        $state = $this->getCurrent();

        return is_array($state['cursors'] ?? null)
            && array_key_exists($entity, $state['cursors']);
    }

    /**
     * Persist the keyset cursor an entity has reached.
     */
    public function setEntityCursor(string $entity, string|int|null $cursor): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        if (!is_array($state['cursors'] ?? null)) {
            $state['cursors'] = [];
        }

        $state['cursors'][$entity] = $cursor;
        $this->persist($state);
    }

    /**
     * Get the ordered entity types for this migration.
     *
     * @return string[]
     */
    public function getEntityTypes(): array
    {
        $state = $this->getCurrent();

        return $state['entity_types'] ?? [];
    }

    /**
     * What this run was asked to migrate.
     *
     * A run started before scopes existed has no key at all, and
     * MigrationScope::fromArray() reads that as "everything" — which is exactly
     * what those runs did.
     */
    public function getScope(): MigrationScope
    {
        $state = $this->getCurrent();

        return MigrationScope::fromArray($state['scope'] ?? null);
    }

    public function setScope(MigrationScope $scope): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['scope'] = $scope->toArray();
        $this->persist($state);
    }

    /**
     * Advance the offset by a given amount.
     */
    public function advanceOffset(int $amount): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['current_offset'] = ($state['current_offset'] ?? 0) + $amount;
        $this->persist($state);
    }

    /**
     * Move to the next entity type (reset offset to 0).
     */
    public function advanceEntity(): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['current_entity_index'] = ($state['current_entity_index'] ?? 0) + 1;
        $state['current_offset'] = 0;
        $this->persist($state);
    }

    /**
     * Check if the migration is currently running.
     */
    public function isRunning(): bool
    {
        $state = $this->getCurrent();

        return $state !== null && $state['status'] === 'running';
    }

    /**
     * Get the migration ID from the current state.
     */
    public function getMigrationId(): ?string
    {
        $state = $this->getCurrent();

        return $state['migration_id'] ?? null;
    }

    /**
     * Whether the current migration is a dry run.
     */
    public function isDryRun(): bool
    {
        $state = $this->getCurrent();

        return !empty($state['dry_run']);
    }

    /**
     * Get the current migration state.
     *
     * Served from the in-request memo after the first read. Every write in this class
     * refreshes it, so the memo can never be behind this request's own changes; the
     * only writer it cannot see is another request, and isCancelled() handles that.
     *
     * @return array<string, mixed>|null
     */
    public function getCurrent(): ?array
    {
        if ($this->memoLoaded) {
            return $this->memo;
        }

        return $this->readFresh();
    }

    /**
     * Read the option, bypassing the memo, and refresh the memo with what came back.
     *
     * @return array<string, mixed>|null
     */
    private function readFresh(): array|null
    {
        $state = get_option(self::OPTION_KEY, null);

        return $this->remember(is_array($state) ? $state : null);
    }

    /**
     * Persist state and keep the memo in step with it.
     *
     * @param array<string, mixed> $state
     */
    private function persist(array $state): void
    {
        update_option(self::OPTION_KEY, $state, false);

        $this->remember($state);
    }

    /**
     * @param array<string, mixed>|null $state
     * @return array<string, mixed>|null
     */
    private function remember(array|null $state): array|null
    {
        $this->memo = $state;
        $this->memoLoaded = true;

        return $state;
    }

    /**
     * Get progress summary.
     *
     * @return array<string, mixed>
     */
    public function getProgress(): array
    {
        $state = $this->getCurrent();
        if (!$state) {
            return ['status' => 'idle'];
        }

        return $state;
    }

    /**
     * Reset / clear state completely.
     */
    public function reset(): void
    {
        delete_option(self::OPTION_KEY);

        $this->remember(null);
    }
}
