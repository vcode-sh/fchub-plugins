<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Blocks;

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
        $mode = (string) ($attributes['mode'] ?? 'compact');
        $hideWhenBase = (bool) ($attributes['hideWhenBaseDisplay'] ?? true);
        $wrapperAttributes = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(['class' => 'fchub-mc-inline-block fchub-mc-inline-block--notice'])
            : 'class="fchub-mc-inline-block fchub-mc-inline-block--notice"';

        return '<div ' . $wrapperAttributes . '>'
            . CurrencyContextPresenter::renderNotice($mode, $hideWhenBase)
            . '</div>';
    }
}
