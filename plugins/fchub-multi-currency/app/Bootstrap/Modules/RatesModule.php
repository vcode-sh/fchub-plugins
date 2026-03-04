<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;

defined('ABSPATH') || exit;

final class RatesModule implements ModuleContract
{
    public function register(): void
    {
        // Allow external override of the rate provider
        add_filter('fchub_mc/rate_provider', function ($provider) {
            return $provider;
        }, 10, 1);
    }
}
