<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Domain\Plan\PlanRuleResolver;
use FChubMemberships\Support\Constants;
use FChubMemberships\Support\ResourceTypeRegistry;

class ContentController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/content', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'index'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/content/protect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'protect'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/content/unprotect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'unprotect'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/content/(?P<id>\d+)', [
            [
                'methods'             => 'PUT,PATCH',
                'callback'            => [self::class, 'update'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [self::class, 'destroy'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
        ]);

        register_rest_route($ns, '/admin/content/search-resources', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'searchResources'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/content/resource-types', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'resourceTypes'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/content/bulk-protect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkProtect'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/content/bulk-unprotect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'bulkUnprotect'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new ProtectionRuleRepository();
        $grantRepo = new GrantRepository();
        $ruleResolver = new PlanRuleResolver();

        $filters = [
            'resource_type'   => $request->get_param('resource_type'),
            'protection_mode' => $request->get_param('protection_mode'),
            'plan_id'         => $request->get_param('plan_id'),
            'search'          => $request->get_param('search'),
            'per_page'        => $request->get_param('per_page') ?: 20,
            'page'            => $request->get_param('page') ?: 1,
        ];

        // If searching by title, we need to fetch all rules and filter post-hydration
        $searchTerm = $filters['search'] ?? '';
        if (!empty($searchTerm)) {
            unset($filters['search']); // Don't search by resource_id in SQL
        }

        $rules = $repo->all($filters);

        $registry = ResourceTypeRegistry::getInstance();

        // Enrich with resource titles and member counts
        foreach ($rules as &$rule) {
            $rule['resource_title'] = self::getResourceTitle($rule['resource_type'], $rule['resource_id']);
            $rule['edit_url'] = self::getEditUrl($rule['resource_type'], $rule['resource_id']);

            // Add type label from registry
            $typeConfig = $registry->get($rule['resource_type']);
            $rule['resource_type_label'] = $typeConfig ? $typeConfig['label'] : $rule['resource_type'];
            $rule['resource_type_group'] = $typeConfig ? $typeConfig['group'] : 'advanced';

            // Count active members with access to this resource
            $planIds = $ruleResolver->findPlansWithResource(Constants::PROVIDER_WORDPRESS_CORE, $rule['resource_type'], $rule['resource_id']);
            $memberCount = 0;
            foreach ($planIds as $planId) {
                $memberCount += $grantRepo->countActiveMembers($planId);
            }
            $rule['member_count'] = $memberCount;

            // Get plan names
            $planRepo = new \FChubMemberships\Storage\PlanRepository();
            $rule['plan_names'] = [];
            foreach ($rule['plan_ids'] ?? [] as $planId) {
                $plan = $planRepo->find((int) $planId);
                if ($plan) {
                    $rule['plan_names'][] = $plan['title'];
                }
            }
        }

        // Filter by title search term (post-hydration)
        if (!empty($searchTerm)) {
            $rules = array_values(array_filter($rules, function ($rule) use ($searchTerm) {
                return stripos($rule['resource_title'], $searchTerm) !== false;
            }));
            $total = count($rules);
        } else {
            $total = $repo->count($filters);
        }

        return new \WP_REST_Response([
            'data'  => $rules,
            'total' => $total,
        ]);
    }

    public static function protect(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $resourceType = sanitize_text_field($data['resource_type'] ?? '');
        $resourceId = sanitize_text_field($data['resource_id'] ?? '');

        if (empty($resourceType) || empty($resourceId)) {
            return new \WP_REST_Response(['message' => __('Resource type and ID are required.', 'fchub-memberships')], 422);
        }

        $registry = ResourceTypeRegistry::getInstance();
        if (!$registry->isValid($resourceType)) {
            return new \WP_REST_Response(['message' => __('Invalid resource type.', 'fchub-memberships')], 422);
        }

        $repo = new ProtectionRuleRepository();
        $id = $repo->createOrUpdate($resourceType, $resourceId, [
            'plan_ids'            => array_map('intval', $data['plan_ids'] ?? []),
            'protection_mode'     => sanitize_text_field($data['protection_mode'] ?? Constants::PROTECTION_MODE_EXPLICIT),
            'restriction_message' => sanitize_textarea_field($data['restriction_message'] ?? ''),
            'redirect_url'        => esc_url_raw($data['redirect_url'] ?? ''),
            'show_teaser'         => ($data['show_teaser'] ?? 'no') === 'yes' ? 'yes' : 'no',
        ]);

        return new \WP_REST_Response([
            'data'    => $repo->find($id),
            'message' => __('Content protected.', 'fchub-memberships'),
        ], 201);
    }

    public static function unprotect(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $resourceType = sanitize_text_field($data['resource_type'] ?? '');
        $resourceId = sanitize_text_field($data['resource_id'] ?? '');

        $repo = new ProtectionRuleRepository();
        $rule = $repo->findByResource($resourceType, $resourceId);

        if ($rule) {
            $repo->delete($rule['id']);
        }

        return new \WP_REST_Response(['message' => __('Protection removed.', 'fchub-memberships')]);
    }

    public static function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        $repo = new ProtectionRuleRepository();
        $updateData = [];

        if (isset($data['plan_ids'])) {
            $updateData['plan_ids'] = array_map('intval', $data['plan_ids']);
        }
        if (isset($data['protection_mode'])) {
            $updateData['protection_mode'] = sanitize_text_field($data['protection_mode']);
        }
        if (isset($data['restriction_message'])) {
            $updateData['restriction_message'] = sanitize_textarea_field($data['restriction_message']);
        }
        if (isset($data['redirect_url'])) {
            $updateData['redirect_url'] = esc_url_raw($data['redirect_url']);
        }
        if (isset($data['show_teaser'])) {
            $updateData['show_teaser'] = $data['show_teaser'] === 'yes' ? 'yes' : 'no';
        }

        $repo->update($id, $updateData);

        return new \WP_REST_Response(['data' => $repo->find($id)]);
    }

    public static function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new ProtectionRuleRepository();
        $repo->delete((int) $request->get_param('id'));
        return new \WP_REST_Response(['message' => __('Protection rule deleted.', 'fchub-memberships')]);
    }

    public static function searchResources(\WP_REST_Request $request): \WP_REST_Response
    {
        $query = sanitize_text_field($request->get_param('query') ?? '');
        $type = sanitize_text_field($request->get_param('type') ?? 'post');

        $registry = ResourceTypeRegistry::getInstance();
        $typeConfig = $registry->get($type);

        // Handle special non-searchable types
        if ($type === 'special_page') {
            return new \WP_REST_Response(['data' => self::getSpecialPages()]);
        }

        if ($type === 'url_pattern') {
            // Return existing URL pattern rules
            $repo = new ProtectionRuleRepository();
            $rules = $repo->all(['resource_type' => 'url_pattern']);
            $results = array_map(fn($r) => [
                'id'    => $r['resource_id'],
                'label' => $r['resource_id'],
                'type'  => 'url_pattern',
            ], $rules);
            return new \WP_REST_Response(['data' => $results]);
        }

        if ($type === 'menu_item') {
            return new \WP_REST_Response(['data' => self::searchMenuItems($query)]);
        }

        if (!$typeConfig || !$typeConfig['searchable']) {
            return new \WP_REST_Response(['data' => []]);
        }

        $provider = $typeConfig['provider'];

        $adapters = [
            Constants::PROVIDER_WORDPRESS_CORE    => \FChubMemberships\Adapters\WordPressContentAdapter::class,
            Constants::PROVIDER_LEARNDASH         => \FChubMemberships\Adapters\LearnDashAdapter::class,
            Constants::PROVIDER_FLUENT_COMMUNITY  => \FChubMemberships\Adapters\FluentCommunityAdapter::class,
        ];

        $class = $adapters[$provider] ?? null;
        if (!$class || !class_exists($class)) {
            return new \WP_REST_Response(['data' => []]);
        }

        $adapter = new $class();
        $results = $adapter->searchResources($query, $type, 20);

        // Add type label for UI grouping
        $typeLabel = $typeConfig['label'] ?? $type;
        foreach ($results as &$result) {
            $result['type'] = $type;
            $result['type_label'] = $typeLabel;
        }

        return new \WP_REST_Response(['data' => $results]);
    }

    /**
     * Bulk protect multiple resources.
     */
    public static function bulkProtect(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $resourceIds = $data['resource_ids'] ?? [];
        $resourceType = sanitize_text_field($data['resource_type'] ?? '');
        $planIds = array_map('intval', $data['plan_ids'] ?? []);

        if (empty($resourceIds) || empty($resourceType)) {
            return new \WP_REST_Response(['message' => __('Resource IDs and type are required.', 'fchub-memberships')], 422);
        }

        $registry = ResourceTypeRegistry::getInstance();
        if (!$registry->isValid($resourceType)) {
            return new \WP_REST_Response(['message' => __('Invalid resource type.', 'fchub-memberships')], 422);
        }

        $repo = new ProtectionRuleRepository();
        $protected = 0;

        foreach ($resourceIds as $resourceId) {
            $resourceId = sanitize_text_field((string) $resourceId);
            $repo->createOrUpdate($resourceType, $resourceId, [
                'plan_ids'        => $planIds,
                'protection_mode' => Constants::PROTECTION_MODE_EXPLICIT,
                'show_teaser'     => 'no',
                'meta'            => ['teaser_mode' => 'none'],
            ]);
            $protected++;
        }

        \FChubMemberships\Domain\AccessEvaluator::clearCache();

        return new \WP_REST_Response([
            'message'   => sprintf(__('%d resources protected.', 'fchub-memberships'), $protected),
            'protected' => $protected,
        ]);
    }

    /**
     * Bulk unprotect multiple resources.
     */
    public static function bulkUnprotect(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        $resourceIds = $data['resource_ids'] ?? [];
        $resourceType = sanitize_text_field($data['resource_type'] ?? '');

        if (empty($resourceIds) || empty($resourceType)) {
            return new \WP_REST_Response(['message' => __('Resource IDs and type are required.', 'fchub-memberships')], 422);
        }

        $repo = new ProtectionRuleRepository();
        $unprotected = 0;

        foreach ($resourceIds as $resourceId) {
            $resourceId = sanitize_text_field((string) $resourceId);
            $rule = $repo->findByResource($resourceType, $resourceId);
            if ($rule) {
                $repo->delete($rule['id']);
                $unprotected++;
            }
        }

        \FChubMemberships\Domain\AccessEvaluator::clearCache();

        return new \WP_REST_Response([
            'message'     => sprintf(__('%d resources unprotected.', 'fchub-memberships'), $unprotected),
            'unprotected' => $unprotected,
        ]);
    }

    private static function getSpecialPages(): array
    {
        return [
            ['id' => 'blog', 'label' => __('Blog / Posts Page', 'fchub-memberships'), 'type' => 'special_page'],
            ['id' => 'front_page', 'label' => __('Front Page', 'fchub-memberships'), 'type' => 'special_page'],
            ['id' => 'search', 'label' => __('Search Results', 'fchub-memberships'), 'type' => 'special_page'],
            ['id' => '404', 'label' => __('404 Page', 'fchub-memberships'), 'type' => 'special_page'],
            ['id' => 'author', 'label' => __('Author Archives', 'fchub-memberships'), 'type' => 'special_page'],
            ['id' => 'date', 'label' => __('Date Archives', 'fchub-memberships'), 'type' => 'special_page'],
        ];
    }

    private static function searchMenuItems(string $query): array
    {
        $args = [
            'post_type'      => 'nav_menu_item',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ];

        if ($query !== '') {
            $args['s'] = $query;
        }

        $posts = get_posts($args);
        $results = [];

        foreach ($posts as $post) {
            $label = $post->post_title ?: wp_setup_nav_menu_item($post)->title ?? "#{$post->ID}";
            $results[] = [
                'id'    => (string) $post->ID,
                'label' => $label,
                'type'  => 'menu_item',
            ];
        }

        return $results;
    }

    private static function getResourceTitle(string $type, string $id): string
    {
        // Special page types
        if ($type === 'special_page') {
            $specialPages = \FChubMemberships\Domain\SpecialPageProtection::getSpecialPageTypes();
            return $specialPages[$id] ?? $id;
        }

        // URL patterns
        if ($type === 'url_pattern') {
            return $id;
        }

        // Comment protection (post-level or wildcard)
        if ($type === 'comment') {
            if ($id === '*') {
                return __('All Protected Content Comments', 'fchub-memberships');
            }
            $title = get_the_title((int) $id);
            return $title ? sprintf(__('Comments on: %s', 'fchub-memberships'), $title) : "#{$id}";
        }

        // Menu items
        if ($type === 'menu_item') {
            $menuItem = get_post((int) $id);
            if ($menuItem) {
                $navItem = wp_setup_nav_menu_item($menuItem);
                return $navItem->title ?? $menuItem->post_title ?: "#{$id}";
            }
            return "#{$id}";
        }

        // Post types (built-in and custom)
        if (in_array($type, ['post', 'page'], true) || post_type_exists($type)) {
            return get_the_title((int) $id) ?: "#{$id}";
        }

        // Taxonomies
        if (taxonomy_exists($type)) {
            $term = get_term((int) $id);
            return ($term && !is_wp_error($term)) ? $term->name : "#{$id}";
        }

        // Fallback: use registry label if available
        $registry = ResourceTypeRegistry::getInstance();
        $typeConfig = $registry->get($type);
        if ($typeConfig) {
            return $typeConfig['label'] . ' #' . $id;
        }

        return $type . ' #' . $id;
    }

    private static function getEditUrl(string $type, string $id): string
    {
        // Comment protection links to the parent post
        if ($type === 'comment' && $id !== '*') {
            return get_edit_post_link((int) $id, 'raw') ?: '';
        }

        // Post types (built-in and custom)
        if (in_array($type, ['post', 'page'], true) || post_type_exists($type)) {
            return get_edit_post_link((int) $id, 'raw') ?: '';
        }

        // Taxonomies
        if (taxonomy_exists($type)) {
            return get_edit_term_link((int) $id, $type) ?: '';
        }

        return '';
    }

    public static function resourceTypes(\WP_REST_Request $request): \WP_REST_Response
    {
        $registry = ResourceTypeRegistry::getInstance();

        $group = sanitize_text_field($request->get_param('group') ?? '');

        if ($group) {
            $types = $registry->getByGroup($group);
        } else {
            $types = $registry->getAll();
        }

        return new \WP_REST_Response([
            'data'         => array_values($types),
            'groups'       => $registry->getGroupLabels(),
            'select_options' => $registry->toSelectOptions(),
        ]);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
