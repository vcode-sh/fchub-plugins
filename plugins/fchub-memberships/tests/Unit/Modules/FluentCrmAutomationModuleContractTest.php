<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Modules;

use FChubMemberships\Core\Container;
use FChubMemberships\Modules\Automation\FluentCrmAutomationModule;
use FChubMemberships\Integration\FluentCrmSync;
use FChubMemberships\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

if (!defined('FLUENTCRM')) {
    define('FLUENTCRM', 'fluentcrm');
}

if (!defined('FLUENTCRM_PLUGIN_VERSION')) {
    define('FLUENTCRM_PLUGIN_VERSION', '3.1.8');
}

final class FluentCrmAutomationModuleContractTest extends PluginTestCase
{
    public function test_registers_automation_before_fluentcrm_init_scans(): void
    {
        $GLOBALS['_fchub_test_action_registrations'] = [];
        $module = new FluentCrmAutomationModule();

        $module->register(new Container());

        $registration = null;
        foreach ($GLOBALS['_fchub_test_action_registrations']['init'] ?? [] as $candidate) {
            if ($candidate['callback'] === [$module, 'bootAutomation']) {
                $registration = $candidate;
                break;
            }
        }

        self::assertNotNull($registration);
        self::assertSame(1, $registration['priority']);
        self::assertLessThan(2, $registration['priority']);
        self::assertLessThan(20, $registration['priority']);
        self::assertSame(1, $registration['accepted_args']);
    }

    public function test_automation_boot_requires_fluentcrm_funnel_capabilities(): void
    {
        self::assertTrue(method_exists(FluentCrmAutomationModule::class, 'isCompatible'));

        $classExists = static fn(string $class): bool => true;

        self::assertFalse(FluentCrmAutomationModule::isCompatible(
            $classExists,
            static fn(string $class, string $method): bool => false
        ));

        self::assertTrue(FluentCrmAutomationModule::isCompatible(
            $classExists,
            static fn(string $class, string $method): bool => $method === 'register'
        ));

        self::assertTrue(method_exists(FluentCrmSync::class, 'hasRequiredCapabilities'));
    }

    public function test_incompatible_automation_registers_one_admin_compatibility_notice(): void
    {
        $module = new FluentCrmAutomationModule();
        $module->bootAutomation();

        self::assertCount(1, $GLOBALS['_fchub_test_actions']['admin_notices'] ?? []);
    }

    #[DataProvider('automationCapabilityClasses')]
    public function test_boot_rejects_each_missing_automation_capability(string $missingClass): void
    {
        $bootCalls = 0;
        $module = new FluentCrmAutomationModule(
            true,
            static fn(): bool => FluentCrmSync::hasRequiredCapabilities(
                'automation',
                null,
                static fn(string $class): bool => true,
                static fn(string $class, string $method): bool => $class !== $missingClass
            ),
            static function () use (&$bootCalls): void {
                $bootCalls++;
            }
        );

        $module->bootAutomation();

        self::assertSame(0, $bootCalls);
        self::assertCount(1, $GLOBALS['_fchub_test_actions']['admin_notices'] ?? []);
    }

    public function test_boot_runs_when_every_automation_capability_is_present(): void
    {
        $bootCalls = 0;
        $module = new FluentCrmAutomationModule(
            true,
            static fn(): bool => true,
            static function () use (&$bootCalls): void {
                $bootCalls++;
            }
        );

        $module->bootAutomation();

        self::assertSame(1, $bootCalls);
        self::assertArrayNotHasKey('admin_notices', $GLOBALS['_fchub_test_actions']);
    }

    /** @return array<string, array{string}> */
    public static function automationCapabilityClasses(): array
    {
        return [
            'trigger register' => ['FluentCrm\\App\\Services\\Funnel\\BaseTrigger'],
            'action register' => ['FluentCrm\\App\\Services\\Funnel\\BaseAction'],
            'benchmark register' => ['FluentCrm\\App\\Services\\Funnel\\BaseBenchMark'],
        ];
    }
}
