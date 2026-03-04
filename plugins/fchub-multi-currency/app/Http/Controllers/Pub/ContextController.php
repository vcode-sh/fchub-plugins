<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Pub;

use FChubMultiCurrency\Domain\Actions\PersistContextAction;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\PreferenceRepository;

defined('ABSPATH') || exit;

final class ContextController
{
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(
            new ResolverChain(),
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
        $params = $request->get_json_params();
        $currencyCode = isset($params['currency']) ? sanitize_text_field($params['currency']) : '';

        if ($currencyCode === '') {
            return new \WP_REST_Response([
                'data' => ['message' => 'Currency code is required.'],
            ], 422);
        }

        $action = new PersistContextAction(new PreferenceRepository());
        $action->execute(strtoupper($currencyCode));

        CurrencyContextService::reset();

        $userId = get_current_user_id();
        do_action('fchub_mc/context_switched', strtoupper($currencyCode), $userId);

        return new \WP_REST_Response([
            'data' => [
                'message'  => 'Currency preference saved.',
                'currency' => strtoupper($currencyCode),
            ],
        ]);
    }
}
