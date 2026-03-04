<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Http\Routes\AdminRoutes;
use FChubMultiCurrency\Http\Routes\PublicRoutes;

defined('ABSPATH') || exit;

final class RestModule implements ModuleContract
{
    public function register(): void
    {
        add_action('rest_api_init', function () {
            PublicRoutes::register();
            AdminRoutes::register();
        });
    }
}
