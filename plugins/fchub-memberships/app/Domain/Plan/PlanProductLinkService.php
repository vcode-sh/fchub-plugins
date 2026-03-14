<?php

namespace FChubMemberships\Domain\Plan;

defined('ABSPATH') || exit;

final class PlanProductLinkService
{
    private PlanService $plans;
    private \wpdb $wpdb;
    private string $metaTable;
    private string $productsTable;

    public function __construct(?PlanService $plans = null, ?\wpdb $wpdb = null)
    {
        $this->plans = $plans ?? new PlanService();
        $this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
        $this->metaTable = $this->wpdb->prefix . 'fct_product_meta';
        $this->productsTable = $this->wpdb->prefix . 'fct_products';
    }

    public function linkedProducts(int $planId): array
    {
        $plan = $this->plans->find($planId);
        if (!$plan) {
            return ['error' => __('Plan not found.', 'fchub-memberships')];
        }

        $feeds = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT m.id AS feed_id, m.object_id AS product_id, m.meta_value,
                    p.title AS product_title
             FROM {$this->metaTable} m
             LEFT JOIN {$this->productsTable} p ON m.object_id = p.id
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

        $productIds = array_filter(array_column($linked, 'product_id'));
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
            $prices = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, price, billing_period, billing_interval FROM {$this->productsTable} WHERE id IN ({$placeholders})",
                ...$productIds
            ), ARRAY_A);

            $priceMap = [];
            foreach ($prices as $price) {
                $priceMap[(int) $price['id']] = $price;
            }

            foreach ($linked as &$item) {
                $pricing = $priceMap[$item['product_id']] ?? [];
                $item['price'] = $pricing['price'] ?? null;
                $item['billing_period'] = $pricing['billing_period'] ?? null;
                $item['billing_interval'] = $pricing['billing_interval'] ?? null;
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

        $product = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, title FROM {$this->productsTable} WHERE id = %d",
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

        $feedSettings = [
            'name'                   => sprintf('%s - %s', $plan['title'], $product['title']),
            'enabled'                => 'yes',
            'plan_id'                => $planId,
            'plan_slug'              => $plan['slug'],
            'validity_mode'          => $plan['duration_type'] === 'fixed_days' ? 'fixed_duration' : ($plan['duration_type'] === 'subscription_mirror' ? 'mirror_subscription' : 'lifetime'),
            'validity_days'          => $plan['duration_days'] ?? 0,
            'grace_period_days'      => $plan['grace_period_days'] ?? 0,
            'watch_on_access_revoke' => 'yes',
            'cancel_behavior'        => 'wait_validity',
            'auto_create_user'       => 'no',
            'event_trigger'          => ['order_paid_done'],
            'triggers'               => ['order_paid_done'],
        ];

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
        $search = sanitize_text_field($search);

        if ($search !== '') {
            $products = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT id, title, price, billing_period, status FROM {$this->productsTable} WHERE title LIKE %s ORDER BY title ASC LIMIT 20",
                '%' . $this->wpdb->esc_like($search) . '%'
            ), ARRAY_A);
        } else {
            $products = $this->wpdb->get_results(
                "SELECT id, title, price, billing_period, status FROM {$this->productsTable} ORDER BY title ASC LIMIT 20",
                ARRAY_A
            );
        }

        return ['data' => $products ?: []];
    }
}
