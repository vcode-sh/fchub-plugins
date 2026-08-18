<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Integration\FluentCartCurrency;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

/**
 * Maps a resolved currency context to the browser contract.
 */
final class CurrencyContextPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function build(CurrencyContext $context, OptionStore $optionStore): array
    {
        $disclosure = (new CheckoutDisclosureService($optionStore))->getDisclosure($context);

        return self::buildCore($context, $optionStore, $disclosure);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildRecovery(CurrencyContext $context, OptionStore $optionStore): array
    {
        $disclosure = (new CheckoutDisclosureService($optionStore))->getDisclosure($context);

        return array_merge(self::buildCore($context, $optionStore, $disclosure), [
            'presentation' => CurrencyContextPresentation::recoveryFragments(
                $context,
                $optionStore,
                $disclosure,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildCore(
        CurrencyContext $context,
        OptionStore $optionStore,
        ?string $disclosure,
    ): array {
        $shopSeparators = FluentCartCurrency::separators();

        return [
            'rate' => $context->rate->rateAsFloat(),
            'displayCurrency' => $context->displayCurrency->code,
            'displayCurrencyName' => $context->displayCurrency->name,
            'baseCurrency' => $context->baseCurrency->code,
            'decimals' => $context->displayCurrency->decimals,
            'symbol' => html_entity_decode($context->displayCurrency->symbol, ENT_QUOTES, 'UTF-8'),
            'position' => $context->displayCurrency->position->value,
            'isBaseDisplay' => $context->isBaseDisplay,
            'resolverSource' => $context->source->value,
            'displayDecSep' => self::resolveDisplaySeparator(
                $context,
                $optionStore,
                'decimal_separator',
                $shopSeparators['decimal'],
            ),
            'displayThousandSep' => self::resolveDisplaySeparator(
                $context,
                $optionStore,
                'thousand_separator',
                $shopSeparators['thousand'],
            ),
            'disclosureEnabled' => $disclosure !== null,
            'disclosureText' => $disclosure,
            // Epoch seconds plus the store's staleness threshold, so the browser
            // can render rate freshness at paint time. A cached document's
            // pre-rendered "2 hours ago" only gets older; these do not.
            'rateFetchedAt' => $context->isBaseDisplay
                ? null
                : (strtotime($context->rate->fetchedAt . ' UTC') ?: null),
            'rateStaleAfterSeconds' => max(1, (int) $optionStore->get('stale_threshold_hrs', 24)) * HOUR_IN_SECONDS,
        ];
    }

    private static function resolveDisplaySeparator(
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
            if (strtoupper((string) ($currency['code'] ?? '')) !== $context->displayCurrency->code) {
                continue;
            }

            $value = (string) ($currency[$field] ?? '');
            if ($value === 'none') {
                return '';
            }

            return $value !== '' ? $value : $fallback;
        }

        return $fallback;
    }
}
