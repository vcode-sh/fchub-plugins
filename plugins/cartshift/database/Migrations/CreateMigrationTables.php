<?php

declare(strict_types=1);

namespace CartShift\Database;

defined('ABSPATH') or die;

use CartShift\Support\Migrations;

/**
 * @deprecated Delegate to Migrations::run(). Kept for backward compatibility.
 */
final class CreateMigrationTables
{
    public function up(): void
    {
        Migrations::run();
    }

    public function drop(): void
    {
        Migrations::dropAll();
    }
}
