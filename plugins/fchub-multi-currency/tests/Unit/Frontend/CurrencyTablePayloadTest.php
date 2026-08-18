<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Frontend;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Frontend\CurrencyTablePayload;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Constants;
use FChubMultiCurrency\Tests\Support\TestCase;
use FluentCart\Api\CurrencySettings;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyTablePayloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ContextModule::resetChain();
        CurrencySettings::setMock(['currency' => 'USD', 'currency_sign' => '$']);
    }

    #[Test]
    public function testTableContainsEverySelectableCurrencyIncludingBase(): void
    {
        $this->storeWith(['EUR', 'PLN']);

        $table = CurrencyTablePayload::build(new OptionStore());

        $this->assertSame(['USD', 'EUR', 'PLN'], array_keys($table));
        $this->assertSame(1.0, $table['USD']['rate']);
        $this->assertSame(0.92, $table['EUR']['rate']);
        $this->assertSame('€', $table['EUR']['symbol']);
    }

    /**
     * The table goes into every cached document, so anything a reader could derive
     * from the key or from a store-level setting is dead weight repeated once per
     * currency. `resolverSource` is worse than dead weight: it describes how one
     * request resolved, which is precisely the kind of state that must not survive
     * into a page somebody else will be served.
     */
    #[Test]
    public function testEntriesCarryNothingDerivableAndNothingRequestShaped(): void
    {
        $this->storeWith(['EUR']);

        foreach (CurrencyTablePayload::build(new OptionStore()) as $code => $entry) {
            foreach (['displayCurrency', 'baseCurrency', 'isBaseDisplay', 'resolverSource', 'disclosureEnabled'] as $key) {
                $this->assertArrayNotHasKey($key, $entry, "{$code} carries a derivable or request-shaped field: {$key}");
            }
        }
    }

    /**
     * The disclosure template accepts `{display_currency}` and `{rate}`, so its
     * rendered text genuinely differs per currency and cannot be hoisted to a
     * single store-level string however tempting the byte count makes it look.
     */
    #[Test]
    public function testDisclosureTextStaysPerCurrencyBecauseItsTokensDo(): void
    {
        $this->storeWith(['EUR', 'PLN']);
        $settings = $GLOBALS['wp_options']['fchub_mc_settings'];
        $settings['checkout_disclosure_enabled'] = 'yes';
        $settings['checkout_disclosure_text'] = 'Showing {display_currency} at {rate}, charged in {base_currency}.';
        $this->setOption('fchub_mc_settings', $settings);
        ContextModule::resetChain();

        $table = CurrencyTablePayload::build(new OptionStore());

        $this->assertStringContainsString('Showing EUR', $table['EUR']['disclosureText']);
        $this->assertStringContainsString('Showing PLN', $table['PLN']['disclosureText']);
        $this->assertNull($table['USD']['disclosureText'], 'The base currency discloses nothing.');
    }

    /**
     * The invariant the whole cached-page repair rests on. A shared cache hands the
     * same bytes to everyone, so the bytes must not depend on who asked for them.
     * If this test ever fails, the plugin has started leaking a visitor's currency
     * into a document another visitor will be served.
     */
    #[Test]
    public function testTableIsIdenticalForVisitorsWithDifferentCookies(): void
    {
        $this->storeWith(['EUR', 'PLN']);

        $_COOKIE[Constants::COOKIE_KEY] = 'EUR';
        ContextModule::resetChain();
        $first = CurrencyTablePayload::build(new OptionStore());

        $_COOKIE[Constants::COOKIE_KEY] = 'PLN';
        ContextModule::resetChain();
        $second = CurrencyTablePayload::build(new OptionStore());

        $this->assertSame($first, $second);
    }

    /**
     * A logged-in visitor's account preference must not reach the table either.
     * Their pages are not cached today, but the table is the thing we are about to
     * bake into every document, so it stays free of anyone in particular.
     */
    #[Test]
    public function testTableIgnoresAnAccountPreference(): void
    {
        $this->storeWith(['EUR']);
        $guestTable = CurrencyTablePayload::build(new OptionStore());

        $this->setCurrentUserId(7);
        $this->setUserMeta(7, Constants::USER_META_KEY, 'EUR');
        ContextModule::resetChain();

        $this->assertSame($guestTable, CurrencyTablePayload::build(new OptionStore()));
    }

    /**
     * A currency whose rate cannot be resolved has no place in a table the browser
     * will switch from. Falling back to base under its own code would let the
     * switcher offer EUR and then quietly show dollars.
     */
    #[Test]
    public function testTableOmitsCurrenciesWithoutAUsableRate(): void
    {
        $this->storeWith(['EUR']);
        $GLOBALS['wpdb_mock_row'] = null;

        $table = CurrencyTablePayload::build(new OptionStore());

        $this->assertSame(['USD'], array_keys($table));
    }

    /**
     * An exact field set rather than a byte budget: the table is repeated once per
     * currency in every cached document, so a field added upstream costs fifty
     * copies. A merchant's own disclosure wording may be any length, which is why
     * this guards the shape and not the size.
     *
     * `flag` and `rateBadge` are rendered HTML on purpose. They are the two
     * surfaces the browser cannot build from primitives — one needs a currency to
     * country mapping, the other a translated relative time — and together they
     * cost about 270 bytes against the 3113 a full fragment set would.
     */
    #[Test]
    public function testEntryFieldSetIsExactlyWhatTheBrowserNeeds(): void
    {
        $this->storeWith(['EUR']);

        $entry = CurrencyTablePayload::build(new OptionStore())['EUR'];

        $this->assertSame([
            'rate',
            'displayCurrencyName',
            'decimals',
            'symbol',
            'position',
            'displayDecSep',
            'displayThousandSep',
            'disclosureText',
            'flag',
            'rateBadge',
        ], array_keys($entry));
    }

    /**
     * The table is built on every storefront request, so its cost is the cost of
     * the whole change. Fifty currencies is a large but real store.
     */
    #[Test]
    public function testAFiftyCurrencyTableBuildsWithinBudget(): void
    {
        $this->storeWith($this->fiftyCodes());

        $started = microtime(true);
        $table = CurrencyTablePayload::build(new OptionStore());
        $elapsed = microtime(true) - $started;

        $this->assertCount(51, $table);
        $this->assertLessThan(0.05, $elapsed, sprintf('Table build took %.1f ms', $elapsed * 1000));
    }

    /**
     * @param string[] $quoteCodes
     */
    private function storeWith(array $quoteCodes): void
    {
        $currencies = [];
        foreach ($quoteCodes as $code) {
            $currencies[] = [
                'code'     => $code,
                'name'     => $code === 'EUR' ? 'Euro' : $code,
                'symbol'   => $code === 'EUR' ? '€' : $code,
                'decimals' => 2,
                'position' => 'left',
            ];
        }

        $this->setOption('fchub_mc_settings', [
            'enabled'            => 'yes',
            'base_currency'      => 'USD',
            'display_currencies' => $currencies,
        ]);

        $this->setWpdbMockRow([
            'base_currency'  => 'USD',
            'quote_currency' => 'EUR',
            'rate'           => '0.92000000',
            'provider'       => 'manual',
            'fetched_at'     => current_time('mysql'),
        ]);
    }

    /**
     * @return string[]
     */
    private function fiftyCodes(): array
    {
        $codes = [];
        foreach (range('A', 'Z') as $first) {
            foreach (['A', 'B'] as $second) {
                $codes[] = 'X' . $first . $second;
                if (count($codes) === 50) {
                    return $codes;
                }
            }
        }

        return $codes;
    }
}
