<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FChubMultiCurrency\Domain\Actions\RefreshRatesAction;
use FChubMultiCurrency\Domain\Actions\SaveManualRatesAction;
use FChubMultiCurrency\Domain\Providers\ProviderRegistry;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\RatesCacheStore;
use InvalidArgumentException;
use RuntimeException;

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

        $threshold = ((int) ($settings['stale_threshold_hrs'] ?? 24)) * HOUR_IN_SECONDS;
        $formatted = array_map(
            static fn(ExchangeRate $rate): array => self::formatRate($rate, $threshold),
            $rates,
        );

        return new \WP_REST_Response([
            'data' => [
                'base_currency' => $baseCurrency,
                'provider' => (string) ($settings['rate_provider'] ?? 'manual'),
                'quote_currencies' => SelectableCurrencyCodes::fromSettings($settings)->quoteCurrencies(),
                'rates'         => $formatted,
            ],
        ]);
    }

    public function refresh(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();
        if (!ProviderRegistry::usesRemoteProvider($optionStore)) {
            return new \WP_REST_Response([
                'data' => [
                    'message' => 'Manual rates are saved explicitly and cannot be refreshed.',
                    'status' => false,
                ],
            ], 409);
        }

        $action = new RefreshRatesAction(
            new ExchangeRateRepository(),
            new RatesCacheStore(),
        );

        $success = $action->execute();

        if (!$success) {
            return new \WP_REST_Response([
                'data' => [
                    'message' => 'Failed to refresh exchange rates. Check the logs for details.',
                    'status'  => false,
                ],
            ], 500);
        }

        return new \WP_REST_Response([
            'data' => [
                'message' => 'Exchange rates refreshed successfully.',
                'status'  => true,
            ],
        ]);
    }

    public function saveManual(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        $submittedRates = is_array($params) ? ($params['rates'] ?? null) : null;
        if (!is_array($submittedRates)) {
            return new \WP_REST_Response([
                'data' => ['message' => 'Provide manual rates as a currency-to-rate object.'],
            ], 400);
        }

        try {
            $rates = (new SaveManualRatesAction(
                new ExchangeRateRepository(),
                new RatesCacheStore(),
            ))->execute($submittedRates);
        } catch (InvalidArgumentException $exception) {
            return new \WP_REST_Response([
                'data' => ['message' => $exception->getMessage()],
            ], 422);
        } catch (RuntimeException) {
            return new \WP_REST_Response([
                'data' => ['message' => 'Manual rates could not be saved.'],
            ], 500);
        }

        $baseCurrency = $rates[0]->baseCurrency;

        return new \WP_REST_Response([
            'data' => [
                'message' => 'Manual rates saved successfully.',
                'status' => true,
                'base_currency' => $baseCurrency,
                'rates' => array_map(
                    static fn(ExchangeRate $rate): array => self::formatRate($rate, PHP_INT_MAX),
                    $rates,
                ),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function formatRate(ExchangeRate $rate, int $threshold): array
    {
        return [
            'base_currency' => $rate->baseCurrency,
            'quote_currency' => $rate->quoteCurrency,
            'rate' => $rate->rate,
            'provider' => $rate->provider->value,
            'fetched_at' => $rate->fetchedAt,
            'is_stale' => $rate->isStale($threshold),
        ];
    }
}
