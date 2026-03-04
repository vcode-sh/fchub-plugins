<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Integration\StoreSettingsExtension;

defined('ABSPATH') || exit;

final class SettingsModule implements ModuleContract
{
    public function register(): void
    {
        StoreSettingsExtension::register();
    }
}
