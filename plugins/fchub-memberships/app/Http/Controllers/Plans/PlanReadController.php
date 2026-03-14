<?php

namespace FChubMemberships\Http\Controllers\Plans;

use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Support\AdminRequestFilters;
use FChubMemberships\Support\Constants;
use FChubMemberships\Support\ResourceTypeRegistry;

defined('ABSPATH') || exit;

final class PlanReadController
{
    public static function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $filters = AdminRequestFilters::planList($request);

        return new \WP_REST_Response([
            'data'  => $service->list($filters),
            'total' => $service->count($filters),
        ]);
    }

    public static function show(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $plan = $service->getFullPlan((int) $request->get_param('id'));

        if (isset($plan['error'])) {
            return new \WP_REST_Response(['message' => $plan['error']], 404);
        }

        $registry = ResourceTypeRegistry::getInstance();
        foreach ($plan['rules'] as &$rule) {
            $rule['resource_label'] = self::resolveResourceName(
                $rule['resource_type'],
                $rule['resource_id'],
                $registry
            );
            $typeConfig = $registry->get($rule['resource_type']);
            $rule['resource_type_label'] = $typeConfig ? $typeConfig['label'] : $rule['resource_type'];
        }

        return new \WP_REST_Response(['data' => $plan]);
    }

    public static function options(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        return new \WP_REST_Response(['data' => $service->getOptions()]);
    }

    public static function dripSchedule(\WP_REST_Request $request): \WP_REST_Response
    {
        $evaluator = new DripEvaluator();
        $schedule = $evaluator->getPlanDripSchedule((int) $request->get_param('id'));
        return new \WP_REST_Response(['data' => $schedule]);
    }

    public static function resolveResources(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $resources = $data['resources'] ?? [];

        if (empty($resources) || !is_array($resources)) {
            return new \WP_REST_Response(['data' => []]);
        }

        $registry = ResourceTypeRegistry::getInstance();
        $resolved = [];

        foreach ($resources as $item) {
            $type = sanitize_text_field($item['resource_type'] ?? '');
            $id = sanitize_text_field($item['resource_id'] ?? '');

            if ($type === '') {
                continue;
            }

            $key = $type . ':' . $id;
            $resolved[$key] = self::resolveResourceName($type, $id, $registry);
        }

        return new \WP_REST_Response(['data' => $resolved]);
    }

    private static function resolveResourceName(string $type, string $id, ResourceTypeRegistry $registry): string
    {
        $typeConfig = $registry->get($type);
        $typeLabel = $typeConfig ? $typeConfig['label'] : ucfirst($type);

        if ($id === '*' || $id === '0' || $id === '') {
            return sprintf(__('All %s', 'fchub-memberships'), $typeLabel);
        }

        if ($type === 'special_page') {
            $specialPages = [
                'blog'       => __('Blog / Posts Page', 'fchub-memberships'),
                'front_page' => __('Front Page', 'fchub-memberships'),
                'search'     => __('Search Results', 'fchub-memberships'),
                '404'        => __('404 Page', 'fchub-memberships'),
                'author'     => __('Author Archives', 'fchub-memberships'),
                'date'       => __('Date Archives', 'fchub-memberships'),
            ];

            return $specialPages[$id] ?? $id;
        }

        if ($type === 'url_pattern') {
            return $id;
        }

        if ($type === 'comment') {
            $title = get_the_title((int) $id);
            return $title ? sprintf(__('Comments on: %s', 'fchub-memberships'), $title) : __('(Deleted)', 'fchub-memberships');
        }

        if ($type === 'menu_item') {
            $menuItem = get_post((int) $id);
            if ($menuItem) {
                $navItem = wp_setup_nav_menu_item($menuItem);
                return $navItem->title ?? $menuItem->post_title ?: __('(Deleted)', 'fchub-memberships');
            }

            return __('(Deleted)', 'fchub-memberships');
        }

        if (in_array($type, ['post', 'page'], true) || post_type_exists($type)) {
            $title = get_the_title((int) $id);
            return $title ?: __('(Deleted)', 'fchub-memberships');
        }

        if (taxonomy_exists($type)) {
            $term = get_term((int) $id);
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }

            return __('(Deleted)', 'fchub-memberships');
        }

        if (in_array($type, ['fc_space', 'fc_course'], true)) {
            $adapters = [
                Constants::PROVIDER_FLUENT_COMMUNITY => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
            ];
            $provider = $typeConfig['provider'] ?? Constants::PROVIDER_WORDPRESS_CORE;
            $class = $adapters[$provider] ?? null;
            if ($class && class_exists($class) && method_exists($class, 'getResourceTitle')) {
                return (new $class())->getResourceTitle($type, $id);
            }
        }

        return $typeLabel . ' #' . $id;
    }
}
