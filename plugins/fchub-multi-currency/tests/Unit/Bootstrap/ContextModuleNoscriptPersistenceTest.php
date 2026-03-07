<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContextModuleNoscriptPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'cookie_enabled'     => 'yes',
            'base_currency'      => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
                ['code' => 'GBP', 'name' => 'British Pound', 'symbol' => '£', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);
    }

    #[Test]
    public function testPostedCurrencyIsPersistedWhenNonceAndCurrencyAreValid(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'valid',
        ];
        $this->setCurrentUserId(42);

        ContextModule::persistPostedCurrencyPreference();

        $this->assertSame('USD', $_COOKIE[Constants::COOKIE_KEY] ?? '');
        $this->assertSame('USD', $GLOBALS['wp_mock_user_meta'][42][Constants::USER_META_KEY] ?? '');
        $this->assertHookFired('fchub_mc/context_switched');
        $this->assertStringContainsString('wp_fchub_mc_event_log', implode(' ', $GLOBALS['wpdb']->queries));
    }

    #[Test]
    public function testInvalidNonceIsRejectedAsSecurityGuard(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => '',
        ];

        ContextModule::persistPostedCurrencyPreference();

        $this->assertArrayNotHasKey(Constants::COOKIE_KEY, $_COOKIE);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }

    #[Test]
    public function testInvalidCurrencyIsRejectedEvenWithValidNonce(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'XSS',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'valid',
        ];

        ContextModule::persistPostedCurrencyPreference();

        $this->assertArrayNotHasKey(Constants::COOKIE_KEY, $_COOKIE);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }

    #[Test]
    public function testGetRequestsDoNotMutatePreferenceState(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'valid',
        ];

        ContextModule::persistPostedCurrencyPreference();

        $this->assertArrayNotHasKey(Constants::COOKIE_KEY, $_COOKIE);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }
}
