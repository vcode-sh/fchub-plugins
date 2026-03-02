<?php

namespace FChubMemberships\Http\Controllers;

defined('ABSPATH') || exit;

use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Domain\Drip\DripEvaluator;
use FChubMemberships\Support\ResourceTypeRegistry;
use FChubMemberships\Support\Constants;

class PlanController
{
    public static function registerRoutes(): void
    {
        $ns = 'fchub-memberships/v1';

        register_rest_route($ns, '/admin/plans', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'index'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'store'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
        ]);

        register_rest_route($ns, '/admin/plans/options', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'options'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'show'],
                'permission_callback' => [self::class, 'adminPermission'],
            ],
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

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/duplicate', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'duplicate'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/drip-schedule', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'dripSchedule'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/linked-products', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'linkedProducts'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/link-product', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'linkProduct'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/unlink-product/(?P<feed_id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [self::class, 'unlinkProduct'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/search-products', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'searchProducts'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/resolve-resources', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'resolveResources'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/export', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'export'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/export-all', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'exportAll'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/import', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'import'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);

        register_rest_route($ns, '/admin/plans/(?P<id>\d+)/schedule', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'schedule'],
            'permission_callback' => [self::class, 'adminPermission'],
        ]);
    }

    public static function index(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $filters = [
            'status'   => $request->get_param('status'),
            'search'   => $request->get_param('search'),
            'per_page' => $request->get_param('per_page') ?: 20,
            'page'     => $request->get_param('page') ?: 1,
            'order_by' => $request->get_param('order_by') ?: 'level',
            'order'    => $request->get_param('order') ?: 'ASC',
        ];

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

        // Enrich rules with human-readable resource names
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

    public static function store(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();

        if (empty($data['title'])) {
            return new \WP_REST_Response(['message' => __('Plan title is required.', 'fchub-memberships')], 422);
        }

        // Validate duration fields
        $durationType = $data['duration_type'] ?? 'lifetime';
        if (!in_array($durationType, ['lifetime', 'fixed_days', 'subscription_mirror'], true)) {
            return new \WP_REST_Response(['message' => __('Invalid duration type.', 'fchub-memberships')], 422);
        }
        if ($durationType === 'fixed_days' && empty($data['duration_days'])) {
            return new \WP_REST_Response(['message' => __('Duration days is required for fixed duration plans.', 'fchub-memberships')], 422);
        }

        // Validate and prepare rules
        $rules = $data['rules'] ?? [];
        $validationError = self::validateRules($rules);
        if ($validationError) {
            return new \WP_REST_Response(['message' => $validationError], 422);
        }
        $rules = self::prepareRulesForStorage($rules);

        $service = new PlanService();
        $result = $service->create([
            'title'               => sanitize_text_field($data['title']),
            'slug'                => sanitize_title($data['slug'] ?? ''),
            'description'         => sanitize_textarea_field($data['description'] ?? ''),
            'status'              => in_array($data['status'] ?? '', ['active', 'inactive', 'archived'], true) ? $data['status'] : 'active',
            'level'               => (int) ($data['level'] ?? 0),
            'includes_plan_ids'   => array_map('intval', $data['includes_plan_ids'] ?? []),
            'restriction_message' => sanitize_textarea_field($data['restriction_message'] ?? ''),
            'redirect_url'        => esc_url_raw($data['redirect_url'] ?? ''),
            'settings'            => $data['settings'] ?? [],
            'meta'                => $data['meta'] ?? [],
            'rules'               => $rules,
            'duration_type'       => $durationType,
            'duration_days'       => isset($data['duration_days']) ? (int) $data['duration_days'] : null,
            'trial_days'          => (int) ($data['trial_days'] ?? 0),
            'grace_period_days'   => (int) ($data['grace_period_days'] ?? 0),
        ]);

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result], 201);
    }

    public static function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $data = $request->get_json_params();

        $service = new PlanService();
        $updateData = [];

        $textFields = ['title', 'description', 'restriction_message'];
        foreach ($textFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = sanitize_textarea_field($data[$field]);
            }
        }

        if (isset($data['slug'])) {
            $updateData['slug'] = sanitize_title($data['slug']);
        }
        if (isset($data['status'])) {
            $updateData['status'] = in_array($data['status'], ['active', 'inactive', 'archived'], true) ? $data['status'] : 'active';
        }
        if (isset($data['level'])) {
            $updateData['level'] = (int) $data['level'];
        }
        if (isset($data['includes_plan_ids'])) {
            $updateData['includes_plan_ids'] = array_map('intval', $data['includes_plan_ids']);
        }
        if (isset($data['redirect_url'])) {
            $updateData['redirect_url'] = esc_url_raw($data['redirect_url']);
        }
        if (isset($data['settings'])) {
            $updateData['settings'] = $data['settings'];
        }
        if (isset($data['meta'])) {
            $updateData['meta'] = $data['meta'];
        }
        if (isset($data['rules'])) {
            $validationError = self::validateRules($data['rules']);
            if ($validationError) {
                return new \WP_REST_Response(['message' => $validationError], 422);
            }
            $updateData['rules'] = self::prepareRulesForStorage($data['rules']);
        }
        if (isset($data['duration_type'])) {
            if (!in_array($data['duration_type'], ['lifetime', 'fixed_days', 'subscription_mirror'], true)) {
                return new \WP_REST_Response(['message' => __('Invalid duration type.', 'fchub-memberships')], 422);
            }
            $updateData['duration_type'] = $data['duration_type'];
        }
        $intUpdateFields = ['duration_days', 'trial_days', 'grace_period_days'];
        foreach ($intUpdateFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field] !== null ? (int) $data[$field] : null;
            }
        }

        $result = $service->update($id, $updateData);

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result]);
    }

    public static function destroy(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $service->delete((int) $request->get_param('id'));
        return new \WP_REST_Response(['message' => __('Plan deleted.', 'fchub-memberships')]);
    }

    public static function duplicate(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $result = $service->duplicate((int) $request->get_param('id'));

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result], 201);
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

    public static function linkedProducts(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $service = new PlanService();
        $planId = (int) $request->get_param('id');
        $plan = $service->find($planId);

        if (!$plan) {
            return new \WP_REST_Response(['message' => __('Plan not found.', 'fchub-memberships')], 404);
        }

        $feedsTable = $wpdb->prefix . 'fct_order_integration_feeds';
        $productsTable = $wpdb->prefix . 'fct_products';

        $feeds = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id AS feed_id, f.product_id, f.title AS feed_title, f.settings, f.enabled,
                    p.title AS product_title
             FROM {$feedsTable} f
             LEFT JOIN {$productsTable} p ON f.product_id = p.id
             WHERE f.integration_key = %s",
            'memberships'
        ), ARRAY_A);

        $linked = [];
        foreach ($feeds ?: [] as $feed) {
            $settings = json_decode($feed['settings'] ?? '{}', true) ?: [];
            // Match by plan_slug or plan_id
            $matchBySlug = ($settings['plan_slug'] ?? '') === $plan['slug'];
            $matchById = (int) ($settings['plan_id'] ?? 0) === $planId;
            if (!$matchBySlug && !$matchById) {
                continue;
            }

            $linked[] = [
                'feed_id'       => (int) $feed['feed_id'],
                'product_id'    => (int) $feed['product_id'],
                'product_title' => $feed['product_title'] ?? '',
                'feed_title'    => $feed['feed_title'] ?? '',
                'triggers'      => $settings['triggers'] ?? [],
                'status'        => !empty($feed['enabled']) ? 'active' : 'inactive',
            ];
        }

        // Enrich with pricing from FluentCart products
        $productIds = array_filter(array_column($linked, 'product_id'));
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
            $prices = $wpdb->get_results($wpdb->prepare(
                "SELECT id, price, billing_period, billing_interval FROM {$productsTable} WHERE id IN ({$placeholders})",
                ...$productIds
            ), ARRAY_A);
            $priceMap = [];
            foreach ($prices as $p) {
                $priceMap[(int) $p['id']] = $p;
            }
            foreach ($linked as &$item) {
                $pricing = $priceMap[$item['product_id']] ?? [];
                $item['price'] = $pricing['price'] ?? null;
                $item['billing_period'] = $pricing['billing_period'] ?? null;
                $item['billing_interval'] = $pricing['billing_interval'] ?? null;
            }
        }

        return new \WP_REST_Response(['data' => $linked]);
    }

    public static function linkProduct(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $service = new PlanService();
        $planId = (int) $request->get_param('id');
        $plan = $service->find($planId);

        if (!$plan) {
            return new \WP_REST_Response(['message' => __('Plan not found.', 'fchub-memberships')], 404);
        }

        $data = $request->get_json_params();
        $productId = (int) ($data['product_id'] ?? 0);

        if (!$productId) {
            return new \WP_REST_Response(['message' => __('Product ID is required.', 'fchub-memberships')], 422);
        }

        $productsTable = $wpdb->prefix . 'fct_products';
        $product = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title FROM {$productsTable} WHERE id = %d",
            $productId
        ), ARRAY_A);

        if (!$product) {
            return new \WP_REST_Response(['message' => __('Product not found.', 'fchub-memberships')], 404);
        }

        // Check if already linked
        $feedsTable = $wpdb->prefix . 'fct_order_integration_feeds';
        $existingFeeds = $wpdb->get_results($wpdb->prepare(
            "SELECT id, settings FROM {$feedsTable} WHERE product_id = %d AND integration_key = 'memberships'",
            $productId
        ), ARRAY_A);

        foreach ($existingFeeds ?: [] as $existing) {
            $existingSettings = json_decode($existing['settings'] ?? '{}', true) ?: [];
            if ((int) ($existingSettings['plan_id'] ?? 0) === $planId ||
                ($existingSettings['plan_slug'] ?? '') === $plan['slug']) {
                return new \WP_REST_Response(['message' => __('This product is already linked to this plan.', 'fchub-memberships')], 422);
            }
        }

        // Create integration feed
        $feedSettings = wp_json_encode([
            'name'                => sprintf('%s - %s', $plan['title'], $product['title']),
            'plan_id'             => $planId,
            'plan_slug'           => $plan['slug'],
            'validity_mode'       => $plan['duration_type'] === 'fixed_days' ? 'fixed_duration' : ($plan['duration_type'] === 'subscription_mirror' ? 'mirror_subscription' : 'lifetime'),
            'validity_days'       => $plan['duration_days'] ?? 0,
            'grace_period_days'   => $plan['grace_period_days'] ?? 0,
            'watch_on_access_revoke' => 'yes',
            'cancel_behavior'     => 'wait_validity',
            'auto_create_user'    => 'no',
            'event_trigger'       => ['order_paid_done'],
            'triggers'            => ['order_paid_done'],
        ]);

        $now = current_time('mysql');
        $wpdb->insert($feedsTable, [
            'product_id'      => $productId,
            'integration_key' => 'memberships',
            'title'           => sprintf('%s - %s', $plan['title'], $product['title']),
            'settings'        => $feedSettings,
            'enabled'         => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $feedId = (int) $wpdb->insert_id;

        return new \WP_REST_Response([
            'data'    => ['feed_id' => $feedId],
            'message' => __('Product linked successfully.', 'fchub-memberships'),
        ], 201);
    }

    public static function unlinkProduct(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $service = new PlanService();
        $planId = (int) $request->get_param('id');
        $feedId = (int) $request->get_param('feed_id');

        $plan = $service->find($planId);
        if (!$plan) {
            return new \WP_REST_Response(['message' => __('Plan not found.', 'fchub-memberships')], 404);
        }

        $feedsTable = $wpdb->prefix . 'fct_order_integration_feeds';
        $feed = $wpdb->get_row($wpdb->prepare(
            "SELECT id, settings FROM {$feedsTable} WHERE id = %d AND integration_key = 'memberships'",
            $feedId
        ), ARRAY_A);

        if (!$feed) {
            return new \WP_REST_Response(['message' => __('Integration feed not found.', 'fchub-memberships')], 404);
        }

        // Verify the feed belongs to this plan
        $settings = json_decode($feed['settings'] ?? '{}', true) ?: [];
        $matchBySlug = ($settings['plan_slug'] ?? '') === $plan['slug'];
        $matchById = (int) ($settings['plan_id'] ?? 0) === $planId;
        if (!$matchBySlug && !$matchById) {
            return new \WP_REST_Response(['message' => __('This feed does not belong to this plan.', 'fchub-memberships')], 422);
        }

        $wpdb->delete($feedsTable, ['id' => $feedId]);

        return new \WP_REST_Response(['message' => __('Product unlinked successfully.', 'fchub-memberships')]);
    }

    public static function searchProducts(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $search = sanitize_text_field($request->get_param('search') ?? '');
        $productsTable = $wpdb->prefix . 'fct_products';

        if ($search) {
            $products = $wpdb->get_results($wpdb->prepare(
                "SELECT id, title, price, billing_period, status FROM {$productsTable} WHERE title LIKE %s ORDER BY title ASC LIMIT 20",
                '%' . $wpdb->esc_like($search) . '%'
            ), ARRAY_A);
        } else {
            $products = $wpdb->get_results(
                "SELECT id, title, price, billing_period, status FROM {$productsTable} ORDER BY title ASC LIMIT 20",
                ARRAY_A
            );
        }

        return new \WP_REST_Response(['data' => $products ?: []]);
    }

    public static function export(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $plan = $service->getFullPlan((int) $request->get_param('id'));
        if (isset($plan['error'])) {
            return new \WP_REST_Response(['message' => $plan['error']], 404);
        }
        // Strip IDs for portability
        unset($plan['id'], $plan['created_at'], $plan['updated_at'], $plan['members_count']);
        foreach ($plan['rules'] as &$rule) {
            unset($rule['id'], $rule['plan_id'], $rule['created_at'], $rule['updated_at']);
        }
        return new \WP_REST_Response(['data' => $plan]);
    }

    public static function import(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = $request->get_json_params();
        if (empty($data['title'])) {
            return new \WP_REST_Response(['message' => __('Import data must include a plan title.', 'fchub-memberships')], 422);
        }
        $service = new PlanService();
        $data['slug'] = ''; // Force auto-generation
        $data['status'] = 'inactive'; // Import as draft
        $result = $service->create($data);
        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }
        return new \WP_REST_Response(['data' => $result, 'message' => __('Plan imported successfully.', 'fchub-memberships')], 201);
    }

    public static function exportAll(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $allPlans = $service->list(['per_page' => 9999]);
        $exported = [];

        foreach ($allPlans as $plan) {
            $full = $service->getFullPlan($plan['id']);
            if (isset($full['error'])) {
                continue;
            }
            unset($full['id'], $full['created_at'], $full['updated_at'], $full['members_count']);
            foreach ($full['rules'] as &$rule) {
                unset($rule['id'], $rule['plan_id'], $rule['created_at'], $rule['updated_at']);
            }
            $exported[] = $full;
        }

        return new \WP_REST_Response(['data' => $exported]);
    }

    public static function schedule(\WP_REST_Request $request): \WP_REST_Response
    {
        $service = new PlanService();
        $planId = (int) $request->get_param('id');
        $plan = $service->find($planId);

        if (!$plan) {
            return new \WP_REST_Response(['message' => __('Plan not found.', 'fchub-memberships')], 404);
        }

        $data = $request->get_json_params();
        $scheduledStatus = sanitize_text_field($data['scheduled_status'] ?? '');
        $scheduledAt = sanitize_text_field($data['scheduled_at'] ?? '');

        // Clear schedule
        if (empty($scheduledStatus) || empty($scheduledAt)) {
            $service->clearSchedule($planId);
            return new \WP_REST_Response(['data' => $service->find($planId), 'message' => __('Schedule cleared.', 'fchub-memberships')]);
        }

        if (!in_array($scheduledStatus, ['active', 'inactive', 'archived'], true)) {
            return new \WP_REST_Response(['message' => __('Invalid scheduled status.', 'fchub-memberships')], 422);
        }

        $result = $service->schedulePlanStatus($planId, $scheduledStatus, $scheduledAt);

        if (isset($result['error'])) {
            return new \WP_REST_Response(['message' => $result['error']], 422);
        }

        return new \WP_REST_Response(['data' => $result, 'message' => __('Status change scheduled.', 'fchub-memberships')]);
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

            if (empty($type)) {
                continue;
            }

            $key = $type . ':' . $id;
            $resolved[$key] = self::resolveResourceName($type, $id, $registry);
        }

        return new \WP_REST_Response(['data' => $resolved]);
    }

    /**
     * Resolve a human-readable name for a resource.
     */
    private static function resolveResourceName(string $type, string $id, ResourceTypeRegistry $registry): string
    {
        $typeConfig = $registry->get($type);
        $typeLabel = $typeConfig ? $typeConfig['label'] : ucfirst($type);

        // Wildcard or "all of type"
        if ($id === '*' || $id === '0' || $id === '') {
            return sprintf(__('All %s', 'fchub-memberships'), $typeLabel);
        }

        // Special page types
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

        // URL patterns
        if ($type === 'url_pattern') {
            return $id;
        }

        // Comment protection
        if ($type === 'comment') {
            $title = get_the_title((int) $id);
            return $title ? sprintf(__('Comments on: %s', 'fchub-memberships'), $title) : __('(Deleted)', 'fchub-memberships');
        }

        // Menu items
        if ($type === 'menu_item') {
            $menuItem = get_post((int) $id);
            if ($menuItem) {
                $navItem = wp_setup_nav_menu_item($menuItem);
                return $navItem->title ?? $menuItem->post_title ?: __('(Deleted)', 'fchub-memberships');
            }
            return __('(Deleted)', 'fchub-memberships');
        }

        // Post types (built-in and custom)
        if (in_array($type, ['post', 'page'], true) || post_type_exists($type)) {
            $title = get_the_title((int) $id);
            return $title ?: __('(Deleted)', 'fchub-memberships');
        }

        // Taxonomies
        if (taxonomy_exists($type)) {
            $term = get_term((int) $id);
            if ($term && !is_wp_error($term)) {
                return $term->name;
            }
            return __('(Deleted)', 'fchub-memberships');
        }

        // FluentCommunity spaces/courses (adapter-based)
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

        // Fallback
        return $typeLabel . ' #' . $id;
    }

    /**
     * Validate rules including drip constraints and resource types.
     */
    private static function validateRules(array $rules): ?string
    {
        $registry = ResourceTypeRegistry::getInstance();

        foreach ($rules as $i => $rule) {
            $ruleNum = $i + 1;

            // Validate resource_type exists in registry
            $resourceType = $rule['resource_type'] ?? '';
            if (!empty($resourceType) && !$registry->isValid($resourceType)) {
                return sprintf(
                    __('Rule #%d: invalid resource type "%s".', 'fchub-memberships'),
                    $ruleNum,
                    $resourceType
                );
            }

            // Validate drip rules
            $dripType = $rule['drip_type'] ?? 'immediate';

            if ($dripType === 'fixed_date' && empty($rule['drip_date'])) {
                return sprintf(
                    __('Rule #%d: drip_date is required when drip type is "Fixed Date".', 'fchub-memberships'),
                    $ruleNum
                );
            }

            if ($dripType === 'fixed_date' && !empty($rule['drip_date'])) {
                $dripDate = strtotime($rule['drip_date']);
                if ($dripDate && $dripDate < strtotime('today')) {
                    return sprintf(
                        __('Rule #%d: drip date cannot be in the past.', 'fchub-memberships'),
                        $ruleNum
                    );
                }
            }

            if ($dripType === 'delayed') {
                $delayDays = (int) ($rule['drip_delay_days'] ?? 0);
                if ($delayDays < 1 || $delayDays > 730) {
                    return sprintf(
                        __('Rule #%d: delay days must be between 1 and 730.', 'fchub-memberships'),
                        $ruleNum
                    );
                }
            }
        }

        return null;
    }

    /**
     * Prepare rules for storage by auto-mapping provider and stripping non-stored fields.
     */
    private static function prepareRulesForStorage(array $rules): array
    {
        $registry = ResourceTypeRegistry::getInstance();

        return array_map(function (array $rule) use ($registry) {
            // Auto-map provider from resource_type
            $resourceType = $rule['resource_type'] ?? '';
            $typeConfig = $registry->get($resourceType);
            if ($typeConfig) {
                $rule['provider'] = $typeConfig['provider'];
            }

            // Strip access_type - it's not stored in the database
            unset($rule['access_type']);

            // Strip frontend-only fields
            unset($rule['resource_label'], $rule['resource_type_label']);

            return $rule;
        }, $rules);
    }

    public static function adminPermission(): bool
    {
        return current_user_can('manage_options');
    }
}
