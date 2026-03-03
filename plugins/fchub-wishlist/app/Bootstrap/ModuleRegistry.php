<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap;

defined('ABSPATH') || exit;

final class ModuleRegistry
{
    /**
     * @return array<class-string<ModuleContract>>
     */
    public static function classes(): array
    {
        $modules = [
            \FChubWishlist\Bootstrap\Modules\CoreModule::class,
            \FChubWishlist\Bootstrap\Modules\RestModule::class,
            \FChubWishlist\Bootstrap\Modules\FrontendModule::class,
            \FChubWishlist\Bootstrap\Modules\PortalModule::class,
            \FChubWishlist\Bootstrap\Modules\AdminModule::class,
            \FChubWishlist\Bootstrap\Modules\FluentCrmModule::class,
        ];

        return apply_filters('fchub_wishlist/modules', $modules);
    }
}
