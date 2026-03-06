<?php

declare(strict_types=1);

namespace FchubThankYou\Http\Controllers;

use FchubThankYou\Domain\Enums\RedirectType;
use FchubThankYou\Domain\ValueObjects\RedirectSettings;
use FchubThankYou\Support\ProductMetaStore;

final class ProductSettingsController
{
    public function __construct(private readonly ProductMetaStore $store)
    {
    }

    public function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $settings = $this->store->find((int) $request->get_param('id'));
        $data     = $settings->toArray();

        // Enrich with resolved label and permalink for the UI
        if ($settings->targetId !== null) {
            $data['target_label']     = get_the_title($settings->targetId) ?: '';
            $data['target_permalink'] = get_permalink($settings->targetId) ?: '';
        } else {
            $data['target_label']     = '';
            $data['target_permalink'] = '';
        }

        return new \WP_REST_Response($data, 200);
    }

    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $productId = (int) $request->get_param('id');

        $typeRaw = (string) $request->get_param('type');
        $type    = RedirectType::tryFrom($typeRaw) ?? RedirectType::Url;

        $targetIdRaw = $request->get_param('target_id');
        $targetId    = $targetIdRaw !== null && $targetIdRaw !== '' ? absint($targetIdRaw) : null;

        $settings = new RedirectSettings(
            enabled:  (bool) $request->get_param('enabled'),
            type:     $type,
            targetId: $targetId !== null && $targetId > 0 ? $targetId : null,
            url:      esc_url_raw((string) ($request->get_param('url') ?? '')),
            postType: sanitize_key((string) ($request->get_param('post_type') ?? '')),
        );

        $this->store->save($productId, $settings);

        $data = $settings->toArray();

        if ($settings->targetId !== null) {
            $data['target_label']     = get_the_title($settings->targetId) ?: '';
            $data['target_permalink'] = get_permalink($settings->targetId) ?: '';
        } else {
            $data['target_label']     = '';
            $data['target_permalink'] = '';
        }

        return new \WP_REST_Response($data, 200);
    }
}
