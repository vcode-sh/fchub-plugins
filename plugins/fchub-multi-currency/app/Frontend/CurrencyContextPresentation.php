<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

/**
 * Owns the server-rendered currency fragments reused by blocks and cache recovery.
 */
final class CurrencyContextPresentation
{
    /**
     * The translated sentences the browser needs to render a currency surface.
     *
     * Placeholders stay intact; the browser fills them from the currency table.
     * These ship once per page. The pre-rendered variants below still serve the
     * REST response, where one request pays for one context — carrying them in
     * every cached document instead would cost 3113 bytes per currency to deliver
     * the single variant a block asks for.
     *
     * @return array<string, string|array<string, array<int, string>>>
     */
    public static function templates(): array
    {
        return [
            // translators: %s is a human-readable time difference, e.g. "2 hours".
            'rateBadgeAgo' => __('Rates updated %s ago', 'fchub-multi-currency'),
            // Singular/plural pairs the browser combines with a count, mirroring
            // human_time_diff's units. The browser renders freshness at paint
            // time because a cached document's pre-rendered age only grows.
            'timeUnits' => [
                // translators: %s is a number of minutes.
                'min'   => [__('%s min', 'fchub-multi-currency'), __('%s mins', 'fchub-multi-currency')],
                // translators: %s is a number of hours.
                'hour'  => [__('%s hour', 'fchub-multi-currency'), __('%s hours', 'fchub-multi-currency')],
                // translators: %s is a number of days.
                'day'   => [__('%s day', 'fchub-multi-currency'), __('%s days', 'fchub-multi-currency')],
                // translators: %s is a number of weeks.
                'week'  => [__('%s week', 'fchub-multi-currency'), __('%s weeks', 'fchub-multi-currency')],
                // translators: %s is a number of months.
                'month' => [__('%s month', 'fchub-multi-currency'), __('%s months', 'fchub-multi-currency')],
                // translators: %s is a number of years.
                'year'  => [__('%s year', 'fchub-multi-currency'), __('%s years', 'fchub-multi-currency')],
            ],
            // translators: %1$s is the base currency code, %2$s the exchange rate, %3$s the display currency code.
            'rate' => __('1 %1$s = %2$s %3$s', 'fchub-multi-currency'),
            // translators: %1$s is the base currency code, %2$s the exchange rate, %3$s the display currency code.
            'rateSentence' => __('Current rate: 1 %1$s = %2$s %3$s', 'fchub-multi-currency'),
            // translators: %1$s is the display currency code, %2$s the base currency code.
            'noticeCompact' => __('Viewing prices in %1$s. Checkout in %2$s.', 'fchub-multi-currency'),
            // translators: %1$s is the display currency code, %2$s the base currency code.
            'noticeFull' => __(
                'Prices shown in %1$s are approximate. Checkout is charged in %2$s.',
                'fchub-multi-currency',
            ),
            'switcherRateBase' => __('Base currency currently in use.', 'fchub-multi-currency'),
            // translators: %s is the base currency code.
            'switcherContext' => __('Display prices only. Checkout is charged in %s.', 'fchub-multi-currency'),
            'switcherContextBase' => __('You are viewing the store base currency.', 'fchub-multi-currency'),
            'currencyUnavailable' => __(
                'That currency is not available right now.',
                'fchub-multi-currency',
            ),
            // translators: %s is the display currency name.
            'currencySwitched' => __('Prices are now shown in %s.', 'fchub-multi-currency'),
        ];
    }

    public static function renderCurrent(CurrencyContext $context, string $displayMode = 'flag_code'): string
    {
        $code = $context->displayCurrency->code;
        $name = $context->displayCurrency->name;
        $symbol = $context->displayCurrency->symbol;
        $flag = CurrencyCatalogueController::codeToFlagImg($code);

        return match ($displayMode) {
            'code' => '<span class="fchub-mc-inline-current">' . esc_html($code) . '</span>',
            'symbol' => '<span class="fchub-mc-inline-current">' . esc_html($symbol) . '</span>',
            'name' => '<span class="fchub-mc-inline-current">' . esc_html($name) . '</span>',
            'flag_name' => '<span class="fchub-mc-inline-current">'
                . $flag
                . '<span class="fchub-mc-inline-current__text">' . esc_html($name) . '</span></span>',
            'symbol_code' => '<span class="fchub-mc-inline-current">'
                . '<span class="fchub-mc-inline-current__text">' . esc_html($symbol) . '</span>'
                . '<span class="fchub-mc-inline-current__text">' . esc_html($code) . '</span></span>',
            default => '<span class="fchub-mc-inline-current">'
                . $flag
                . '<span class="fchub-mc-inline-current__text">' . esc_html($code) . '</span></span>',
        };
    }

    public static function renderRate(
        CurrencyContext $context,
        int $precision = 4,
        string $format = 'compact',
    ): string {
        $precision = max(0, min(8, $precision));
        $rate = number_format((float) $context->rate->rate, $precision, '.', '');

        $text = match ($format) {
            'sentence' => sprintf(
                /* translators: 1: base currency code, 2: rate, 3: display currency code */
                __('Current rate: 1 %1$s = %2$s %3$s', 'fchub-multi-currency'),
                $context->baseCurrency->code,
                $rate,
                $context->displayCurrency->code,
            ),
            default => sprintf(
                /* translators: 1: base currency code, 2: rate, 3: display currency code */
                __('1 %1$s = %2$s %3$s', 'fchub-multi-currency'),
                $context->baseCurrency->code,
                $rate,
                $context->displayCurrency->code,
            ),
        };

        return '<span class="fchub-mc-inline-rate">' . esc_html($text) . '</span>';
    }

    public static function renderNotice(
        CurrencyContext $context,
        OptionStore $optionStore,
        string $mode = 'compact',
    ): string {
        if ($mode === 'checkout') {
            $disclosure = (new CheckoutDisclosureService($optionStore))->getDisclosure($context);

            return $disclosure === null
                ? ''
                : '<span class="fchub-mc-inline-notice">' . $disclosure . '</span>';
        }

        $text = match ($mode) {
            'full' => sprintf(
                /* translators: 1: display currency code, 2: base currency code */
                __('Prices shown in %1$s are approximate. Checkout is charged in %2$s.', 'fchub-multi-currency'),
                $context->displayCurrency->code,
                $context->baseCurrency->code,
            ),
            default => sprintf(
                /* translators: 1: display currency code, 2: base currency code */
                __('Viewing prices in %1$s. Checkout in %2$s.', 'fchub-multi-currency'),
                $context->displayCurrency->code,
                $context->baseCurrency->code,
            ),
        };

        return '<span class="fchub-mc-inline-notice">' . esc_html($text) . '</span>';
    }

    /**
     * @return array{rateBadge: string, rateValue: string, contextNote: string}
     */
    public static function switcherParts(CurrencyContext $context, OptionStore $optionStore): array
    {
        return [
            'rateBadge' => self::renderRateBadge($context, $optionStore),
            'rateValue' => self::renderSwitcherRate($context),
            'contextNote' => self::renderSwitcherContext($context),
        ];
    }

    /**
     * Builds every supported block variant once for a rare cached-page recovery.
     *
     * @return array<string, mixed>
     */
    public static function recoveryFragments(
        CurrencyContext $context,
        OptionStore $optionStore,
        ?string $checkoutDisclosure,
    ): array {
        $rates = ['compact' => [], 'sentence' => []];
        foreach (array_keys($rates) as $format) {
            for ($precision = 0; $precision <= 8; $precision++) {
                $rates[$format][$precision] = self::renderRate($context, $precision, $format);
            }
        }

        $current = [];
        foreach (['flag_code', 'code', 'symbol', 'name', 'flag_name', 'symbol_code'] as $mode) {
            $current[$mode] = self::renderCurrent($context, $mode);
        }

        return [
            'flag' => CurrencyCatalogueController::codeToFlagImg($context->displayCurrency->code),
            'current' => $current,
            'rate' => $rates,
            'notice' => [
                'compact' => self::renderNotice($context, $optionStore, 'compact'),
                'full' => self::renderNotice($context, $optionStore, 'full'),
                'checkout' => self::renderCheckoutDisclosure($checkoutDisclosure),
            ],
            'switcher' => self::switcherParts($context, $optionStore),
        ];
    }

    private static function renderCheckoutDisclosure(?string $disclosure): string
    {
        return $disclosure === null
            ? ''
            : '<span class="fchub-mc-inline-notice">' . $disclosure . '</span>';
    }

    public static function renderRateBadge(CurrencyContext $context, OptionStore $optionStore): string
    {
        if ($context->isBaseDisplay || $optionStore->get('show_rate_freshness_badge', 'yes') !== 'yes') {
            return '';
        }

        $fetchedTimestamp = strtotime($context->rate->fetchedAt . ' UTC');
        if ($fetchedTimestamp === false) {
            return '';
        }

        $threshold = (int) $optionStore->get('stale_threshold_hrs', 24) * HOUR_IN_SECONDS;
        $isStale = $context->rate->isStale($threshold);
        $class = 'fchub-mc-rate-badge' . ($isStale ? ' fchub-mc-rate-badge--stale' : '');
        $text = esc_html(sprintf(
            /* translators: %s: human-readable time difference, e.g. "2 hours" */
            __('Rates updated %s ago', 'fchub-multi-currency'),
            human_time_diff($fetchedTimestamp, time()),
        ));

        return '<span class="' . $class . '">'
            . '<span class="fchub-mc-rate-badge__dot" aria-hidden="true"></span>'
            . $text
            . '</span>';
    }

    private static function renderSwitcherRate(CurrencyContext $context): string
    {
        if ($context->isBaseDisplay) {
            return '<span class="fchub-mc-rate-context">'
                . esc_html__('Base currency currently in use.', 'fchub-multi-currency')
                . '</span>';
        }

        $text = sprintf(
            /* translators: 1: base currency code, 2: exchange rate, 3: display currency code */
            __('1 %1$s = %2$s %3$s', 'fchub-multi-currency'),
            $context->baseCurrency->code,
            $context->rate->rate,
            $context->displayCurrency->code,
        );

        return '<span class="fchub-mc-rate-context">' . esc_html($text) . '</span>';
    }

    private static function renderSwitcherContext(CurrencyContext $context): string
    {
        if ($context->isBaseDisplay) {
            return '<span class="fchub-mc-rate-context">'
                . esc_html__('You are viewing the store base currency.', 'fchub-multi-currency')
                . '</span>';
        }

        $text = sprintf(
            /* translators: %s: base currency code */
            __('Display prices only. Checkout is charged in %s.', 'fchub-multi-currency'),
            $context->baseCurrency->code,
        );

        return '<span class="fchub-mc-rate-context">' . esc_html($text) . '</span>';
    }
}
