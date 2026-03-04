<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\FeatureFlags;
use FChubMultiCurrency\Support\Hooks;

defined('ABSPATH') || exit;

final class FrontendModule implements ModuleContract
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets'], 6);
        add_shortcode('fchub_currency_switcher', [self::class, 'renderSwitcher']);
    }

    public static function enqueueAssets(): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(new ResolverChain(), $optionStore);
        $context = $contextService->resolve();

        $config = [
            'rate'            => $context->rate->rateAsFloat(),
            'displayCurrency' => $context->displayCurrency->code,
            'baseCurrency'    => $context->baseCurrency->code,
            'decimals'        => $context->displayCurrency->decimals,
            'symbol'          => $context->displayCurrency->symbol,
            'position'        => $context->displayCurrency->position->value,
            'isBaseDisplay'   => $context->isBaseDisplay,
            'restUrl'         => rest_url(Constants::REST_NAMESPACE),
            'nonce'           => wp_create_nonce('wp_rest'),
            'currencies'      => $optionStore->get('display_currencies', []),
        ];

        if (FeatureFlags::isEnabled('js_projection')) {
            wp_enqueue_script(
                'fchub-mc-projection',
                FCHUB_MC_URL . 'assets/js/currency-projection.js',
                [],
                FCHUB_MC_VERSION,
                true,
            );
            wp_localize_script('fchub-mc-projection', 'fchubMcConfig', $config);
        }

        wp_enqueue_script(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/js/currency-switcher.js',
            [],
            FCHUB_MC_VERSION,
            true,
        );

        if (!FeatureFlags::isEnabled('js_projection')) {
            wp_localize_script('fchub-mc-switcher', 'fchubMcConfig', $config);
        }

        wp_enqueue_style(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/css/currency-switcher.css',
            [],
            FCHUB_MC_VERSION,
        );
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function renderSwitcher($atts): string
    {
        if (!Hooks::isEnabled()) {
            return '';
        }

        $optionStore = new OptionStore();
        $currencies = $optionStore->get('display_currencies', []);
        $contextService = new CurrencyContextService(new ResolverChain(), $optionStore);
        $context = $contextService->resolve();
        $currentCode = $context->displayCurrency->code;

        if (empty($currencies)) {
            return '';
        }

        $html = '<select data-fchub-mc-switcher class="fchub-mc-switcher">';
        foreach ($currencies as $currency) {
            if (!is_array($currency) || empty($currency['code'])) {
                continue;
            }
            $code = esc_attr($currency['code']);
            $name = esc_html($currency['name'] ?? $code);
            $selected = ($code === $currentCode) ? ' selected' : '';
            $html .= "<option value=\"{$code}\"{$selected}>{$name}</option>";
        }
        $html .= '</select>';

        return $html;
    }
}
