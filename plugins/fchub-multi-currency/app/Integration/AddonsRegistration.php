<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

defined('ABSPATH') || exit;

final class AddonsRegistration
{
    public static function register(): void
    {
        add_filter('fluent_cart/integration/addons', [self::class, 'registerAddon']);
    }

    /**
     * @param array<string, array<string, mixed>> $addons
     * @return array<string, array<string, mixed>>
     */
    public static function registerAddon(array $addons): array
    {
        $addons['fchub-multi-currency'] = [
            'title'       => __('FCHub Multi-Currency', 'fchub-multi-currency'),
            'description' => __('Display-layer multi-currency with exchange rate management and checkout disclosure.', 'fchub-multi-currency'),
            'logo'        => FCHUB_MC_URL . 'assets/icons/multi-currency.svg',
            'enabled'     => true,
            'categories'  => ['enhancement'],
        ];

        return $addons;
    }
}
