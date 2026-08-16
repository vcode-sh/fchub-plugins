<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Enums\RoundingMode;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Services\RoundingPolicy;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Hooks;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Models\Order;

defined('ABSPATH') || exit;

/** Implements the public price helpers against FluentCart's cent-based money contract. */
final class PublicPriceApi
{
    public static function formatPrice(float $basePrice): string
    {
        if (!defined('FLUENTCART_VERSION')) {
            return (string) $basePrice;
        }
        if (!Hooks::isEnabled()) {
            return CurrencySettings::getPriceHtml($basePrice);
        }

        $optionStore = new OptionStore();
        $context = CurrencyContextService::getResolved();
        if ($context === null) {
            $context = (new CurrencyContextService(
                ContextModule::buildResolverChain($optionStore),
                $optionStore,
            ))->resolve();
        }
        if ($context->isBaseDisplay) {
            return CurrencySettings::getPriceHtml($basePrice);
        }

        $converted = function_exists('bcmul')
            ? bcmul((string) $basePrice, $context->rate->rate, 8)
            : (string) ($basePrice * (float) $context->rate->rate);
        $roundingMode = RoundingMode::tryFrom((string) $optionStore->get('rounding_mode', 'half_up'))
            ?? RoundingMode::HalfUp;
        $decimals = $context->displayCurrency->decimals;
        $minorUnitPrecision = max(0, 2 - min(2, $decimals));
        $rounded = (new RoundingPolicy($roundingMode, $minorUnitPrecision))->apply($converted);

        return CurrencySettings::getPriceHtml(
            $rounded,
            $context->displayCurrency->code,
            $decimals > 0,
        );
    }

    public static function getOrderDisplayCurrency(int $orderId): ?string
    {
        if (!defined('FLUENTCART_VERSION')) {
            return null;
        }

        /** @var Order|null $order */
        $order = Order::query()->find($orderId);
        if ($order === null) {
            return null;
        }

        $currency = $order->getMeta('_fchub_mc_display_currency');

        return $currency ? (string) $currency : null;
    }

    public static function formatOrderPrice(float $basePrice, int $orderId): string
    {
        if (!defined('FLUENTCART_VERSION')) {
            return (string) $basePrice;
        }

        /** @var Order|null $order */
        $order = Order::query()->find($orderId);
        if ($order === null) {
            return CurrencySettings::getPriceHtml($basePrice);
        }

        $displayCurrency = $order->getMeta('_fchub_mc_display_currency');
        $rate = $order->getMeta('_fchub_mc_rate');
        if (!$displayCurrency || !$rate) {
            return CurrencySettings::getPriceHtml($basePrice);
        }

        $converted = round($basePrice * (float) $rate, 2);

        return CurrencySettings::getPriceHtml(
            $converted,
            (string) $displayCurrency,
            !CurrenciesHelper::isZeroDecimal((string) $displayCurrency),
        );
    }
}
