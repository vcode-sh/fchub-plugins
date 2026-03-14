<?php

declare(strict_types=1);

namespace CartShift\Domain\Migration\Contracts;

defined('ABSPATH') || exit;

interface MigratorInterface
{
    /**
     * The entity type this migrator handles (e.g. 'products', 'customers').
     */
    public function entityType(): string;

    /**
     * Count the total number of WC records available for migration.
     */
    public function count(): int;

    /**
     * Execute the migration for this entity type.
     */
    public function run(): void;
}
