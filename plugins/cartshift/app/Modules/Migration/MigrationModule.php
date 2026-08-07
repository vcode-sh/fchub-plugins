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

            // Factory builds a fresh orchestrator each invocation. Migrators read
            // the migration ID from MigrationState at the moment they write, so
            // this is about not holding stale repositories, nothing more.
            $orchestratorFactory = static function () use ($c): MigrationOrchestrator {
                $state = $c->get(MigrationState::class);
                $idMap = $c->get(IdMapRepository::class);
                $log = $c->get(MigrationLogRepository::class);

                $migrators = [
                    new ProductMigrator($idMap, $log, $state),
                    new CustomerMigrator($idMap, $log, $state),
                    new CouponMigrator($idMap, $log, $state),
                    new OrderMigrator($idMap, $log, $state),
                    new SubscriptionMigrator($idMap, $log, $state),
                ];

                return new MigrationOrchestrator($migrators, $state, $idMap, $log);
            };

            return new BatchProcessor($orchestratorFactory, $state);
        });

        // The Action Scheduler handler must be attached on every request, not
        // just admin ones: the request that actually runs a queued batch is a
        // cron or Action Scheduler runner request, and it will not find the hook
        // unless it was registered at plugin boot.
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
