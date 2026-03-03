<?php

declare(strict_types=1);

namespace FChubWishlist\Frontend\Hooks;

defined('ABSPATH') || exit;

final class WishlistIcon
{
    public static function render(string $style = 'heart', int $width = 20, int $height = 20): string
    {
        $path = match ($style) {
            'bookmark' => 'M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z',
            'star' => 'M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.56'
                . ' 5.82 22 7 14.14l-5-4.87 6.91-1.01z',
            default => 'M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06'
                . 'a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78'
                . ' 1.06-1.06a5.5 5.5 0 0 0 0-7.78z',
        };

        return sprintf(
            '<svg class="fchub-wishlist-heart-icon" xmlns="http://www.w3.org/2000/svg" '
            . 'viewBox="0 0 24 24" width="%d" height="%d" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="%s"/></svg>',
            $width,
            $height,
            esc_attr($path)
        );
    }
}
