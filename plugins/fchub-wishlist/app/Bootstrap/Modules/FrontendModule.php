<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap\Modules;

use FChubWishlist\Bootstrap\ModuleContract;
use FChubWishlist\Frontend\Assets\AssetLoader;
use FChubWishlist\Frontend\Hooks\ProductCardHeartHook;
use FChubWishlist\Frontend\Hooks\SingleProductButtonHook;
use FChubWishlist\Frontend\Hooks\CounterBadgeHook;
use FChubWishlist\Frontend\Shortcode\WishlistShortcode;

defined('ABSPATH') || exit;

final class FrontendModule implements ModuleContract
{
    public function register(): void
    {
        // Enqueue frontend JS/CSS assets (priority 6 to load early)
        add_action('wp_enqueue_scripts', [AssetLoader::class, 'enqueue'], 6);

        // Inject heart icon on product cards
        ProductCardHeartHook::register();

        // Inject "Add to Wishlist" button on single product page
        SingleProductButtonHook::register();

        // Render wishlist counter badge in header
        CounterBadgeHook::register();

        // Register [fchub_wishlist] shortcode
        WishlistShortcode::register();
    }
}
