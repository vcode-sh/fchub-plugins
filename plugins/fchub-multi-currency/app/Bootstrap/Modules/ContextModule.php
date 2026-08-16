<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Actions\PersistContextAction;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\Enums\StaleRateFallback;
use FChubMultiCurrency\Domain\Resolvers\CookieResolver;
use FChubMultiCurrency\Domain\Resolvers\GeoResolver;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\Resolvers\UrlParamResolver;
use FChubMultiCurrency\Domain\Resolvers\UserMetaResolver;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\Services\ExchangeRateService;
use FChubMultiCurrency\Domain\ValueObjects\Currency;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\EventLogger;
use FChubMultiCurrency\Support\FeatureFlags;
use FChubMultiCurrency\Support\Hooks;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Helpers\CurrenciesHelper;

defined('ABSPATH') || exit;

final class ContextModule implements ModuleContract
{
    private static ?ResolverChain $cachedChain = null;

    public function register(): void
    {
        add_action('wp', [self::class, 'persistPostedCurrencyPreference'], 0);
        add_action('wp', [self::class, 'resolveContext'], 1);
        add_action('wp_login', [self::class, 'mergeGuestPreference'], 10, 2);
    }

    public static function persistPostedCurrencyPreference(): void
    {
        if (!Hooks::isEnabled()) {
            return;
        }

        $requestMethod = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';
        if ($requestMethod !== 'POST') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $submitted = isset($_POST[CurrencySwitcherRenderer::NOSCRIPT_FIELD])
            ? sanitize_text_field(wp_unslash((string) $_POST[CurrencySwitcherRenderer::NOSCRIPT_FIELD]))
            : '';

        if ($submitted === '') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $nonce = isset($_POST[CurrencySwitcherRenderer::NOSCRIPT_NONCE])
            ? sanitize_text_field(wp_unslash((string) $_POST[CurrencySwitcherRenderer::NOSCRIPT_NONCE]))
            : '';

        if ($nonce === '' || !wp_verify_nonce($nonce, CurrencySwitcherRenderer::NOSCRIPT_ACTION)) {
            return;
        }

        $optionStore = new OptionStore();
        $allowedCodes = SelectableCurrencyCodes::fromSettings($optionStore->all())->all();
        $currencyCode = strtoupper($submitted);

        if (!in_array($currencyCode, $allowedCodes, true)) {
            return;
        }

        $result = (new PersistContextAction(
            new PreferenceRepository(),
            $optionStore,
        ))->execute($currencyCode);

        // Nothing was stored — a logged-out visitor with cookie persistence disabled. Faking the
        // cookie for this one request would only show the chosen currency until the next page load,
        // so report the failure instead of pretending the switch worked.
        if (!$result->persisted()) {
            do_action('fchub_mc/context_switch_not_persisted', $currencyCode, get_current_user_id());
            EventLogger::log('context_switch_not_persisted_noscript', get_current_user_id(), [
                'currency' => $currencyCode,
                'source' => 'noscript',
            ]);

            return;
        }

        $_COOKIE[Constants::COOKIE_KEY] = $currencyCode;
        CurrencyContextService::reset();

        do_action('fchub_mc/context_switched', $currencyCode, get_current_user_id());
        EventLogger::log('context_switched_noscript', get_current_user_id(), [
            'currency' => $currencyCode,
            'source' => 'noscript',
        ]);
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

        $optionStore = new OptionStore();
        if ($optionStore->get('cookie_enabled', 'yes') !== 'yes') {
            return;
        }
        if ($optionStore->get('account_persistence_enabled', 'yes') !== 'yes') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $guestCurrency = isset($_COOKIE['fchub_mc_currency']) ? sanitize_text_field(wp_unslash($_COOKIE['fchub_mc_currency'])) : '';

        if ($guestCurrency === '' || !isset($user->ID)) {
            return;
        }

        $existingPref = get_user_meta($user->ID, '_fchub_mc_currency', true);

        if (!$existingPref) {
            $allowedCodes = SelectableCurrencyCodes::fromSettings($optionStore->all())->all();
            $code = strtoupper($guestCurrency);

            if (!in_array($code, $allowedCodes, true)) {
                return;
            }

            update_user_meta($user->ID, '_fchub_mc_currency', $code);
        }
    }

    public static function resetChain(): void
    {
        self::$cachedChain = null;
    }

    public static function buildResolverChain(OptionStore $optionStore): ResolverChain
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
     * Resolves a validated browser cookie without consulting public URL settings.
     */
    public static function resolveCookiePreference(OptionStore $optionStore, string $currencyCode): CurrencyContext
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
        $configured = self::findConfiguredCurrency($code, $enabledCurrencies);
        if ($configured !== null) {
            return $configured;
        }

        $settings = CurrencySettings::get();
        $currencies = CurrenciesHelper::getCurrencies();
        $signs = CurrenciesHelper::getCurrencySigns();
        $zeroDecimal = CurrenciesHelper::zeroDecimalCurrencies();
        $position = match ((string) ($settings['currency_position'] ?? 'before')) {
            'after', 'right' => 'right',
            'after_space', 'right_space' => 'right_space',
            'before_space', 'left_space' => 'left_space',
            default => 'left',
        };

        return Currency::from([
            'code' => $code,
            'name' => $currencies[$code] ?? $code,
            'symbol' => $signs[$code] ?? $settings['currency_sign'] ?? $code,
            'decimals' => isset($zeroDecimal[$code]) ? 0 : 2,
            'position' => $position,
        ]);
    }
}
