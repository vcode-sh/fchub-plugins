<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Integration\FluentCommunitySync;

defined('ABSPATH') || exit;

final class FluentCommunityModule implements ModuleContract
{
    public function register(): void
    {
        FluentCommunitySync::register();
    }
}
