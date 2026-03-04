<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Http\Controllers\Admin;

use FChubMultiCurrency\Storage\OptionStore;

defined('ABSPATH') || exit;

final class SettingsAdminController
{
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $optionStore = new OptionStore();

        return new \WP_REST_Response([
            'data' => [
                'settings' => $optionStore->all(),
            ],
        ]);
    }

    public function save(\WP_REST_Request $request): \WP_REST_Response
    {
        $params = $request->get_json_params();
        $optionStore = new OptionStore();

        $allowedKeys = array_keys(\FChubMultiCurrency\Support\Constants::DEFAULT_SETTINGS);
        $sanitized = [];

        foreach ($allowedKeys as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }

            $sanitized[$key] = match (true) {
                is_array($params[$key]) => $params[$key],
                is_int($params[$key])   => $params[$key],
                default                 => sanitize_text_field((string) $params[$key]),
            };
        }

        $optionStore->save($sanitized);

        return new \WP_REST_Response([
            'data' => [
                'message'  => 'Settings saved successfully.',
                'settings' => $optionStore->all(),
            ],
        ]);
    }
}
