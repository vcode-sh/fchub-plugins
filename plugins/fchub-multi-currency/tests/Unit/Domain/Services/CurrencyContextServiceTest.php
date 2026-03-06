<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class CurrencyContextServiceTest extends TestCase
{
    #[Test]
    public function testResolveReturnsCachedContext(): void
    {
        $context = MockBuilder::context();

        $chain = new ResolverChain();
        $callCount = 0;
        $chain->add(ResolverSource::Cookie, function () use ($context, &$callCount) {
            $callCount++;
            return $context;
        });

        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'display_currencies' => [],
        ]);

        $service = new CurrencyContextService($chain, new OptionStore());
        $first = $service->resolve();
        $second = $service->resolve();

        $this->assertSame($first, $second);
        $this->assertSame(1, $callCount, 'Chain should only be called once due to caching');
    }

    #[Test]
    public function testResetClearsCache(): void
    {
        $context = MockBuilder::context();

        $chain = new ResolverChain();
        $callCount = 0;
        $chain->add(ResolverSource::Cookie, function () use ($context, &$callCount) {
            $callCount++;
            return $context;
        });

        $this->setOption('fchub_mc_settings', [
            'base_currency' => 'USD',
            'display_currencies' => [],
        ]);

        $service = new CurrencyContextService($chain, new OptionStore());

        $service->resolve();
        $this->assertSame(1, $callCount, 'Chain should be called on first resolve');

        CurrencyContextService::reset();

        $service->resolve();
        $this->assertSame(2, $callCount, 'Chain should be called again after reset');
    }

    #[Test]
    public function testResolveHandlesInvalidDisplayCurrenciesShape(): void
    {
        $chain = new ResolverChain();
        $chain->add(ResolverSource::Fallback, fn() => null);

        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => 'not-an-array',
        ]);

        $service = new CurrencyContextService($chain, new OptionStore());
        $context = $service->resolve();

        $this->assertTrue($context->isBaseDisplay);
        $this->assertSame('USD', $context->displayCurrency->code);
    }

    #[Test]
    public function testStaticCacheSharedAcrossServiceInstances(): void
    {
        // PERF-1/BUG-3 fix: fchub_mc_format_price() must not rebuild the resolver
        // chain on every call. The static cache means a second CurrencyContextService
        // instance reuses the resolved context without invoking the chain again.
        $context = MockBuilder::context();

        $chain = new ResolverChain();
        $callCount = 0;
        $chain->add(ResolverSource::Cookie, function () use ($context, &$callCount) {
            $callCount++;
            return $context;
        });

        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [],
        ]);

        $optionStore = new OptionStore();

        // First service instance resolves and caches
        $service1 = new CurrencyContextService($chain, $optionStore);
        $service1->resolve();
        $this->assertSame(1, $callCount);

        // Second service instance (simulating a second fchub_mc_format_price call)
        // uses a fresh chain that would increment callCount if invoked
        $chain2 = new ResolverChain();
        $chain2->add(ResolverSource::Cookie, function () use ($context, &$callCount) {
            $callCount++;
            return $context;
        });
        $service2 = new CurrencyContextService($chain2, $optionStore);
        $result = $service2->resolve();

        $this->assertSame(1, $callCount, 'Second service instance should reuse static cache, not call chain again');
        $this->assertSame($context, $result);
    }

    #[Test]
    public function testGetResolvedReturnsNullBeforeResolve(): void
    {
        $this->assertNull(CurrencyContextService::getResolved());
    }

    #[Test]
    public function testGetResolvedReturnsCachedContextAfterResolve(): void
    {
        // Set up options so resolve() succeeds with a display currency
        $this->setOption('fchub_mc_settings', [
            'base_currency'      => 'USD',
            'display_currencies' => [
                ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimals' => 2, 'position' => 'right'],
            ],
            'default_display_currency' => 'EUR',
        ]);

        $optionStore = new OptionStore();
        $chain = ContextModule::buildResolverChain($optionStore);
        $service = new CurrencyContextService($chain, $optionStore);
        $context = $service->resolve();

        $this->assertSame($context, CurrencyContextService::getResolved());
    }
}
