<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Modules\Automation\FluentCrmAutomationModule;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class FluentCrmAutomationModuleAbsentTest extends PluginTestCase
{
    public function test_absent_fluentcrm_skips_automation_without_an_admin_notice(): void
    {
        self::assertTrue(method_exists(FluentCrmAutomationModule::class, 'hasFluentCrm'));

        (new FluentCrmAutomationModule(false))->bootAutomation();

        self::assertArrayNotHasKey('admin_notices', $GLOBALS['_fchub_test_actions']);
    }
}
