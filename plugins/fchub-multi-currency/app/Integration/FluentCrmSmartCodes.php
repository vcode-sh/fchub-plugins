<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class FluentCrmSmartCodes
{
    /**
     * FluentCart funnel triggers registered by FluentCRM. Each one starts its
     * funnel sequence with `source_ref_id` set to a FluentCart order id, which
     * is exactly what parse() resolves the currency meta from, so the smart
     * codes are meaningful in all of them.
     *
     * These are hook names, not slugs. A dash-style name ('fluent-cart-...')
     * matches no trigger and silently disables the whole group.
     */
    private const CART_FUNNELS = [
        'fluent_cart/order_paid_done',
        'fluent_cart/order_fully_refunded',
        'fluent_cart/order_status_changed',
        'fluent_cart/order_status_changed_to_canceled',
        'fluent_cart/shipping_status_changed_to_shipped',
        'fluent_cart/shipping_status_changed_to_delivered',
        'fluent_cart/subscription_activated',
        'fluent_cart/subscription_renewed',
        'fluent_cart/subscription_canceled',
        'fluent_cart/subscription_eot',
        'fluent_cart/subscription_expired_validity',
    ];

    public static function register(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        add_filter('fluent_crm/extended_smart_codes', [self::class, 'registerGlobalSmartCodes']);
        add_filter('fluent_crm_funnel_context_smart_codes', [self::class, 'registerFunnelSmartCodes'], 10, 3);
        add_filter('fluent_crm/smartcode_group_callback_mc_order', [self::class, 'parse'], 10, 4);
    }

    /**
     * @param array<int|string, mixed> $groups
     * @return array<int|string, mixed>
     */
    public static function registerGlobalSmartCodes(array $groups): array
    {
        $groups[] = self::buildSmartCodeGroup();

        return $groups;
    }

    /**
     * The filter dispatches three arguments: the group list, the funnel trigger
     * name and the funnel model. The list must stay a sequential array — the
     * funnel editor spreads it in JavaScript, and a string key would turn the
     * JSON payload into an object.
     *
     * @param array<int|string, mixed> $groups
     * @param string|null $triggerName
     * @param object|null $funnel
     * @return array<int|string, mixed>
     */
    public static function registerFunnelSmartCodes(array $groups, $triggerName = null, $funnel = null): array
    {
        $trigger = is_string($triggerName) ? $triggerName : '';

        if ($trigger === '' && is_object($funnel) && isset($funnel->trigger_name)) {
            $trigger = (string) $funnel->trigger_name;
        }

        if (!in_array($trigger, self::CART_FUNNELS, true)) {
            return $groups;
        }

        $groups[] = self::buildSmartCodeGroup();

        return $groups;
    }

    public static function parse(string $code, string $valueKey, string $defaultValue, $subscriber): string
    {
        try {
            $funnelSubscriberId = $subscriber->funnel_subscriber_id ?? null;

            if (!$funnelSubscriberId) {
                return $defaultValue;
            }

            $funnelSub = \FluentCrm\App\Models\FunnelSubscriber::query()
                ->where('id', $funnelSubscriberId)
                ->first();

            if (!$funnelSub) {
                return $defaultValue;
            }

            $sourceRefId = $funnelSub['source_ref_id'] ?? null;

            if (!$sourceRefId) {
                return $defaultValue;
            }

            $order = \FluentCart\App\Models\Order::query()->find((int) $sourceRefId);

            if (!$order) {
                return $defaultValue;
            }

            return self::resolveValue($order, $valueKey, $defaultValue);
        } catch (\Throwable $e) {
            Logger::error('Smart code parsing failed', [
                'error'     => $e->getMessage(),
                'value_key' => $valueKey,
            ]);

            return $defaultValue;
        }
    }

    public static function resolveValue(object $order, string $valueKey, string $defaultValue): string
    {
        $displayCurrency = $order->getMeta('_fchub_mc_display_currency');
        $baseCurrency = $order->getMeta('_fchub_mc_base_currency');
        $rate = $order->getMeta('_fchub_mc_rate');

        switch ($valueKey) {
            case 'display_currency':
                return $displayCurrency ?: ($order->currency ?? $defaultValue);

            case 'base_currency':
                return $baseCurrency ?: ($order->currency ?? $defaultValue);

            case 'display_total':
                if (!$displayCurrency || !$rate) {
                    return $defaultValue;
                }
                return DisplayPriceFormatter::format(
                    (float) $order->total_amount,
                    (string) $rate,
                    (string) $displayCurrency,
                    new OptionStore(),
                );

            case 'display_subtotal':
                if (!$displayCurrency || !$rate) {
                    return $defaultValue;
                }
                return DisplayPriceFormatter::format(
                    (float) $order->subtotal,
                    (string) $rate,
                    (string) $displayCurrency,
                    new OptionStore(),
                );

            case 'exchange_rate':
                return $rate ?: '1';

            case 'charged_notice':
                if (!$displayCurrency || !$baseCurrency || !$rate) {
                    return $defaultValue;
                }
                return sprintf(
                    'Charged in %s at rate 1 %s = %s %s',
                    $baseCurrency,
                    $baseCurrency,
                    $rate,
                    $displayCurrency,
                );

            default:
                return $defaultValue;
        }
    }

    /**
     * @return array{key: string, title: string, description: string, shortcodes: array<string, string>}
     */
    private static function buildSmartCodeGroup(): array
    {
        return [
            'key'         => 'mc_order',
            'title'       => 'Multi-Currency Order',
            'description' => 'Currency and exchange rate values captured on the order.',
            'shortcodes'  => self::getSmartCodeDefinitions(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function getSmartCodeDefinitions(): array
    {
        return [
            '{{mc_order.display_currency}}' => 'Display Currency Code',
            '{{mc_order.base_currency}}'    => 'Base Currency Code',
            '{{mc_order.display_total}}'    => 'Total in Display Currency',
            '{{mc_order.display_subtotal}}' => 'Subtotal in Display Currency',
            '{{mc_order.exchange_rate}}'    => 'Exchange Rate Used',
            '{{mc_order.charged_notice}}'  => 'Checkout Currency Notice',
        ];
    }
}
