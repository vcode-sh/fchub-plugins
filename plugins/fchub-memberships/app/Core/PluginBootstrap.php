<?php

namespace FChubMemberships\Core;

use FChubMemberships\Modules\Admin\AdminModule;
use FChubMemberships\Modules\Automation\FluentCrmAutomationModule;
use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
use FChubMemberships\Modules\Runtime\FluentCartRuntimeModule;

defined('ABSPATH') || exit;

final class PluginBootstrap
{
    public static function boot(): void
    {
        $container = new Container();
        $container->instance(FeatureFlags::class, FeatureFlags::fromWordPress());

        $registry = new ModuleRegistry($container);
        $container->instance(ModuleRegistry::class, $registry);

        $registry
            ->add(new InfrastructureModule())
            ->add(new AdminModule())
            ->add(new FluentCartRuntimeModule())
            ->add(new FluentCrmAutomationModule());

        $registry->boot();
    }
}
