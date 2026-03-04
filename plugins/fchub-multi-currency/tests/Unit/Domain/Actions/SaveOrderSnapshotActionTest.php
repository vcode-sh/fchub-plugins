<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Domain\Actions\SaveOrderSnapshotAction;
use FChubMultiCurrency\Domain\Enums\ResolverSource;
use FChubMultiCurrency\Domain\Resolvers\ResolverChain;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SaveOrderSnapshotActionTest extends TestCase
{
    #[Test]
    public function testSkipsSnapshotWhenBaseDisplay(): void
    {
        $context = MockBuilder::baseOnlyContext();

        $chain = new ResolverChain();
        $chain->add(ResolverSource::Fallback, fn() => $context);

        $this->setOption('fchub_mc_settings', ['base_currency' => 'USD']);

        $contextService = new CurrencyContextService($chain, new OptionStore());
        $action = new SaveOrderSnapshotAction($contextService);
        $order = (object) ['id' => 1];
        $action->execute($order);

        $this->assertEmpty($GLOBALS['wp_mock_post_meta']);
    }

    #[Test]
    public function testSavesSnapshotWhenDifferentCurrency(): void
    {
        $context = MockBuilder::context(['is_base_display' => false]);

        $chain = new ResolverChain();
        $chain->add(ResolverSource::Cookie, fn() => $context);

        $this->setOption('fchub_mc_settings', ['base_currency' => 'USD']);

        $contextService = new CurrencyContextService($chain, new OptionStore());
        $action = new SaveOrderSnapshotAction($contextService);
        $order = (object) ['id' => 42];
        $action->execute($order);

        $this->assertSame('EUR', $GLOBALS['wp_mock_post_meta'][42]['_fchub_mc_display_currency']);
        $this->assertSame('USD', $GLOBALS['wp_mock_post_meta'][42]['_fchub_mc_base_currency']);
    }
}
