<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Integration\FluentCrmSync;

defined('ABSPATH') || exit;

final class FluentCrmModule implements ModuleContract
{
    public function register(): void
    {
        FluentCrmSync::register();
    }
}
