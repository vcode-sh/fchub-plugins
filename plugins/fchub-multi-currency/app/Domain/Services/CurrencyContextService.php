<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Logger;

defined('ABSPATH') || exit;

final class CurrencyContextService
{
    private static ?CurrencyContext $resolved = null;

    public function __construct(
        private ResolverChain $resolverChain,
        private OptionStore $optionStore,
    ) {
    }

    /** The service every production caller wants: this visitor's context via the standard resolver chain. */
    public static function forVisitor(OptionStore $optionStore): self
    {
        return new self(CurrencyResolution::chain($optionStore), $optionStore);
    }

    public function resolve(): CurrencyContext
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $settings = $this->optionStore->all();
        $baseCurrencyCode = $settings['base_currency'] ?? 'USD';
        $enabledCurrencies = $settings['display_currencies'] ?? [];
        if (!is_array($enabledCurrencies)) {
            $enabledCurrencies = [];
        }

        $resolved = $this->resolverChain->resolve($baseCurrencyCode, $enabledCurrencies);

        if ($resolved === null) {
            $baseCurrency = Currency::from([
                'code'     => $baseCurrencyCode,
                'name'     => $baseCurrencyCode,
                'symbol'   => $baseCurrencyCode,
                'decimals' => 2,
                'position' => 'left',
            ]);
            $resolved = CurrencyContext::baseOnly($baseCurrency);
        }

        self::$resolved = self::applyContextFilter($resolved);

        return self::$resolved;
    }

    public static function getResolved(): ?CurrencyContext
    {
        return self::$resolved;
    }

    /** Public extension point; a rogue filter return degrades to the unfiltered context instead of fataling the page. */
    public static function applyContextFilter(CurrencyContext $context): CurrencyContext
    {
        $filtered = apply_filters('fchub_mc/context', $context);

        if (!$filtered instanceof CurrencyContext) {
            Logger::debug('fchub_mc/context filter returned a non-context value; ignoring it', [
                'type' => get_debug_type($filtered),
            ]);

            return $context;
        }

        return $filtered;
    }

    public static function reset(): void
    {
        self::$resolved = null;
        CurrencyResolution::resetChain();
    }
}
