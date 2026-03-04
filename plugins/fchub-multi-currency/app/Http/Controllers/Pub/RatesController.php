<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Pub;

use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class RatesController
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
            ];
        }

        return new \WP_REST_Response([
            'data' => [
                'base_currency' => $baseCurrency,
                'rates'         => $formatted,
            ],
        ]);
    }
}
