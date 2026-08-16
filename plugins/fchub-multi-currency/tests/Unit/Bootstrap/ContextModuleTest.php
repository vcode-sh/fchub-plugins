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
    protected function setUp(): void
    {
        parent::setUp();

        // buildResolverChain() caches its built chain statically — reset it so
        // an earlier test's settings (which resolver, gated by which setting,
        // ends up in the chain) can't leak into a later one.
        ContextModule::resetChain();
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

    /**
     * Regression test for a source-attribution bug found while investigating
     * why the address-bar cleanup (1b60b95) still wasn't stripping the URL
     * param on a base-currency switch: ContextModule::buildContextFromCode()'s
     * base-currency-match branch called CurrencyContext::baseOnly() without
     * passing the resolver's real $source through, so a guest resolving to
     * the base currency via ?currency=USD was mislabeled source "default"
     * instead of "url_param" — even though UrlParamResolver genuinely
     * matched. currency-projection.js's stripUrlParamFromAddressBar() checks
     * cfg.source === "url_param" before stripping the param, so it correctly
     * did nothing, given what it was (wrongly) told.
     */
    #[Test]
    public function testGuestResolvingToBaseCurrencyViaUrlParamReportsUrlParamSource(): void
    {
        $_GET = ['currency' => 'EUR'];
        $_COOKIE = [];

        $settings = [
            'base_currency' => 'EUR',
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
        $this->assertSame(
            ResolverSource::UrlParam,
            $context->source,
            'a guest whose ?currency= URL param resolved to the base currency must still report source url_param, not be silently relabeled "default" (Fallback) — that mislabeling both misreports where the preference came from and, since RECONCILABLE_SOURCES treats "default" as reconcilable, risks client-side reconciliation second-guessing a source that must never be overridden',
        );
    }

    /**
     * Same bug, UserMeta path: a signed-in visitor whose saved account
     * preference resolves to the base currency must report source
     * "user_meta", not "default".
     */
    #[Test]
    public function testSignedInVisitorResolvingToBaseCurrencyViaUserMetaReportsUserMetaSource(): void
    {
        $_GET = [];
        $_COOKIE = [];

        $settings = [
            'base_currency' => 'EUR',
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
        $this->setCurrentUserId(42);
        $this->setUserMeta(42, '_fchub_mc_currency', 'EUR');

        $chain = ContextModule::buildResolverChain(new OptionStore());
        $context = $chain->resolve($settings['base_currency'], $settings['display_currencies']);

        $this->assertNotNull($context);
        $this->assertSame('EUR', $context->baseCurrency->code);
        $this->assertSame('EUR', $context->displayCurrency->code);
        $this->assertTrue($context->isBaseDisplay);
        $this->assertSame(
            ResolverSource::UserMeta,
            $context->source,
            'a signed-in visitor whose saved account preference resolved to the base currency must still report source user_meta, not be silently relabeled "default" (Fallback)',
        );
    }
}
