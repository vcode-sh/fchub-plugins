<?php

declare(strict_types=1);

namespace FChubWishlist\FluentCRM;

defined('ABSPATH') || exit;

class WishlistAutomation
{
    public static function boot(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        // Triggers
        new Triggers\ItemAddedTrigger();
        new Triggers\ItemRemovedTrigger();

        // Actions
        new Actions\AddToWishlistAction();

        // Profile Section
        (new ProfileSection\WishlistProfileSection())->register();

        // Segment Filters
        Filters\WishlistFilters::register();
    }
}
