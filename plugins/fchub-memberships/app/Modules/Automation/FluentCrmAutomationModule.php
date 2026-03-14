<?php

namespace FChubMemberships\Modules\Automation;

use FChubMemberships\Core\Container;
use FChubMemberships\Core\Contracts\ModuleInterface;

defined('ABSPATH') || exit;

final class FluentCrmAutomationModule implements ModuleInterface
{
    public function key(): string
    {
        return 'fluentcrm_automation';
    }

    public function register(Container $container): void
    {
        add_action('init', [$this, 'bootAutomation'], 30);
    }

    public function bootAutomation(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        \FChubMemberships\FluentCRM\FluentCrmAutomation::boot();
    }
}
