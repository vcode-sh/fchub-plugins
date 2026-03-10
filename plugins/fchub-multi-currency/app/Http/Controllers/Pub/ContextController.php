<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Pub;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Actions\PersistContextAction;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;
use FChubMultiCurrency\Support\EventLogger;
use FChubMultiCurrency\Support\Hooks;
use FChubMultiCurrency\Support\IpResolver;

defined('ABSPATH') || exit;

final class ContextController
{
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );

        $context = $contextService->resolve();

        return new \WP_REST_Response([
            'data' => [
                'display_currency' => $context->displayCurrency->code,
                'base_currency'    => $context->baseCurrency->code,
                'rate'             => $context->rate->rate,
                'source'           => $context->source->value,
                'is_base_display'  => $context->isBaseDisplay,
            ],
        ]);
    }

    public function set(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!Hooks::isEnabled()) {
            return new \WP_REST_Response([
                'data' => ['message' => 'Multi-Currency is disabled.'],
            ], 403);
        }

        // Rate limit: 30 requests per minute per IP
        $ip = IpResolver::resolve();
        $rateLimitKey = 'fchub_mc_rl_' . substr(md5($ip), 0, 12);
        $hits = (int) get_transient($rateLimitKey);

        if ($hits >= 30) {
            return new \WP_REST_Response([
                'data' => ['message' => 'Too many requests. Please try again later.'],
            ], 429);
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new \WP_REST_Response([
                'data' => ['message' => 'Invalid JSON payload.'],
            ], 400);
        }

        $currencyCode = isset($params['currency']) ? sanitize_text_field($params['currency']) : '';

        if ($currencyCode === '') {
            return new \WP_REST_Response([
                'data' => ['message' => 'Currency code is required.'],
            ], 422);
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
            return new \WP_REST_Response([
                'data' => ['message' => 'Invalid currency code.'],
            ], 422);
        }

        // Increment rate limit only after validation passes
        set_transient($rateLimitKey, $hits + 1, MINUTE_IN_SECONDS);

        $action = new PersistContextAction(new PreferenceRepository(), $optionStore);
        $action->execute($currencyCode);

        CurrencyContextService::reset();

        $userId = get_current_user_id();
        do_action('fchub_mc/context_switched', $currencyCode, $userId);
        EventLogger::log('context_switched', $userId, [
            'currency' => $currencyCode,
            'source' => 'rest',
        ]);

        return new \WP_REST_Response([
            'data' => [
                'message'  => 'Currency preference saved.',
                'currency' => $currencyCode,
            ],
        ]);
    }
}
