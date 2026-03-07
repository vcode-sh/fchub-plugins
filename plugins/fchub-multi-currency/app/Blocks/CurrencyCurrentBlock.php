<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

use FChubMultiCurrency\Frontend\CurrencyContextPresenter;

defined('ABSPATH') || exit;

final class CurrencyCurrentBlock
{
    public static function metadataPath(): string
    {
        return FCHUB_MC_PATH . 'blocks/currency-current';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        $displayMode = (string) ($attributes['displayMode'] ?? 'flag_code');
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'fchub-mc-inline-block fchub-mc-inline-block--current'])
            : 'class="fchub-mc-inline-block fchub-mc-inline-block--current"';

        return '<div ' . $wrapperAttributes . '>'
            . CurrencyContextPresenter::renderCurrentCurrency($displayMode)
            . '</div>';
    }
}
