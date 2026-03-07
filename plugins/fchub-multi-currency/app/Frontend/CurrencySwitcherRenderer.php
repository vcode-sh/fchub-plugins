<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Hooks;
use FluentCart\App\Helpers\CurrenciesHelper;

defined('ABSPATH') || exit;

final class CurrencySwitcherRenderer
{
    public const NOSCRIPT_FIELD = 'fchub_mc_currency';

    public const NOSCRIPT_NONCE = '_fchub_mc_switcher_nonce';

    public const NOSCRIPT_ACTION = 'fchub_mc_switcher_noscript';

    /**
     * @return array<string, string|bool|array<int, string>>
     */
    public static function defaults(): array
    {
        return [
            'useGlobalDefaults'    => true,
            'preset'               => 'default',
            'label'               => '',
            'align'               => 'left',
            'labelPosition'       => 'before',
            'showFlag'            => true,
            'showCode'            => true,
            'showSymbol'          => false,
            'showName'            => false,
            'showRateBadge'       => true,
            'showOptionFlags'     => true,
            'showOptionCodes'     => true,
            'showOptionSymbols'   => false,
            'showOptionNames'     => true,
            'showActiveIndicator' => true,
            'showContextNote'     => false,
            'showRateValue'       => false,
            'searchMode'          => 'off',
            'favoriteCurrencies'  => [],
            'showFavoritesFirst'  => true,
            'size'                => 'md',
            'widthMode'           => 'auto',
            'dropdownPosition'    => 'auto',
            'dropdownDirection'   => 'auto',
        ];
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function normalizeShortcodeAttributes(array $atts): array
    {
        return [
            'useGlobalDefaults'    => true,
            'preset'               => $atts['preset'] ?? null,
            'label'               => (string) ($atts['label'] ?? ''),
            'align'               => (string) ($atts['align'] ?? 'left'),
            'labelPosition'       => $atts['label_position'] ?? $atts['labelPosition'] ?? null,
            'showFlag'            => self::normalizeNullableBool($atts['show_flag'] ?? $atts['showFlag'] ?? null),
            'showCode'            => self::normalizeNullableBool($atts['show_code'] ?? $atts['showCode'] ?? null),
            'showSymbol'          => self::normalizeNullableBool($atts['show_symbol'] ?? $atts['showSymbol'] ?? null),
            'showName'            => self::normalizeNullableBool($atts['show_name'] ?? $atts['showName'] ?? null),
            'showRateBadge'       => self::normalizeNullableBool($atts['show_rate_badge'] ?? $atts['showRateBadge'] ?? null),
            'showOptionFlags'     => self::normalizeNullableBool($atts['show_option_flags'] ?? $atts['showOptionFlags'] ?? null),
            'showOptionCodes'     => self::normalizeNullableBool($atts['show_option_codes'] ?? $atts['showOptionCodes'] ?? null),
            'showOptionSymbols'   => self::normalizeNullableBool($atts['show_option_symbols'] ?? $atts['showOptionSymbols'] ?? null),
            'showOptionNames'     => self::normalizeNullableBool($atts['show_option_names'] ?? $atts['showOptionNames'] ?? null),
            'showActiveIndicator' => self::normalizeNullableBool($atts['show_active_indicator'] ?? $atts['showActiveIndicator'] ?? null),
            'showContextNote'     => self::normalizeNullableBool($atts['show_context_note'] ?? $atts['showContextNote'] ?? null),
            'showRateValue'       => self::normalizeNullableBool($atts['show_rate_value'] ?? $atts['showRateValue'] ?? null),
            'searchMode'          => $atts['search_mode'] ?? $atts['searchMode'] ?? null,
            'favoriteCurrencies'  => self::normalizeNullableCurrencyCodeList($atts['favorite_currencies'] ?? $atts['favoriteCurrencies'] ?? null),
            'showFavoritesFirst'  => self::normalizeNullableBool($atts['show_favorites_first'] ?? $atts['showFavoritesFirst'] ?? null),
            'size'                => $atts['size'] ?? null,
            'widthMode'           => $atts['width_mode'] ?? $atts['widthMode'] ?? null,
            'dropdownPosition'    => $atts['dropdown_position'] ?? $atts['dropdownPosition'] ?? null,
            'dropdownDirection'   => $atts['dropdown_direction'] ?? $atts['dropdownDirection'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function normalizeBlockAttributes(array $atts): array
    {
        return [
            'useGlobalDefaults'    => self::toBool($atts['useGlobalDefaults'] ?? true),
            'preset'               => (string) ($atts['preset'] ?? 'default'),
            'label'               => (string) ($atts['label'] ?? ''),
            'align'               => (string) ($atts['align'] ?? 'left'),
            'labelPosition'       => (string) ($atts['labelPosition'] ?? 'before'),
            'showFlag'            => self::toBool($atts['showFlag'] ?? true),
            'showCode'            => self::toBool($atts['showCode'] ?? true),
            'showSymbol'          => self::toBool($atts['showSymbol'] ?? false),
            'showName'            => self::toBool($atts['showName'] ?? false),
            'showRateBadge'       => self::toBool($atts['showRateBadge'] ?? true),
            'showOptionFlags'     => self::toBool($atts['showOptionFlags'] ?? true),
            'showOptionCodes'     => self::toBool($atts['showOptionCodes'] ?? true),
            'showOptionSymbols'   => self::toBool($atts['showOptionSymbols'] ?? false),
            'showOptionNames'     => self::toBool($atts['showOptionNames'] ?? true),
            'showActiveIndicator' => self::toBool($atts['showActiveIndicator'] ?? true),
            'showContextNote'     => self::toBool($atts['showContextNote'] ?? false),
            'showRateValue'       => self::toBool($atts['showRateValue'] ?? false),
            'searchMode'          => (string) ($atts['searchMode'] ?? 'off'),
            'favoriteCurrencies'  => self::normalizeCurrencyCodeList($atts['favoriteCurrencies'] ?? []),
            'showFavoritesFirst'  => self::toBool($atts['showFavoritesFirst'] ?? true),
            'size'                => (string) ($atts['size'] ?? 'md'),
            'widthMode'           => (string) ($atts['widthMode'] ?? 'auto'),
            'dropdownPosition'    => (string) ($atts['dropdownPosition'] ?? 'auto'),
            'dropdownDirection'   => (string) ($atts['dropdownDirection'] ?? 'auto'),
        ];
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function buildStageClassName(array $atts): string
    {
        $defaults = self::defaults();
        $atts = array_merge($defaults, $atts);

        $classes = ['fchub-mc-switcher-stage'];

        $classes[] = match ($atts['align']) {
            'right'  => 'fchub-mc-switcher-stage--right',
            'center' => 'fchub-mc-switcher-stage--center',
            default  => 'fchub-mc-switcher-stage--left',
        };

        $classes[] = 'fchub-mc-switcher-stage--label-' . self::normalizeLabelPosition($atts['labelPosition']);

        return implode(' ', $classes);
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function buildWidgetClassName(array $atts): string
    {
        $defaults = self::defaults();
        $atts = array_merge($defaults, $atts);

        $classes = ['fchub-mc-switcher'];

        $classes[] = 'fchub-mc-switcher--preset-' . self::normalizePreset($atts['preset']);

        $classes[] = match ($atts['align']) {
            'right'  => 'fchub-mc-switcher--right',
            'center' => 'fchub-mc-switcher--center',
            default  => 'fchub-mc-switcher--left',
        };

        $size = in_array($atts['size'], ['sm', 'md', 'lg'], true) ? $atts['size'] : 'md';
        $classes[] = 'fchub-mc-switcher--size-' . $size;

        $widthMode = in_array($atts['widthMode'], ['auto', 'full'], true) ? $atts['widthMode'] : 'auto';
        $classes[] = 'fchub-mc-switcher--width-' . $widthMode;

        $dropdownPosition = in_array($atts['dropdownPosition'], ['auto', 'start', 'end'], true) ? $atts['dropdownPosition'] : 'auto';
        $classes[] = 'fchub-mc-switcher--dropdown-' . $dropdownPosition;

        $dropdownDirection = in_array($atts['dropdownDirection'], ['auto', 'up', 'down'], true) ? $atts['dropdownDirection'] : 'auto';
        $classes[] = 'fchub-mc-switcher--direction-' . $dropdownDirection;

        if ($atts['showName']) {
            $classes[] = 'fchub-mc-switcher--show-name';
        }

        if ($atts['showSymbol']) {
            $classes[] = 'fchub-mc-switcher--show-symbol';
        }

        if (!$atts['showFlag']) {
            $classes[] = 'fchub-mc-switcher--hide-flag';
        }

        return implode(' ', $classes);
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function renderShortcode(array $atts): string
    {
        return self::render(self::normalizeShortcodeAttributes($atts), 'shortcode');
    }

    /**
     * @param array<string, mixed> $atts
     */
    public static function renderBlock(array $atts, string $wrapperAttributes): string
    {
        return self::render(self::normalizeBlockAttributes($atts), 'block', $wrapperAttributes);
    }

    /**
     * @param array<string, mixed> $atts
     */
    private static function render(array $atts, string $context, string $wrapperAttributes = ''): string
    {
        if (!Hooks::isEnabled()) {
            return '';
        }

        $optionStore = new OptionStore();
        $atts = self::resolveEffectiveAttributes($atts, $context, $optionStore);

        $atts['align'] = in_array($atts['align'], ['left', 'center', 'right'], true) ? $atts['align'] : 'left';
        $atts['preset'] = self::normalizePreset($atts['preset']);
        $atts['size'] = in_array($atts['size'], ['sm', 'md', 'lg'], true) ? $atts['size'] : 'md';
        $atts['widthMode'] = in_array($atts['widthMode'], ['auto', 'full'], true) ? $atts['widthMode'] : 'auto';
        $atts['dropdownPosition'] = in_array($atts['dropdownPosition'], ['auto', 'start', 'end'], true)
            ? $atts['dropdownPosition']
            : 'auto';
        $atts['dropdownDirection'] = in_array($atts['dropdownDirection'], ['auto', 'up', 'down'], true)
            ? $atts['dropdownDirection']
            : 'auto';
        $atts['labelPosition'] = self::normalizeLabelPosition($atts['labelPosition']);
        $atts['searchMode'] = in_array($atts['searchMode'], ['off', 'inline'], true) ? $atts['searchMode'] : 'off';

        if (!$atts['showCode'] && !$atts['showName'] && !$atts['showSymbol']) {
            $atts['showCode'] = true;
        }

        if (!$atts['showOptionCodes'] && !$atts['showOptionNames'] && !$atts['showOptionSymbols']) {
            $atts['showOptionCodes'] = true;
        }

        $currencies = self::resolveCurrencies($optionStore);

        if ($currencies === []) {
            return '';
        }

        $currencies = self::prioritizeCurrencies(
            $currencies,
            $atts['favoriteCurrencies'],
            (bool) $atts['showFavoritesFirst'],
        );

        $contextService = new CurrencyContextService(ContextModule::buildResolverChain($optionStore), $optionStore);
        $contextState = $contextService->resolve();
        $currentCode = $contextState->displayCurrency->code;
        $currentName = self::findCurrencyName($currencies, $currentCode);
        $currentSymbol = self::findCurrencySymbol($currencies, $currentCode);

        FrontendModule::ensureSwitcherAssetsEnqueued();

        $stageClassName = self::buildStageClassName($atts);
        $widgetClassName = self::buildWidgetClassName($atts);

        if ($context === 'block') {
            $html = '<div ' . trim($wrapperAttributes) . '>';
        } else {
            $html = '<span class="' . esc_attr($stageClassName) . '">';
        }

        if ($context === 'block') {
            $html .= '<div class="' . esc_attr($stageClassName) . '">';
        }

        $label = sanitize_text_field((string) $atts['label']);
        $renderLabelFirst = in_array($atts['labelPosition'], ['before', 'above'], true);

        if ($label !== '' && $renderLabelFirst) {
            $html .= '<span class="fchub-mc-switcher__label">' . esc_html($label) . '</span>';
        }

        $html .= '<span class="' . esc_attr($widgetClassName) . '" data-fchub-mc-switcher>';
        $html .= '<button type="button" class="fchub-mc-switcher__trigger" data-fchub-mc-trigger>';

        if ($atts['showFlag']) {
            $html .= '<span class="fchub-mc-switcher__flag">'
                . CurrencyCatalogueController::codeToFlagImg($currentCode)
                . '</span>';
        }

        if ($atts['showCode']) {
            $html .= '<span class="fchub-mc-switcher__code">' . esc_html($currentCode) . '</span>';
        }

        if ($atts['showSymbol']) {
            $html .= '<span class="fchub-mc-switcher__symbol">' . esc_html($currentSymbol) . '</span>';
        }

        if ($atts['showName']) {
            $html .= '<span class="fchub-mc-switcher__name">' . esc_html($currentName) . '</span>';
        }

        $html .= '<span class="fchub-mc-switcher__caret" aria-hidden="true">&#9662;</span>';
        $html .= '</button>';

        $html .= '<span class="fchub-mc-switcher__dropdown" data-fchub-mc-dropdown hidden>';
        if ($atts['searchMode'] === 'inline') {
            $html .= '<span class="fchub-mc-switcher__search-wrap">';
            $html .= '<input type="search" class="fchub-mc-switcher__search" data-fchub-mc-search placeholder="'
                . esc_attr__('Search currency', 'fchub-multi-currency')
                . '" autocomplete="off" />';
            $html .= '</span>';
        }
        $html .= '<span class="fchub-mc-switcher__list" role="listbox" aria-label="'
            . esc_attr__('Select currency', 'fchub-multi-currency') . '">';

        foreach ($currencies as $currency) {
            if (!is_array($currency) || empty($currency['code'])) {
                continue;
            }

            $code = esc_attr((string) $currency['code']);
            $name = esc_html((string) ($currency['name'] ?? $code));
            $flag = CurrencyCatalogueController::codeToFlagImg((string) $currency['code']);
            $isActive = $code === $currentCode;
            $activeClass = $isActive ? ' fchub-mc-switcher__option--active' : '';
            $ariaSelected = $isActive ? 'true' : 'false';

            $html .= "<span class=\"fchub-mc-switcher__option{$activeClass}\""
                . " role=\"option\" data-value=\"{$code}\""
                . " aria-selected=\"{$ariaSelected}\" tabindex=\"-1\">";

            if ($atts['showOptionFlags']) {
                $html .= "<span class=\"fchub-mc-switcher__flag\">{$flag}</span>";
            }

            if ($atts['showOptionCodes']) {
                $html .= "<span class=\"fchub-mc-switcher__option-code\">{$code}</span>";
            }

            if ($atts['showOptionSymbols']) {
                $html .= '<span class="fchub-mc-switcher__option-symbol">'
                    . esc_html((string) ($currency['symbol'] ?? ''))
                    . '</span>';
            }

            if (($atts['showOptionCodes'] || $atts['showOptionSymbols']) && $atts['showOptionNames']) {
                $html .= '<span class="fchub-mc-switcher__option-sep" aria-hidden="true">&mdash;</span>';
            }

            if ($atts['showOptionNames']) {
                $html .= "<span class=\"fchub-mc-switcher__option-name\">{$name}</span>";
            }

            if ($atts['showActiveIndicator']) {
                $html .= '<span class="fchub-mc-switcher__option-check" aria-hidden="true">'
                    . ($isActive ? '&#10003;' : '')
                    . '</span>';
            }

            $html .= '</span>';
        }

        $html .= '</span>';
        $html .= self::renderFooter($optionStore, $contextState, $atts);
        $html .= '</span>';
        $html .= self::renderNoJsFallback($currencies, $currentCode);
        $html .= '</span>';

        if ($label !== '' && !$renderLabelFirst) {
            $html .= '<span class="fchub-mc-switcher__label">' . esc_html($label) . '</span>';
        }

        if ($context === 'block') {
            $html .= '</div></div>';
        } else {
            $html .= '</span>';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $atts
     * @return array<string, mixed>
     */
    private static function resolveEffectiveAttributes(array $atts, string $context, OptionStore $optionStore): array
    {
        $defaults = self::defaults();
        $globalDefaults = self::normalizeGlobalDefaults($optionStore->get('switcher_defaults', []));

        if ($context === 'shortcode') {
            return array_merge(
                $defaults,
                $globalDefaults,
                array_filter($atts, static fn (mixed $value): bool => $value !== null),
            );
        }

        if (($atts['useGlobalDefaults'] ?? false) === true) {
            return array_merge($defaults, $globalDefaults, [
                'useGlobalDefaults' => true,
                'label' => (string) ($atts['label'] ?? ''),
            ]);
        }

        return array_merge($defaults, $atts);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function resolveCurrencies(OptionStore $optionStore): array
    {
        $currencies = $optionStore->get('display_currencies', []);
        if (!is_array($currencies) || $currencies === []) {
            return [];
        }

        $contextService = new CurrencyContextService(ContextModule::buildResolverChain($optionStore), $optionStore);
        $contextState = $contextService->resolve();
        $baseCode = $contextState->baseCurrency->code;

        $basePresent = false;
        foreach ($currencies as $currency) {
            if (is_array($currency) && (($currency['code'] ?? '') === $baseCode)) {
                $basePresent = true;
                break;
            }
        }

        if (!$basePresent) {
            $allCurrencies = CurrenciesHelper::getCurrencies();
            $allSigns = CurrenciesHelper::getCurrencySigns();
            array_unshift($currencies, [
                'code'   => $baseCode,
                'name'   => $allCurrencies[$baseCode] ?? $baseCode,
                'symbol' => $allSigns[$baseCode] ?? $baseCode,
            ]);
        }

        return $currencies;
    }

    /**
     * @param string[] $favoriteCodes
     * @return array<int, array<string, mixed>>
     */
    public static function publicCurrencies(OptionStore $optionStore, array $favoriteCodes = [], bool $showFavoritesFirst = true): array
    {
        return self::prioritizeCurrencies(
            self::resolveCurrencies($optionStore),
            $favoriteCodes,
            $showFavoritesFirst,
        );
    }

    public static function currentSelection(OptionStore $optionStore): string
    {
        $contextService = new CurrencyContextService(ContextModule::buildResolverChain($optionStore), $optionStore);
        return $contextService->resolve()->displayCurrency->code;
    }

    /**
     * @param array<int, array<string, mixed>> $currencies
     */
    private static function findCurrencyName(array $currencies, string $currencyCode): string
    {
        foreach ($currencies as $currency) {
            if (!is_array($currency)) {
                continue;
            }

            if (strtoupper((string) ($currency['code'] ?? '')) === strtoupper($currencyCode)) {
                return (string) ($currency['name'] ?? $currencyCode);
            }
        }

        return $currencyCode;
    }

    /**
     * @param array<int, array<string, mixed>> $currencies
     */
    private static function findCurrencySymbol(array $currencies, string $currencyCode): string
    {
        foreach ($currencies as $currency) {
            if (!is_array($currency)) {
                continue;
            }

            if (strtoupper((string) ($currency['code'] ?? '')) === strtoupper($currencyCode)) {
                return (string) ($currency['symbol'] ?? $currencyCode);
            }
        }

        return $currencyCode;
    }

    /**
     * @param mixed $defaults
     * @return array<string, mixed>
     */
    /**
     * @param mixed $defaults
     * @return array<string, mixed>
     */
    public static function normalizeGlobalDefaults(mixed $defaults): array
    {
        if (!is_array($defaults)) {
            $defaults = [];
        }

        return [
            'useGlobalDefaults'    => true,
            'preset'               => self::normalizePreset((string) ($defaults['preset'] ?? 'default')),
            'labelPosition'        => self::normalizeLabelPosition($defaults['label_position'] ?? 'before'),
            'showFlag'             => self::toBool($defaults['show_flag'] ?? true),
            'showCode'             => self::toBool($defaults['show_code'] ?? true),
            'showSymbol'           => self::toBool($defaults['show_symbol'] ?? false),
            'showName'             => self::toBool($defaults['show_name'] ?? false),
            'showOptionFlags'      => self::toBool($defaults['show_option_flags'] ?? true),
            'showOptionCodes'      => self::toBool($defaults['show_option_codes'] ?? true),
            'showOptionSymbols'    => self::toBool($defaults['show_option_symbols'] ?? false),
            'showOptionNames'      => self::toBool($defaults['show_option_names'] ?? true),
            'showActiveIndicator'  => self::toBool($defaults['show_active_indicator'] ?? true),
            'showRateBadge'        => self::toBool($defaults['show_rate_badge'] ?? true),
            'showRateValue'        => self::toBool($defaults['show_rate_value'] ?? false),
            'showContextNote'      => self::toBool($defaults['show_context_note'] ?? false),
            'searchMode'           => in_array(($defaults['search_mode'] ?? 'off'), ['off', 'inline'], true) ? $defaults['search_mode'] : 'off',
            'favoriteCurrencies'   => self::normalizeCurrencyCodeList($defaults['favorite_currencies'] ?? []),
            'showFavoritesFirst'   => self::toBool($defaults['show_favorites_first'] ?? true),
            'size'                 => in_array(($defaults['size'] ?? 'md'), ['sm', 'md', 'lg'], true) ? $defaults['size'] : 'md',
            'widthMode'            => in_array(($defaults['width_mode'] ?? 'auto'), ['auto', 'full'], true) ? $defaults['width_mode'] : 'auto',
            'dropdownPosition'     => in_array(
                ($defaults['dropdown_position'] ?? 'auto'),
                ['auto', 'start', 'end'],
                true,
            ) ? $defaults['dropdown_position'] : 'auto',
            'dropdownDirection'    => in_array(
                ($defaults['dropdown_direction'] ?? 'auto'),
                ['auto', 'up', 'down'],
                true,
            ) ? $defaults['dropdown_direction'] : 'auto',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $currencies
     * @param string[] $favoriteCodes
     * @return array<int, array<string, mixed>>
     */
    private static function prioritizeCurrencies(array $currencies, array $favoriteCodes, bool $showFavoritesFirst): array
    {
        if (!$showFavoritesFirst || $favoriteCodes === []) {
            return $currencies;
        }

        $favoriteMap = array_flip(array_map('strtoupper', $favoriteCodes));

        usort($currencies, static function (array $left, array $right) use ($favoriteMap): int {
            $leftFavorite = isset($favoriteMap[strtoupper((string) ($left['code'] ?? ''))]);
            $rightFavorite = isset($favoriteMap[strtoupper((string) ($right['code'] ?? ''))]);

            if ($leftFavorite === $rightFavorite) {
                return 0;
            }

            return $leftFavorite ? -1 : 1;
        });

        return $currencies;
    }

    /**
     * @param array<string, mixed> $atts
     */
    private static function renderFooter(
        OptionStore $optionStore,
        \FChubMultiCurrency\Domain\ValueObjects\CurrencyContext $contextState,
        array $atts,
    ): string {
        $parts = [];

        if ((bool) $atts['showRateBadge'] && !$contextState->isBaseDisplay) {
            $parts[] = self::renderRateBadge($optionStore, $contextState->rate);
        }

        if ((bool) $atts['showRateValue']) {
            $parts[] = self::renderRateValue($contextState);
        }

        if ((bool) $atts['showContextNote']) {
            $parts[] = self::renderContextNote($contextState);
        }

        $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return '';
        }

        return '<span class="fchub-mc-switcher__footer">' . implode('', $parts) . '</span>';
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
            sprintf(
                /* translators: %s: human-readable time difference, e.g. "2 hours" */
                __('Rates updated %s ago', 'fchub-multi-currency'),
                $ago,
            ),
        );

        return "<span class=\"{$class}\">"
            . '<span class="fchub-mc-rate-badge__dot" aria-hidden="true"></span>'
            . $text
            . '</span>';
    }

    private static function renderRateValue(
        \FChubMultiCurrency\Domain\ValueObjects\CurrencyContext $contextState,
    ): string {
        if ($contextState->isBaseDisplay) {
            return '<span class="fchub-mc-rate-context">'
                . esc_html__('Base currency currently in use.', 'fchub-multi-currency')
                . '</span>';
        }

        $text = sprintf(
            /* translators: 1: base currency code, 2: exchange rate, 3: display currency code */
            __('1 %1$s = %2$s %3$s', 'fchub-multi-currency'),
            $contextState->baseCurrency->code,
            $contextState->rate->rate,
            $contextState->displayCurrency->code,
        );

        return '<span class="fchub-mc-rate-context">' . esc_html($text) . '</span>';
    }

    private static function renderContextNote(
        \FChubMultiCurrency\Domain\ValueObjects\CurrencyContext $contextState,
    ): string {
        if ($contextState->isBaseDisplay) {
            return '<span class="fchub-mc-rate-context">'
                . esc_html__('You are viewing the store base currency.', 'fchub-multi-currency')
                . '</span>';
        }

        $text = sprintf(
            /* translators: %s: base currency code */
            __('Display prices only. Checkout is charged in %s.', 'fchub-multi-currency'),
            $contextState->baseCurrency->code,
        );

        return '<span class="fchub-mc-rate-context">' . esc_html($text) . '</span>';
    }

    /**
     * @param array<int, array<string, mixed>> $currencies
     */
    private static function renderNoJsFallback(array $currencies, string $currentCode): string
    {
        $html = '<noscript>';
        $html .= '<style>.fchub-mc-switcher{display:none !important;}</style>';
        $html .= '<form method="post" class="fchub-mc-switcher-fallback-form">';
        $html .= '<input type="hidden" name="' . esc_attr(self::NOSCRIPT_NONCE) . '" value="'
            . esc_attr(wp_create_nonce(self::NOSCRIPT_ACTION)) . '" />';
        $html .= '<label class="fchub-mc-switcher-fallback-label" for="fchub-mc-switcher-fallback-select">'
            . esc_html__('Select currency', 'fchub-multi-currency')
            . '</label>';
        $html .= '<select id="fchub-mc-switcher-fallback-select" class="fchub-mc-switcher-fallback" name="'
            . esc_attr(self::NOSCRIPT_FIELD) . '">';

        foreach ($currencies as $currency) {
            if (!is_array($currency) || empty($currency['code'])) {
                continue;
            }

            $code = strtoupper((string) $currency['code']);
            $name = (string) ($currency['name'] ?? $code);
            $selected = $code === strtoupper($currentCode) ? ' selected' : '';
            $html .= '<option value="' . esc_attr($code) . '"' . $selected . '>'
                . esc_html($code . ' - ' . $name)
                . '</option>';
        }

        $html .= '</select>';
        $html .= '<button type="submit" class="fchub-mc-switcher-fallback-submit">'
            . esc_html__('Apply', 'fchub-multi-currency')
            . '</button>';
        $html .= '</form>';
        $html .= '</noscript>';

        return $html;
    }

    /**
     * @return string[]
     */
    public static function allowedCurrencyCodes(OptionStore $optionStore): array
    {
        $settings = $optionStore->all();
        $baseCode = strtoupper((string) ($settings['base_currency'] ?? 'USD'));
        $codes = [$baseCode];

        foreach (($settings['display_currencies'] ?? []) as $currency) {
            if (!is_array($currency) || empty($currency['code'])) {
                continue;
            }

            $codes[] = strtoupper((string) $currency['code']);
        }

        return array_values(array_unique($codes));
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['no', 'false', '0', 'off'], true)) {
                return false;
            }

            if (in_array($normalized, ['yes', 'true', '1', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }

    private static function normalizeLabelPosition(mixed $value): string
    {
        if (!is_string($value)) {
            return 'before';
        }

        return match ($value) {
            'inline' => 'before',
            'stacked' => 'above',
            'before', 'after', 'above', 'below' => $value,
            default => 'before',
        };
    }

    private static function normalizePreset(string $value): string
    {
        return in_array($value, ['default', 'pill', 'minimal', 'subtle', 'glass', 'contrast'], true)
            ? $value
            : 'default';
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    /**
     * @param mixed $value
     * @return string[]
     */
    public static function normalizeCurrencyCodeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $currencyCode) {
            if (!is_string($currencyCode)) {
                continue;
            }

            $code = strtoupper(sanitize_text_field($currencyCode));
            if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
                continue;
            }

            $codes[] = $code;
        }

        return array_values(array_unique($codes));
    }

    private static function normalizeNullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return self::toBool($value);
    }

    /**
     * @param mixed $value
     * @return string[]|null
     */
    private static function normalizeNullableCurrencyCodeList(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return self::normalizeCurrencyCodeList($value);
    }
}
