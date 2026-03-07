<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap;

defined('ABSPATH') || exit;

final class ModuleRegistry
{
    /**
     * @return array<class-string<ModuleContract>>
     */
    public static function classes(): array
    {
        $modules = [
            Modules\SettingsModule::class,
            Modules\CoreModule::class,
            Modules\ContextModule::class,
            Modules\FrontendModule::class,
            Modules\BlocksModule::class,
            Modules\CheckoutModule::class,
            Modules\AdminModule::class,
            Modules\RestModule::class,
            Modules\FluentCrmModule::class,
            Modules\FluentCommunityModule::class,
            Modules\DiagnosticsModule::class,
        ];

        return apply_filters('fchub_mc/modules', $modules);
    }
}
