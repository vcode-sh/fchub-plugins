<?php

declare(strict_types=1);

namespace CartShift\CLI;

defined('ABSPATH') || exit;

use CartShift\State\MigrationState;

final class MigrateCommand
{
    /**
     * Register all CartShift WP-CLI commands.
     */
    public static function register(): void
    {
        \WP_CLI::add_command('cartshift migrate', [self::class, 'migrate']);
        \WP_CLI::add_command('cartshift rollback', [self::class, 'rollback']);
        \WP_CLI::add_command('cartshift status', [self::class, 'status']);
    }

    /**
     * Run the WooCommerce to FluentCart migration.
     *
     * ## DESCRIPTION
     *
     * Migration via WP-CLI coming in CartShift Pro.
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function migrate(array $args, array $assocArgs): void
    {
        \WP_CLI::log('Migration via WP-CLI coming in CartShift Pro.');
    }

    /**
     * Roll back a previous migration.
     *
     * ## DESCRIPTION
     *
     * Rollback via WP-CLI coming in CartShift Pro.
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function rollback(array $args, array $assocArgs): void
    {
        \WP_CLI::log('Rollback via WP-CLI coming in CartShift Pro.');
    }

    /**
     * Show the current migration status.
     *
     * ## DESCRIPTION
     *
     * Reads the stored migration state and displays the current status.
     *
     * @param string[] $args       Positional arguments.
     * @param string[] $assocArgs  Associative arguments.
     */
    public static function status(array $args, array $assocArgs): void
    {
        $state = new MigrationState();
        $progress = $state->getProgress();

        if ($progress['status'] === 'idle') {
            \WP_CLI::log('No migration has been run yet.');
            return;
        }

        \WP_CLI::log(sprintf('Status: %s', $progress['status']));

        if (!empty($progress['migration_id'])) {
            \WP_CLI::log(sprintf('Migration ID: %s', $progress['migration_id']));
        }

        if (!empty($progress['started_at'])) {
            \WP_CLI::log(sprintf('Started at: %s', $progress['started_at']));
        }

        if (!empty($progress['completed_at'])) {
            \WP_CLI::log(sprintf('Completed at: %s', $progress['completed_at']));
        }

        if (!empty($progress['error'])) {
            \WP_CLI::warning(sprintf('Error: %s', $progress['error']));
        }

        if (!empty($progress['entities']) && is_array($progress['entities'])) {
            \WP_CLI::log('');
            \WP_CLI::log('Entity progress:');

            foreach ($progress['entities'] as $type => $entity) {
                \WP_CLI::log(sprintf(
                    '  %s: %s — %d/%d processed, %d skipped, %d errors',
                    $type,
                    $entity['status'] ?? 'unknown',
                    $entity['processed'] ?? 0,
                    $entity['total'] ?? 0,
                    $entity['skipped'] ?? 0,
                    $entity['errors'] ?? 0,
                ));
            }
        }
    }
}
