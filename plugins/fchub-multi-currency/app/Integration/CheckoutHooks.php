<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Bootstrap\Modules\ContextModule;
use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;
use FChubMultiCurrency\Support\Hooks;

defined('ABSPATH') || exit;

final class CheckoutHooks
{
    public static function register(): void
    {
        add_filter('fluent_cart/checkout/after_patch_checkout_data_fragments', [self::class, 'addDisclosure'], 10, 2);
    }

    public static function addDisclosure(array $fragments, $allData): array
    {
        if (!Hooks::isEnabled()) {
            return $fragments;
        }

        $optionStore = new OptionStore();
        $disclosureService = new CheckoutDisclosureService($optionStore);

        $contextService = new CurrencyContextService(
            ContextModule::buildResolverChain($optionStore),
            $optionStore,
        );

        $context = $contextService->resolve();
        $disclosure = $disclosureService->getDisclosure($context);

        if ($disclosure !== null) {
            $fragments['fchub_mc_disclosure'] = $disclosure;
        }

        return $fragments;
    }
}
