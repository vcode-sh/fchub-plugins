<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Adapters\Contracts\AccessAdapterInterface;
use FChubMemberships\Adapters\Contracts\BatchResourceLabelAdapterInterface;
use FChubMemberships\Storage\ProtectionRuleRepository;
use FChubMemberships\Storage\PlanRepository;
use FChubMemberships\Domain\AccessEvaluator;
use FChubMemberships\Support\Constants;
use FChubMemberships\Support\ResourceTypeRegistry;

class ContentController
{
    private const MAX_SCALAR_LABEL_LOOKUPS = 100;
    private const MAX_BATCH_LABEL_IDS = 100;
    private const MAX_TITLE_SEARCH_CANDIDATES = 1000;

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
        try {
            return self::indexResponse($request);
        } catch (\UnexpectedValueException) {
            return new \WP_REST_Response([
                'code' => 'fchub_content_label_provider_unavailable',
                'message' => __('Provider resource labels could not be loaded. Please retry.', 'fchub-memberships'),
            ], 503);
        } catch (\RuntimeException) {
            return new \WP_REST_Response([
                'code' => 'fchub_content_storage_unavailable',
                'message' => __('Membership content could not be loaded. Please retry.', 'fchub-memberships'),
            ], 503);
        }
    }

    private static function indexResponse(\WP_REST_Request $request): \WP_REST_Response
    {
        $repo = new ProtectionRuleRepository();
        $filters = [
            'resource_type'   => $request->get_param('resource_type'),
            'protection_mode' => $request->get_param('protection_mode'),
            'plan_id'         => $request->get_param('plan_id'),
        ];
        $page = max(1, (int) ($request->get_param('page') ?: 1));
        $perPage = min(100, max(1, (int) ($request->get_param('per_page') ?: 20)));
        $searchTerm = trim((string) ($request->get_param('search') ?? ''));
        $registry = ResourceTypeRegistry::getInstance();

        if ($searchTerm !== '') {
            $matchedRules = [];
            $candidateRules = $repo->all(array_merge($filters, [
                'page' => 1,
                'per_page' => self::MAX_TITLE_SEARCH_CANDIDATES + 1,
            ]));
            if (count($candidateRules) > self::MAX_TITLE_SEARCH_CANDIDATES) {
                return new \WP_REST_Response([
                    'code' => 'fchub_content_search_too_broad',
                    'message' => __('Content search exceeds 1,000 candidates. Add a filter and retry.', 'fchub-memberships'),
                ], 422);
            }
            if (self::exceedsScalarLabelLookupLimit($candidateRules, $registry)) {
                return new \WP_REST_Response([
                    'code' => 'fchub_content_search_too_broad',
                    'message' => __(
                        'Content search exceeds 100 candidates for a provider without batch label support. '
                            . 'Add a filter and retry.',
                        'fchub-memberships'
                    ),
                ], 422);
            }
            $searchableRules = self::enrichResourceMetadataBatch($candidateRules, $registry, true);
            foreach ($searchableRules as $rule) {
                if (stripos($rule['resource_title'], $searchTerm) !== false) {
                    $matchedRules[] = $rule;
                }
            }

            $total = count($matchedRules);
            $rules = array_slice($matchedRules, ($page - 1) * $perPage, $perPage);
        } else {
            $rules = self::enrichResourceMetadataBatch($repo->all(array_merge($filters, [
                'page' => $page,
                'per_page' => $perPage,
            ])), $registry);
            $total = $repo->count($filters);
        }

        $planIds = [];
        $resources = [];
        foreach ($rules as $key => $rule) {
            array_push($planIds, ...array_map('intval', $rule['plan_ids'] ?? []));
            $typeConfig = $registry->getForRead((string) $rule['resource_type']);
            $resources[$key] = [
                'provider' => (string) ($typeConfig['provider'] ?? Constants::PROVIDER_WORDPRESS_CORE),
                'resource_type' => (string) $rule['resource_type'],
                'resource_id' => (string) $rule['resource_id'],
            ];
        }

        $plans = (new PlanRepository())->findMany($planIds);
        $memberCounts = $resources !== []
            ? (new AccessEvaluator())->countDistinctUsersWithResourceAccessBatch($resources)
            : [];

        foreach ($rules as $key => &$rule) {
            $rule['member_count'] = (int) ($memberCounts[$key] ?? 0);
            $rule['plan_names'] = [];
            foreach ($rule['plan_ids'] ?? [] as $planId) {
                $planId = (int) $planId;
                if (isset($plans[$planId])) {
                    $rule['plan_names'][] = $plans[$planId]['title'];
                }
            }
        }
        unset($rule);

        return new \WP_REST_Response([
            'data'    => $rules,
            'total'   => $total,
            'summary' => $repo->summary(),
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

        $class = $typeConfig['adapter'] ?? null;
        if (!is_string($class) || !class_exists($class)) {
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

    /** @param array<string, mixed> $rule */
    private static function enrichResourceMetadata(array $rule, ResourceTypeRegistry $registry): array
    {
        $type = (string) $rule['resource_type'];
        $id = (string) $rule['resource_id'];
        $typeConfig = $registry->getForRead($type);
        $rule['resource_title'] = self::getResourceTitle($type, $id, $typeConfig);
        $rule['edit_url'] = self::getEditUrl($type, $id);
        $rule['resource_type_label'] = $typeConfig ? $typeConfig['label'] : $type;
        $rule['resource_type_group'] = $typeConfig ? $typeConfig['group'] : 'advanced';

        return $rule;
    }

    /**
     * Resolve candidate labels once per known resource type before title matching.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    private static function enrichResourceMetadataBatch(
        array $rules,
        ResourceTypeRegistry $registry,
        bool $strictProviderLabels = false
    ): array {
        $groups = [];
        foreach ($rules as $key => $rule) {
            $type = (string) $rule['resource_type'];
            $groups[$type]['keys'][] = $key;
            $groups[$type]['ids'][] = (string) $rule['resource_id'];
        }

        foreach ($groups as $type => $group) {
            $typeConfig = $registry->getForRead($type);
            $labels = self::batchLabelsForType(
                $type,
                array_values(array_unique($group['ids'])),
                $typeConfig,
                $registry,
                $strictProviderLabels
            );
            foreach ($group['keys'] as $key) {
                $id = (string) $rules[$key]['resource_id'];
                $rules[$key]['resource_title'] = $labels[$id]
                    ?? self::fallbackResourceTitle($type, $id, $typeConfig);
                $rules[$key]['edit_url'] = self::getEditUrl($type, $id);
                $rules[$key]['resource_type_label'] = $typeConfig ? $typeConfig['label'] : $type;
                $rules[$key]['resource_type_group'] = $typeConfig ? $typeConfig['group'] : 'advanced';
            }
        }

        return $rules;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private static function exceedsScalarLabelLookupLimit(
        array $rules,
        ResourceTypeRegistry $registry
    ): bool {
        $resourceIdsByType = [];
        foreach ($rules as $rule) {
            $type = (string) $rule['resource_type'];
            $resourceId = (string) $rule['resource_id'];
            if ($resourceId !== '*') {
                $resourceIdsByType[$type][$resourceId] = true;
            }
        }

        foreach ($resourceIdsByType as $type => $resourceIds) {
            $adapterClass = $registry->getForRead($type)['adapter'] ?? null;
            if (!is_string($adapterClass) || !class_exists($adapterClass)) {
                continue;
            }
            if (is_a($adapterClass, AccessAdapterInterface::class, true)
                && !is_a($adapterClass, BatchResourceLabelAdapterInterface::class, true)
                && count($resourceIds) > self::MAX_SCALAR_LABEL_LOOKUPS
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string[] $resourceIds
     * @param array<string, mixed>|null $typeConfig
     * @return array<string, string>
     */
    private static function batchLabelsForType(
        string $type,
        array $resourceIds,
        ?array $typeConfig,
        ResourceTypeRegistry $registry,
        bool $strictProviderLabels
    ): array {
        if ($type === 'special_page') {
            $specialPages = \FChubMemberships\Domain\SpecialPageProtection::getSpecialPageTypes();
            return array_combine($resourceIds, array_map(
                static fn(string $id): string => (string) ($specialPages[$id] ?? $id),
                $resourceIds
            )) ?: [];
        }
        if ($type === 'url_pattern') {
            return array_combine($resourceIds, $resourceIds) ?: [];
        }

        $adapterClass = $typeConfig['adapter'] ?? null;
        if (!is_string($adapterClass) || !class_exists($adapterClass)) {
            return [];
        }

        try {
            $adapter = new $adapterClass();
            $readType = $type === 'comment' ? 'post' : $registry->resolveReadType($type);
            $lookupIds = array_values(array_filter(
                $resourceIds,
                static fn(string $id): bool => $id !== '*'
            ));
            if ($adapter instanceof BatchResourceLabelAdapterInterface) {
                $labels = [];
                foreach (array_chunk($lookupIds, self::MAX_BATCH_LABEL_IDS) as $chunk) {
                    $labels = array_replace($labels, $adapter->getResourceLabels($readType, $chunk));
                }
            } elseif ($adapter instanceof AccessAdapterInterface) {
                $labels = [];
                foreach (array_slice($lookupIds, 0, self::MAX_SCALAR_LABEL_LOOKUPS) as $id) {
                    try {
                        $labels[$id] = $adapter->getResourceLabel($readType, $id);
                    } catch (\Throwable $exception) {
                        if ($strictProviderLabels) {
                            throw $exception;
                        }
                        continue;
                    }
                }
            } else {
                return [];
            }
            if ($type === 'comment') {
                $labels = array_map(
                    static fn(string $label): string => sprintf(
                        __('Comments on: %s', 'fchub-memberships'),
                        $label
                    ),
                    $labels
                );
                if (in_array('*', $resourceIds, true)) {
                    $labels['*'] = __('All Protected Content Comments', 'fchub-memberships');
                }
            }

            return $labels;
        } catch (\Throwable $exception) {
            if ($strictProviderLabels) {
                throw new \UnexpectedValueException(
                    'Unable to load provider resource labels.',
                    0,
                    $exception
                );
            }
            return [];
        }
    }

    /** @param array<string, mixed>|null $typeConfig */
    private static function fallbackResourceTitle(string $type, string $id, ?array $typeConfig): string
    {
        if ($type === 'comment' && $id === '*') {
            return __('All Protected Content Comments', 'fchub-memberships');
        }
        if ($type === 'url_pattern' || $type === 'special_page') {
            return $id;
        }
        if ($typeConfig) {
            return $typeConfig['label'] . ' #' . $id;
        }

        return $type . ' #' . $id;
    }

    /** @param array<string, mixed>|null $typeConfig */
    private static function getResourceTitle(string $type, string $id, ?array $typeConfig = null): string
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

        $registry = ResourceTypeRegistry::getInstance();
        $typeConfig ??= $registry->getForRead($type);
        if (($typeConfig['provider'] ?? Constants::PROVIDER_WORDPRESS_CORE) !== Constants::PROVIDER_WORDPRESS_CORE) {
            $adapterClass = $typeConfig['adapter'] ?? null;
            if (is_string($adapterClass)
                && class_exists($adapterClass)
                && is_a($adapterClass, AccessAdapterInterface::class, true)
            ) {
                try {
                    $adapter = new $adapterClass();
                    return $adapter->getResourceLabel($registry->resolveReadType($type), $id);
                } catch (\Throwable) {
                    // Keep the persisted rule readable when its provider is unavailable.
                }
            }
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
