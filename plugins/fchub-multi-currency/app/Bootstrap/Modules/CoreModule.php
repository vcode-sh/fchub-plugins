<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\GDPR\PersonalDataHandler;
use FChubMultiCurrency\Integration\AddonsRegistration;
use FChubMultiCurrency\Integration\MultiCurrencySettings;

defined('ABSPATH') || exit;

final class CoreModule implements ModuleContract
{
    public function register(): void
    {
        AddonsRegistration::register();
        MultiCurrencySettings::register();
        PersonalDataHandler::register();
    }
}
