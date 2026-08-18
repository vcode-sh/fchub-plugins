<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Domain\Actions\PersistContextAction;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Services\CurrencyResolution;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\EventLogger;
use FChubMultiCurrency\Support\Hooks;

defined('ABSPATH') || exit;

/**
 * Handles the currency form a visitor without JavaScript submits.
 *
 * It is the one path where the server, not the browser, decides a visitor's
 * currency — so it is also the only one that has to read a POST, check a nonce and
 * validate a code. That is request handling, not context wiring, and it was making
 * ContextModule answer a question that is not its own.
 */
final class NoscriptCurrencyForm
{
    public static function handle(): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';
        if ($requestMethod !== 'POST') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $submitted = isset($_POST[CurrencySwitcherRenderer::NOSCRIPT_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[CurrencySwitcherRenderer::NOSCRIPT_FIELD]))
            : '';

        if ($submitted === '') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $nonce = isset($_POST[CurrencySwitcherRenderer::NOSCRIPT_NONCE])
            ? sanitize_text_field(wp_unslash((string) $_POST[CurrencySwitcherRenderer::NOSCRIPT_NONCE]))
            : '';
        $nonceVerified = $nonce !== '' && wp_verify_nonce($nonce, CurrencySwitcherRenderer::NOSCRIPT_ACTION);

        // The nonce protects the logged-in account write, so there it stays
        // mandatory — and logged-in pages are rendered fresh, so it is valid.
        // A guest's nonce came baked into a page an edge may have cached past
        // the nonce tick, and their switch writes only their own cookie — an
        // operation the REST endpoint and the URL parameter already allow
        // without one. Rejecting the stale nonce would silently break the
        // no-JS form on exactly the cached pages this plugin exists to serve.
        if (!$nonceVerified && get_current_user_id() > 0) {
            return;
        }

        $optionStore = new OptionStore();
        $allowedCodes = SelectableCurrencyCodes::fromSettings($optionStore->all())->all();
        $currencyCode = strtoupper($submitted);

        if (!in_array($currencyCode, $allowedCodes, true)) {
            return;
        }

        if (CurrencyResolution::selectablePreference($optionStore, $currencyCode) === null) {
            EventLogger::log('context_switch_rate_unavailable_noscript', get_current_user_id(), [
                'currency' => $currencyCode,
                'source' => 'noscript',
            ]);

            return;
        }

        $result = (new PersistContextAction(
            new PreferenceRepository(),
            $optionStore,
        ))->execute($currencyCode);

        // Nothing was stored — a logged-out visitor with cookie persistence disabled. Faking the
        // cookie for this one request would only show the chosen currency until the next page load,
        // so report the failure instead of pretending the switch worked.
        if (!$result->persisted()) {
            do_action('fchub_mc/context_switch_not_persisted', $currencyCode, get_current_user_id());
            EventLogger::log('context_switch_not_persisted_noscript', get_current_user_id(), [
                'currency' => $currencyCode,
                'source' => 'noscript',
            ]);

            return;
        }

        $_COOKIE[Constants::COOKIE_KEY] = $currencyCode;
        CurrencyContextService::reset();

        do_action('fchub_mc/context_switched', $currencyCode, get_current_user_id());
        EventLogger::log('context_switched_noscript', get_current_user_id(), [
            'currency' => $currencyCode,
            'source' => 'noscript',
        ]);
    }
}
