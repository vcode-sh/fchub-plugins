<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class FrontendModuleShortcodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the static cached chain before each test
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_GET = [];
        $_COOKIE = [];
    }

    #[Test]
    public function testLabelAttributeRendersLabelSpan(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'          => 'yes',
            'base_currency'    => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        // No rate → falls back to base
        $this->setWpdbMockRow(null);

        $html = FrontendModule::renderSwitcher(['label' => 'Currency']);

        $this->assertStringContainsString(
            'class="fchub-mc-switcher__label"',
            $html,
            'Label span element should be present'
        );
        $this->assertStringContainsString(
            'Currency',
            $html,
            'Label text should be rendered'
        );
    }

    #[Test]
    public function testNoLabelAttributeOmitsLabelSpan(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'          => 'yes',
            'base_currency'    => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $this->setWpdbMockRow(null);

        $html = FrontendModule::renderSwitcher([]);

        $this->assertStringNotContainsString(
            'fchub-mc-switcher__label',
            $html,
            'Label span should not appear when no label attribute is set'
        );
    }

    #[Test]
    public function testAlignRightAddsRightClass(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'          => 'yes',
            'base_currency'    => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $this->setWpdbMockRow(null);

        $html = FrontendModule::renderSwitcher(['align' => 'right']);

        $this->assertStringContainsString(
            'fchub-mc-switcher--right',
            $html,
            'Right alignment class should be added'
        );
    }

    #[Test]
    public function testAlignCenterAddsCenterClass(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'          => 'yes',
            'base_currency'    => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $this->setWpdbMockRow(null);

        $html = FrontendModule::renderSwitcher(['align' => 'center']);

        $this->assertStringContainsString(
            'fchub-mc-switcher--center',
            $html,
            'Center alignment class should be added'
        );
    }

    #[Test]
    public function testDefaultAlignDoesNotAddAlignmentClass(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'          => 'yes',
            'base_currency'    => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $this->setWpdbMockRow(null);

        $html = FrontendModule::renderSwitcher([]);

        $this->assertStringNotContainsString('fchub-mc-switcher--right', $html);
        $this->assertStringNotContainsString('fchub-mc-switcher--center', $html);
    }
}
