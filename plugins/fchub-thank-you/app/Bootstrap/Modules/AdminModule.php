<?php

declare(strict_types=1);

namespace FchubThankYou\Bootstrap\Modules;

use FchubThankYou\Bootstrap\ModuleContract;
use FchubThankYou\Support\Assets;
use FchubThankYou\Support\Constants;

final class AdminModule implements ModuleContract
{
    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'maybeEnqueueAssets']);
    }

    public function maybeEnqueueAssets(): void
    {
        if (($_GET['page'] ?? '') !== 'fluent-cart') {
            return;
        }

        wp_enqueue_script(
            Constants::ADMIN_SCRIPT_HANDLE,
            Assets::adminScriptUrl(),
            [Constants::FC_HOOKS_HANDLE],
            Assets::version(),
            true,
        );

        wp_localize_script(Constants::ADMIN_SCRIPT_HANDLE, 'fchubThankYouData', [
            'restUrl' => rest_url(Constants::REST_NAMESPACE . '/'),
            'nonce'   => wp_create_nonce('wp_rest'),
        ]);
    }
}
