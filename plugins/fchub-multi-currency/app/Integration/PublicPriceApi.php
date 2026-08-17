<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Hooks;
use FluentCart\Api\CurrencySettings;
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

        return DisplayPriceFormatter::format(
            $basePrice,
            $context->rate->rate,
            $context->displayCurrency->code,
            $optionStore,
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

        return DisplayPriceFormatter::format(
            $basePrice,
            (string) $rate,
            (string) $displayCurrency,
            new OptionStore(),
        );
    }
}
