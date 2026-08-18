<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Integration\OrderSnapshotHooks;

defined('ABSPATH') || exit;

/**
 * Deliberately absent: the checkout patch-fragments filter. FluentCart sends
 * that array through wp_send_json to a client calling fragments.forEach(), so
 * a string-keyed entry breaks every checkout patch. The disclosure the old
 * filter carried is injected client-side by currency-projection.js, which is
 * also the only place that ever consumed it.
 */
final class CheckoutModule implements ModuleContract
{
    public function register(): void
    {
        OrderSnapshotHooks::register();
    }
}
