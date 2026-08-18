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
    public function testCookieRecoveryKeepsItsSourceWhenTheSelectedRateIsUnavailable(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'right_space',
            ]],
        ]);
        $this->setWpdbMockRow(null);

        $context = ContextModule::resolveExplicitPreference(new OptionStore(), 'EUR');

        $this->assertTrue($context->isBaseDisplay);
        $this->assertSame('USD', $context->displayCurrency->code);
        $this->assertSame(ResolverSource::Cookie, $context->source);
    }

    #[Test]
    public function testBaseFallbackUsesFluentCartCurrencyMetadata(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'display_currencies' => [],
        ]);

        $context = ContextModule::resolveExplicitPreference(new OptionStore(), 'USD');

        $this->assertSame('US Dollar', $context->displayCurrency->name);
        $this->assertSame('$', $context->displayCurrency->symbol);
        $this->assertSame('left', $context->displayCurrency->position->value);
    }

    #[Test]
    public function testCookiePreferenceEntryPointRemainsCompatibleWithVersionOneFourFive(): void
    {
        $this->setOption('fchub_mc_settings', [
            'display_currencies' => [],
        ]);

        $context = ContextModule::resolveExplicitPreference(new OptionStore(), 'USD');

        $this->assertSame('USD', $context->displayCurrency->code);
        $this->assertTrue($context->isBaseDisplay);
    }

    #[Test]
    public function testRemovedDefaultCurrencyCannotSurviveThroughHistoricalRateData(): void
    {
        $_GET = [];
        $_COOKIE = [];

        $settings = [
            'base_currency' => 'USD',
            'default_display_currency' => 'GBP',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'right_space',
            ]],
        ];
        $this->setOption('fchub_mc_settings', $settings);
        $this->setWpdbMockRow([
            'base_currency' => 'USD',
            'quote_currency' => 'GBP',
            'rate' => '0.79000000',
            'provider' => 'manual',
            'fetched_at' => current_time('mysql'),
        ]);

        $context = ContextModule::buildResolverChain(new OptionStore())
            ->resolve($settings['base_currency'], $settings['display_currencies']);

        $this->assertNotNull($context);
        $this->assertTrue($context->isBaseDisplay);
        $this->assertSame('USD', $context->displayCurrency->code);
        $this->assertSame(ResolverSource::Fallback, $context->source);
        $this->assertSame([], $GLOBALS['wpdb']->queries, 'Removed currencies must not trigger a rate lookup.');
    }

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
