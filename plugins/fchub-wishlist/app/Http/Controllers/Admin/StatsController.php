<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Admin;

use FChubWishlist\Storage\Queries\WishlistStatsQuery;

defined('ABSPATH') || exit;

final class StatsController
{
    public static function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $stats = new WishlistStatsQuery();
        $overview = $stats->getOverview();

        return new \WP_REST_Response([
            'success' => true,
            'data'    => array_merge($overview, [
                'most_wishlisted'  => $stats->getMostWishlistedWithTitles(20),
                'daily_activity'   => $stats->getDailyActivity(30),
                'average_items'    => $stats->getAverageItemsPerWishlist(),
                'active_wishlists' => $stats->getActiveWishlistCount(30),
            ]),
        ]);
    }
}
