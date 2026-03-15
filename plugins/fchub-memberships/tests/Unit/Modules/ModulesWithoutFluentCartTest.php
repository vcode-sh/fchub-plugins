<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Modules\Admin\AdminModule;
use FChubMemberships\Modules\Infrastructure\InfrastructureModule;
use FChubMemberships\Modules\Runtime\FluentCartRuntimeModule;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class ModulesWithoutFluentCartTest extends PluginTestCase
{
    #[RunInSeparateProcess]
    public function test_modules_return_early_when_fluentcart_is_not_available(): void
    {
        if (getenv('XDEBUG_MODE') === 'coverage') {
            self::markTestSkipped('Separate-process coverage is unstable in PHPUnit 13 with Xdebug.');
        }

        self::assertFalse(defined('FLUENTCART_VERSION'));

        $admin = new AdminModule();
        $admin->registerAdminMenu();

        $runtime = new FluentCartRuntimeModule();
        $runtime->bootRuntime();

        $infrastructure = new InfrastructureModule();
        $infrastructure->runValidityCheck();
        $infrastructure->runDripProcess();
        $infrastructure->runExpiryNotifications();
        $infrastructure->runDailyStats();
        $infrastructure->runAuditCleanup();
        $infrastructure->runTrialCheck();
        $infrastructure->runPlanSchedule();

        self::assertSame([], $GLOBALS['_fchub_test_menu_pages']);
        self::assertArrayNotHasKey('fluent_cart/integration/integration_options_plan_id', $GLOBALS['_fchub_test_filters']);
        self::assertArrayNotHasKey('rest_api_init', $GLOBALS['_fchub_test_actions']);
        self::assertSame([], $GLOBALS['_fchub_test_queries']);
    }
}
