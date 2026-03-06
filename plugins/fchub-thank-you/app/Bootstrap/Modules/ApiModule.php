<?php

declare(strict_types=1);

namespace FchubThankYou\Bootstrap\Modules;

use FchubThankYou\Bootstrap\ModuleContract;
use FchubThankYou\Http\Routes\ApiRoutes;

final class ApiModule implements ModuleContract
{
    public function register(): void
    {
        add_action('rest_api_init', static function (): void {
            (new ApiRoutes())->register();
        });
    }
}
