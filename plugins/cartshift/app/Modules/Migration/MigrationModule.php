<?php

declare(strict_types=1);

namespace CartShift\Modules\Migration;

use CartShift\Core\Container;
use CartShift\Core\Contracts\ModuleInterface;
use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Domain\Migration\MigrationFinalizer;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Domain\Migration\MigrationOrchestratorFactory;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Storage\ProductMapRepository;

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
        $container->singleton(ProductMapRepository::class, static fn (): ProductMapRepository => new ProductMapRepository());
        $container->singleton(MigrationFinalizer::class, static fn (Container $c): MigrationFinalizer => new MigrationFinalizer(
            $c->get(IdMapRepository::class),
            $c->get(MigrationLogRepository::class),
        ));

        // Both FluentCart touchpoints are injected as closures so MappingPromoter
        // itself stays unit-testable without a live FluentCart install. They are
        // MigrationOrchestratorFactory's own statics rather than closures written
        // here, so WP-CLI — which has no container and builds its own factory —
        // promotes through identical code instead of a lookalike that can drift.
        $container->singleton(MappingPromoter::class, static fn (Container $c): MappingPromoter => new MappingPromoter(
            $c->get(ProductMapRepository::class),
            $c->get(IdMapRepository::class),
            MigrationOrchestratorFactory::fcProductStillExists(...),
            MigrationOrchestratorFactory::createOrphanVariant(...),
            MigrationOrchestratorFactory::fcVariantIdsFor(...),
            MigrationOrchestratorFactory::linkLosesDownloads(...),
        ));

        $container->singleton(
            MigrationOrchestratorFactory::class,
            static fn (Container $c): MigrationOrchestratorFactory => new MigrationOrchestratorFactory(
                $c->get(IdMapRepository::class),
                $c->get(MigrationLogRepository::class),
                $c->get(MigrationState::class),
                $c->get(MappingPromoter::class),
                $c->get(ProductMapRepository::class),
            ),
        );

        $container->singleton(BatchProcessor::class, static function (Container $c): BatchProcessor {
            // A fresh orchestrator per invocation. Migrators read the migration
            // ID from MigrationState at the moment they write, so this is about
            // not holding stale repositories, nothing more — and assembling one
            // is the factory's job, not this closure's, because a run built here
            // and a run built in the REST controller have to be the same run.
            $orchestratorFactory = static fn (): MigrationOrchestrator
                => $c->get(MigrationOrchestratorFactory::class)->forRun();

            return new BatchProcessor($orchestratorFactory, $c->get(MigrationState::class));
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
                'CartShift\\Http\\Controllers\\PreviewController',
                'CartShift\\Http\\Controllers\\MigrationController',
                'CartShift\\Http\\Controllers\\RollbackController',
                'CartShift\\Http\\Controllers\\FinalizeController',
                'CartShift\\Http\\Controllers\\LogController',
                'CartShift\\Http\\Controllers\\MappingController',
            ];

            foreach ($controllers as $class) {
                if (class_exists($class)) {
                    (new $class($container))->registerRoutes();
                }
            }
        });
    }
}
