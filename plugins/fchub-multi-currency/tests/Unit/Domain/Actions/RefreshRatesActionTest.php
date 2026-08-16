<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Domain\Actions\RefreshRatesAction;
use FChubMultiCurrency\Domain\Enums\RateProvider;
use FChubMultiCurrency\Domain\ValueObjects\ExchangeRate;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class RefreshRatesActionTest extends TestCase
{
    #[Test]
    public function testManualRatesCannotBeMadeFreshByTheRemoteRefreshAction(): void
    {
        CurrencySettings::setMock(['currency' => 'USD']);
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'rate_provider' => 'manual',
            'display_currencies' => [['code' => 'EUR']],
        ]);
        $this->setWpdbMockResults([[
            'base_currency' => 'USD',
            'quote_currency' => 'EUR',
            'rate' => '0.92000000',
            'provider' => 'manual',
            'fetched_at' => '2026-01-01 00:00:00',
        ]]);
        $cache = new RatesCacheStore();
        $cache->set(new ExchangeRate(
            baseCurrency: 'USD',
            quoteCurrency: 'EUR',
            rate: '0.92000000',
            provider: RateProvider::Manual,
            fetchedAt: '2026-01-01 00:00:00',
        ));

        $result = $this->action($cache)->execute();

        $this->assertFalse($result);
        $this->assertSame('2026-01-01 00:00:00', $cache->get('USD', 'EUR')?->fetchedAt);
        $this->assertSame([], $this->rateInsertQueries());
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testCompleteRemoteSnapshotUsesOneWriteAndOneTimestamp(): void
    {
        $this->configureRemote(['EUR', 'GBP']);
        $this->mockExchangeRateApi([
            'USD' => '1.00000000',
            'EUR' => '0.92000000',
            'GBP' => '0.79000000',
        ]);
        $cache = new RatesCacheStore();

        $result = $this->action($cache)->execute();

        $this->assertTrue($result);
        $inserts = $this->rateInsertQueries();
        $this->assertCount(1, $inserts);
        $this->assertStringContainsString("'USD', 'EUR', '0.92000000', 'exchange_rate_api'", $inserts[0]);
        $this->assertStringContainsString("'USD', 'GBP', '0.79000000', 'exchange_rate_api'", $inserts[0]);

        $eur = $cache->get('USD', 'EUR');
        $gbp = $cache->get('USD', 'GBP');
        $this->assertSame('0.92000000', $eur?->rate);
        $this->assertSame('0.79000000', $gbp?->rate);
        $this->assertSame($eur?->fetchedAt, $gbp?->fetchedAt);

        $events = $this->getActionsFired('fchub_mc/rates_refreshed');
        $this->assertCount(1, $events);
        $this->assertSame(['USD', 2], $events[0]['args']);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function incompleteProviderSnapshots(): iterable
    {
        yield 'missing configured quote' => [[
            'USD' => '1.00000000',
            'EUR' => '0.92000000',
        ]];
        yield 'scientific notation' => [[
            'USD' => '1.00000000',
            'EUR' => '0.92000000',
            'GBP' => '7.9e-1',
        ]];
        yield 'zero quote' => [[
            'USD' => '1.00000000',
            'EUR' => '0.92000000',
            'GBP' => '0.00000000',
        ]];
    }

    #[Test]
    #[DataProvider('incompleteProviderSnapshots')]
    public function testIncompleteRemoteSnapshotLeavesEveryLastGoodRateUntouched(array $providerRates): void
    {
        $this->configureRemote(['EUR', 'GBP']);
        $this->mockExchangeRateApi($providerRates);
        $cache = $this->seedLastGoodRates();

        $result = $this->action($cache)->execute();

        $this->assertFalse($result);
        $this->assertSame('0.91000000', $cache->get('USD', 'EUR')?->rate);
        $this->assertSame('0.78000000', $cache->get('USD', 'GBP')?->rate);
        $this->assertSame('2026-01-01 00:00:00', $cache->get('USD', 'EUR')?->fetchedAt);
        $this->assertSame('2026-01-01 00:00:00', $cache->get('USD', 'GBP')?->fetchedAt);
        $this->assertSame([], $this->rateInsertQueries());
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testDatabaseFailureCannotPublishTheUnpersistedSnapshotToCache(): void
    {
        $this->configureRemote(['EUR', 'GBP']);
        $this->mockExchangeRateApi([
            'USD' => '1.00000000',
            'EUR' => '0.92000000',
            'GBP' => '0.79000000',
        ]);
        $cache = $this->seedLastGoodRates();
        $GLOBALS['wpdb_mock_query_result'] = false;

        $result = $this->action($cache)->execute();

        $this->assertFalse($result);
        $this->assertSame('0.91000000', $cache->get('USD', 'EUR')?->rate);
        $this->assertSame('0.78000000', $cache->get('USD', 'GBP')?->rate);
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testRemoteRefreshWithoutConfiguredQuotesFailsBeforeNetworkAccess(): void
    {
        $this->configureRemote([]);
        $this->mockExchangeRateApi(['USD' => '1.00000000']);

        $result = $this->action(new RatesCacheStore())->execute();

        $this->assertFalse($result);
        $this->assertSame([], $GLOBALS['wp_remote_requests']);
        $this->assertSame([], $this->rateInsertQueries());
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    /**
     * @param string[] $quoteCurrencies
     */
    private function configureRemote(array $quoteCurrencies): void
    {
        CurrencySettings::setMock(['currency' => 'USD']);
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'rate_provider' => 'exchange_rate_api',
            'rate_provider_api_key' => 'api-key',
            'display_currencies' => array_map(
                static fn(string $code): array => ['code' => $code],
                $quoteCurrencies,
            ),
        ]);
    }

    /**
     * @param array<string, string> $rates
     */
    private function mockExchangeRateApi(array $rates): void
    {
        $body = (string) json_encode([
            'result' => 'success',
            'conversion_rates' => $rates,
        ]);
        $GLOBALS['wp_mock_remote_response'] = [
            'body' => $body,
            'response' => ['code' => 200],
        ];
        $GLOBALS['wp_mock_remote_body'] = $body;
    }

    private function seedLastGoodRates(): RatesCacheStore
    {
        $cache = new RatesCacheStore();
        foreach (['EUR' => '0.91000000', 'GBP' => '0.78000000'] as $quote => $rate) {
            $cache->set(new ExchangeRate(
                baseCurrency: 'USD',
                quoteCurrency: $quote,
                rate: $rate,
                provider: RateProvider::Manual,
                fetchedAt: '2026-01-01 00:00:00',
            ));
        }

        return $cache;
    }

    private function action(RatesCacheStore $cache): RefreshRatesAction
    {
        return new RefreshRatesAction(new ExchangeRateRepository(), $cache);
    }

    /**
     * @return string[]
     */
    private function rateInsertQueries(): array
    {
        return array_values(array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $query): bool => str_contains($query, 'INSERT INTO `wp_fchub_mc_rate_history`'),
        ));
    }
}
