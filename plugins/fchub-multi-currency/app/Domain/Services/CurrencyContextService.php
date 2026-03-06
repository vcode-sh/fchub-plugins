<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class CurrencyContextService
{
    private static ?CurrencyContext $resolved = null;

    public function __construct(
        private ResolverChain $resolverChain,
        private OptionStore $optionStore,
    ) {
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

        self::$resolved = apply_filters('fchub_mc/context', $resolved);

        return self::$resolved;
    }

    public static function getResolved(): ?CurrencyContext
    {
        return self::$resolved;
    }

    public static function reset(): void
    {
        self::$resolved = null;
    }
}
