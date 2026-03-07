<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class CurrencyContextPresenter
{
    public static function resolveContext(): \FChubMultiCurrency\Domain\ValueObjects\CurrencyContext
    {
        $optionStore = new OptionStore();
        $service = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );

        return $service->resolve();
    }

    /**
     * @return array{code: string, name: string, symbol: string, flag: string, is_base_display: bool}
     */
    public static function currentCurrencyParts(): array
    {
        $context = self::resolveContext();

        return [
            'code' => $context->displayCurrency->code,
            'name' => $context->displayCurrency->name,
            'symbol' => $context->displayCurrency->symbol,
            'flag' => CurrencyCatalogueController::codeToFlagImg($context->displayCurrency->code),
            'is_base_display' => $context->isBaseDisplay,
        ];
    }

    public static function renderCurrentCurrency(string $displayMode = 'flag_code'): string
    {
        $parts = self::currentCurrencyParts();

        return match ($displayMode) {
            'code' => '<span class="fchub-mc-inline-current">' . esc_html($parts['code']) . '</span>',
            'symbol' => '<span class="fchub-mc-inline-current">' . esc_html($parts['symbol']) . '</span>',
            'name' => '<span class="fchub-mc-inline-current">' . esc_html($parts['name']) . '</span>',
            'flag_name' => '<span class="fchub-mc-inline-current">'
                . $parts['flag']
                . '<span class="fchub-mc-inline-current__text">' . esc_html($parts['name']) . '</span></span>',
            'symbol_code' => '<span class="fchub-mc-inline-current">'
                . '<span class="fchub-mc-inline-current__text">' . esc_html($parts['symbol']) . '</span>'
                . '<span class="fchub-mc-inline-current__text">' . esc_html($parts['code']) . '</span></span>',
            default => '<span class="fchub-mc-inline-current">'
                . $parts['flag']
                . '<span class="fchub-mc-inline-current__text">' . esc_html($parts['code']) . '</span></span>',
        };
    }

    public static function renderRateValue(int $precision = 4, string $format = 'compact', bool $hideWhenBase = false): string
    {
        $context = self::resolveContext();

        if ($hideWhenBase && $context->isBaseDisplay) {
            return '';
        }

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

    public static function renderNotice(string $mode = 'compact', bool $hideWhenBase = true): string
    {
        $context = self::resolveContext();
        if ($hideWhenBase && $context->isBaseDisplay) {
            return '';
        }

        if ($mode === 'checkout') {
            $disclosure = (new CheckoutDisclosureService(new OptionStore()))->getDisclosure($context);
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
}
