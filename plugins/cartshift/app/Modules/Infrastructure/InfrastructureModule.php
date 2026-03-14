<?php

declare(strict_types=1);

namespace CartShift\Modules\Infrastructure;

use CartShift\Core\Container;
use CartShift\Core\Contracts\ModuleInterface;
use CartShift\Support\Logger;
use CartShift\Support\Migrations;

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
        if (Migrations::needsUpgrade()) {
            Migrations::run();
        }

        $container->instance(Logger::class, new Logger());
    }
}
