<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Domain\Actions;

use FChubMultiCurrency\Domain\ValueObjects\CurrencyContext;

defined('ABSPATH') || exit;

/** Writes the display-currency snapshot onto an order; the caller resolves the context. */
final class SaveOrderSnapshotAction
{
    public function execute(object $order, CurrencyContext $context): void
    {
        if ($context->isBaseDisplay) {
            return;
        }

        $order->updateMeta('_fchub_mc_display_currency', $context->displayCurrency->code);
        $order->updateMeta('_fchub_mc_base_currency', $context->baseCurrency->code);
        $order->updateMeta('_fchub_mc_rate', $context->rate->rate);
        $order->updateMeta('_fchub_mc_disclosure_version', FCHUB_MC_VERSION);
    }
}
