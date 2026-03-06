<?php

declare(strict_types=1);

namespace FchubThankYou\Bootstrap;

use FchubThankYou\Support\Hooks;

final class ModuleRegistry
{
    /** @return list<class-string<ModuleContract>> */
    public static function classes(): array
    {
        return apply_filters(Hooks::MODULES, [
            Modules\RedirectModule::class,
            Modules\ApiModule::class,
            Modules\AdminModule::class,
        ]);
    }
}
