<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\ModuleContract;
use FChubMultiCurrency\Domain\Actions\PersistContextAction;
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
use FChubMultiCurrency\Frontend\CurrencySwitcherRenderer;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Support\EventLogger;
use FChubMultiCurrency\Support\FeatureFlags;
use FChubMultiCurrency\Support\Hooks;
use FChubMultiCurrency\Support\Profiler;

defined('ABSPATH') || exit;

final class ContextModule implements ModuleContract
{
    private static ?ResolverChain $cachedChain = null;

    public function register(): void
    {
        add_action('wp', [self::class, 'persistPostedCurrencyPreference'], 0);
        add_action('wp', [self::class, 'resolveContext'], 1);
        add_action('wp_login', [self::class, 'mergeGuestPreference'], 10, 2);

        // Temporary instrumentation for issue #72's non-base-currency latency
        // investigation — see Support\Profiler. resolveContext() below is
        // what actually runs the resolver chain (and, for a non-base
        // currency, ExchangeRateService::getRate()) on a real front-end page
        // load — the exact path the reported ~6.5s reload goes through,
        // as opposed to a direct REST fetch of GET /context, which shares
        // the same underlying code but not necessarily the same object-cache
        // bootstrapping on every host. Emits an HTML comment near the end of
        // the page, gated on the same ?_debug_timing=1 flag as the REST
        // endpoint, since a normal page load has no JSON response to attach
        // this to. Not meant to ship long-term.
        add_action('wp_footer', [self::class, 'outputDebugTimingComment'], 999);
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
        $allowedCodes = CurrencySwitcherRenderer::allowedCurrencyCodes($optionStore);
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

        $debug = Profiler::isRequested();
        if ($debug) {
            Profiler::reset();
            Profiler::mark('wp_hook_resolve_start');
        }

        $optionStore = new OptionStore();
        $chain = self::buildResolverChain($optionStore);
        if ($debug) {
            Profiler::mark('chain_built');
        }

        $service = new CurrencyContextService($chain, $optionStore);
        $context = $service->resolve();

        if ($debug) {
            Profiler::mark('context_resolved');
            Profiler::note('resolved_context', [
                'display_currency' => $context->displayCurrency->code,
                'base_currency'    => $context->baseCurrency->code,
                'source'           => $context->source->value,
                'is_base_display'  => $context->isBaseDisplay,
            ]);
        }
    }

    /**
     * Temporary instrumentation for issue #72 — see Support\Profiler and the
     * comment on register()'s wp_footer hook above. Prints as an HTML
     * comment so it's visible in "View Source" / fetched markup without
     * altering the rendered page. Not meant to ship long-term.
     */
    public static function outputDebugTimingComment(): void
    {
        if (!Hooks::isEnabled() || !Profiler::isRequested()) {
            return;
        }

        $payload = [
            'is_logged_in' => is_user_logged_in(),
            'profile'      => Profiler::report(),
            'notes'        => Profiler::noteReport(),
        ];

        $json = wp_json_encode($payload, JSON_PRETTY_PRINT);
        if ($json === false) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed diagnostic JSON built entirely from internal timing/reflection data, not request input; comment delimiters are hardcoded and cannot be broken out of by any value here
        echo "\n<!-- fchub-mc-debug-timing\n" . $json . "\n-->\n";
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
            $allowedCodes = CurrencySwitcherRenderer::allowedCurrencyCodes($optionStore);
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

        $staleFallback = $settings['stale_fallback'] ?? 'base';

        $chain = new ResolverChain();

        // Priority 1: URL parameter
        if (($settings['url_param_enabled'] ?? 'yes') === 'yes') {
            $paramKey = $settings['url_param_key'] ?? 'currency';
            $urlResolver = new UrlParamResolver($paramKey);
            $chain->add(ResolverSource::UrlParam, self::wrapResolver($urlResolver, $rateService, $rateRepo, $staleFallback, ResolverSource::UrlParam));
        }

        // Priority 2: Logged-in user meta
        if (($settings['account_persistence_enabled'] ?? 'yes') === 'yes') {
            $userMetaResolver = new UserMetaResolver();
            $chain->add(ResolverSource::UserMeta, self::wrapResolver($userMetaResolver, $rateService, $rateRepo, $staleFallback, ResolverSource::UserMeta));
        }

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
        $chain->add(ResolverSource::Fallback, function (
            string $baseCurrencyCode,
            array $enabledCurrencies
        ) use (
            $defaultCurrency,
            $rateService,
            $rateRepo,
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
                $rateRepo,
                $staleFallback,
                ResolverSource::Fallback,
            );
        });

        self::$cachedChain = $chain;

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
        return function (
            string $baseCurrencyCode,
            array $enabledCurrencies
        ) use (
            $resolver,
            $rateService,
            $rateRepo,
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
                $rateRepo,
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
        ExchangeRateRepository $rateRepo,
        string $staleFallback,
        ResolverSource $source,
    ): CurrencyContext {
        $resolvedCode = strtoupper($code);
        $baseCode = strtoupper($baseCurrencyCode);
        $baseCurrency = self::findCurrency($baseCode, $enabledCurrencies);

        if ($resolvedCode === $baseCode) {
            return CurrencyContext::baseOnly($baseCurrency);
        }

        $displayCurrency = self::findCurrency($resolvedCode, $enabledCurrencies);
        $rate = $rateService->getRate($baseCode, $resolvedCode);

        if ($rate === null && $staleFallback === 'last_known') {
            // If stale_fallback is 'last_known', try the most recent DB rate even if stale.
            $rate = $rateRepo->findLatest($baseCode, $resolvedCode);
        }

        if ($rate === null) {
            return CurrencyContext::baseOnly($baseCurrency);
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
