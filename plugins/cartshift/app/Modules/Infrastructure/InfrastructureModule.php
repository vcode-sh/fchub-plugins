<?php

declare(strict_types=1);

namespace CartShift\Modules\Infrastructure;

use CartShift\Core\Container;
use CartShift\Core\Contracts\ModuleInterface;
use CartShift\Support\Logger;
use CartShift\Support\Migrations;
use CartShift\Domain\Transfer\Order\HistoricalPaymentGuard;

defined('ABSPATH') || exit();

final class InfrastructureModule implements ModuleInterface
{
    #[\Override]
    public function key(): string
    {
        return 'infrastructure';
    }

    #[\Override]
    public function register(Container $container): void
    {
        if (Migrations::needsAutomaticUpgrade()) {
            Migrations::run();
        }

        $container->instance(Logger::class, new Logger());
        $paymentGuard = new HistoricalPaymentGuard();
        $paymentGuard->register();
        $container->instance(HistoricalPaymentGuard::class, $paymentGuard);
    }
}
