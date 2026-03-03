<?php

declare(strict_types=1);

namespace FChubWishlist\Http\Controllers\Admin;

use FChubWishlist\Http\Requests\SettingsRequest;
use FChubWishlist\Support\Constants;

defined('ABSPATH') || exit;

final class SettingsController
{
    public static function get(\WP_REST_Request $request): \WP_REST_Response
    {
        return new \WP_REST_Response([
            'success' => true,
            'data'    => self::getSettings(),
        ]);
    }

    public static function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();

        if (!is_array($data) || empty($data)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => __('No settings provided.', 'fchub-wishlist'),
            ], 422);
        }

        $sanitised = SettingsRequest::validate($data);
        $settings = self::getSettings();

        foreach ($sanitised as $key => $value) {
            $settings[$key] = $value;
        }

        update_option(Constants::OPTION_SETTINGS, $settings);

        return new \WP_REST_Response([
            'success' => true,
            'data'    => $settings,
            'message' => __('Settings saved.', 'fchub-wishlist'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSettings(): array
    {
        $saved = get_option(Constants::OPTION_SETTINGS, []);
        return wp_parse_args(is_array($saved) ? $saved : [], Constants::DEFAULT_SETTINGS);
    }
}
