<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\FeatureFlags;
use FChubMultiCurrency\Support\Hooks;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\CurrenciesHelper;

defined('ABSPATH') || exit;

final class FrontendModule implements ModuleContract
{
    public function register(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueueAssets'], 6);
        add_shortcode('fchub_currency_switcher', [self::class, 'renderSwitcher']);
    }

    public static function enqueueAssets(): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(ContextModule::buildResolverChain($optionStore), $optionStore);
        $context = $contextService->resolve();

        // Read FluentCart's base currency formatting config (before any filter)
        $fcSettings = CurrencySettings::get();
        $baseSeparatorSetting = $fcSettings['currency_separator'] ?? 'dot';

        $config = [
            'rate'              => $context->rate->rateAsFloat(),
            'displayCurrency'   => $context->displayCurrency->code,
            'baseCurrency'      => $context->baseCurrency->code,
            'decimals'          => $context->displayCurrency->decimals,
            'symbol'            => html_entity_decode($context->displayCurrency->symbol, ENT_QUOTES, 'UTF-8'),
            'position'          => $context->displayCurrency->position->value,
            'isBaseDisplay'     => $context->isBaseDisplay,
            'roundingMode'      => $optionStore->get('rounding_mode', 'half_up'),
            'restUrl'           => rest_url(Constants::REST_NAMESPACE),
            'nonce'             => wp_create_nonce('wp_rest'),
            'currencies'        => $optionStore->get('display_currencies', []),
            'flagBaseUrl'       => FCHUB_MC_URL . 'assets/flags/4x3/',
            // Base currency formatting info for JS price parsing
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
            'displayThousandSep'    => self::resolveDisplaySep($context, $optionStore, 'thousand_separator', match ($context->displayCurrency->position->value) {
                'right', 'right_space' => '.',
                default                => ',',
            }),
        ];

        $disclosureService = new CheckoutDisclosureService($optionStore);
        $disclosure = $disclosureService->getDisclosure($context);
        $config['disclosureEnabled'] = $disclosure !== null;
        $config['disclosureText'] = $disclosure;

        if (FeatureFlags::isEnabled('js_projection')) {
            $projectionPath = FCHUB_MC_PATH . 'assets/js/currency-projection.js';
            wp_enqueue_script(
                'fchub-mc-projection',
                FCHUB_MC_URL . 'assets/js/currency-projection.js',
                [],
                (string) (@filemtime($projectionPath) ?: FCHUB_MC_VERSION),
                true,
            );
            wp_localize_script('fchub-mc-projection', 'fchubMcConfig', $config);
        }

        $switcherJsPath = FCHUB_MC_PATH . 'assets/js/currency-switcher.js';
        wp_enqueue_script(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/js/currency-switcher.js',
            [],
            (string) (@filemtime($switcherJsPath) ?: FCHUB_MC_VERSION),
            true,
        );

        if (!FeatureFlags::isEnabled('js_projection')) {
            wp_localize_script('fchub-mc-switcher', 'fchubMcConfig', $config);
        }

        $switcherCssPath = FCHUB_MC_PATH . 'assets/css/currency-switcher.css';
        wp_enqueue_style(
            'fchub-mc-switcher',
            FCHUB_MC_URL . 'assets/css/currency-switcher.css',
            [],
            (string) (@filemtime($switcherCssPath) ?: FCHUB_MC_VERSION),
        );
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
                return $value !== '' ? $value : $fallback;
            }
        }

        return $fallback;
    }

    private static function renderRateBadge(OptionStore $optionStore, ExchangeRate $rate): string
    {
        if ($optionStore->get('show_rate_freshness_badge', 'yes') !== 'yes') {
            return '';
        }

        $staleThresholdHrs = (int) $optionStore->get('stale_threshold_hrs', 24);
        $staleThresholdSeconds = $staleThresholdHrs * 3600;
        $isStale = $rate->isStale($staleThresholdSeconds);

        $fetchedTimestamp = strtotime($rate->fetchedAt . ' UTC');
        if ($fetchedTimestamp === false) {
            return '';
        }

        $ago = human_time_diff($fetchedTimestamp, time());
        $class = 'fchub-mc-rate-badge' . ($isStale ? ' fchub-mc-rate-badge--stale' : '');
        $text = esc_html(
            /* translators: %s: human-readable time difference, e.g. "2 hours" */
            sprintf(__('Rates updated %s ago', 'fchub-multi-currency'), $ago),
        );

        return '<span class="fchub-mc-switcher__footer">'
            . "<span class=\"{$class}\">"
            . '<span class="fchub-mc-rate-badge__dot" aria-hidden="true"></span>'
            . $text
            . '</span></span>';
    }

    /**
     * @param array<string, string>|string $atts
     */
    public static function renderSwitcher($atts): string
    {
        if (!Hooks::isEnabled()) {
            return '';
        }

        $atts = shortcode_atts([
            'label' => '',
            'align' => 'left',
        ], $atts, 'fchub_currency_switcher');

        $optionStore = new OptionStore();
        $currencies = $optionStore->get('display_currencies', []);
        $contextService = new CurrencyContextService(ContextModule::buildResolverChain($optionStore), $optionStore);
        $context = $contextService->resolve();
        $currentCode = $context->displayCurrency->code;

        if (empty($currencies)) {
            return '';
        }

        // Ensure the store's base currency is always available in the switcher
        $baseCode = $context->baseCurrency->code;
        $basePresent = false;
        foreach ($currencies as $currency) {
            if (is_array($currency) && ($currency['code'] ?? '') === $baseCode) {
                $basePresent = true;
                break;
            }
        }
        if (!$basePresent) {
            // Look up proper name/symbol from FluentCart's catalogue (context fallback may only have the code)
            $allCurrencies = CurrenciesHelper::getCurrencies();
            $allSigns = CurrenciesHelper::getCurrencySigns();
            array_unshift($currencies, [
                'code'   => $baseCode,
                'name'   => $allCurrencies[$baseCode] ?? $baseCode,
                'symbol' => $allSigns[$baseCode] ?? $baseCode,
            ]);
        }

        $currentFlag = CurrencyCatalogueController::codeToFlagImg($currentCode);

        // Use only inline elements (<span>) to prevent wpautop from injecting <p> tags.
        // ARIA roles (listbox, option) handle semantics; CSS handles layout.
        $alignClass = match ($atts['align']) {
            'right'  => ' fchub-mc-switcher--right',
            'center' => ' fchub-mc-switcher--center',
            default  => '',
        };
        $html = '<span class="fchub-mc-switcher' . $alignClass . '" data-fchub-mc-switcher>';

        $label = sanitize_text_field($atts['label']);
        if ($label !== '') {
            $html .= '<span class="fchub-mc-switcher__label">' . esc_html($label) . '</span>';
        }

        $html .= '<button type="button" class="fchub-mc-switcher__trigger" data-fchub-mc-trigger>';
        $html .= '<span class="fchub-mc-switcher__flag">' . $currentFlag . '</span>';
        $html .= '<span class="fchub-mc-switcher__code">' . esc_html($currentCode) . '</span>';
        $html .= '<span class="fchub-mc-switcher__caret" aria-hidden="true">&#9662;</span>';
        $html .= '</button>';

        // Dropdown panel
        $html .= '<span class="fchub-mc-switcher__dropdown" data-fchub-mc-dropdown hidden>';
        $html .= '<span class="fchub-mc-switcher__list" role="listbox" aria-label="'
            . esc_attr__('Select currency', 'fchub-multi-currency') . '">';

        foreach ($currencies as $currency) {
            if (!is_array($currency) || empty($currency['code'])) {
                continue;
            }
            $code = esc_attr($currency['code']);
            $name = esc_html($currency['name'] ?? $code);
            $flag = CurrencyCatalogueController::codeToFlagImg($currency['code']);
            $isActive = ($code === $currentCode);
            $activeClass = $isActive ? ' fchub-mc-switcher__option--active' : '';
            $ariaSelected = $isActive ? 'true' : 'false';

            $html .= "<span class=\"fchub-mc-switcher__option{$activeClass}\""
                . " role=\"option\" data-value=\"{$code}\""
                . " aria-selected=\"{$ariaSelected}\" tabindex=\"-1\">";
            $html .= "<span class=\"fchub-mc-switcher__flag\">{$flag}</span>";
            $html .= "<span class=\"fchub-mc-switcher__option-code\">{$code}</span>";
            $html .= '<span class="fchub-mc-switcher__option-sep" aria-hidden="true">&mdash;</span>';
            $html .= "<span class=\"fchub-mc-switcher__option-name\">{$name}</span>";
            $html .= '</span>';
        }

        $html .= '</span>'; // listbox
        $html .= self::renderRateBadge($optionStore, $context->rate);
        $html .= '</span>'; // dropdown
        $html .= '</span>'; // switcher

        return $html;
    }
}
