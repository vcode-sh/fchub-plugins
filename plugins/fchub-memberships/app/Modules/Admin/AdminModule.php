<?php

namespace FChubMemberships\Modules\Admin;

use FChubMemberships\Core\Container;
use FChubMemberships\Core\Contracts\ModuleInterface;

defined('ABSPATH') || exit;

final class AdminModule implements ModuleInterface
{
    public function key(): string
    {
        return 'admin';
    }

    public function register(Container $container): void
    {
        add_action('admin_menu', [$this, 'registerAdminMenu']);
    }

    public function registerAdminMenu(): void
    {
        if (!defined('FLUENTCART_VERSION')) {
            return;
        }

        \FChubMemberships\Support\AdminMenu::register();
    }
}
