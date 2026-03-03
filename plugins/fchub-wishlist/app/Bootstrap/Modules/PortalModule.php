<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap\Modules;

use FChubWishlist\Bootstrap\ModuleContract;
use FChubWishlist\Frontend\Portal\CustomerPortalEndpoint;

defined('ABSPATH') || exit;

final class PortalModule implements ModuleContract
{
    public function register(): void
    {
        CustomerPortalEndpoint::register();
    }
}
