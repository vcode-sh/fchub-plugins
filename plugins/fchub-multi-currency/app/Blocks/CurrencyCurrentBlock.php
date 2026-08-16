<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
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
        FrontendModule::ensureContextAssetEnqueued();

        $displayMode = (string) ($attributes['displayMode'] ?? 'flag_code');
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes([
                'class' => 'fchub-mc-inline-block fchub-mc-inline-block--current',
                'data-fchub-mc-context-current' => $displayMode,
            ])
            : 'class="fchub-mc-inline-block fchub-mc-inline-block--current"'
                . ' data-fchub-mc-context-current="' . esc_attr($displayMode) . '"';

        return '<div ' . $wrapperAttributes . '>'
            . CurrencyContextPresenter::renderCurrentCurrency($displayMode)
            . '</div>';
    }
}
