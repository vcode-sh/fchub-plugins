<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Pub;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Bootstrap\Modules\FrontendModule;
use FChubMultiCurrency\Domain\Actions\PersistContextAction;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Support\EventLogger;
use FChubMultiCurrency\Support\Hooks;
use FChubMultiCurrency\Support\IpResolver;
use FChubMultiCurrency\Support\Profiler;

defined('ABSPATH') || exit;

final class ContextController
{
    /**
     * Machine-readable outcome codes. Clients branch on these; the message is
     * for humans and is translated, so matching on it would break the moment
     * somebody installs a language pack.
     */
    public const CODE_SAVED = 'preference_saved';
    public const CODE_MODULE_DISABLED = 'module_disabled';
    public const CODE_RATE_LIMITED = 'rate_limited';
    public const CODE_INVALID_PAYLOAD = 'invalid_payload';
    public const CODE_CURRENCY_REQUIRED = 'currency_required';
    public const CODE_INVALID_CURRENCY = 'invalid_currency';
    public const CODE_PERSISTENCE_UNAVAILABLE = 'persistence_unavailable';

    /**
     * Resolves the currency context for this request and returns it, along with the
     * price-formatting metadata (symbol, decimals, separators, disclosure text) a
     * client needs to fully re-render prices — not just the bare code and rate.
     *
     * Because UrlParamResolver reads `$_GET` directly with top priority in the
     * resolver chain (see ContextModule::buildResolverChain), a request such as
     * `GET /context?currency=EUR` resolves to EUR regardless of any cookie — which
     * makes this endpoint usable as a client-side reconciliation source when a page
     * was served with a stale cached/cookie-less currency context. See
     * currency-projection.js's reconcile().
     *
     * The response varies per visitor (cookie, account, query string), so it must
     * never be cached — by the browser, by an edge/CDN layer, or by anything in
     * between. See self::noStore().
     *
     * Temporary instrumentation for issue #72 — see Support\Profiler and the
     * marks in ExchangeRateService::getRate(). Pass ?_debug_timing=1 to get a
     * `_profile` breakdown in the response showing where the request's time
     * actually went (resolver chain vs. rate cache vs. DB fallback), instead of
     * guessing from round-trip time alone. Not meant to ship long-term — remove
     * once the real bottleneck is found and fixed.
     */
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        Profiler::reset();
        Profiler::mark('get_start');

        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );
        Profiler::mark('chain_built');

        $context = $contextService->resolve();
        Profiler::mark('context_resolved');

        $pricing = FrontendModule::buildPricingConfig($context, $optionStore);
        Profiler::mark('pricing_built');

        $data = [
            'display_currency'           => $context->displayCurrency->code,
            'display_currency_name'      => $pricing['displayCurrencyName'],
            'base_currency'              => $context->baseCurrency->code,
            'rate'                       => $context->rate->rate,
            'source'                     => $context->source->value,
            'is_base_display'            => $context->isBaseDisplay,
            'symbol'                     => $pricing['symbol'],
            'decimals'                   => $pricing['decimals'],
            'position'                   => $pricing['position'],
            'display_decimal_separator'  => $pricing['displayDecSep'],
            'display_thousand_separator' => $pricing['displayThousandSep'],
            'disclosure_enabled'         => $pricing['disclosureEnabled'],
            'disclosure_text'            => $pricing['disclosureText'],
        ];

        if ($request->get_param('_debug_timing')) {
            $data['_profile'] = Profiler::report();
        }

        return self::noStore(new \WP_REST_Response(['data' => $data]));
    }

    public function set(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Hooks::isEnabled()) {
            return self::failure(
                self::CODE_MODULE_DISABLED,
                __('Multi-Currency is disabled.', 'fchub-multi-currency'),
                403,
            );
        }

        // Rate limit: 30 requests per minute per IP
        $ip = IpResolver::resolve();
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);
        $hits = (int) get_transient($rateLimitKey);

        if ($hits >= 30) {
            return self::failure(
                self::CODE_RATE_LIMITED,
                __('Too many requests. Please try again later.', 'fchub-multi-currency'),
                429,
            );
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return self::failure(
                self::CODE_INVALID_PAYLOAD,
                __('Invalid JSON payload.', 'fchub-multi-currency'),
                400,
            );
        }

        $currencyCode = isset($params['currency']) ? sanitize_text_field($params['currency']) : '';

        if ($currencyCode === '') {
            return self::failure(
                self::CODE_CURRENCY_REQUIRED,
                __('Currency code is required.', 'fchub-multi-currency'),
                422,
            );
        }

        $currencyCode = strtoupper($currencyCode);

        $optionStore = new OptionStore();
        $baseCurrency = strtoupper((string) $optionStore->get('base_currency', 'USD'));
        $displayCurrencies = $optionStore->get('display_currencies', []);
        if (!is_array($displayCurrencies)) {
            $displayCurrencies = [];
        }

        $validCodes = [$baseCurrency];
        foreach ($displayCurrencies as $currency) {
            if (!is_array($currency) || empty($currency['code'])) {
                continue;
            }

            $validCodes[] = strtoupper((string) $currency['code']);
        }

        if (!in_array($currencyCode, $validCodes, true)) {
            return self::failure(
                self::CODE_INVALID_CURRENCY,
                __('Invalid currency code.', 'fchub-multi-currency'),
                422,
            );
        }

        // Increment rate limit only after validation passes
        set_transient($rateLimitKey, $hits + 1, MINUTE_IN_SECONDS);

        $action = new PersistContextAction(new PreferenceRepository(), $optionStore);
        $result = $action->execute($currencyCode);

        $userId = get_current_user_id();

        // Nowhere to put the preference — typically a logged-out visitor while cookie persistence
        // is disabled. Saying "saved" here is what made the switcher look broken: the next request
        // simply resolved back to the default currency.
        if (!$result->persisted()) {
            EventLogger::log('context_switch_not_persisted', $userId, [
                'currency' => $currencyCode,
                'source' => 'rest',
            ]);

            // 409 rather than 403: the module is working and another visitor on this
            // very site would succeed. What blocks this one is the current persistence
            // configuration, which the visitor can resolve by signing in and the site
            // owner can resolve in settings. 403 is reserved for the module being off.
            return self::failure(
                self::CODE_PERSISTENCE_UNAVAILABLE,
                $userId > 0
                    ? __(
                        'Currency preference could not be saved because both cookie and account persistence are disabled.',
                        'fchub-multi-currency',
                    )
                    : __(
                        'Currency preference could not be saved. Cookie persistence is disabled, so logged-out visitors cannot keep a currency choice.',
                        'fchub-multi-currency',
                    ),
                409,
                [
                    'currency'  => $currencyCode,
                    'persisted' => false,
                ],
            );
        }

        CurrencyContextService::reset();

        do_action('fchub_mc/context_switched', $currencyCode, $userId);
        EventLogger::log('context_switched', $userId, [
            'currency' => $currencyCode,
            'source' => 'rest',
        ]);

        return self::noStore(new \WP_REST_Response([
            'data' => [
                'code'      => self::CODE_SAVED,
                'message'   => __('Currency preference saved.', 'fchub-multi-currency'),
                'currency'  => $currencyCode,
                'persisted' => true,
            ],
        ]));
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function failure(string $code, string $message, int $status, array $extra = []): \WP_REST_Response
    {
        return self::noStore(new \WP_REST_Response([
            'data' => array_merge(
                [
                    'code'    => $code,
                    'message' => $message,
                ],
                $extra,
            ),
        ], $status));
    }

    /**
     * Marks a response as never cacheable. Every response this controller returns
     * (GET and POST, success and failure) is visitor-specific — resolved from the
     * request's cookie, logged-in account, and query string — so a cached copy
     * would serve one visitor's currency to another. This is what a bug on
     * screenplayunlimited.com/Rocket.net turned out to be: the browser silently
     * served a stale GET /context?currency=X response from its own HTTP cache once
     * that exact URL had been fetched once, because nothing told it not to (issue
     * #72). Applied unconditionally here rather than left to hosts/CDNs to get
     * right, since the failure mode is silent and visitor-specific to reproduce.
     */
    private static function noStore(\WP_REST_Response $response): \WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store');

        return $response;
    }
}
