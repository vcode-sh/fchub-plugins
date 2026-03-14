<?php

namespace FChubMemberships\Core\Contracts;

use FChubMemberships\Core\Container;

defined('ABSPATH') || exit;

interface ModuleInterface
{
    public function key(): string;

    public function register(Container $container): void;
}
