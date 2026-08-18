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
     * @return array<string, string|array<int, int>|array<string, array<int, string>>>
     */
    public static function templates(): array
    {
        [$nplurals, $pluralRule] = self::pluralRule();

        return [
            // translators: %s is a human-readable time difference, e.g. "2 hours".
            'rateBadgeAgo' => __('Rates updated %s ago', 'fchub-multi-currency'),
            // Every plural form per unit, mirroring human_time_diff's units.
            // The browser renders freshness at paint time because a cached
            // document's pre-rendered age only grows.
            'timeUnits' => self::timeUnitForms($nplurals, $pluralRule),
            // Which form a count selects, precomputed for n = 0..200; counts
            // past 200 repeat the 101..200 block. The site locale is a store
            // fact, so the rule is safe in cached HTML.
            'timePluralRule' => $pluralRule,
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

    /**
     * The site locale's plural-form selector, precomputed as a lookup table
     * because the browser must pick a form for a live count without
     * evaluating gettext expressions. Rules depend only on n, n%10 and n%100
     * with exact comparisons against small constants, so indices 0..100 plus
     * one full 101..200 period cover every count.
     *
     * @return array{0: int, 1: array<int, int>}
     */
    private static function pluralRule(): array
    {
        $translations = get_translations_for_domain('fchub-multi-currency');
        $header = is_callable([$translations, 'get_header'])
            ? $translations->get_header('Plural-Forms')
            : false;

        if (
            is_string($header)
            && class_exists(\Plural_Forms::class)
            && preg_match('/nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*(.+?);?\s*$/', $header, $match)
        ) {
            try {
                $parsed = new \Plural_Forms(rtrim($match[2], ';'));
                $nplurals = max(1, (int) $match[1]);
                $table = [];
                for ($n = 0; $n <= 200; $n++) {
                    $table[] = min(max(0, (int) $parsed->get($n)), $nplurals - 1);
                }

                return [$nplurals, $table];
            } catch (\Throwable) {
                // A malformed header falls through to the English rule.
            }
        }

        $table = [];
        for ($n = 0; $n <= 200; $n++) {
            $table[] = $n !== 1 ? 1 : 0;
        }

        return [2, $table];
    }

    /**
     * Every plural form of every time unit under the active locale, indexed
     * the way the rule table selects them.
     *
     * @param array<int, int> $pluralRule
     * @return array<string, array<int, string>>
     */
    private static function timeUnitForms(int $nplurals, array $pluralRule): array
    {
        $units = [
            // translators: %s is a number of minutes.
            'min'   => _n_noop('%s min', '%s mins', 'fchub-multi-currency'),
            // translators: %s is a number of hours.
            'hour'  => _n_noop('%s hour', '%s hours', 'fchub-multi-currency'),
            // translators: %s is a number of days.
            'day'   => _n_noop('%s day', '%s days', 'fchub-multi-currency'),
            // translators: %s is a number of weeks.
            'week'  => _n_noop('%s week', '%s weeks', 'fchub-multi-currency'),
            // translators: %s is a number of months.
            'month' => _n_noop('%s month', '%s months', 'fchub-multi-currency'),
            // translators: %s is a number of years.
            'year'  => _n_noop('%s year', '%s years', 'fchub-multi-currency'),
        ];

        // One representative count per form: translate_nooped_plural picks
        // the form itself, so asking with each representative enumerates the
        // whole set.
        $representatives = [];
        foreach ($pluralRule as $count => $form) {
            $representatives[$form] ??= $count;
        }

        $forms = [];
        foreach ($units as $key => $pair) {
            $unitForms = [];
            for ($form = 0; $form < $nplurals; $form++) {
                $unitForms[] = translate_nooped_plural(
                    $pair,
                    $representatives[$form] ?? 1,
                    'fchub-multi-currency',
                );
            }
            $forms[$key] = $unitForms;
        }

        return $forms;
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
