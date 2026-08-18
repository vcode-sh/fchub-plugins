<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Frontend;

use FChubMultiCurrency\Frontend\NoscriptCurrencyForm;
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class NoscriptCurrencyFormTest extends TestCase
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

        NoscriptCurrencyForm::handle();

        $this->assertSame('USD', $_COOKIE[Constants::COOKIE_KEY] ?? '');
        $this->assertSame('USD', $GLOBALS['wp_mock_user_meta'][42][Constants::USER_META_KEY] ?? '');
        $this->assertHookFired('fchub_mc/context_switched');
        $this->assertStringContainsString('wp_fchub_mc_event_log', implode(' ', $GLOBALS['wpdb']->queries));
    }

    /**
     * The nonce baked into a cached page outlives its tick: a guest reading a
     * document the edge cached yesterday submits a nonce WordPress no longer
     * accepts. Their switch writes only their own cookie — the same operation
     * the REST endpoint and the URL parameter already allow without a nonce —
     * so an expired nonce must not silently swallow it.
     */
    #[Test]
    public function testGuestPostSurvivesAnExpiredNonceFromACachedPage(): void
    {
        $GLOBALS['wp_mock_verify_nonce'] = false;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'expired-by-cache-age',
        ];

        NoscriptCurrencyForm::handle();

        $this->assertSame('USD', $_COOKIE[Constants::COOKIE_KEY] ?? '');
        $this->assertHookFired('fchub_mc/context_switched');
    }

    /**
     * The account write is the one target worth forging a request for, so the
     * logged-in path keeps demanding a fresh nonce — and logged-in pages are
     * not served from shared caches, so theirs is always fresh.
     */
    #[Test]
    public function testLoggedInPostStillRequiresAValidNonce(): void
    {
        $GLOBALS['wp_mock_verify_nonce'] = false;
        $this->setCurrentUserId(42);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'forged',
        ];

        NoscriptCurrencyForm::handle();

        $this->assertArrayNotHasKey(Constants::USER_META_KEY, $GLOBALS['wp_mock_user_meta'][42] ?? []);
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

        NoscriptCurrencyForm::handle();

        $this->assertArrayNotHasKey(Constants::COOKIE_KEY, $_COOKIE);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }

    #[Test]
    public function testConfiguredCurrencyWithoutAUsableRateIsNotPersisted(): void
    {
        CurrencySettings::setMock(['currency' => 'USD']);
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'cookie_enabled'     => 'yes',
            'display_currencies' => [[
                'code' => 'EUR',
                'name' => 'Euro',
                'symbol' => '€',
                'decimals' => 2,
                'position' => 'right_space',
            ]],
        ]);
        $this->setWpdbMockRow(null);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'EUR',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'valid',
        ];

        NoscriptCurrencyForm::handle();

        $this->assertCount(0, $GLOBALS['fchub_mc_setcookie_calls']);
        $this->assertArrayNotHasKey(Constants::COOKIE_KEY, $_COOKIE);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }

    #[Test]
    public function testGuestPostIsNotFakedWhenNothingCanBePersisted(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'cookie_enabled'     => 'no',
            'base_currency'      => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'valid',
        ];

        NoscriptCurrencyForm::handle();

        // No cookie faked for the current request, and no "switched" signal for listeners.
        $this->assertArrayNotHasKey(Constants::COOKIE_KEY, $_COOKIE);
        $this->assertHookNotFired('fchub_mc/context_switched');
        $this->assertHookFired('fchub_mc/context_switch_not_persisted');
    }

    #[Test]
    public function testLoggedInPostStillPersistsWhenCookiesAreDisabled(): void
    {
        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'cookie_enabled'     => 'no',
            'base_currency'      => 'EUR',
            'display_currencies' => [
                ['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);
        $this->setCurrentUserId(42);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'valid',
        ];

        NoscriptCurrencyForm::handle();

        $this->assertSame('USD', $GLOBALS['wp_mock_user_meta'][42][Constants::USER_META_KEY] ?? '');
        $this->assertHookFired('fchub_mc/context_switched');
        $this->assertHookNotFired('fchub_mc/context_switch_not_persisted');
    }

    #[Test]
    public function testGetRequestsDoNotMutatePreferenceState(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [
            CurrencySwitcherRenderer::NOSCRIPT_FIELD => 'USD',
            CurrencySwitcherRenderer::NOSCRIPT_NONCE => 'valid',
        ];

        NoscriptCurrencyForm::handle();

        $this->assertArrayNotHasKey(Constants::COOKIE_KEY, $_COOKIE);
        $this->assertHookNotFired('fchub_mc/context_switched');
    }
}
