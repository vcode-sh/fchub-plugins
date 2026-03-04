<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap;

defined('ABSPATH') || exit;

interface ModuleContract
{
    public function register(): void;
}
