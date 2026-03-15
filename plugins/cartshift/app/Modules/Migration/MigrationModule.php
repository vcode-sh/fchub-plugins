<?php

declare(strict_types=1);

namespace CartShift\Modules\Migration;

use CartShift\Core\Container;
use CartShift\Core\Contracts\ModuleInterface;
use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Domain\Migration\MigrationFinalizer;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;

defined('ABSPATH') || exit();

final class MigrationModule implements ModuleInterface
{
    #[\Override]
    public function key(): string
    {
        return 'migration';
    }

    #[\Override]
    public function register(Container $container): void
    {
        $container->singleton(IdMapRepository::class, static fn (): IdMapRepository => new IdMapRepository());
        $container->singleton(MigrationLogRepository::class, static fn (): MigrationLogRepository => new MigrationLogRepository());
        $container->singleton(MigrationState::class, static fn (): MigrationState => new MigrationState());
        $container->singleton(MigrationFinalizer::class, static fn (Container $c): MigrationFinalizer => new MigrationFinalizer(
            $c->get(IdMapRepository::class),
        ));

        $container->singleton(BatchProcessor::class, static function (Container $c): BatchProcessor {
            $state = $c->get(MigrationState::class);

            // Factory builds a fresh orchestrator each invocation so migrators
            // always carry the current migration ID from state.
            $orchestratorFactory = static function () use ($c): MigrationOrchestrator {
                $state = $c->get(MigrationState::class);
                $idMap = $c->get(IdMapRepository::class);
                $log = $c->get(MigrationLogRepository::class);
                $migrationId = $state->getMigrationId() ?? '';

                $migrators = [
                    new ProductMigrator($idMap, $log, $state, $migrationId),
                    new CustomerMigrator($idMap, $log, $state, $migrationId),
                    new CouponMigrator($idMap, $log, $state, $migrationId),
                    new OrderMigrator($idMap, $log, $state, $migrationId),
                    new SubscriptionMigrator($idMap, $log, $state, $migrationId),
                ];

                return new MigrationOrchestrator($migrators, $state, $idMap, $log);
            };

            return new BatchProcessor($orchestratorFactory, $state);
        });

        // Register Action Scheduler hook early so AS can find it.
        $batchProcessor = $container->get(BatchProcessor::class);
        $batchProcessor->register();

        add_action('rest_api_init', static function () use ($container): void {
            $controllers = [
                'CartShift\\Http\\Controllers\\PreflightController',
                'CartShift\\Http\\Controllers\\MigrationController',
                'CartShift\\Http\\Controllers\\RollbackController',
                'CartShift\\Http\\Controllers\\FinalizeController',
                'CartShift\\Http\\Controllers\\LogController',
            ];

            foreach ($controllers as $class) {
                if (class_exists($class)) {
                    (new $class($container))->registerRoutes();
                }
            }
        });
    }
}
