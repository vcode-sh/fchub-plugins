<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Actions\SaveOrderSnapshotAction;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\Enums\StaleRateFallback;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Services\ExchangeRateService;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\FluentCartEvent;
use FChubMultiCurrency\Support\Hooks;

defined('ABSPATH') || exit;

final class OrderSnapshotHooks
{
    public static function register(): void
    {
        add_action('fluent_cart/checkout/prepare_other_data', [self::class, 'captureAtCheckout'], 10, 1);
        add_action('fluent_cart/order_paid_done', [self::class, 'saveSnapshot'], 10, 1);
    }

    /**
     * Capture currency context at checkout time, while the customer's HTTP request is active.
     *
     * @param array<string, mixed> $data
     */
    public static function captureAtCheckout(array $data): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $order = $data['order'] ?? null;
        if ($order === null) {
            return;
        }

        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );

        $context = $contextService->resolve();

        if (!$context->isBaseDisplay) {
            $order->updateMeta('_fchub_mc_display_currency', $context->displayCurrency->code);
            $order->updateMeta('_fchub_mc_base_currency', $context->baseCurrency->code);
            $order->updateMeta('_fchub_mc_rate', $context->rate->rate);
            $order->updateMeta('_fchub_mc_disclosure_version', FCHUB_MC_VERSION);
        } else {
            // Sentinel: mark as "captured" so saveSnapshot() knows checkout ran
            $order->updateMeta('_fchub_mc_display_currency', $context->baseCurrency->code);
        }
    }

    public static function saveSnapshot($eventData): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $order = FluentCartEvent::extractOrder($eventData);
        if ($order === null) {
            return;
        }

        // If snapshot was captured at checkout, skip
        $existingMeta = $order->getMeta('_fchub_mc_display_currency', '');
        if ($existingMeta !== '' && $existingMeta !== null) {
            return;
        }

        // Fallback for manual/API orders or pre-1.2.1 upgrades:
        // try the order customer's stored preference, validated against enabled currencies
        $userId = (int) ($order->user_id ?? 0);
        if ($userId <= 0) {
            return;
        }

        $prefRepo = new PreferenceRepository();
        $preferredCode = $prefRepo->getUserMeta($userId);
        if ($preferredCode === null) {
            return;
        }

        $optionStore = new OptionStore();
        $context = self::buildFallbackContext($preferredCode, $optionStore);
        if ($context === null) {
            return;
        }

        $contextService = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );
        $action = new SaveOrderSnapshotAction($contextService);
        $action->execute($order, $context);
    }

    /**
     * Build a CurrencyContext for a specific currency code, validated against enabled currencies.
     * Returns null if the code is not enabled or no rate is available (better than a wrong snapshot).
     */
    private static function buildFallbackContext(string $code, OptionStore $optionStore): ?CurrencyContext
    {
        $settings = $optionStore->all();
        $baseCurrencyCode = strtoupper((string) ($settings['base_currency'] ?? 'USD'));
        $enabledCurrencies = $settings['display_currencies'] ?? [];
        if (!is_array($enabledCurrencies)) {
            $enabledCurrencies = [];
        }

        $code = strtoupper($code);

        // Validate against enabled currencies
        $isEnabled = false;
        foreach ($enabledCurrencies as $currencyData) {
            if (is_array($currencyData) && strtoupper($currencyData['code'] ?? '') === $code) {
                $isEnabled = true;
                break;
            }
        }

        if (!$isEnabled) {
            return null;
        }

        if ($code === $baseCurrencyCode) {
            return null; // Base currency = no snapshot needed
        }

        $rateService = new ExchangeRateService(new ExchangeRateRepository(), new RatesCacheStore());
        $staleFallback = StaleRateFallback::tryFrom((string) ($settings['stale_fallback'] ?? 'base'))
            ?? StaleRateFallback::Base;
        $maxRateAge = max(1, (int) ($settings['stale_threshold_hrs'] ?? 24)) * HOUR_IN_SECONDS;
        $rate = $rateService->getUsableRate($baseCurrencyCode, $code, $maxRateAge, $staleFallback);

        if ($rate === null) {
            return null;
        }

        $baseCurrency = self::findCurrency($baseCurrencyCode, $enabledCurrencies);
        $displayCurrency = self::findCurrency($code, $enabledCurrencies);

        return new CurrencyContext(
            displayCurrency: $displayCurrency,
            baseCurrency: $baseCurrency,
            rate: $rate,
            source: ResolverSource::UserMeta,
            isBaseDisplay: false,
        );
    }

    /**
     * @param array<int|string, mixed> $enabledCurrencies
     */
    private static function findCurrency(string $code, array $enabledCurrencies): Currency
    {
        foreach ($enabledCurrencies as $currencyData) {
            if (is_array($currencyData) && strtoupper($currencyData['code'] ?? '') === strtoupper($code)) {
                return Currency::from($currencyData);
            }
        }

        return Currency::from([
            'code'     => $code,
            'name'     => $code,
            'symbol'   => $code,
            'decimals' => 2,
            'position' => 'left',
        ]);
    }
}
