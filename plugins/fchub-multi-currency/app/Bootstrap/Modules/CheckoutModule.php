<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Integration\CheckoutHooks;
use FChubMultiCurrency\Integration\OrderSnapshotHooks;

defined('ABSPATH') || exit;

final class CheckoutModule implements ModuleContract
{
    public function register(): void
    {
        CheckoutHooks::register();
        OrderSnapshotHooks::register();
    }
}
