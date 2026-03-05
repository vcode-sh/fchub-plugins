<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\ModuleRegistry;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ModuleRegistryTest extends TestCase
{
    #[Test]
    public function testReturnsArrayOfModuleClasses(): void
    {
        $classes = ModuleRegistry::classes();

        $this->assertIsArray($classes);
        $this->assertNotEmpty($classes);
    }

    #[Test]
    public function testSettingsModuleIsFirst(): void
    {
        $classes = ModuleRegistry::classes();

        $this->assertStringContainsString('SettingsModule', $classes[0]);
    }

    #[Test]
    public function testContainsAllExpectedModules(): void
    {
        $classes = ModuleRegistry::classes();
        $classNames = array_map(fn($c) => basename(str_replace('\\', '/', $c)), $classes);

        $expected = [
            'SettingsModule', 'CoreModule', 'ContextModule',
            'FrontendModule', 'CheckoutModule', 'AdminModule', 'RestModule',
            'FluentCrmModule', 'FluentCommunityModule', 'DiagnosticsModule',
        ];

        foreach ($expected as $name) {
            $this->assertContains($name, $classNames, "Missing module: {$name}");
        }
    }
}
