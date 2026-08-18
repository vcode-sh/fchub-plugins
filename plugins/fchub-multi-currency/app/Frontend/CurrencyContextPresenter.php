<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Services\CurrencyResolution;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

/**
 * Renders the first frame of a currency surface, in the store's base currency.
 *
 * Deliberately not the visitor's currency: these surfaces go into documents a
 * shared cache hands to everyone, so a resolved answer here would be whoever
 * warmed the cache. The browser repaints each surface from the currency table
 * before it can matter, and a visitor without JavaScript sees the currency they
 * are actually charged in.
 */
final class CurrencyContextPresenter
{
    public static function baseContext(): \FChubMultiCurrency\Domain\ValueObjects\CurrencyContext
    {
        $optionStore = new OptionStore();

        return CurrencyContextService::applyContextFilter(
            CurrencyResolution::explicitPreference(
                $optionStore,
                (string) ($optionStore->all()['base_currency'] ?? 'USD'),
            ),
        );
    }

    /**
     * @return array{code: string, name: string, symbol: string, flag: string, is_base_display: bool}
     */
    public static function currentCurrencyParts(): array
    {
        $context = self::baseContext();

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
        return CurrencyContextPresentation::renderCurrent(self::baseContext(), $displayMode);
    }

    public static function renderRateValue(int $precision = 4, string $format = 'compact', bool $hideWhenBase = false): string
    {
        $context = self::baseContext();

        if ($hideWhenBase && $context->isBaseDisplay) {
            return '';
        }

        return CurrencyContextPresentation::renderRate($context, $precision, $format);
    }

    public static function renderNotice(string $mode = 'compact', bool $hideWhenBase = true): string
    {
        $context = self::baseContext();
        if ($hideWhenBase && $context->isBaseDisplay) {
            return '';
        }

        return CurrencyContextPresentation::renderNotice($context, new OptionStore(), $mode);
    }
}
