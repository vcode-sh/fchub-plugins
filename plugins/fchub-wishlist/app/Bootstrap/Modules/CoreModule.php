<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap\Modules;

use FChubWishlist\Bootstrap\ModuleContract;
use FChubWishlist\Domain\GuestSession;
use FChubWishlist\Domain\PurchaseWatcher;
use FChubWishlist\Integration\AddonsRegistration;
use FChubWishlist\Integration\DashboardWidget;
use FChubWishlist\GDPR\PersonalDataHandler;

defined('ABSPATH') || exit;

final class CoreModule implements ModuleContract
{
    public function register(): void
    {
        // Register guest session cookie management and merge-on-login hooks
        GuestSession::register();

        // Register purchase watcher for auto-remove on order paid
        PurchaseWatcher::register();

        // Register in the FluentCart "Integration Modules" UI list
        AddonsRegistration::register();

        // Register dashboard stats widget
        DashboardWidget::register();

        // Register GDPR export and erasure hooks
        PersonalDataHandler::register();
    }
}
