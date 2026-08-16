<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Domain\ValueObjects\SelectableCurrencyCodes;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Support\EventLogger;
use FChubMultiCurrency\Support\Logger;
use InvalidArgumentException;
use RuntimeException;

defined('ABSPATH') || exit;

/** Validates and stores one complete manual-rate snapshot. */
final class SaveManualRatesAction
{
    public function __construct(
        private ExchangeRateRepository $repository,
        private RatesCacheStore $cache,
    ) {
    }

    /**
     * @param array<string, mixed> $submittedRates
     * @return array<ExchangeRate>
     */
    public function execute(array $submittedRates): array
    {
        $settings = (new OptionStore())->all();
        if (($settings['rate_provider'] ?? 'manual') !== RateProvider::Manual->value) {
            throw new InvalidArgumentException('Switch the rate provider to Manual rates before saving.');
        }

        $baseCurrency = (string) $settings['base_currency'];
        $quoteCurrencies = SelectableCurrencyCodes::fromSettings($settings)->quoteCurrencies();
        if ($quoteCurrencies === []) {
            throw new InvalidArgumentException('Add at least one display currency before saving manual rates.');
        }

        $submittedRates = self::normalizeKeys($submittedRates);
        if (array_diff($quoteCurrencies, array_keys($submittedRates)) !== []) {
            throw new InvalidArgumentException('Provide one rate for every configured display currency.');
        }
        if (array_diff(array_keys($submittedRates), $quoteCurrencies) !== []) {
            throw new InvalidArgumentException('Rates may only be saved for configured display currencies.');
        }

        $fetchedAt = gmdate('Y-m-d H:i:s');
        $rates = [];
        foreach ($quoteCurrencies as $quoteCurrency) {
            $rates[] = new ExchangeRate(
                baseCurrency: $baseCurrency,
                quoteCurrency: $quoteCurrency,
                rate: self::normalizeRate($submittedRates[$quoteCurrency]),
                provider: RateProvider::Manual,
                fetchedAt: $fetchedAt,
            );
        }

        if (!$this->repository->insertMany($rates)) {
            throw new RuntimeException('Manual rates could not be saved.');
        }

        foreach ($rates as $rate) {
            $this->cache->set($rate);
        }

        do_action('fchub_mc/rates_refreshed', $baseCurrency, count($rates));
        EventLogger::log('rates_refreshed', get_current_user_id(), [
            'base_currency' => $baseCurrency,
            'provider' => RateProvider::Manual->value,
            'count' => count($rates),
        ]);
        Logger::info('Manual rates saved successfully', [
            'base_currency' => $baseCurrency,
            'count' => count($rates),
        ]);

        return $rates;
    }

    /**
     * @param array<string, mixed> $rates
     * @return array<string, mixed>
     */
    private static function normalizeKeys(array $rates): array
    {
        $normalized = [];
        foreach ($rates as $code => $rate) {
            $normalizedCode = strtoupper((string) $code);
            if (isset($normalized[$normalizedCode])) {
                throw new InvalidArgumentException('Rates may only be saved for configured display currencies.');
            }
            $normalized[$normalizedCode] = $rate;
        }

        return $normalized;
    }

    private static function normalizeRate(mixed $rate): string
    {
        if (!is_string($rate)) {
            throw new InvalidArgumentException(
                'Each manual rate must be a positive decimal string with up to 8 decimal places.',
            );
        }

        $rate = trim($rate);
        if (preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,8})?$/', $rate) !== 1) {
            throw new InvalidArgumentException(
                'Each manual rate must be a positive decimal string with up to 8 decimal places.',
            );
        }
        if (preg_match('/[1-9]/', $rate) !== 1) {
            throw new InvalidArgumentException('Each manual rate must be greater than zero.');
        }

        [$integer, $fraction] = array_pad(explode('.', $rate, 2), 2, '');

        return $integer . '.' . str_pad($fraction, 8, '0');
    }
}
