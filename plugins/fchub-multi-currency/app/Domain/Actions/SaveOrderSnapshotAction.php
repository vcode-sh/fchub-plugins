<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Domain\Services\CurrencyContextService;

defined('ABSPATH') || exit;

final class SaveOrderSnapshotAction
{
    public function __construct(
        private CurrencyContextService $contextService,
    ) {
    }

    public function execute(object $order): void
    {
        $context = $this->contextService->resolve();

        if ($context->isBaseDisplay) {
            return;
        }

        $orderId = (int) $order->id;

        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- order meta write on purchase
        update_post_meta($orderId, '_fchub_mc_display_currency', $context->displayCurrency->code);
        update_post_meta($orderId, '_fchub_mc_base_currency', $context->baseCurrency->code);
        update_post_meta($orderId, '_fchub_mc_rate', $context->rate->rate);
        update_post_meta($orderId, '_fchub_mc_disclosure_version', FCHUB_MC_VERSION);
    }
}
