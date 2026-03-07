<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Storage;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class OptionStoreTest extends TestCase
{
    #[Test]
    public function testSwitcherDefaultsAreDeepMerged(): void
    {
        $this->setOption('fchub_mc_settings', [
            'switcher_defaults' => [
                'preset' => 'glass',
                'show_symbol' => 'yes',
            ],
        ]);

        $settings = (new OptionStore())->all();

        $this->assertSame('glass', $settings['switcher_defaults']['preset']);
        $this->assertSame('yes', $settings['switcher_defaults']['show_symbol']);
        $this->assertArrayHasKey('show_code', $settings['switcher_defaults']);
        $this->assertArrayHasKey('dropdown_position', $settings['switcher_defaults']);
    }
}
