<?php

namespace FChubHub\Core;

use FChubHub\Http\Routes;
use FChubHub\Support\AdminMenu;
use FChubHub\Support\HubUpdater;

defined('ABSPATH') || exit;

/**
 * The whole of FCHub's runtime footprint: register the admin menu, its
 * plugin-list action link, the REST routes the admin screen talks to, and an
 * updater that speaks only for FCHub itself. Nothing here reaches into another
 * plugin's data, and nothing here is required for any product plugin to work.
 */
final class Plugin
{
    public static function boot(): void
    {
        add_action('admin_menu', [AdminMenu::class, 'register'], 28);
        add_filter('plugin_action_links_' . plugin_basename(FCHUB_HUB_FILE), [AdminMenu::class, 'actionLinks']);

        add_action('rest_api_init', [Routes::class, 'register']);

        HubUpdater::register();
    }
}
