<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration;

defined('ABSPATH') || exit;

use CartShift\Domain\Migration\Contracts\MigratorInterface;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationStatus;

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
     * Run migration for the given entity types.
     *
     * @param string[] $entityTypes Entity types to migrate (e.g. ['products', 'customers']).
     * @return string The migration ID.
     */
    public function run(array $entityTypes, bool $dryRun = false): string
    {
        $migrationId = wp_generate_uuid4();

        /** @see 'cartshift/migration/entity_types' */
        $entityTypes = apply_filters('cartshift/migration/entity_types', $entityTypes);

        $this->state->start($entityTypes, $dryRun);

        /** @see 'cartshift/migration/started' */
        do_action('cartshift/migration/started', $migrationId, $entityTypes, $dryRun);

        try {
            foreach ($this->resolveMigrators($entityTypes) as $migrator) {
                if ($this->state->isCancelled()) {
                    break;
                }

                $type = $migrator->entityType();

                /** @see 'cartshift/migration/entity_started' */
                do_action('cartshift/migration/entity_started', $type, $migrationId);

                $migrator->run();

                /** @see 'cartshift/migration/entity_completed' */
                do_action('cartshift/migration/entity_completed', $type, $migrationId);
            }

            if (!$this->state->isCancelled()) {
                $this->state->complete();

                /** @see 'cartshift/migration/completed' */
                do_action('cartshift/migration/completed', $migrationId);
            }
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
        }

        return $migrationId;
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
