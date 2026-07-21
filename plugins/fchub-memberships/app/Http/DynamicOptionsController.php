<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Support\ResourceTypeRegistry;

class DynamicOptionsController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/resource-types', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'resourceTypes'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/providers', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'providers'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fluentcrm-tags', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fluentcrmTags'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fluentcrm-lists', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fluentcrmLists'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fc-spaces', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fcSpaces'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/fc-badges', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'fcBadges'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function planOptions(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        return new \WP_REST_Response(['data' => $service->getOptions()]);
    }

    public static function resourceTypes(\WP_REST_Request $request): \WP_REST_Response
    {
        $provider = (string) ($request->get_param('provider') ?: 'wordpress_core');
        $options = [];

        foreach (ResourceTypeRegistry::getInstance()->getAll() as $type) {
            if (($type['provider'] ?? '') !== $provider) {
                continue;
            }

            $options[] = ['value' => $type['key'], 'label' => $type['label']];
        }

        return new \WP_REST_Response(['data' => $options]);
    }

    public static function providers(\WP_REST_Request $request): \WP_REST_Response
    {
        $labels = [
            'wordpress_core' => __('WordPress Core', 'fchub-memberships'),
            'learndash' => __('LearnDash', 'fchub-memberships'),
            'fluentcrm' => __('FluentCRM', 'fchub-memberships'),
            'fluent_community' => __('FluentCommunity', 'fchub-memberships'),
        ];
        $providers = [];

        foreach (ResourceTypeRegistry::getInstance()->getAll() as $type) {
            $provider = $type['provider'] ?? '';
            if (!isset($labels[$provider]) || isset($providers[$provider])) {
                continue;
            }

            $providers[$provider] = ['value' => $provider, 'label' => $labels[$provider]];
        }

        return new \WP_REST_Response(['data' => array_values($providers)]);
    }

    public static function fluentcrmTags(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
            return new \WP_REST_Response(['data' => []]);
        }

        $search = $request->get_param('search') ?: '';
        $adapter = new \FChubMemberships\Adapters\FluentCrmAdapter();
        $tags = $adapter->searchResources($search, 'fluentcrm_tag', 50);

        return new \WP_REST_Response(['data' => $tags]);
    }

    public static function fluentcrmLists(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!defined('FLUENTCRM') || !function_exists('FluentCrmApi')) {
            return new \WP_REST_Response(['data' => []]);
        }

        $search = $request->get_param('search') ?: '';
        $adapter = new \FChubMemberships\Adapters\FluentCrmAdapter();
        $lists = $adapter->searchResources($search, 'fluentcrm_list', 50);

        return new \WP_REST_Response(['data' => $lists]);
    }

    public static function fcSpaces(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            return new \WP_REST_Response(['data' => []]);
        }

        $search = $request->get_param('search') ?: '';
        $adapter = new \FChubMemberships\Adapters\FluentCommunityAdapter();
        $spaces = $adapter->searchResources($search, 'fc_space', 50);

        return new \WP_REST_Response(['data' => $spaces]);
    }

    public static function fcBadges(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!defined('FLUENT_COMMUNITY_PLUGIN_VERSION') || !class_exists('FluentCommunity\App\Models\Badge')) {
            return new \WP_REST_Response(['data' => []]);
        }

        $search = $request->get_param('search') ?: '';
        $builder = \FluentCommunity\App\Models\Badge::query();

        if ($search !== '') {
            $builder->where('title', 'LIKE', '%' . $search . '%');
        }

        $badges = $builder->limit(50)->get();
        $results = [];

        foreach ($badges as $badge) {
            $results[] = [
                'id'    => (string) $badge->id,
                'label' => $badge->title,
            ];
        }

        return new \WP_REST_Response(['data' => $results]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
