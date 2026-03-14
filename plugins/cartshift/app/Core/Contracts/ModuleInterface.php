<?php

declare(strict_types=1);

namespace CartShift\Core\Contracts;

use CartShift\Core\Container;

defined('ABSPATH') || exit();

interface ModuleInterface
{
    public function key(): string;

    public function register(Container $container): void;
}
