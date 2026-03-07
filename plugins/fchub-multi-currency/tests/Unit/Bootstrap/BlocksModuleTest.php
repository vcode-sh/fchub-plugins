<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Blocks\CurrencySwitcherBlock;
use FChubMultiCurrency\Bootstrap\Modules\BlocksModule;
use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class BlocksModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetContextModuleCache();

        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $this->setWpdbMockRow([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate' => '1.10000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);

        FrontendModule::registerAssets();
    }

    #[Test]
    public function testBlocksModuleRegistersAssetsAndMetadataBasedBlock(): void
    {
        $module = new BlocksModule();
        $module->register();

        $this->assertArrayHasKey('fchub-mc-switcher-block-editor-script', $GLOBALS['wp_registered_scripts']);
        $this->assertArrayHasKey('fchub-multi-currency/switcher', $GLOBALS['wp_registered_blocks']);
        $this->assertArrayHasKey('fchub-multi-currency/current-currency', $GLOBALS['wp_registered_blocks']);
        $this->assertArrayHasKey('fchub-multi-currency/exchange-rate', $GLOBALS['wp_registered_blocks']);
        $this->assertArrayHasKey('fchub-multi-currency/context-notice', $GLOBALS['wp_registered_blocks']);
        $this->assertArrayHasKey('fchub-multi-currency/selector-buttons', $GLOBALS['wp_registered_blocks']);
        $this->assertArrayHasKey('fchub-multi-currency', $GLOBALS['wp_registered_block_pattern_categories']);
        $this->assertArrayHasKey('fchub-multi-currency/currency-showcase', $GLOBALS['wp_registered_block_patterns']);
        $this->assertSame(
            'fchub-multi-currency/switcher',
            $GLOBALS['wp_registered_blocks']['fchub-multi-currency/switcher']['metadata']['name'] ?? null,
        );
        $this->assertIsCallable(
            $GLOBALS['wp_registered_blocks']['fchub-multi-currency/switcher']['args']['render_callback'] ?? null,
        );
    }

    #[Test]
    public function testBlockRenderUsesDivRootAndSanitizesUnsupportedAttributeValues(): void
    {
        $html = CurrencySwitcherBlock::render([
            'align' => 'not-real',
            'size' => 'huge',
            'widthMode' => 'everything',
            'dropdownPosition' => 'middle',
            'dropdownDirection' => 'sideways',
            'showFlag' => false,
            'showCode' => false,
            'showName' => false,
        ]);

        $this->assertStringStartsWith('<div ', $html);
        $this->assertStringContainsString('fchub-mc-switcher-stage--left', $html);
        $this->assertStringContainsString('fchub-mc-switcher--left', $html);
        $this->assertStringContainsString('fchub-mc-switcher--size-md', $html);
        $this->assertStringContainsString('fchub-mc-switcher--width-auto', $html);
        $this->assertStringContainsString('fchub-mc-switcher--dropdown-auto', $html);
        $this->assertStringContainsString('fchub-mc-switcher--direction-auto', $html);
        $this->assertStringContainsString('class="fchub-mc-switcher__code">USD</span>', $html);
    }

    private function resetContextModuleCache(): void
    {
        $ref = new \ReflectionClass(\FChubMultiCurrency\Bootstrap\Modules\ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }
}
