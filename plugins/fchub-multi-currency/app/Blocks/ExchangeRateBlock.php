<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

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
        $precision = (int) ($attributes['precision'] ?? 4);
        $format = (string) ($attributes['format'] ?? 'compact');
        $hideWhenBase = (bool) ($attributes['hideWhenBaseDisplay'] ?? false);
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'fchub-mc-inline-block fchub-mc-inline-block--rate'])
            : 'class="fchub-mc-inline-block fchub-mc-inline-block--rate"';

        return '<div ' . $wrapperAttributes . '>'
            . CurrencyContextPresenter::renderRateValue($precision, $format, $hideWhenBase)
            . '</div>';
    }
}
