<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\FeatureFlags;
use FChubMultiCurrency\Support\Hooks;
use FluentCart\Api\CurrencySettings;

defined('ABSPATH') || exit;

final class FrontendModule implements ModuleContract
{
    public function register(): void
    {
        self::registerAssets();
        add_action('wp_enqueue_scripts', [self::class, 'enqueueProjectionAssets'], 6);
        add_shortcode('fchub_currency_switcher', [self::class, 'renderSwitcher']);
    }

    public static function registerAssets(): void
    {
        $projectionPath = FCHUB_MC_PATH . 'assets/js/currency-projection.js';
        wp_register_script(
            'fchub-mc-projection',
            FCHUB_MC_URL . 'assets/js/currency-projection.js',
            [],
            (string) (@filemtime($projectionPath) ?: FCHUB_MC_VERSION),
            true,
        );

        $switcherJsPath = FCHUB_MC_PATH . 'assets/js/currency-switcher.js';
        wp_register_script(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/js/currency-switcher.js',
            [],
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

        wp_localize_script('fchub-mc-projection', 'fchubMcConfig', self::buildFrontendConfig());
        wp_enqueue_script('fchub-mc-projection');
    }

    public static function ensureSwitcherAssetsEnqueued(): void
    {
        wp_localize_script('fchub-mc-switcher', 'fchubMcConfig', self::buildFrontendConfig());
        wp_enqueue_script('fchub-mc-switcher');
        wp_enqueue_style('fchub-mc-switcher');
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
        $baseSeparatorSetting = $fcSettings['currency_separator'] ?? 'dot';

        $config = [
            'rate'                  => $context->rate->rateAsFloat(),
            'displayCurrency'       => $context->displayCurrency->code,
            'displayCurrencyName'   => $context->displayCurrency->name,
            'baseCurrency'          => $context->baseCurrency->code,
            'decimals'              => $context->displayCurrency->decimals,
            'symbol'                => html_entity_decode($context->displayCurrency->symbol, ENT_QUOTES, 'UTF-8'),
            'position'              => $context->displayCurrency->position->value,
            'isBaseDisplay'         => $context->isBaseDisplay,
            'roundingMode'          => $optionStore->get('rounding_mode', 'half_up'),
            'restUrl'               => rest_url(Constants::REST_NAMESPACE),
            'nonce'                 => wp_create_nonce('wp_rest'),
            'currencies'            => $optionStore->get('display_currencies', []),
            'flagBaseUrl'           => FCHUB_MC_URL . 'assets/flags/4x3/',
            'baseCurrencySign'      => html_entity_decode($fcSettings['currency_sign'] ?? '$', ENT_QUOTES, 'UTF-8'),
            'baseCurrencyPosition'  => $fcSettings['currency_position'] ?? 'before',
            'baseCurrencyCode'      => $fcSettings['currency'] ?? 'USD',
            'baseDecimalSep'        => match ($baseSeparatorSetting) {
                'comma', 'space_comma' => ',',
                default                => '.',
            },
            'baseThousandSep'       => match ($baseSeparatorSetting) {
                'comma'       => '.',
                'space_comma' => ' ',
                'none_dot'    => '',
                default       => ',',
            },
            'baseDecimals'          => ($fcSettings['is_zero_decimal'] ?? false) ? 0 : 2,
            'displayDecSep'         => self::resolveDisplaySep($context, $optionStore, 'decimal_separator', match ($context->displayCurrency->position->value) {
                'right', 'right_space' => ',',
                default                => '.',
            }),
            'displayThousandSep'    => self::resolveDisplaySep(
                $context,
                $optionStore,
                'thousand_separator',
                match ($context->displayCurrency->position->value) {
                    'right', 'right_space' => '.',
                    default                => ',',
                },
            ),
        ];

        $disclosureService = new CheckoutDisclosureService($optionStore);
        $disclosure = $disclosureService->getDisclosure($context);
        $config['disclosureEnabled'] = $disclosure !== null;
        $config['disclosureText'] = $disclosure;

        return $config;
    }

    private static function resolveDisplaySep(
        \FChubMultiCurrency\Domain\ValueObjects\CurrencyContext $context,
        OptionStore $optionStore,
        string $field,
        string $fallback,
    ): string {
        $currencies = $optionStore->get('display_currencies', []);
        if (!is_array($currencies)) {
            return $fallback;
        }

        foreach ($currencies as $currency) {
            if (!is_array($currency)) {
                continue;
            }
            if (strtoupper($currency['code'] ?? '') === $context->displayCurrency->code) {
                $value = $currency[$field] ?? '';
                if ($value === 'none') {
                    return '';
                }

                return $value !== '' ? $value : $fallback;
            }
        }

        return $fallback;
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
