<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Actions\SaveOrderSnapshotAction;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class OrderSnapshotHooks
{
    public static function register(): void
    {
        add_action('fluent_cart/order_paid_done', [self::class, 'saveSnapshot'], 10, 1);
    }

    public static function saveSnapshot($order): void
    {
        $optionStore = new OptionStore();
        $contextService = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );

        $action = new SaveOrderSnapshotAction($contextService);
        $action->execute($order);
    }
}
