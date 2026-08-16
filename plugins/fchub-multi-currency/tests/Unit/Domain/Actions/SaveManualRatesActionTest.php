<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Domain\Actions\SaveManualRatesAction;
use FChubMultiCurrency\Storage\ExchangeRateRepository;
use FChubMultiCurrency\Storage\RatesCacheStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class SaveManualRatesActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CurrencySettings::setMock(['currency' => 'EUR']);
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'PLN',
            'rate_provider' => 'manual',
            'display_currencies' => [
                ['code' => 'EUR'],
                ['code' => 'USD'],
                ['code' => 'GBP'],
            ],
        ]);
    }

    #[Test]
    public function testPersistsOneAtomicSnapshotForEveryConfiguredQuote(): void
    {
        $rates = $this->action()->execute([
            'USD' => '1.12345678',
            'GBP' => '0.86',
        ]);

        $this->assertCount(2, $rates);
        $this->assertSame('EUR', $rates[0]->baseCurrency);
        $this->assertSame('USD', $rates[0]->quoteCurrency);
        $this->assertSame('1.12345678', $rates[0]->rate);
        $this->assertSame('0.86000000', $rates[1]->rate);
        $this->assertSame($rates[0]->fetchedAt, $rates[1]->fetchedAt);

        $queries = array_values(array_filter(
            $GLOBALS['wpdb']->queries,
            static fn(string $query): bool => str_contains($query, 'INSERT INTO `wp_fchub_mc_rate_history`'),
        ));
        $this->assertCount(1, $queries);
        $this->assertStringContainsString("'EUR', 'USD', '1.12345678', 'manual'", $queries[0]);
        $this->assertStringContainsString("'EUR', 'GBP', '0.86000000', 'manual'", $queries[0]);

        $cache = $GLOBALS['wp_cache_store']['fchub_mc_rates'] ?? [];
        $this->assertSame('1.12345678', $cache['EUR_USD']['rate']);
        $this->assertSame('0.86000000', $cache['EUR_GBP']['rate']);

        $events = $this->getActionsFired('fchub_mc/rates_refreshed');
        $this->assertCount(1, $events);
        $this->assertSame(['EUR', 2], $events[0]['args']);
    }

    #[Test]
    public function testDatabaseFailureLeavesCacheAndSuccessHookUntouched(): void
    {
        $GLOBALS['wpdb_mock_query_result'] = false;

        try {
            $this->action()->execute([
                'USD' => '1.10',
                'GBP' => '0.85',
            ]);
            $this->fail('Expected manual rate persistence to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Manual rates could not be saved.', $exception->getMessage());
        }

        $this->assertSame([], $GLOBALS['wp_cache_store']['fchub_mc_rates'] ?? []);
        $this->assertHookNotFired('fchub_mc/rates_refreshed');
    }

    #[Test]
    public function testRemoteProviderCannotWriteManualHistory(): void
    {
        $settings = get_option('fchub_mc_settings');
        $settings['rate_provider'] = 'ecb';
        $this->setOption('fchub_mc_settings', $settings);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Switch the rate provider to Manual rates before saving.');

        $this->action()->execute(['USD' => '1.10', 'GBP' => '0.85']);
    }

    #[Test]
    public function testRequiresAtLeastOneConfiguredQuoteCurrency(): void
    {
        $settings = get_option('fchub_mc_settings');
        $settings['display_currencies'] = [['code' => 'EUR']];
        $this->setOption('fchub_mc_settings', $settings);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Add at least one display currency before saving manual rates.');

        $this->action()->execute([]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidRates(): iterable
    {
        yield 'missing configured currency' => [
            ['USD' => '1.10'],
            'Provide one rate for every configured display currency.',
        ];
        yield 'unexpected currency' => [
            ['USD' => '1.10', 'GBP' => '0.85', 'JPY' => '170'],
            'Rates may only be saved for configured display currencies.',
        ];
        yield 'JSON number loses exact decimal contract' => [
            ['USD' => 1.10, 'GBP' => '0.85'],
            'Each manual rate must be a positive decimal string with up to 8 decimal places.',
        ];
        yield 'scientific notation' => [
            ['USD' => '1e2', 'GBP' => '0.85'],
            'Each manual rate must be a positive decimal string with up to 8 decimal places.',
        ];
        yield 'zero' => [
            ['USD' => '0.00000000', 'GBP' => '0.85'],
            'Each manual rate must be greater than zero.',
        ];
        yield 'negative' => [
            ['USD' => '-1.10', 'GBP' => '0.85'],
            'Each manual rate must be a positive decimal string with up to 8 decimal places.',
        ];
        yield 'too many decimal places' => [
            ['USD' => '1.123456789', 'GBP' => '0.85'],
            'Each manual rate must be a positive decimal string with up to 8 decimal places.',
        ];
        yield 'exceeds database integer precision' => [
            ['USD' => '10000000000', 'GBP' => '0.85'],
            'Each manual rate must be a positive decimal string with up to 8 decimal places.',
        ];
    }

    #[Test]
    #[DataProvider('invalidRates')]
    public function testRejectsIncompleteOrInexactSnapshots(array $submitted, string $message): void
    {
        try {
            $this->action()->execute($submitted);
            $this->fail('Expected manual rate validation to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame([], $GLOBALS['wpdb']->queries);
        $this->assertSame([], $GLOBALS['wp_cache_store']['fchub_mc_rates'] ?? []);
    }

    private function action(): SaveManualRatesAction
    {
        return new SaveManualRatesAction(
            new ExchangeRateRepository(),
            new RatesCacheStore(),
        );
    }
}
