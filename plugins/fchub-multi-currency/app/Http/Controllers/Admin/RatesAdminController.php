<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FChubMultiCurrency\Domain\Actions\RefreshRatesAction;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\Queries\RateHistoryQuery;
use FChubMultiCurrency\Storage\RatesCacheStore;

defined('ABSPATH') || exit;

final class RatesAdminController
{
    public function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();
        $settings = $optionStore->all();
        $baseCurrency = $settings['base_currency'] ?? 'USD';

        $repository = new ExchangeRateRepository();
        $rates = $repository->findAllLatest($baseCurrency);

        $formatted = [];

        foreach ($rates as $rate) {
            $formatted[] = [
                'base_currency'  => $rate->baseCurrency,
                'quote_currency' => $rate->quoteCurrency,
                'rate'           => $rate->rate,
                'provider'       => $rate->provider->value,
                'fetched_at'     => $rate->fetchedAt,
                'is_stale'       => $rate->isStale(((int) ($settings['stale_threshold_hrs'] ?? 24)) * 3600),
            ];
        }

        return new \WP_REST_Response([
            'data' => [
                'base_currency' => $baseCurrency,
                'rates'         => $formatted,
            ],
        ]);
    }

    public function refresh(\WP_REST_Request $request): \WP_REST_Response
    {
        $action = new RefreshRatesAction(
            new ExchangeRateRepository(),
            new RatesCacheStore(),
        );

        $action->execute();

        return new \WP_REST_Response([
            'data' => [
                'message' => 'Exchange rates refreshed successfully.',
                'status'  => true,
            ],
        ]);
    }
}
