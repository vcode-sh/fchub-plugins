<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;

defined('ABSPATH') || exit;

final class MultiCurrencySettings
{
    public static function register(): void
    {
        $slug = Constants::FC_ADDON_SLUG;
        add_filter("fluent_cart/integration/global_integration_settings_{$slug}", [self::class, 'getGlobalSettings'], 10, 2);
        add_filter("fluent_cart/integration/global_integration_fields_{$slug}", [self::class, 'getGlobalFields'], 10, 2);
        add_action("fluent_cart/integration/save_global_integration_settings_{$slug}", [self::class, 'saveGlobalSettings'], 10, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        return (new OptionStore())->all();
    }

    public static function getGlobalSettings($settings, $args): array
    {
        return self::getSettings();
    }

    public static function getGlobalFields($fields, $args): array
    {
        $fieldSettings = [
            'logo'             => FCHUB_MC_URL . 'assets/icons/multi-currency.svg',
            'save_button_text' => __('Save Settings', 'fchub-multi-currency'),
            'valid_message'    => __('Multi-Currency integration is active', 'fchub-multi-currency'),
            'invalid_message'  => __('Multi-Currency integration is disabled', 'fchub-multi-currency'),
            'fields'           => [
                'enabled' => [
                    'type'    => 'select',
                    'label'   => __('Enable Multi-Currency', 'fchub-multi-currency'),
                    'tips'    => __('Master switch for display-layer multi-currency across the store.', 'fchub-multi-currency'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-multi-currency'),
                        'no'  => __('No', 'fchub-multi-currency'),
                    ],
                ],
                'checkout_disclosure_enabled' => [
                    'type'    => 'select',
                    'label'   => __('Checkout Disclosure', 'fchub-multi-currency'),
                    'tips'    => __('Show a notice at checkout that payment is processed in the base currency.', 'fchub-multi-currency'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-multi-currency'),
                        'no'  => __('No', 'fchub-multi-currency'),
                    ],
                ],
                'fluentcrm_enabled' => [
                    'type'    => 'select',
                    'label'   => __('FluentCRM Sync', 'fchub-multi-currency'),
                    'tips'    => __('Tag contacts in FluentCRM based on their currency preference.', 'fchub-multi-currency'),
                    'options' => [
                        'yes' => __('Yes', 'fchub-multi-currency'),
                        'no'  => __('No', 'fchub-multi-currency'),
                    ],
                ],
                'full_settings' => [
                    'type'  => 'link',
                    'label' => __('Full Settings', 'fchub-multi-currency'),
                    'tips'  => __('Configure currencies, exchange rates, checkout disclosure, and more.', 'fchub-multi-currency'),
                    'url'   => admin_url('admin.php?page=fluent-cart#/settings/multi-currency'),
                ],
            ],
        ];

        return $fieldSettings;
    }

    public static function saveGlobalSettings($args): void
    {
        $integration = is_array($args) && is_array($args['integration'] ?? null)
            ? $args['integration']
            : [];
        $optionStore = new OptionStore();
        $settings    = self::getSettings();
        $settings['enabled'] = ($integration['enabled'] ?? 'yes') === 'yes' ? 'yes' : 'no';
        $settings['checkout_disclosure_enabled'] = ($integration['checkout_disclosure_enabled'] ?? 'yes') === 'yes'
            ? 'yes'
            : 'no';
        $settings['fluentcrm_enabled'] = ($integration['fluentcrm_enabled'] ?? 'yes') === 'yes' ? 'yes' : 'no';

        $optionStore->save($settings);

        wp_send_json([
            'message' => __('Multi-Currency settings saved.', 'fchub-multi-currency'),
            'status'  => true,
        ], 200);
    }
}
