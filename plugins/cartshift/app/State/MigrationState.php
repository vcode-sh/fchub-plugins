<?php

declare(strict_types=1);

namespace CartShift\State;

defined('ABSPATH') || exit;

final class MigrationState
{
    private const string OPTION_KEY = 'cartshift_migration_state';

    /**
     * Start a new migration run.
     *
     * @param string[] $entityTypes Entity types to migrate.
     * @return array<string, mixed> The new migration state.
     */
    public function start(array $entityTypes, bool $dryRun = false): array
    {
        $state = [
            'migration_id'         => wp_generate_uuid4(),
            'status'               => 'running',
            'dry_run'              => $dryRun,
            'started_at'           => gmdate('Y-m-d H:i:s'),
            'completed_at'         => null,
            'entity_types'         => array_values($entityTypes),
            'current_entity_index' => 0,
            'current_offset'       => 0,
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

        update_option(self::OPTION_KEY, $state, false);

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

        update_option(self::OPTION_KEY, $state, false);
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
        update_option(self::OPTION_KEY, $state, false);
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

        update_option(self::OPTION_KEY, $state, false);
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

        update_option(self::OPTION_KEY, $state, false);
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
        update_option(self::OPTION_KEY, $state, false);
    }

    /**
     * Check whether the migration has been cancelled.
     */
    public function isCancelled(): bool
    {
        $state = $this->getCurrent();

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

        update_option(self::OPTION_KEY, $state, false);
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
     * Get the current offset within the current entity batch.
     */
    public function getCurrentOffset(): int
    {
        $state = $this->getCurrent();

        return $state['current_offset'] ?? 0;
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
     * Advance the offset by a given amount.
     */
    public function advanceOffset(int $amount): void
    {
        $state = $this->getCurrent();
        if (!$state) {
            return;
        }

        $state['current_offset'] = ($state['current_offset'] ?? 0) + $amount;
        update_option(self::OPTION_KEY, $state, false);
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
        update_option(self::OPTION_KEY, $state, false);
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
     * @return array<string, mixed>|null
     */
    public function getCurrent(): ?array
    {
        $state = get_option(self::OPTION_KEY, null);

        return is_array($state) ? $state : null;
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
    }
}
