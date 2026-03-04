<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap;

defined('ABSPATH') || exit;

final class Plugin
{
    public static function boot(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        foreach (ModuleRegistry::classes() as $moduleClass) {
            if (!class_exists($moduleClass)) {
                continue;
            }

            $module = new $moduleClass();

            if ($module instanceof ModuleContract) {
                $module->register();
            }
        }
    }
}
