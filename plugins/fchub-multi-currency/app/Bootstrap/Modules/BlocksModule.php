<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Blocks\CurrencySwitcherBlock;
use FChubMultiCurrency\Blocks\CurrencyCurrentBlock;
use FChubMultiCurrency\Blocks\CurrencyContextNoticeBlock;
use FChubMultiCurrency\Blocks\CurrencySelectorButtonsBlock;
use FChubMultiCurrency\Blocks\ExchangeRateBlock;
use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class BlocksModule implements ModuleContract
{
    public function register(): void
    {
        self::registerAssets();
        self::registerBlocks();
        self::registerPatterns();
        add_action('enqueue_block_editor_assets', [self::class, 'enqueueEditorAssets']);
    }

    public static function registerAssets(): void
    {
        $editorScriptPath = FCHUB_MC_PATH . 'blocks/currency-switcher/editor.js';
        wp_register_script(
            'fchub-mc-switcher-block-editor-script',
            FCHUB_MC_URL . 'blocks/currency-switcher/editor.js',
            ['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n'],
            (string) (@filemtime($editorScriptPath) ?: FCHUB_MC_VERSION),
            true,
        );

        $editorStylePath = FCHUB_MC_PATH . 'blocks/currency-switcher/editor.css';
        wp_register_style(
            'fchub-mc-switcher-block-editor-style',
            FCHUB_MC_URL . 'blocks/currency-switcher/editor.css',
            ['fchub-mc-switcher'],
            (string) (@filemtime($editorStylePath) ?: FCHUB_MC_VERSION),
        );
    }

    public static function registerBlocks(): void
    {
        $blocks = [
            [CurrencySwitcherBlock::metadataPath(), [CurrencySwitcherBlock::class, 'render']],
            [CurrencyCurrentBlock::metadataPath(), [CurrencyCurrentBlock::class, 'render']],
            [ExchangeRateBlock::metadataPath(), [ExchangeRateBlock::class, 'render']],
            [CurrencyContextNoticeBlock::metadataPath(), [CurrencyContextNoticeBlock::class, 'render']],
            [CurrencySelectorButtonsBlock::metadataPath(), [CurrencySelectorButtonsBlock::class, 'render']],
        ];

        foreach ($blocks as [$metadataPath, $renderCallback]) {
            register_block_type($metadataPath, [
                'render_callback' => $renderCallback,
            ]);
        }
    }

    public static function registerPatterns(): void
    {
        if (!function_exists('register_block_pattern_category') || !function_exists('register_block_pattern')) {
            return;
        }

        register_block_pattern_category('fchub-multi-currency', [
            'label' => __('FCHub Multi-Currency', 'fchub-multi-currency'),
        ]);

        register_block_pattern('fchub-multi-currency/currency-showcase', [
            'title' => __('Currency Showcase', 'fchub-multi-currency'),
            'categories' => ['fchub-multi-currency'],
            'description' => __('A ready-made showcase of the full currency block family.', 'fchub-multi-currency'),
            'content' =>
                '<!-- wp:group {"layout":{"type":"constrained"}} -->'
                . '<div class="wp-block-group">'
                . '<!-- wp:heading {"level":3} --><h3>Currency Showcase</h3><!-- /wp:heading -->'
                . '<!-- wp:fchub-multi-currency/switcher {"useGlobalDefaults":true} /-->'
                . '<!-- wp:columns --><div class="wp-block-columns">'
                . '<!-- wp:column --><div class="wp-block-column"><!-- wp:fchub-multi-currency/current-currency /--></div><!-- /wp:column -->'
                . '<!-- wp:column --><div class="wp-block-column"><!-- wp:fchub-multi-currency/exchange-rate /--></div><!-- /wp:column -->'
                . '<!-- wp:column --><div class="wp-block-column"><!-- wp:fchub-multi-currency/context-notice /--></div><!-- /wp:column -->'
                . '</div><!-- /wp:columns -->'
                . '<!-- wp:fchub-multi-currency/selector-buttons /-->'
                . '</div><!-- /wp:group -->',
        ]);
    }

    public static function enqueueEditorAssets(): void
    {
        $settings = (new OptionStore())->all();

        wp_localize_script('fchub-mc-switcher-block-editor-script', 'fchubMcBlockEditor', [
            'settings' => $settings,
            'switcherDefaults' => $settings['switcher_defaults'] ?? [],
            'catalogue' => CurrencyCatalogueController::getCatalogue(),
            'displayCurrencies' => $settings['display_currencies'] ?? [],
            'baseCurrency' => $settings['base_currency'] ?? 'USD',
        ]);

        wp_enqueue_script('fchub-mc-switcher-block-editor-script');
        wp_enqueue_style('fchub-mc-switcher');
        wp_enqueue_style('fchub-mc-switcher-block-editor-style');
    }
}
