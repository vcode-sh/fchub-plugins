<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Services\CurrencyResolution;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Frontend\NoscriptCurrencyForm;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Hooks;

defined('ABSPATH') || exit;

/**
 * Wires currency-context handling into WordPress: the no-JS form handler and
 * the login-time merge of a guest's cookie preference. Resolution itself lives
 * in Domain\Services\CurrencyResolution.
 */
final class ContextModule implements ModuleContract
{
    public function register(): void
    {
        add_action('wp', [NoscriptCurrencyForm::class, 'handle'], 0);
        add_action('wp_login', [self::class, 'mergeGuestPreference'], 10, 2);
    }

    public static function mergeGuestPreference(string $userLogin, $user): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $optionStore = new OptionStore();
        if ($optionStore->get('cookie_enabled', 'yes') !== 'yes') {
            return;
        }
        if ($optionStore->get('account_persistence_enabled', 'yes') !== 'yes') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $guestCurrency = isset($_COOKIE['fchub_mc_currency']) ? sanitize_text_field(wp_unslash($_COOKIE['fchub_mc_currency'])) : '';

        if ($guestCurrency === '' || !isset($user->ID)) {
            return;
        }

        $existingPref = get_user_meta($user->ID, '_fchub_mc_currency', true);

        if (!$existingPref) {
            $allowedCodes = SelectableCurrencyCodes::fromSettings($optionStore->all())->all();
            $code = strtoupper($guestCurrency);

            if (!in_array($code, $allowedCodes, true)) {
                return;
            }

            update_user_meta($user->ID, '_fchub_mc_currency', $code);
        }
    }

    /** Back-compat delegate kept for tests/e2e/generate-fixture.php; new code calls CurrencyResolution. */
    public static function resetChain(): void
    {
        CurrencyResolution::resetChain();
    }

    /** Back-compat delegate kept for tests/e2e/generate-fixture.php; new code calls CurrencyResolution. */
    public static function resolveExplicitPreference(OptionStore $optionStore, string $currencyCode): CurrencyContext
    {
        return CurrencyResolution::explicitPreference($optionStore, $currencyCode);
    }
}
