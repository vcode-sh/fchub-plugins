<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class CurrencySelectorButtonsBlock
{
    public static function metadataPath(): string
    {
        return FCHUB_MC_PATH . 'blocks/currency-selector-buttons';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        $optionStore = new OptionStore();
        $defaults = CurrencySwitcherRenderer::normalizeGlobalDefaults($optionStore->get('switcher_defaults', []));
        $favoriteCurrencies = CurrencySwitcherRenderer::normalizeCurrencyCodeList($attributes['favoriteCurrencies'] ?? []);
        $showFavoritesFirst = (bool) ($attributes['showFavoritesFirst'] ?? true);

        $currencies = CurrencySwitcherRenderer::publicCurrencies($optionStore, $favoriteCurrencies, $showFavoritesFirst);
        if ($currencies === []) {
            return '';
        }

        FrontendModule::ensureSwitcherAssetsEnqueued();

        $current = CurrencySwitcherRenderer::currentSelection($optionStore);
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'fchub-mc-selector-buttons'])
            : 'class="fchub-mc-selector-buttons"';

        $html = '<div ' . $wrapperAttributes . ' data-fchub-mc-button-switcher>';

        foreach ($currencies as $currency) {
            $code = (string) ($currency['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $isActive = strtoupper($code) === strtoupper($current);
            $className = 'fchub-mc-selector-buttons__button' . ($isActive ? ' is-active' : '');
            $html .= '<button type="button" class="' . esc_attr($className) . '" data-value="' . esc_attr($code) . '">';
            $html .= '<span class="fchub-mc-selector-buttons__flag">' . esc_html((string) ($currency['flag'] ?? '')) . '</span>';
            $html .= '<span class="fchub-mc-selector-buttons__label">' . esc_html($code) . '</span>';
            $html .= '</button>';
        }

        $html .= '</div>';

        return $html;
    }
}
