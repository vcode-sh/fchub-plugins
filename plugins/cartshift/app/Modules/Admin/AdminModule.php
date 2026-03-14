<?php

declare(strict_types=1);

namespace CartShift\Modules\Admin;

use CartShift\Core\Container;
use CartShift\Core\Contracts\ModuleInterface;
use CartShift\Core\FeatureFlags;
use CartShift\Support\AdminMenu;

defined('ABSPATH') || exit();

final class AdminModule implements ModuleInterface
{
    #[\Override]
    public function key(): string
    {
        return 'admin';
    }

    #[\Override]
    public function register(Container $container): void
    {
        if (! is_admin()) {
            return;
        }

        /** @var FeatureFlags $flags */
        $flags = $container->get(FeatureFlags::class);

        $menu = new AdminMenu($flags);
        $container->instance(AdminMenu::class, $menu);

        add_action('admin_menu', [$menu, 'addMenuPage']);
        add_action('admin_enqueue_scripts', [$menu, 'enqueueAssets']);
    }
}
