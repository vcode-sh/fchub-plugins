<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Frontend\CurrencyContextPresenter;

defined('ABSPATH') || exit;

final class CurrencyContextNoticeBlock
{
    public static function metadataPath(): string
    {
        return FCHUB_MC_PATH . 'blocks/currency-context-notice';
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public static function render(array $attributes = []): string
    {
        FrontendModule::ensureContextAssetEnqueued();

        $mode = (string) ($attributes['mode'] ?? 'compact');
        $hideWhenBase = (bool) ($attributes['hideWhenBaseDisplay'] ?? true);
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes([
                'class' => 'fchub-mc-inline-block fchub-mc-inline-block--notice',
                'data-fchub-mc-context-notice' => $mode,
                'data-fchub-mc-hide-when-base' => $hideWhenBase ? '1' : '0',
            ])
            : 'class="fchub-mc-inline-block fchub-mc-inline-block--notice"'
                . ' data-fchub-mc-context-notice="' . esc_attr($mode) . '"'
                . ' data-fchub-mc-hide-when-base="' . ($hideWhenBase ? '1' : '0') . '"';

        return '<div ' . $wrapperAttributes . '>'
            . CurrencyContextPresenter::renderNotice($mode, $hideWhenBase)
            . '</div>';
    }
}
