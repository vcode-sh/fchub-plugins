<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Services;

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
}
