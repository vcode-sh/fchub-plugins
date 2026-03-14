<?php

declare(strict_types=1);

namespace CartShift\Modules\Migration;

use CartShift\Core\Container;
use CartShift\Core\Contracts\ModuleInterface;
use CartShift\Domain\Migration\MigrationFinalizer;
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
