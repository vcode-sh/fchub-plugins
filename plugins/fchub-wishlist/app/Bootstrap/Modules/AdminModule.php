<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap\Modules;

use FChubWishlist\Bootstrap\ModuleContract;
use FChubWishlist\Integration\ProductsColumn;
use FChubWishlist\Support\AdminMenu;

defined('ABSPATH') || exit;

final class AdminModule implements ModuleContract
{
    public function register(): void
    {
        add_action('fluent_cart/loading_app', [AdminMenu::class, 'enqueueAssets']);
        add_action('fluent_cart/admin_js_loaded', [AdminMenu::class, 'ensureLoadOrder']);

        ProductsColumn::register();
    }
}
