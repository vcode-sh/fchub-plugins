<?php

declare(strict_types=1);

namespace FChubWishlist\Bootstrap;

defined('ABSPATH') || exit;

interface ModuleContract
{
    public function register(): void;
}
