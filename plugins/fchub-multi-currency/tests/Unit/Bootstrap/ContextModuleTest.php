<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContextModuleTest extends TestCase
{
    #[Test]
    public function testFallbackKeepsStoreBaseWhenDefaultDisplayCurrencyHasNoRate(): void
    {
        $_GET = [];
        $_COOKIE = [];

        $settings = [
            'base_currency' => 'EUR',
            'default_display_currency' => 'USD',
            'display_currencies' => [
                [
                    'code' => 'USD',
                    'name' => 'US Dollar',
                    'symbol' => '$',
                    'decimals' => 2,
                    'position' => 'left',
                ],
            ],
        ];

        $this->setOption('fchub_mc_settings', $settings);
        $this->setWpdbMockRow(null);

        $chain = ContextModule::buildResolverChain(new OptionStore());
        $context = $chain->resolve($settings['base_currency'], $settings['display_currencies']);

        $this->assertNotNull($context);
        $this->assertSame('EUR', $context->baseCurrency->code);
        $this->assertSame('EUR', $context->displayCurrency->code);
        $this->assertTrue($context->isBaseDisplay);
        $this->assertSame(ResolverSource::Fallback, $context->source);
    }

    #[Test]
    public function testFallbackUsesDefaultDisplayCurrencyWithoutOverwritingBaseWhenRateExists(): void
    {
        $_GET = [];
        $_COOKIE = [];

        $settings = [
            'base_currency' => 'EUR',
            'default_display_currency' => 'USD',
            'display_currencies' => [
                [
                    'code' => 'USD',
                    'name' => 'US Dollar',
                    'symbol' => '$',
                    'decimals' => 2,
                    'position' => 'left',
                ],
            ],
        ];

        $this->setOption('fchub_mc_settings', $settings);
        $this->setWpdbMockRow([
            'base_currency' => 'EUR',
            'quote_currency' => 'USD',
            'rate' => '1.10000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);

        $chain = ContextModule::buildResolverChain(new OptionStore());
        $context = $chain->resolve($settings['base_currency'], $settings['display_currencies']);

        $this->assertNotNull($context);
        $this->assertSame('EUR', $context->baseCurrency->code);
        $this->assertSame('USD', $context->displayCurrency->code);
        $this->assertFalse($context->isBaseDisplay);
        $this->assertSame('EUR', $context->rate->baseCurrency);
        $this->assertSame('USD', $context->rate->quoteCurrency);
        $this->assertSame(ResolverSource::Fallback, $context->source);
    }
}
