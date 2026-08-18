<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Frontend;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

/**
 * Every currency a visitor may select, resolved once and shipped whole.
 *
 * A storefront page cannot know which currency this visitor wants, because a
 * shared cache will hand the same bytes to everyone. So it ships all of them and
 * lets the browser choose. The table depends only on store settings and rates,
 * never on the request, which is what makes the document cacheable.
 *
 * Currencies without a usable rate are omitted rather than quietly resolving to
 * base: a switcher that offers EUR and then shows dollars is worse than one that
 * does not offer EUR.
 */
final class CurrencyTablePayload
{
    /**
     * What a single resolved context carries that a table of every context must not.
     *
     * Four are simply readable from what remains: the key, the store's base, and
     * whether either matches. `resolverSource` is the one that matters — it records
     * how one request resolved, so every entry would claim "cookie" in a document
     * served to visitors who sent none.
     *
     * `disclosureText` is absent from this list on purpose: its template accepts
     * `{display_currency}` and `{rate}`, so it genuinely differs per currency.
     */
    private const PER_REQUEST_OR_DERIVABLE = [
        'displayCurrency',
        'baseCurrency',
        'isBaseDisplay',
        'resolverSource',
        'disclosureEnabled',
    ];

    /**
     * @return array<string, array<string, mixed>> Keyed by uppercase currency code.
     */
    public static function build(OptionStore $optionStore): array
    {
        $drop = array_flip(self::PER_REQUEST_OR_DERIVABLE);
        $table = [];

        foreach (SelectableCurrencyCodes::fromSettings($optionStore->all())->all() as $code) {
            $context = ContextModule::resolveSelectablePreference($optionStore, $code);
            if ($context === null) {
                continue;
            }

            $table[$code] = array_diff_key(
                CurrencyContextPayload::build($context, $optionStore),
                $drop,
            );
        }

        return $table;
    }
}
