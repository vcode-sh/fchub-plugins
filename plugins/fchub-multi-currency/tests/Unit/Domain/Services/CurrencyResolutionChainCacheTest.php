<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Domain\Services\CurrencyResolution;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyResolutionChainCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the memoised chain before each test
        CurrencyResolution::resetChain();

        $_GET = [];
        $_COOKIE = [];
    }

    #[Test]
    public function testChainReturnsSameInstanceOnSecondCall(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'    => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $optionStore = new OptionStore();

        $chain1 = CurrencyResolution::chain($optionStore);
        $chain2 = CurrencyResolution::chain($optionStore);

        $this->assertSame(
            $chain1,
            $chain2,
            'chain must return the cached instance on subsequent calls'
        );
    }

    #[Test]
    public function testCachedChainIsResolverChainInstance(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
        ]);

        $optionStore = new OptionStore();
        $chain = CurrencyResolution::chain($optionStore);

        $this->assertInstanceOf(
            \FChubMultiCurrency\Domain\Resolvers\ResolverChain::class,
            $chain
        );
    }
}
