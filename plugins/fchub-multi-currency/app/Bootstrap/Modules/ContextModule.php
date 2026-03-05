<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\Resolvers\CookieResolver;
use FChubMultiCurrency\Domain\Resolvers\GeoResolver;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\Resolvers\UrlParamResolver;
use FChubMultiCurrency\Domain\Resolvers\UserMetaResolver;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Services\ExchangeRateService;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\FeatureFlags;
use FChubMultiCurrency\Support\Hooks;

defined('ABSPATH') || exit;

final class ContextModule implements ModuleContract
{
    public function register(): void
    {
        add_action('wp', [self::class, 'resolveContext'], 1);
        add_action('wp_login', [self::class, 'mergeGuestPreference'], 10, 2);
    }

    public static function resolveContext(): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $optionStore = new OptionStore();
        $chain = self::buildResolverChain($optionStore);
        $service = new CurrencyContextService($chain, $optionStore);
        $service->resolve();
    }

    public static function mergeGuestPreference(string $userLogin, $user): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $guestCurrency = isset($_COOKIE['fchub_mc_currency']) ? sanitize_text_field(wp_unslash($_COOKIE['fchub_mc_currency'])) : '';

        if ($guestCurrency === '' || !isset($user->ID)) {
            return;
        }

        $existingPref = get_user_meta($user->ID, '_fchub_mc_currency', true);

        if (!$existingPref) {
            update_user_meta($user->ID, '_fchub_mc_currency', strtoupper($guestCurrency));
        }
    }

    public static function buildResolverChain(OptionStore $optionStore): ResolverChain
    {
        $settings = $optionStore->all();
        $rateRepo = new ExchangeRateRepository();
        $rateService = new ExchangeRateService(
            $rateRepo,
            new RatesCacheStore(),
        );

        $staleFallback = $settings['stale_fallback'] ?? 'base';

        $chain = new ResolverChain();

        // Priority 1: URL parameter
        if (($settings['url_param_enabled'] ?? 'yes') === 'yes') {
            $paramKey = $settings['url_param_key'] ?? 'currency';
            $urlResolver = new UrlParamResolver($paramKey);
            $chain->add(ResolverSource::UrlParam, self::wrapResolver($urlResolver, $rateService, $rateRepo, $staleFallback, ResolverSource::UrlParam));
        }

        // Priority 2: Logged-in user meta
        $userMetaResolver = new UserMetaResolver();
        $chain->add(ResolverSource::UserMeta, self::wrapResolver($userMetaResolver, $rateService, $rateRepo, $staleFallback, ResolverSource::UserMeta));

        // Priority 3: Guest cookie
        if (($settings['cookie_enabled'] ?? 'yes') === 'yes') {
            $cookieResolver = new CookieResolver();
            $chain->add(ResolverSource::Cookie, self::wrapResolver($cookieResolver, $rateService, $rateRepo, $staleFallback, ResolverSource::Cookie));
        }

        // Priority 4: Geolocation (feature-flagged)
        if (($settings['geo_enabled'] ?? 'no') === 'yes' && FeatureFlags::isEnabled('geo_resolver')) {
            $geoResolver = new GeoResolver();
            $chain->add(ResolverSource::Geo, self::wrapResolver($geoResolver, $rateService, $rateRepo, $staleFallback, ResolverSource::Geo));
        }

        // Priority 5: Default (uses default_display_currency setting, falls back to base)
        $defaultCurrency = $settings['default_display_currency'] ?? '';
        $chain->add(ResolverSource::Fallback, function (string $baseCurrencyCode, array $enabledCurrencies) use ($defaultCurrency) {
            $code = ($defaultCurrency !== '' && $defaultCurrency !== $baseCurrencyCode)
                ? $defaultCurrency
                : $baseCurrencyCode;

            $currency = self::findCurrency($code, $enabledCurrencies);
            return CurrencyContext::baseOnly($currency);
        });

        return $chain;
    }

    /**
     * Wraps a resolver that returns ?string into a callable that returns ?CurrencyContext.
     *
     * @param object $resolver Any resolver with a resolve(string, array): ?string method
     */
    private static function wrapResolver(
        object $resolver,
        ExchangeRateService $rateService,
        ExchangeRateRepository $rateRepo,
        string $staleFallback,
        ResolverSource $source,
    ): callable {
        return function (string $baseCurrencyCode, array $enabledCurrencies) use ($resolver, $rateService, $rateRepo, $staleFallback, $source): ?CurrencyContext {
            $code = $resolver->resolve($baseCurrencyCode, $enabledCurrencies);

            if ($code === null) {
                return null;
            }

            if (strtoupper($code) === strtoupper($baseCurrencyCode)) {
                $baseCurrency = self::findCurrency($code, $enabledCurrencies);
                return CurrencyContext::baseOnly($baseCurrency);
            }

            $displayCurrency = self::findCurrency($code, $enabledCurrencies);
            $baseCurrency = Currency::from([
                'code'     => $baseCurrencyCode,
                'name'     => $baseCurrencyCode,
                'symbol'   => $baseCurrencyCode,
                'decimals' => 2,
                'position' => 'left',
            ]);

            $rate = $rateService->getRate($baseCurrencyCode, $code);

            if ($rate === null) {
                // If stale_fallback is 'last_known', try the most recent rate from DB even if stale
                if ($staleFallback === 'last_known') {
                    $rate = $rateRepo->findLatest($baseCurrencyCode, $code);
                }

                if ($rate === null) {
                    return CurrencyContext::baseOnly($baseCurrency);
                }
            }

            return new CurrencyContext(
                displayCurrency: $displayCurrency,
                baseCurrency: $baseCurrency,
                rate: $rate,
                source: $source,
                isBaseDisplay: false,
            );
        };
    }

    /**
     * @param array<int|string, mixed> $enabledCurrencies
     */
    private static function findCurrency(string $code, array $enabledCurrencies): Currency
    {
        foreach ($enabledCurrencies as $currencyData) {
            if (is_array($currencyData) && strtoupper($currencyData['code'] ?? '') === strtoupper($code)) {
                return Currency::from($currencyData);
            }
        }

        return Currency::from([
            'code'     => $code,
            'name'     => $code,
            'symbol'   => $code,
            'decimals' => 2,
            'position' => 'left',
        ]);
    }
}
