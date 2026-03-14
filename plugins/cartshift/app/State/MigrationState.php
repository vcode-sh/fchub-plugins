<?php

namespace CartShift\State;

defined('ABSPATH') or die;

class MigrationState
{
    const OPTION_KEY = 'cartshift_migration_state';

    /**
     * Start a new migration run.
     *
     * @param array $entityTypes Entity types to migrate.
     * @return array The new migration state.
     */
    public function start(array $entityTypes, bool $dryRun = false): array
    {
        $state = [
            'migration_id' => wp_generate_uuid4(),
            'status'       => 'running',
            'dry_run'      => $dryRun,
            'started_at'   => gmdate('Y-m-d H:i:s'),
            'completed_at' => null,
            'entities'     => [],
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
     * Get the current migration state.
     *
     * @return array|null
     */
    public function getCurrent(): ?array
    {
        $state = get_option(self::OPTION_KEY, null);
        return is_array($state) ? $state : null;
    }

    /**
     * Get progress summary.
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
