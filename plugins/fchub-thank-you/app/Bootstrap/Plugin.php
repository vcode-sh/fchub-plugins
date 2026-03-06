<?php

declare(strict_types=1);

namespace FchubThankYou\Bootstrap;

final class Plugin
{
    public static function boot(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        foreach (ModuleRegistry::classes() as $moduleClass) {
            /** @var ModuleContract $module */
            $module = new $moduleClass();
            $module->register();
        }
    }
}
