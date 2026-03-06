<?php

declare(strict_types=1);

namespace FchubThankYou\Domain\Services;

use FchubThankYou\Domain\Contracts\ConflictResolverContract;
use FchubThankYou\Domain\Enums\RedirectType;
use FchubThankYou\Support\ProductMetaStore;

final class ProductRedirectResolver implements ConflictResolverContract
{
    public function __construct(private readonly ProductMetaStore $store)
    {
    }

    /** @param list<int> $productIds */
    public function resolve(array $productIds): ?string
    {
        foreach ($productIds as $productId) {
            $settings = $this->store->find($productId);
            if (! $settings->enabled || ! $settings->hasValidTarget()) {
                continue;
            }

            $url = match ($settings->type) {
                RedirectType::Page, RedirectType::Post, RedirectType::Cpt
                    => $settings->targetId !== null ? get_permalink($settings->targetId) ?: null : null,
                RedirectType::Url => $settings->url,
            };

            if ($url !== null && $url !== '') {
                return $url;
            }
        }
        return null;
    }
}
