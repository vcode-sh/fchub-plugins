<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;

defined('ABSPATH') || exit;

final class CurrencySwitcherBlock
{
    public static function metadataPath(): string
    {
        return FCHUB_MC_PATH . 'blocks/currency-switcher';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        $normalized = CurrencySwitcherRenderer::normalizeBlockAttributes($attributes);
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes([
                'class' => 'fchub-mc-switcher-block',
            ])
            : 'class="' . esc_attr(
                'wp-block-fchub-multi-currency-switcher fchub-mc-switcher-block',
            ) . '"';

        return CurrencySwitcherRenderer::renderBlock($normalized, $wrapperAttributes);
    }
}
