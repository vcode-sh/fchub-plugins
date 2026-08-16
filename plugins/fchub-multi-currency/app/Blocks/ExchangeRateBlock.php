<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Frontend\CurrencyContextPresenter;

defined('ABSPATH') || exit;

final class ExchangeRateBlock
{
    public static function metadataPath(): string
    {
        return FCHUB_MC_PATH . 'blocks/exchange-rate';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        FrontendModule::ensureContextAssetEnqueued();

        $precision = (int) ($attributes['precision'] ?? 4);
        $format = (string) ($attributes['format'] ?? 'compact');
        $hideWhenBase = (bool) ($attributes['hideWhenBaseDisplay'] ?? false);
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes([
                'class' => 'fchub-mc-inline-block fchub-mc-inline-block--rate',
                'data-fchub-mc-context-rate' => $format,
                'data-fchub-mc-rate-precision' => (string) max(0, min(8, $precision)),
                'data-fchub-mc-hide-when-base' => $hideWhenBase ? '1' : '0',
            ])
            : 'class="fchub-mc-inline-block fchub-mc-inline-block--rate"'
                . ' data-fchub-mc-context-rate="' . esc_attr($format) . '"'
                . ' data-fchub-mc-rate-precision="' . esc_attr((string) max(0, min(8, $precision))) . '"'
                . ' data-fchub-mc-hide-when-base="' . ($hideWhenBase ? '1' : '0') . '"';

        return '<div ' . $wrapperAttributes . '>'
            . CurrencyContextPresenter::renderRateValue($precision, $format, $hideWhenBase)
            . '</div>';
    }
}
