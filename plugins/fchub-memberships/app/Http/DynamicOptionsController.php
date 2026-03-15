<?php

namespace FChubMemberships\Http;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Plan\PlanService;

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
        $provider = $request->get_param('provider') ?: 'wordpress_core';

        $adapters = [
            'wordpress_core'   => \FChubMemberships\Adapters\WordPressContentAdapter::class,
            'learndash'        => \FChubMemberships\Adapters\LearnDashAdapter::class,
            'fluentcrm'        => \FChubMemberships\Adapters\FluentCrmAdapter::class,
            'fluent_community' => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
        ];

        $class = $adapters[$provider] ?? null;
        if (!$class || !class_exists($class)) {
            return new \WP_REST_Response(['data' => []]);
        }

        $adapter = new $class();
        $types = $adapter->getResourceTypes();

        $options = [];
        foreach ($types as $key => $label) {
            $options[] = ['value' => $key, 'label' => $label];
        }

        return new \WP_REST_Response(['data' => $options]);
    }

    public static function providers(\WP_REST_Request $request): \WP_REST_Response
    {
        $providers = [
            ['value' => 'wordpress_core', 'label' => __('WordPress Core', 'fchub-memberships')],
        ];

        if (defined('LEARNDASH_VERSION')) {
            $providers[] = ['value' => 'learndash', 'label' => __('LearnDash', 'fchub-memberships')];
        }

        if (defined('FLUENTCRM')) {
            $providers[] = ['value' => 'fluentcrm', 'label' => __('FluentCRM', 'fchub-memberships')];
        }

        if (defined('FLUENT_COMMUNITY_PLUGIN_VERSION')) {
            $providers[] = ['value' => 'fluent_community', 'label' => __('FluentCommunity', 'fchub-memberships')];
        }

        return new \WP_REST_Response(['data' => $providers]);
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
