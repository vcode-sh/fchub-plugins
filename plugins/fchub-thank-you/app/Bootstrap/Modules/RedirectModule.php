<?php

declare(strict_types=1);

namespace FchubThankYou\Bootstrap\Modules;

use FchubThankYou\Bootstrap\ModuleContract;
use FchubThankYou\Domain\Services\OrderProductResolver;
use FchubThankYou\Domain\Services\ProductRedirectResolver;
use FchubThankYou\Support\ProductMetaStore;

final class RedirectModule implements ModuleContract
{
    public function register(): void
    {
        add_filter('fluentcart/payment/success_url', [$this, 'maybeRedirect'], 10, 2);
    }

    /** @param array<string, mixed> $context */
    public function maybeRedirect(string $url, array $context): string
    {
        $hash = (string) ($context['transaction_hash'] ?? '');
        if ($hash === '') {
            return $url;
        }

        $productIds = (new OrderProductResolver())->fromTransactionHash($hash);
        if ($productIds === []) {
            return $url;
        }

        return (new ProductRedirectResolver(new ProductMetaStore()))->resolve($productIds) ?? $url;
    }
}
