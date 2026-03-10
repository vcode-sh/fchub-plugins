<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class FluentCrmSmartCodes
{
    private const CART_FUNNELS = [
        'fluent-cart-order-paid',
        'fluent-cart-order-refunded',
        'fluent-cart-subscription-activated',
        'fluent-cart-subscription-renewed',
        'fluent-cart-subscription-canceled',
    ];

    public static function register(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        add_filter('fluent_crm/extended_smart_codes', [self::class, 'registerGlobalSmartCodes']);
        add_filter('fluent_crm_funnel_context_smart_codes', [self::class, 'registerFunnelSmartCodes'], 10, 2);
        add_filter('fluent_crm/smartcode_group_callback_mc_order', [self::class, 'parse'], 10, 4);
    }

    /**
     * @param array<string, mixed> $groups
     * @return array<string, mixed>
     */
    public static function registerGlobalSmartCodes(array $groups): array
    {
        $groups['mc_order'] = [
            'title'     => 'Multi-Currency Order',
            'shortcodes' => self::getSmartCodeDefinitions(),
        ];

        return $groups;
    }

    /**
     * @param array<string, mixed> $groups
     * @param object|null $funnel
     * @return array<string, mixed>
     */
    public static function registerFunnelSmartCodes(array $groups, $funnel = null): array
    {
        if ($funnel && isset($funnel->trigger_name) && !in_array($funnel->trigger_name, self::CART_FUNNELS, true)) {
            return $groups;
        }

        $groups['mc_order'] = [
            'title'     => 'Multi-Currency Order',
            'shortcodes' => self::getSmartCodeDefinitions(),
        ];

        return $groups;
    }

    public static function parse(string $code, string $valueKey, string $defaultValue, $subscriber): string
    {
        try {
            $funnelSubscriberId = $subscriber->funnel_subscriber_id ?? null;

            if (!$funnelSubscriberId) {
                return $defaultValue;
            }

            $funnelSub = \FluentCrm\App\Models\FunnelSubscriber::where('id', $funnelSubscriberId)->first();

            if (!$funnelSub) {
                return $defaultValue;
            }

            $sourceRefId = $funnelSub['source_ref_id'] ?? null;

            if (!$sourceRefId) {
                return $defaultValue;
            }

            $order = \FluentCart\App\Models\Order::find((int) $sourceRefId);

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
                $displayCents = (int) round($order->total_amount * (float) $rate);
                return \FluentCart\App\Helpers\Helper::toDecimal($displayCents, true, $displayCurrency);

            case 'display_subtotal':
                if (!$displayCurrency || !$rate) {
                    return $defaultValue;
                }
                $displayCents = (int) round($order->subtotal * (float) $rate);
                return \FluentCart\App\Helpers\Helper::toDecimal($displayCents, true, $displayCurrency);

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
