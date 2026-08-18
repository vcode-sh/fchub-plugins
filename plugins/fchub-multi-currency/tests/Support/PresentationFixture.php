<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Support;

use FChubMultiCurrency\Domain\Enums\CurrencyPosition;
use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Frontend\CurrencyContextPresentation;
use FChubMultiCurrency\Http\Controllers\Admin\CurrencyCatalogueController;
use FChubMultiCurrency\Storage\OptionStore;

/**
 * The one context both renderers are measured against.
 *
 * Kept in a shared class so the generator script and the PHPUnit guard cannot
 * describe subtly different worlds and then agree with each other about it.
 */
final class PresentationFixture
{
    /**
     * @return array<string, mixed>
     */
    public static function build(): array
    {
        $GLOBALS['wp_options']['fchub_mc_settings'] = [
            'base_currency' => 'USD',
            'checkout_disclosure_enabled' => 'yes',
            'checkout_disclosure_text' => 'Your payment will be processed in {base_currency}.',
        ];

        $optionStore = new OptionStore();
        $context = self::context();
        $current = [];
        foreach (['flag_code', 'code', 'symbol', 'name', 'flag_name', 'symbol_code'] as $mode) {
            $current[$mode] = CurrencyContextPresentation::renderCurrent($context, $mode);
        }

        $rate = [];
        foreach (['compact', 'sentence'] as $format) {
            for ($precision = 0; $precision <= 8; $precision++) {
                $rate[$format][$precision] = CurrencyContextPresentation::renderRate($context, $precision, $format);
            }
        }

        return [
            'displayCurrency' => 'EUR',
            'baseCurrency' => 'USD',
            'entry' => [
                'rate' => 0.92,
                'symbol' => '€',
                'displayCurrencyName' => 'Euro',
                'flag' => CurrencyCatalogueController::codeToFlagImg('EUR'),
                'disclosureText' => 'Your payment will be processed in USD.',
            ],
            'templates' => CurrencyContextPresentation::templates(),
            'current' => $current,
            'rate' => $rate,
            'notice' => [
                'compact' => CurrencyContextPresentation::renderNotice($context, $optionStore, 'compact'),
                'full' => CurrencyContextPresentation::renderNotice($context, $optionStore, 'full'),
            ],
        ];
    }

    private static function context(): CurrencyContext
    {
        return new CurrencyContext(
            displayCurrency: new Currency('EUR', 'Euro', '€', 2, CurrencyPosition::Left),
            baseCurrency: new Currency('USD', 'US Dollar', '$', 2, CurrencyPosition::Left),
            rate: new ExchangeRate('USD', 'EUR', '0.92', RateProvider::Manual, '2026-08-18 06:00:00'),
            source: ResolverSource::Cookie,
            isBaseDisplay: false,
        );
    }
}
