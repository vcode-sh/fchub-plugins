<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Integration;

use FChubMultiCurrency\Domain\Services\CheckoutDisclosureService;
use FChubMultiCurrency\Domain\Services\CurrencyContextService;
use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class CheckoutHooks
{
    public static function register(): void
    {
        add_filter('fluent_cart/checkout/after_patch_checkout_data_fragments', [self::class, 'addDisclosure'], 10, 2);
    }

    public static function addDisclosure(array $fragments, $allData): array
    {
        $optionStore = new OptionStore();
        $disclosureService = new CheckoutDisclosureService($optionStore);

        $contextService = new CurrencyContextService(
            new \FChubMultiCurrency\Domain\Resolvers\ResolverChain(),
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
