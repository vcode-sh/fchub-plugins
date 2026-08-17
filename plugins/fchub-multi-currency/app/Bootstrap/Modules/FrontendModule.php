<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Frontend\CurrencyContextPayload;
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Integration\FluentCartCurrency;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\FeatureFlags;
use FChubMultiCurrency\Support\Hooks;
use FluentCart\Api\CurrencySettings;

defined('ABSPATH') || exit;

final class FrontendModule implements ModuleContract
{
    private static bool $contextAssetConfigured = false;

    public function register(): void
    {
        self::registerAssets();
        add_action('wp_enqueue_scripts', [self::class, 'enqueueProjectionAssets'], 6);
        add_shortcode('fchub_currency_switcher', [self::class, 'renderSwitcher']);
    }

    public static function registerAssets(): void
    {
        self::$contextAssetConfigured = false;

        $contextPath = FCHUB_MC_PATH . 'assets/js/currency-context.js';
        wp_register_script(
            'fchub-mc-context',
            FCHUB_MC_URL . 'assets/js/currency-context.js',
            [],
            (string) (@filemtime($contextPath) ?: FCHUB_MC_VERSION),
            true,
        );

        $projectionPath = FCHUB_MC_PATH . 'assets/js/currency-projection.js';
        wp_register_script(
            'fchub-mc-projection',
            FCHUB_MC_URL . 'assets/js/currency-projection.js',
            ['fchub-mc-context'],
            (string) (@filemtime($projectionPath) ?: FCHUB_MC_VERSION),
            true,
        );

        $switcherJsPath = FCHUB_MC_PATH . 'assets/js/currency-switcher.js';
        wp_register_script(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/js/currency-switcher.js',
            ['fchub-mc-context'],
            (string) (@filemtime($switcherJsPath) ?: FCHUB_MC_VERSION),
            true,
        );

        $switcherCssPath = FCHUB_MC_PATH . 'assets/css/currency-switcher.css';
        wp_register_style(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/css/currency-switcher.css',
            [],
            (string) (@filemtime($switcherCssPath) ?: FCHUB_MC_VERSION),
        );
    }

    public static function enqueueProjectionAssets(): void
    {
        if (!Hooks::isEnabled() || !FeatureFlags::isEnabled('js_projection')) {
            return;
        }

        self::ensureContextAssetEnqueued();
        wp_enqueue_script('fchub-mc-projection');
    }

    public static function ensureSwitcherAssetsEnqueued(): void
    {
        self::ensureContextAssetEnqueued();
        wp_enqueue_script('fchub-mc-switcher');
        wp_enqueue_style('fchub-mc-switcher');
    }

    public static function ensureContextAssetEnqueued(): void
    {
        if (!self::$contextAssetConfigured) {
            wp_add_inline_script(
                'fchub-mc-context',
                'window.fchubMcConfig = ' . wp_json_encode(self::buildFrontendConfig()) . ';',
                'before',
            );
            self::$contextAssetConfigured = true;
        }

        wp_enqueue_script('fchub-mc-context');
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildFrontendConfig(): array
    {
        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(ContextModule::buildResolverChain($optionStore), $optionStore);
        $context = $contextService->resolve();

        $fcSettings = CurrencySettings::get();
        $shopSeparators = FluentCartCurrency::separators();

        return array_merge(CurrencyContextPayload::build($context, $optionStore), [
            'roundingMode'          => $optionStore->get('rounding_mode', 'half_up'),
            'restUrl'               => rest_url(Constants::REST_NAMESPACE),
            'nonce'                 => wp_create_nonce('wp_rest'),
            'currencies'            => $optionStore->get('display_currencies', []),
            'allowedCurrencyCodes'  => SelectableCurrencyCodes::fromSettings($optionStore->all())->all(),
            'cookieName'            => Constants::COOKIE_KEY,
            'cookiePersistenceEnabled' => $optionStore->get('cookie_enabled', 'yes') === 'yes',
            'cookieLifetimeDays'    => (int) $optionStore->get('cookie_lifetime_days', 90),
            'accountPersistenceEnabled' => $optionStore->get('account_persistence_enabled', 'yes') === 'yes',
            'isLoggedIn'            => get_current_user_id() > 0,
            'urlParamEnabled'       => $optionStore->get('url_param_enabled', 'yes') === 'yes',
            'urlParamKey'           => (string) $optionStore->get('url_param_key', 'currency'),
            'flagBaseUrl'           => FCHUB_MC_URL . 'assets/flags/4x3/',
            'baseCurrencySign'      => html_entity_decode($fcSettings['currency_sign'] ?? '$', ENT_QUOTES, 'UTF-8'),
            'baseCurrencyPosition'  => $fcSettings['currency_position'] ?? 'before',
            'baseCurrencyCode'      => $fcSettings['currency'] ?? 'USD',
            'baseDecimalSep'        => $shopSeparators['decimal'],
            'baseThousandSep'       => $shopSeparators['thousand'],
            'baseDecimals'          => ($fcSettings['is_zero_decimal'] ?? false) ? 0 : 2,
        ]);
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function renderSwitcher($atts): string
    {
        return CurrencySwitcherRenderer::renderShortcode(shortcode_atts([
            'preset'                => null,
            'label'                 => '',
            'align'                 => 'left',
            'label_position'        => null,
            'show_flag'             => null,
            'show_code'             => null,
            'show_symbol'           => null,
            'show_name'             => null,
            'show_rate_badge'       => null,
            'show_option_flags'     => null,
            'show_option_codes'     => null,
            'show_option_symbols'   => null,
            'show_option_names'     => null,
            'show_active_indicator' => null,
            'show_context_note'     => null,
            'show_rate_value'       => null,
            'search_mode'           => null,
            'favorite_currencies'   => null,
            'show_favorites_first'  => null,
            'size'                  => null,
            'width_mode'            => null,
            'dropdown_position'     => null,
            'dropdown_direction'    => null,
        ], $atts, 'fchub_currency_switcher'));
    }
}
