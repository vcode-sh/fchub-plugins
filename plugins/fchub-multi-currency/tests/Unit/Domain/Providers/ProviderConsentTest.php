<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Providers;

use FChubMultiCurrency\Domain\Providers\ProviderRegistry;
use FChubMultiCurrency\Domain\Providers\EcbProvider;
use FChubMultiCurrency\Domain\Providers\ExchangeRateApiProvider;
use FChubMultiCurrency\Domain\Providers\OpenExchangeRatesProvider;
use FChubMultiCurrency\Domain\Actions\RefreshRatesAction;
use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ProviderConsentTest extends TestCase
{
    #[Test]
    public function emptySettingsResolveToTheNoNetworkManualProvider(): void
    {
        $options = new OptionStore();

        self::assertSame('manual', ProviderRegistry::resolve($options)->name());
        self::assertFalse(ProviderRegistry::usesRemoteProvider($options));
    }

    #[Test]
    public function manualSettingsDoNotGrantNetworkConsent(): void
    {
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'manual']);

        self::assertFalse(ProviderRegistry::usesRemoteProvider(new OptionStore()));
    }

    #[Test]
    public function selectingAnyRemoteProviderGrantsNetworkConsent(): void
    {
        foreach (['ecb', 'exchange_rate_api', 'open_exchange_rates'] as $provider) {
            $this->setOption('fchub_mc_settings', ['rate_provider' => $provider]);

            self::assertTrue(
                ProviderRegistry::usesRemoteProvider(new OptionStore()),
                "Expected {$provider} to be treated as a remote provider.",
            );
        }
    }

    #[Test]
    public function invalidSavedProviderFallsBackToManualWithoutNetworkConsent(): void
    {
        $this->setOption('fchub_mc_settings', ['rate_provider' => 'made_up_provider']);

        $options = new OptionStore();

        self::assertSame('manual', ProviderRegistry::resolve($options)->name());
        self::assertFalse(ProviderRegistry::usesRemoteProvider($options));
    }

    #[Test]
    public function remoteProvidersUseHttpsAndBoundEveryResponseToFifteenSecondsAndOneMegabyte(): void
    {
        $GLOBALS['wp_mock_remote_response'] = new \WP_Error('offline', 'No network in unit tests.');

        (new EcbProvider())->fetchRates('EUR');
        (new ExchangeRateApiProvider('api-key'))->fetchRates('USD');
        (new OpenExchangeRatesProvider('app-id'))->fetchRates('USD');

        self::assertCount(3, $GLOBALS['wp_remote_requests']);
        foreach ($GLOBALS['wp_remote_requests'] as $request) {
            self::assertStringStartsWith('https://', $request['url']);
            self::assertSame(15, $request['args']['timeout'] ?? null);
            self::assertSame(1_048_576, $request['args']['limit_response_size'] ?? null);
        }
    }

    #[Test]
    public function invalidProviderResponsesNeverWriteSecretsToLogs(): void
    {
        $secret = 'do-not-log-this-key';
        $body = json_encode([
            'result' => 'error',
            'debug' => $secret,
        ]);
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => $body,
            'response' => ['code' => 401],
        ];
        $GLOBALS['wp_mock_remote_body'] = $body;

        self::assertSame([], (new ExchangeRateApiProvider($secret))->fetchRates('USD'));

        self::assertStringNotContainsString(
            $secret,
            (string) json_encode($GLOBALS['fluent_cart_logs']),
        );
    }

    #[Test]
    public function oversizedProviderResponsesAreRejectedBeforeParsing(): void
    {
        $body = str_repeat('x', 1_048_577);
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => $body,
            'response' => ['code' => 200],
        ];
        $GLOBALS['wp_mock_remote_body'] = $body;

        self::assertSame([], (new ExchangeRateApiProvider('api-key'))->fetchRates('USD'));
        self::assertSame([], (new OpenExchangeRatesProvider('app-id'))->fetchRates('USD'));
        self::assertSame([], (new EcbProvider())->fetchRates('EUR'));
    }

    #[Test]
    public function jsonProvidersRejectMissingOrZeroBaseRates(): void
    {
        foreach (
            [
                [new ExchangeRateApiProvider('api-key'), [
                    'result' => 'success',
                    'conversion_rates' => ['EUR' => 0.92],
                ]],
                [new OpenExchangeRatesProvider('app-id'), [
                    'rates' => ['USD' => 0, 'EUR' => 0.92],
                ]],
            ] as [$provider, $payload]
        ) {
            $body = json_encode($payload);
            $GLOBALS['wp_mock_remote_response'] = [
                'body' => $body,
                'response' => ['code' => 200],
            ];
            $GLOBALS['wp_mock_remote_body'] = $body;

            self::assertSame([], $provider->fetchRates('USD'));
        }
    }

    #[Test]
    public function manualCronRefreshMakesNoHttpRequest(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'rate_provider' => 'manual',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $this->setWpdbMockResults([]);

        $result = (new RefreshRatesAction(
            new ExchangeRateRepository(),
            new RatesCacheStore(),
        ))->execute();

        self::assertFalse($result);
        self::assertSame([], $GLOBALS['wp_remote_requests']);
    }

    #[Test]
    public function failedRemoteRefreshLeavesTheLastGoodRateInCache(): void
    {
        $cache = new RatesCacheStore();
        $lastGoodRate = new ExchangeRate(
            baseCurrency: 'USD',
            quoteCurrency: 'EUR',
            rate: '0.92000000',
            provider: RateProvider::Manual,
            fetchedAt: '2026-07-25 12:00:00',
        );
        $cache->set($lastGoodRate);
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'rate_provider' => 'ecb',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => '<broken',
            'response' => ['code' => 200],
        ];
        $GLOBALS['wp_mock_remote_body'] = '<broken';

        $result = (new RefreshRatesAction(new ExchangeRateRepository(), $cache))->execute();

        self::assertFalse($result);
        self::assertSame('0.92000000', $cache->get('USD', 'EUR')?->rate);
    }

    #[Test]
    public function malformedRemoteRateDoesNotEvictTheLastGoodRate(): void
    {
        $cache = new RatesCacheStore();
        $cache->set(new ExchangeRate(
            baseCurrency: 'USD',
            quoteCurrency: 'EUR',
            rate: '0.92000000',
            provider: RateProvider::Manual,
            fetchedAt: '2026-07-25 12:00:00',
        ));
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'rate_provider' => 'exchange_rate_api',
            'rate_provider_api_key' => 'api-key',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $body = json_encode([
            'result' => 'success',
            'conversion_rates' => [
                'USD' => 1,
                'EUR' => 'definitely-not-a-rate',
            ],
        ]);
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => $body,
            'response' => ['code' => 200],
        ];
        $GLOBALS['wp_mock_remote_body'] = $body;

        $result = (new RefreshRatesAction(new ExchangeRateRepository(), $cache))->execute();

        self::assertFalse($result);
        self::assertSame('0.92000000', $cache->get('USD', 'EUR')?->rate);
    }

    #[Test]
    public function legacySettingsWithoutAProviderArePersistedAsManualWithoutChangingOtherSettings(): void
    {
        $legacySettings = [
            'base_currency' => 'GBP',
            'display_currencies' => [['code' => 'EUR']],
            'rate_refresh_interval_hrs' => 12,
        ];
        $this->setOption('fchub_mc_settings', $legacySettings);
        $options = new OptionStore();

        $options->ensureExplicitRateProvider();

        self::assertSame(
            $legacySettings + ['rate_provider' => 'manual'],
            $GLOBALS['wp_options']['fchub_mc_settings'],
        );
    }
}
