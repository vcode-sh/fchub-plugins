<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap\Modules;

use FChubWishlist\Bootstrap\ModuleContract;
use FChubWishlist\Http\Routes\PublicRoutes;
use FChubWishlist\Http\Routes\AdminRoutes;

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
