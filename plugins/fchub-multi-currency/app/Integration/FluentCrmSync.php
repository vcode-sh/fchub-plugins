<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class FluentCrmSync
{
    public static function register(): void
    {
        if (!defined('FLUENTCRM')) {
            return;
        }

        add_action('fchub_mc/context_switched', [self::class, 'onContextSwitched'], 10, 2);
        add_action('fluent_cart/order_paid_done', [self::class, 'onOrderPaid'], 10, 1);
    }

    public static function onContextSwitched(string $currencyCode, int $userId): void
    {
        if ($userId === 0) {
            return;
        }

        $settings = (new OptionStore())->all();

        if (($settings['fluentcrm_enabled'] ?? 'yes') !== 'yes') {
            return;
        }

        try {
            $contactApi = FluentCrmApi('contacts');
            $contact = $contactApi->getContactByUserId($userId);

            if (!$contact) {
                return;
            }

            $fieldKey = $settings['fluentcrm_field_preferred'] ?? 'preferred_currency';
            $contact->syncCustomFieldValues([$fieldKey => $currencyCode], false);

            $tagPrefix = $settings['fluentcrm_tag_prefix'] ?? 'currency:';
            $tagName = $tagPrefix . strtoupper($currencyCode);

            if (($settings['fluentcrm_auto_create_tags'] ?? 'yes') === 'yes') {
                $tag = \FluentCrm\App\Models\Tag::firstOrCreate(
                    ['slug' => sanitize_title($tagName)],
                    ['title' => $tagName],
                );

                $contact->attachTags([$tag->id]);
            }
        } catch (\Throwable $e) {
            Logger::error('FluentCRM sync failed on context switch', [
                'error'    => $e->getMessage(),
                'user_id'  => $userId,
                'currency' => $currencyCode,
            ]);
        }
    }

    public static function onOrderPaid($order): void
    {
        $settings = (new OptionStore())->all();

        if (($settings['fluentcrm_enabled'] ?? 'yes') !== 'yes') {
            return;
        }

        try {
            $userId = (int) ($order->user_id ?? 0);

            if ($userId === 0) {
                return;
            }

            $contactApi = FluentCrmApi('contacts');
            $contact = $contactApi->getContactByUserId($userId);

            if (!$contact) {
                return;
            }

            $displayCurrency = $order->getMeta('_fchub_mc_display_currency');
            $rate = $order->getMeta('_fchub_mc_rate');

            if ($displayCurrency) {
                $fieldKey = $settings['fluentcrm_field_last_order'] ?? 'last_order_display_currency';
                $contact->syncCustomFieldValues([$fieldKey => $displayCurrency], false);
            }

            if ($rate) {
                $rateFieldKey = $settings['fluentcrm_field_last_rate'] ?? 'last_order_fx_rate';
                $contact->syncCustomFieldValues([$rateFieldKey => $rate], false);
            }
        } catch (\Throwable $e) {
            Logger::error('FluentCRM sync failed on order paid', [
                'error'    => $e->getMessage(),
                'order_id' => $order->id ?? 'unknown',
            ]);
        }
    }
}
