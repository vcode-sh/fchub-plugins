<?php

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

final class PlanProductLinkService
{
    private PlanService $plans;
    private \wpdb $wpdb;
    private string $metaTable;
    private string $variationsTable;

    public function __construct(?PlanService $plans = null, ?\wpdb $wpdb = null)
    {
        $this->plans = $plans ?? new PlanService();
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->metaTable = $this->wpdb->prefix . 'fct_product_meta';
        $this->variationsTable = $this->wpdb->prefix . 'fct_product_variations';
    }

    public function linkedProducts(int $planId): array
    {
        $plan = $this->plans->find($planId);
        if (!$plan) {
            return ['error' => __('Plan not found.', 'fchub-memberships')];
        }

        $postsTable = $this->wpdb->prefix . 'posts';

        $feeds = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT m.id AS feed_id, m.object_id AS product_id, m.meta_value,
                    p.post_title AS product_title
             FROM {$this->metaTable} m
             LEFT JOIN {$postsTable} p ON m.object_id = p.ID
             WHERE m.object_type = 'product_integration'
               AND m.meta_key = %s",
            'memberships'
        ), ARRAY_A);

        $linked = [];
        foreach ($feeds ?: [] as $feed) {
            $settings = is_string($feed['meta_value'])
                ? (json_decode($feed['meta_value'], true) ?: [])
                : ($feed['meta_value'] ?? []);

            $matchBySlug = ($settings['plan_slug'] ?? '') === $plan['slug'];
            $matchById = (int) ($settings['plan_id'] ?? 0) === $planId;
            if (!$matchBySlug && !$matchById) {
                continue;
            }

            $linked[] = [
                'feed_id'       => (int) $feed['feed_id'],
                'product_id'    => (int) $feed['product_id'],
                'product_title' => $feed['product_title'] ?? '',
                'feed_title'    => $settings['name'] ?? '',
                'triggers'      => $settings['triggers'] ?? $settings['event_trigger'] ?? [],
                'status'        => ($settings['enabled'] ?? 'yes') === 'yes' ? 'active' : 'inactive',
            ];
        }

        // Fetch all variations for each product
        $productIds = array_filter(array_column($linked, 'product_id'));
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
            $variations = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT post_id, variation_title, item_price, payment_type
                 FROM {$this->variationsTable}
                 WHERE post_id IN ({$placeholders})
                 ORDER BY COALESCE(serial_index, 0) ASC",
                ...$productIds
            ), ARRAY_A);

            $varMap = [];
            foreach ($variations as $v) {
                $pid = (int) $v['post_id'];
                $varMap[$pid][] = [
                    'title'        => $v['variation_title'],
                    'price'        => (int) $v['item_price'],
                    'payment_type' => $v['payment_type'],
                ];
            }

            foreach ($linked as &$item) {
                $vars = $varMap[$item['product_id']] ?? [];
                $first = $vars[0] ?? [];
                $item['price'] = $first['price'] ?? null;
                $item['billing_period'] = $first['payment_type'] ?? null;
                $item['variations'] = $vars;
            }
        }

        return ['data' => $linked];
    }

    public function linkProduct(int $planId, int $productId): array
    {
        $plan = $this->plans->find($planId);
        if (!$plan) {
            return ['error' => __('Plan not found.', 'fchub-memberships'), 'status' => 404];
        }

        if (!$productId) {
            return ['error' => __('Product ID is required.', 'fchub-memberships'), 'status' => 422];
        }

        $postsTable = $this->wpdb->prefix . 'posts';
        $product = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT ID as id, post_title as title FROM {$postsTable} WHERE ID = %d",
            $productId
        ), ARRAY_A);

        if (!$product) {
            return ['error' => __('Product not found.', 'fchub-memberships'), 'status' => 404];
        }

        // Check for existing feed linking this product to this plan
        $existingFeeds = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT id, meta_value FROM {$this->metaTable}
             WHERE object_id = %d AND object_type = 'product_integration' AND meta_key = 'memberships'",
            $productId
        ), ARRAY_A);

        foreach ($existingFeeds ?: [] as $existing) {
            $existingSettings = is_string($existing['meta_value'])
                ? (json_decode($existing['meta_value'], true) ?: [])
                : ($existing['meta_value'] ?? []);

            if ((int) ($existingSettings['plan_id'] ?? 0) === $planId ||
                ($existingSettings['plan_slug'] ?? '') === $plan['slug']) {
                return ['error' => __('This product is already linked to this plan.', 'fchub-memberships'), 'status' => 422];
            }
        }

        $validityModeMap = [
            'fixed_days'          => 'fixed_duration',
            'subscription_mirror' => 'mirror_subscription',
            'fixed_anchor'        => 'anchor_billing',
        ];
        $durationType = $plan['duration_type'] ?? 'lifetime';
        $validityMode = $validityModeMap[$durationType] ?? 'lifetime';

        $feedSettings = [
            'name'                   => sprintf('%s - %s', $plan['title'], $product['title']),
            'enabled'                => 'yes',
            'plan_id'                => $planId,
            'plan_slug'              => $plan['slug'],
            'validity_mode'          => $validityMode,
            'validity_days'          => $plan['duration_days'] ?? 0,
            'grace_period_days'      => $plan['grace_period_days'] ?? 0,
            'watch_on_access_revoke' => 'yes',
            'cancel_behavior'        => 'wait_validity',
            'auto_create_user'       => 'no',
            'event_trigger'          => ['order_paid_done'],
            'triggers'               => ['order_paid_done'],
        ];

        if ($durationType === 'fixed_anchor') {
            $planMeta = $plan['meta'] ?? [];
            $feedSettings['billing_anchor_day'] = (int) ($planMeta['billing_anchor_day'] ?? 1);
        }

        $this->wpdb->insert($this->metaTable, [
            'object_id'   => $productId,
            'object_type' => 'product_integration',
            'meta_key'    => 'memberships',
            'meta_value'  => wp_json_encode($feedSettings),
        ]);

        do_action('fluent_cart/reindex_integration_feeds', []);

        return [
            'data'    => ['feed_id' => (int) $this->wpdb->insert_id],
            'message' => __('Product linked successfully.', 'fchub-memberships'),
            'status'  => 201,
        ];
    }

    public function unlinkProduct(int $planId, int $feedId): array
    {
        $plan = $this->plans->find($planId);
        if (!$plan) {
            return ['error' => __('Plan not found.', 'fchub-memberships'), 'status' => 404];
        }

        $feed = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, meta_value FROM {$this->metaTable}
             WHERE id = %d AND object_type = 'product_integration' AND meta_key = 'memberships'",
            $feedId
        ), ARRAY_A);

        if (!$feed) {
            return ['error' => __('Integration feed not found.', 'fchub-memberships'), 'status' => 404];
        }

        $settings = is_string($feed['meta_value'])
            ? (json_decode($feed['meta_value'], true) ?: [])
            : ($feed['meta_value'] ?? []);

        $matchBySlug = ($settings['plan_slug'] ?? '') === $plan['slug'];
        $matchById = (int) ($settings['plan_id'] ?? 0) === $planId;
        if (!$matchBySlug && !$matchById) {
            return ['error' => __('This feed does not belong to this plan.', 'fchub-memberships'), 'status' => 422];
        }

        $this->wpdb->delete($this->metaTable, ['id' => $feedId]);

        do_action('fluent_cart/reindex_integration_feeds', []);

        return ['message' => __('Product unlinked successfully.', 'fchub-memberships')];
    }

    public function searchProducts(string $search = ''): array
    {
        $postsTable = $this->wpdb->prefix . 'posts';
        $search = sanitize_text_field($search);

        $where = "p.post_type = 'fluent-products' AND p.post_status = 'publish'";
        if ($search !== '') {
            $where .= $this->wpdb->prepare(' AND p.post_title LIKE %s', '%' . $this->wpdb->esc_like($search) . '%');
        }

        $posts = $this->wpdb->get_results(
            "SELECT p.ID as id, p.post_title as title FROM {$postsTable} p WHERE {$where} ORDER BY p.post_title ASC LIMIT 20",
            ARRAY_A
        );

        if (empty($posts)) {
            return ['data' => []];
        }

        $postIds = array_column($posts, 'id');
        $placeholders = implode(',', array_fill(0, count($postIds), '%d'));
        $variations = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT post_id, variation_title, item_price, payment_type
             FROM {$this->variationsTable}
             WHERE post_id IN ({$placeholders})
             ORDER BY COALESCE(serial_index, 0) ASC",
            ...$postIds
        ), ARRAY_A);

        $varMap = [];
        foreach ($variations as $v) {
            $varMap[(int) $v['post_id']][] = [
                'title'        => $v['variation_title'],
                'price'        => (int) $v['item_price'],
                'payment_type' => $v['payment_type'],
            ];
        }

        $products = [];
        foreach ($posts as $post) {
            $pid = (int) $post['id'];
            $vars = $varMap[$pid] ?? [];
            $firstVar = $vars[0] ?? [];
            $products[] = [
                'id'         => $pid,
                'title'      => $post['title'],
                'price'      => $firstVar['price'] ?? null,
                'payment_type' => $firstVar['payment_type'] ?? null,
                'variations' => $vars,
            ];
        }

        return ['data' => $products];
    }
}
