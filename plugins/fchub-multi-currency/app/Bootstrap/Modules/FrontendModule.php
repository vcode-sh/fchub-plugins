<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
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
        $isCommaDecimal = self::shopUsesCommaDecimal($fcSettings);

        return array_merge(self::buildPricingConfig($context, $optionStore), [
            'roundingMode'             => $optionStore->get('rounding_mode', 'half_up'),
            'restUrl'                  => rest_url(Constants::REST_NAMESPACE),
            'nonce'                    => wp_create_nonce('wp_rest'),
            'currencies'               => $optionStore->get('display_currencies', []),
            'flagBaseUrl'              => FCHUB_MC_URL . 'assets/flags/4x3/',
            'baseCurrencySign'         => html_entity_decode($fcSettings['currency_sign'] ?? '$', ENT_QUOTES, 'UTF-8'),
            'baseCurrencyPosition'     => $fcSettings['currency_position'] ?? 'before',
            'baseCurrencyCode'         => $fcSettings['currency'] ?? 'USD',
            'baseDecimalSep'           => $isCommaDecimal ? ',' : '.',
            'baseThousandSep'          => $isCommaDecimal ? '.' : ',',
            'baseDecimals'             => ($fcSettings['is_zero_decimal'] ?? false) ? 0 : 2,
            // Whether a guest's currency choice may additionally be mirrored to
            // localStorage on the client, and reconciled against it on load. Tied to
            // cookie_enabled: if the site owner disabled guest persistence outright,
            // localStorage must not become a silent back door around that choice.
            // See PublicRoutes GET /context and currency-projection.js reconcile().
            'guestLocalStorageEnabled' => $optionStore->get('cookie_enabled', 'yes') === 'yes',
            // Whether the resolver chain's top-priority UrlParamResolver is active,
            // and which query parameter it reads (see
            // ContextModule::buildResolverChain(), Priority 1). currency-switcher.js
            // uses this to append the switched-to currency to the post-switch reload
            // URL, so the page's own server-render resolves it correctly in that
            // same request — no second client-side reconciliation fetch needed, and
            // no dependency on the guest cookie reaching the server on the reload
            // (issue #72). Mirrors url_param_enabled/url_param_key exactly; if the
            // site owner has turned URL-param resolution off, this must be false so
            // the switcher doesn't rely on a resolver that isn't in the chain.
            'urlParamEnabled'          => $optionStore->get('url_param_enabled', 'yes') === 'yes',
            'urlParamKey'              => $optionStore->get('url_param_key', 'currency'),
        ]);
    }

    /**
     * The subset of the frontend config that depends on which CurrencyContext was
     * resolved (as opposed to site-wide settings like restUrl or the base currency's
     * parsing config). Shared with ContextController::get() so a client-side
     * reconciliation fetch of GET /context receives the same shape of data it would
     * have gotten from the localized page config, just for a different currency.
     *
     * @return array<string, mixed>
     */
    public static function buildPricingConfig(CurrencyContext $context, OptionStore $optionStore): array
    {
        $disclosureService = new CheckoutDisclosureService($optionStore);
        $disclosure = $disclosureService->getDisclosure($context);

        return [
            'rate'                => $context->rate->rateAsFloat(),
            'displayCurrency'     => $context->displayCurrency->code,
            'displayCurrencyName' => $context->displayCurrency->name,
            'baseCurrency'        => $context->baseCurrency->code,
            'decimals'            => $context->displayCurrency->decimals,
            'symbol'              => html_entity_decode($context->displayCurrency->symbol, ENT_QUOTES, 'UTF-8'),
            'position'            => $context->displayCurrency->position->value,
            'isBaseDisplay'       => $context->isBaseDisplay,
            'source'              => $context->source->value,
            'displayDecSep'       => self::resolveDisplaySep($context, $optionStore, 'decimal_separator', match ($context->displayCurrency->position->value) {
                'right', 'right_space' => ',',
                default                => '.',
            }),
            'displayThousandSep'  => self::resolveDisplaySep(
                $context,
                $optionStore,
                'thousand_separator',
                match ($context->displayCurrency->position->value) {
                    'right', 'right_space' => '.',
                    default                => ',',
                },
            ),
            'disclosureEnabled'   => $disclosure !== null,
            'disclosureText'      => $disclosure,
        ];
    }

    /**
     * Whether FluentCart prints store prices with a comma decimal separator.
     *
     * Number Format is one radio (`decimal_separator`), and the thousand
     * separator is always its opposite — see FluentCart's own `Helper::toDecimal`.
     * Anything other than `comma` means the dot pairing, including the character
     * `.` that `CurrencySettings::get()` returns for an untouched store.
     * `currency_separator` is leftover in that array and FluentCart no longer
     * reads it.
     *
     * @param array<string, mixed> $fcSettings
     */
    private static function shopUsesCommaDecimal(array $fcSettings): bool
    {
        return ($fcSettings['decimal_separator'] ?? '') === 'comma';
    }

    private static function resolveDisplaySep(
        CurrencyContext $context,
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
