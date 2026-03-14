<?php

declare(strict_types=1);

namespace CartShift\Core;

use CartShift\Modules\Admin\AdminModule;
use CartShift\Modules\Infrastructure\InfrastructureModule;
use CartShift\Modules\Migration\MigrationModule;

defined('ABSPATH') || exit();

final class PluginBootstrap
{
    private static Container|null $container = null;

    public static function boot(): void
    {
        $container = new Container();
        $container->instance(FeatureFlags::class, FeatureFlags::fromWordPress());

        $registry = new ModuleRegistry($container);
        $container->instance(ModuleRegistry::class, $registry);

        $registry
            ->add(new InfrastructureModule())
            ->add(new AdminModule())
            ->add(new MigrationModule());

        $registry->boot();

        self::$container = $container;
    }

    public static function container(): Container
    {
        if (self::$container === null) {
            throw new \RuntimeException('CartShift has not been booted yet.');
        }

        return self::$container;
    }
}
