<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Services;

use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\Enums\StaleRateFallback;
use FChubMultiCurrency\Domain\Resolvers\CookieResolver;
use FChubMultiCurrency\Domain\Resolvers\GeoResolver;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\Resolvers\UrlParamResolver;
use FChubMultiCurrency\Domain\Resolvers\UserMetaResolver;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Integration\FluentCartCurrency;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\FeatureFlags;

defined('ABSPATH') || exit;

/**
 * Turns a currency code into this store's CurrencyContext.
 *
 * The code arrives one of two ways: explicitly, when a caller already holds a
 * validated preference, or discovered, when the memoised resolver chain walks
 * the request's URL parameter, account meta, cookie and geolocation in
 * priority order. Either way the answer carries a usable rate or falls back to
 * base — never a currency the store cannot actually honour.
 */
final class CurrencyResolution
{
    private static ?ResolverChain $cachedChain = null;

    public static function resetChain(): void
    {
        self::$cachedChain = null;
    }

    public static function chain(OptionStore $optionStore): ResolverChain
    {
        if (self::$cachedChain !== null) {
            return self::$cachedChain;
        }

        $settings = $optionStore->all();
        $rateRepo = new ExchangeRateRepository();
        $rateService = new ExchangeRateService(
            $rateRepo,
            new RatesCacheStore(),
        );

        $staleFallback = StaleRateFallback::tryFrom((string) ($settings['stale_fallback'] ?? 'base'))
            ?? StaleRateFallback::Base;
        $maxRateAge = max(1, (int) ($settings['stale_threshold_hrs'] ?? 24)) * HOUR_IN_SECONDS;

        $chain = new ResolverChain();

        // Priority 1: URL parameter
        if (($settings['url_param_enabled'] ?? 'yes') === 'yes') {
            $paramKey = $settings['url_param_key'] ?? 'currency';
            $urlResolver = new UrlParamResolver($paramKey);
            $chain->add(ResolverSource::UrlParam, self::wrapResolver($urlResolver, $rateService, $maxRateAge, $staleFallback, ResolverSource::UrlParam));
        }

        // Priority 2: Logged-in user meta
        if (($settings['account_persistence_enabled'] ?? 'yes') === 'yes') {
            $userMetaResolver = new UserMetaResolver();
            $chain->add(ResolverSource::UserMeta, self::wrapResolver($userMetaResolver, $rateService, $maxRateAge, $staleFallback, ResolverSource::UserMeta));
        }

        // Priority 3: Guest cookie
        if (($settings['cookie_enabled'] ?? 'yes') === 'yes') {
            $cookieResolver = new CookieResolver();
            $chain->add(ResolverSource::Cookie, self::wrapResolver($cookieResolver, $rateService, $maxRateAge, $staleFallback, ResolverSource::Cookie));
        }

        // Priority 4: Geolocation (feature-flagged)
        if (($settings['geo_enabled'] ?? 'no') === 'yes' && FeatureFlags::isEnabled('geo_resolver')) {
            $geoResolver = new GeoResolver();
            $chain->add(ResolverSource::Geo, self::wrapResolver($geoResolver, $rateService, $maxRateAge, $staleFallback, ResolverSource::Geo));
        }

        // Priority 5: Default (uses default_display_currency setting, falls back to base)
        $defaultCurrency = $settings['default_display_currency'] ?? '';
        $chain->add(ResolverSource::Fallback, function (
            string $baseCurrencyCode,
            array $enabledCurrencies
        ) use (
            $defaultCurrency,
            $rateService,
            $maxRateAge,
            $staleFallback
        ) {
            $baseCode = strtoupper($baseCurrencyCode);
            $defaultCode = strtoupper((string) $defaultCurrency);
            $code = ($defaultCode !== '' && $defaultCode !== $baseCode)
                ? $defaultCode
                : $baseCode;

            return self::buildContextFromCode(
                $code,
                $baseCode,
                $enabledCurrencies,
                $rateService,
                $maxRateAge,
                $staleFallback,
                ResolverSource::Fallback,
            );
        });

        self::$cachedChain = $chain;

        return $chain;
    }

    /**
     * Resolves a validated browser preference without consulting public URL settings.
     */
    public static function explicitPreference(OptionStore $optionStore, string $currencyCode): CurrencyContext
    {
        $settings = $optionStore->all();
        $baseCode = strtoupper((string) ($settings['base_currency'] ?? 'USD'));
        $currencies = is_array($settings['display_currencies'] ?? null)
            ? $settings['display_currencies']
            : [];
        $rateService = new ExchangeRateService(new ExchangeRateRepository(), new RatesCacheStore());
        $maxRateAge = max(1, (int) ($settings['stale_threshold_hrs'] ?? 24)) * HOUR_IN_SECONDS;
        $staleFallback = StaleRateFallback::tryFrom((string) ($settings['stale_fallback'] ?? 'base'))
            ?? StaleRateFallback::Base;

        return self::buildContextFromCode(
            strtoupper($currencyCode),
            $baseCode,
            $currencies,
            $rateService,
            $maxRateAge,
            $staleFallback,
            ResolverSource::Cookie,
        );
    }

    /** Resolves a preference only when the selected currency has a usable server-side context. */
    public static function selectablePreference(
        OptionStore $optionStore,
        string $currencyCode,
    ): ?CurrencyContext {
        $code = strtoupper($currencyCode);
        $context = CurrencyContextService::applyContextFilter(
            self::explicitPreference($optionStore, $code),
        );

        if ($context->displayCurrency->code !== $code) {
            return null;
        }
        if ($code !== $context->baseCurrency->code && $context->isBaseDisplay) {
            return null;
        }

        return $context;
    }

    /**
     * Wraps a resolver that returns ?string into a callable that returns ?CurrencyContext.
     *
     * @param object $resolver Any resolver with a resolve(string, array): ?string method
     */
    private static function wrapResolver(
        object $resolver,
        ExchangeRateService $rateService,
        int $maxRateAge,
        StaleRateFallback $staleFallback,
        ResolverSource $source,
    ): callable {
        return function (
            string $baseCurrencyCode,
            array $enabledCurrencies
        ) use (
            $resolver,
            $rateService,
            $maxRateAge,
            $staleFallback,
            $source
        ): ?CurrencyContext {
            $code = $resolver->resolve($baseCurrencyCode, $enabledCurrencies);

            if ($code === null) {
                return null;
            }

            return self::buildContextFromCode(
                $code,
                $baseCurrencyCode,
                $enabledCurrencies,
                $rateService,
                $maxRateAge,
                $staleFallback,
                $source,
            );
        };
    }

    /**
     * @param array<int|string, mixed> $enabledCurrencies
     */
    private static function buildContextFromCode(
        string $code,
        string $baseCurrencyCode,
        array $enabledCurrencies,
        ExchangeRateService $rateService,
        int $maxRateAge,
        StaleRateFallback $staleFallback,
        ResolverSource $source,
    ): CurrencyContext {
        $resolvedCode = strtoupper($code);
        $baseCode = strtoupper($baseCurrencyCode);
        $baseCurrency = self::findBaseCurrency($baseCode, $enabledCurrencies);

        if (!SelectableCurrencyCodes::from($baseCode, $enabledCurrencies)->contains($resolvedCode)) {
            return CurrencyContext::baseOnly($baseCurrency, $source);
        }

        if ($resolvedCode === $baseCode) {
            return CurrencyContext::baseOnly($baseCurrency, $source);
        }

        $displayCurrency = self::findConfiguredCurrency($resolvedCode, $enabledCurrencies);
        if ($displayCurrency === null) {
            return CurrencyContext::baseOnly($baseCurrency, $source);
        }

        $rate = $rateService->getUsableRate($baseCode, $resolvedCode, $maxRateAge, $staleFallback);

        if ($rate === null) {
            return CurrencyContext::baseOnly($baseCurrency, $source);
        }

        return new CurrencyContext(
            displayCurrency: $displayCurrency,
            baseCurrency: $baseCurrency,
            rate: $rate,
            source: $source,
            isBaseDisplay: false,
        );
    }

    /**
     * @param array<int|string, mixed> $enabledCurrencies
     */
    private static function findConfiguredCurrency(string $code, array $enabledCurrencies): ?Currency
    {
        foreach ($enabledCurrencies as $currencyData) {
            if (is_array($currencyData) && strtoupper($currencyData['code'] ?? '') === strtoupper($code)) {
                return Currency::from($currencyData);
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $enabledCurrencies
     */
    private static function findBaseCurrency(string $code, array $enabledCurrencies): Currency
    {
        return self::findConfiguredCurrency($code, $enabledCurrencies)
            ?? FluentCartCurrency::baseCurrency($code);
    }
}
