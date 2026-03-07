<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;

defined('ABSPATH') || exit;

final class SaveOrderSnapshotAction
{
    public function __construct(
        private CurrencyContextService $contextService,
    ) {
    }

    public function execute(object $order, ?CurrencyContext $context = null): void
    {
        $context = $context ?? $this->contextService->resolve();

        if ($context->isBaseDisplay) {
            return;
        }

        $order->updateMeta('_fchub_mc_display_currency', $context->displayCurrency->code);
        $order->updateMeta('_fchub_mc_base_currency', $context->baseCurrency->code);
        $order->updateMeta('_fchub_mc_rate', $context->rate->rate);
        $order->updateMeta('_fchub_mc_disclosure_version', FCHUB_MC_VERSION);
    }
}
