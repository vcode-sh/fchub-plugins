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
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;

defined('ABSPATH') || exit();

final class MigrationModule implements ModuleInterface
{
    /** @var list<class-string> */
    private const array CONTROLLERS = [
        \CartShift\Http\Controllers\PreflightController::class,
        \CartShift\Http\Controllers\PreviewController::class,
        \CartShift\Http\Controllers\MigrationController::class,
        \CartShift\Http\Controllers\RollbackController::class,
        \CartShift\Http\Controllers\FinalizeController::class,
        \CartShift\Http\Controllers\LogController::class,
        \CartShift\Http\Controllers\MappingController::class,
        \CartShift\Http\Controllers\SubscriptionAuditController::class,
        \CartShift\Http\Controllers\SubscriptionPackageController::class,
    ];

    #[\Override]
    public function key(): string
    {
        return 'migration';
    }

    #[\Override]
    public function register(Container $container): void
    {
        // Both mapping repositories are bound at the `local` source namespace,
        // stated rather than defaulted. A cross-site run resolves its own pair
        // from the operator-supplied `--source-key`, and that key belongs to the
        // command that was given it — not to a container singleton shared with
        // every same-site request in the process.
        $container->singleton(
            IdMapRepository::class,
            static fn (): IdMapRepository => new IdMapRepository(Constants::DEFAULT_SOURCE_KEY),
        );
        $container->singleton(MigrationLogRepository::class, static fn (): MigrationLogRepository => new MigrationLogRepository());
        $container->singleton(MigrationState::class, static fn (): MigrationState => new MigrationState());
        $container->singleton(
            ProductMapRepository::class,
            static fn (): ProductMapRepository => new ProductMapRepository(Constants::DEFAULT_SOURCE_KEY),
        );

        // The dataset seam. Both are stateless, both are shared by the live and
        // package sources Task 3 adds, and both must be the same instance
        // everywhere so there is exactly one canonicalisation in the process.
        $container->singleton(
            SubscriptionRecordFactory::class,
            static fn (): SubscriptionRecordFactory => new SubscriptionRecordFactory(),
        );
        $container->singleton(
            DatasetClosureValidator::class,
            static fn (): DatasetClosureValidator => new DatasetClosureValidator(),
        );
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
            foreach (self::CONTROLLERS as $class) {
                if (class_exists($class)) {
                    (new $class($container))->registerRoutes();
                }
            }
        });
    }

    /** @return list<class-string> */
    public static function controllerClasses(): array
    {
        return self::CONTROLLERS;
    }
}
