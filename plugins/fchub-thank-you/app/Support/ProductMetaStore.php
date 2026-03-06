<?php

declare(strict_types=1);

namespace FchubThankYou\Support;

use FchubThankYou\Domain\Enums\RedirectType;
use FchubThankYou\Domain\ValueObjects\RedirectSettings;

class ProductMetaStore
{
    public function find(int $productId): RedirectSettings
    {
        $typeRaw = (string) get_post_meta($productId, Constants::META_TYPE, true);
        $type    = RedirectType::tryFrom($typeRaw) ?? RedirectType::Url;

        $targetIdRaw = get_post_meta($productId, Constants::META_TARGET_ID, true);
        $targetId    = $targetIdRaw !== '' && $targetIdRaw !== false ? (int) $targetIdRaw : null;

        return new RedirectSettings(
            enabled:  get_post_meta($productId, Constants::META_ENABLED, true) === 'yes',
            type:     $type,
            targetId: $targetId,
            url:      (string) get_post_meta($productId, Constants::META_URL, true),
            postType: (string) get_post_meta($productId, Constants::META_POST_TYPE, true),
        );
    }

    public function save(int $productId, RedirectSettings $settings): void
    {
        update_post_meta($productId, Constants::META_ENABLED, $settings->enabled ? 'yes' : 'no');
        update_post_meta($productId, Constants::META_TYPE, $settings->type->value);
        update_post_meta($productId, Constants::META_URL, $settings->url);

        if ($settings->targetId !== null) {
            update_post_meta($productId, Constants::META_TARGET_ID, $settings->targetId);
        } else {
            delete_post_meta($productId, Constants::META_TARGET_ID);
        }

        if ($settings->postType !== '') {
            update_post_meta($productId, Constants::META_POST_TYPE, $settings->postType);
        } else {
            delete_post_meta($productId, Constants::META_POST_TYPE);
        }
    }
}
