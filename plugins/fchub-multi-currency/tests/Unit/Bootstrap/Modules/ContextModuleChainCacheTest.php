<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Bootstrap\Modules;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ContextModuleChainCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset the static cached chain before each test
        $ref = new \ReflectionClass(ContextModule::class);
        $prop = $ref->getProperty('cachedChain');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $_GET = [];
        $_COOKIE = [];
    }

    #[Test]
    public function testBuildResolverChainReturnsSameInstanceOnSecondCall(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency'    => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'left'],
            ],
        ]);

        $optionStore = new OptionStore();

        $chain1 = ContextModule::buildResolverChain($optionStore);
        $chain2 = ContextModule::buildResolverChain($optionStore);

        $this->assertSame(
            $chain1,
            $chain2,
            'buildResolverChain must return the cached instance on subsequent calls'
        );
    }

    #[Test]
    public function testCachedChainIsResolverChainInstance(): void
    {
        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
        ]);

        $optionStore = new OptionStore();
        $chain = ContextModule::buildResolverChain($optionStore);

        $this->assertInstanceOf(
            \FChubMultiCurrency\Domain\Resolvers\ResolverChain::class,
            $chain
        );
    }
}
