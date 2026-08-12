<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Support\WooStorage;
use CartShift\Support\MoneyHelper;

defined('ABSPATH') || exit;

/** Normalises real Woo objects without reading product/order business postmeta. */
final class LoadedWooSourceApi implements WooSourceApi
{
    /** @param array{baseurl?: string, basedir?: string}|null $uploads */
    public function __construct(private readonly ?array $uploads = null) {}

    public function productCensusPage(int $page, int $limit): array
    {
        if (!class_exists('WP_Query')) {
            throw new \RuntimeException('WP_Query is unavailable.');
        }

        $query = new \WP_Query([
            'post_type' => ['product', 'product_variation'],
            'post_status' => function_exists('get_post_stati') ? array_values(get_post_stati()) : 'any',
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]);

        return $this->positiveIds((array) $query->posts);
    }

    public function semanticProductIds(): array
    {
        if (!function_exists('wc_get_products')) {
            throw new \RuntimeException('wc_get_products() is unavailable.');
        }

        $statuses = function_exists('get_post_stati') ? array_values(get_post_stati()) : ['publish'];
        $ids = [];

        for ($page = 1; ; ++$page) {
            $batch = wc_get_products([
                'limit' => 100,
                'page' => $page,
                'return' => 'ids',
                'status' => $statuses,
                'type' => array_values(array_unique([
                    ...array_keys(function_exists('wc_get_product_types') ? wc_get_product_types() : []),
                    'variation',
                ])),
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            $batch = $this->positiveIds((array) $batch);
            $ids = [...$ids, ...$batch];

            if (count($batch) < 100) {
                break;
            }
        }

        sort($ids, SORT_NUMERIC);

        return array_values(array_unique($ids));
    }

    public function lookupProductIds(): array
    {
        global $wpdb;

        if (!isset($wpdb)) {
            throw new \RuntimeException('WordPress database access is unavailable.');
        }

        $table = $wpdb->wc_product_meta_lookup ?? $wpdb->prefix . 'wc_product_meta_lookup';
        $rows = $wpdb->get_col("SELECT product_id FROM `{$table}` ORDER BY product_id ASC");

        return $this->positiveIds((array) $rows);
    }

    public function product(int $id): ?array
    {
        if (!function_exists('wc_get_product')) {
            throw new \RuntimeException('wc_get_product() is unavailable.');
        }

        $product = wc_get_product($id);

        if (!is_object($product)) {
            return null;
        }

        $attributes = [];

        foreach ((array) $product->get_attributes() as $name => $attribute) {
            if (is_object($attribute) && method_exists($attribute, 'is_taxonomy') && $attribute->is_taxonomy()) {
                $attributes[] = 'global';
            } else {
                $attributes[] = is_string($name) && str_starts_with($name, 'pa_') ? 'global' : 'custom';
            }

            if (is_object($attribute) && method_exists($attribute, 'get_options') && in_array('', (array) $attribute->get_options(), true)) {
                $attributes[] = 'wildcard';
            }

            if (!is_object($attribute) && ($attribute === '' || (is_array($attribute) && in_array('', $attribute, true)))) {
                $attributes[] = 'wildcard';
            }
        }

        $downloads = [];

        foreach ((array) $product->get_downloads() as $download) {
            $file = is_object($download) && method_exists($download, 'get_file')
                ? (string) $download->get_file()
                : '';
            $downloads[] = $this->downloadContract($file);
        }

        $media = [];

        if ((int) $product->get_image_id() > 0) {
            $media[] = (int) $product->get_parent_id() > 0 ? 'variation' : 'featured';
        }

        if ((array) $product->get_gallery_image_ids() !== []) {
            $media[] = 'gallery';
        }

        $modified = $product->get_date_modified();
        $saleFrom = $product->get_date_on_sale_from();
        $saleTo = $product->get_date_on_sale_to();
        $type = (string) $product->get_type();
        $children = $type === 'grouped' && method_exists($product, 'get_children')
            ? (array) $product->get_children()
            : [];

        return [
            'id' => (int) $product->get_id(),
            'type' => $type,
            'status' => (string) $product->get_status(),
            'sku_length' => $this->textLength((string) $product->get_sku()),
            'sku_fingerprint' => hash('sha256', (string) $product->get_sku()),
            'parent_id' => (int) $product->get_parent_id(),
            'modified_gmt' => $modified ? $modified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null,
            'attribute_contracts' => array_values(array_unique($attributes)),
            'regular_price' => (string) $product->get_regular_price('edit'),
            'sale_price' => (string) $product->get_sale_price('edit'),
            'sale_scheduled' => $saleFrom !== null || $saleTo !== null,
            'tax_class' => (string) $product->get_tax_class() === '' ? 'standard' : (string) $product->get_tax_class(),
            'stock_owner' => $product->get_manage_stock() === 'parent' ? 'parent' : 'self',
            'backorders' => (string) $product->get_backorders(),
            'downloads' => array_values(array_unique($downloads)),
            'media' => array_values(array_unique($media)),
            'catalogue_visibility' => (string) $product->get_catalog_visibility(),
            'featured' => (bool) $product->get_featured(),
            'menu_order' => (int) $product->get_menu_order(),
            'purchase_note' => (string) $product->get_purchase_note() !== '',
            'reviews_enabled' => (bool) $product->get_reviews_allowed(),
            'review_count' => (int) $product->get_review_count(),
            'has_rating' => (float) $product->get_average_rating() !== 0.0,
            'sales_count' => (int) $product->get_total_sales(),
            'has_global_unique_id' => method_exists($product, 'get_global_unique_id')
                && (string) $product->get_global_unique_id() !== '',
            'password_protected' => method_exists($product, 'get_password')
                && (string) $product->get_password() !== '',
            'upsell_count' => count((array) $product->get_upsell_ids()),
            'cross_sell_count' => count((array) $product->get_cross_sell_ids()),
            'grouped_child_count' => count($children),
            'has_external_fields' => $type === 'external'
                && ((method_exists($product, 'get_product_url') && (string) $product->get_product_url() !== '')
                    || (method_exists($product, 'get_button_text') && (string) $product->get_button_text() !== '')),
            'extension_metadata_count' => 0,
        ];
    }

    public function orderCensusPage(int $page, int $limit): array
    {
        if (!function_exists('wc_get_orders')) {
            throw new \RuntimeException('wc_get_orders() is unavailable.');
        }

        $result = wc_get_orders([
            'type' => 'shop_order',
            'status' => function_exists('wc_get_order_statuses') ? array_keys(wc_get_order_statuses()) : 'any',
            'limit' => $limit,
            'page' => $page,
            'paginate' => true,
            'return' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        $orders = is_object($result) && isset($result->orders) ? $result->orders : $result;

        return $this->positiveIds((array) $orders);
    }

    public function order(int $id): ?array
    {
        if (!function_exists('wc_get_order')) {
            throw new \RuntimeException('wc_get_order() is unavailable.');
        }

        $order = wc_get_order($id);

        if (!is_object($order)) {
            return null;
        }

        $productIds = [];
        $missingProductRefs = [];

        foreach ((array) $order->get_items('line_item') as $item) {
            if (is_object($item) && method_exists($item, 'get_product_id')) {
                $productId = (int) $item->get_product_id();

                if ($productId > 0) {
                    $productIds[] = $productId;
                } elseif (method_exists($item, 'get_id') && function_exists('wc_get_order_item_meta')) {
                    $lineId = (int) $item->get_id();
                    $rawProductId = (int) wc_get_order_item_meta($lineId, '_product_id', true);
                    if ($lineId > 0 && $rawProductId > 0) {
                        $missingProductRefs[] = [
                            'line_id' => $lineId,
                            'product_id' => $rawProductId,
                            'line_shape' => $this->historicalLineShape($order, $item),
                        ];
                    }
                }
            }
        }

        usort(
            $missingProductRefs,
            static fn (array $left, array $right): int => [$left['line_id'], $left['product_id']]
                <=> [$right['line_id'], $right['product_id']],
        );

        $total = (float) $order->get_total();
        $refunded = abs((float) $order->get_total_refunded());
        $refundState = $refunded <= 0.0 ? 'none' : ($refunded + 0.00001 >= $total ? 'full' : 'partial');
        $modified = $order->get_date_modified();

        $noteFacts = $this->orderNoteFacts($id);

        return [
            'id' => (int) $order->get_id(),
            'status' => (string) $order->get_status(),
            'modified_gmt' => $modified ? $modified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null,
            'product_ids' => array_values(array_unique($productIds)),
            'missing_product_refs' => $missingProductRefs,
            'has_fee' => (array) $order->get_items('fee') !== [],
            'has_coupon' => (array) $order->get_items('coupon') !== [],
            'has_shipping' => (array) $order->get_items('shipping') !== [],
            'tax_rate_count' => count((array) $order->get_items('tax')),
            'refund_state' => $refundState,
            'refund_count' => method_exists($order, 'get_refunds') ? count((array) $order->get_refunds()) : 0,
            'note_count' => $noteFacts['count'],
            'customer_visible_note_count' => $noteFacts['customer_visible_count'],
        ];
    }

    /** @return array{count:int,customer_visible_count:int} */
    private function orderNoteFacts(int $orderId): array
    {
        if (!function_exists('wc_get_order_notes')) {
            return ['count' => 0, 'customer_visible_count' => 0];
        }
        $notes = wc_get_order_notes(['order_id' => $orderId]);
        if (!is_array($notes)) {
            throw new \RuntimeException('Order note census read failed.');
        }
        $visible = 0;
        foreach ($notes as $note) {
            if (!is_object($note)) {
                throw new \RuntimeException('Order note census returned an invalid row.');
            }
            $value = property_exists($note, 'customer_note') ? $note->customer_note : null;
            if (!is_bool($value) && !in_array($value, [0, 1, '0', '1'], true)) {
                throw new \RuntimeException('Order note visibility is ambiguous.');
            }
            $visible += (bool) $value ? 1 : 0;
        }
        return ['count' => count($notes), 'customer_visible_count' => $visible];
    }

    public function subscriptionCensusPage(int $page, int $limit): array
    {
        global $wpdb;

        if (!isset($wpdb)) {
            throw new \RuntimeException('WordPress database access is unavailable.');
        }

        $offset = ($page - 1) * $limit;
        $wpdb->last_error = '';
        $query = WooStorage::isHposEnabled()
            ? $wpdb->prepare(
                "SELECT id FROM `{$wpdb->prefix}wc_orders` WHERE type = 'shop_subscription' ORDER BY id ASC LIMIT %d OFFSET %d",
                $limit,
                $offset,
            )
            : $wpdb->prepare(
                "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'shop_subscription' ORDER BY ID ASC LIMIT %d OFFSET %d",
                $limit,
                $offset,
            );
        $ids = $wpdb->get_col($query);

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Subscription census read failed.');
        }

        return $this->positiveIds((array) $ids);
    }

    public function subscription(int $id): ?array
    {
        if (!function_exists('wcs_get_subscription')) {
            return null;
        }

        $subscription = wcs_get_subscription($id);

        if (!is_object($subscription)) {
            return null;
        }

        $relations = [];

        foreach (['parent', 'renewal', 'switch', 'resubscribe'] as $relationship) {
            $relations[$relationship] = $this->positiveIds(
                (array) $subscription->get_related_orders('ids', $relationship),
            );
        }

        $modified = $subscription->get_date_modified();

        return [
            'id' => (int) $subscription->get_id(),
            'status' => method_exists($subscription, 'get_status') ? (string) $subscription->get_status() : '',
            'source_gateway' => method_exists($subscription, 'get_payment_method')
                ? (string) $subscription->get_payment_method()
                : '',
            'requires_manual_renewal' => method_exists($subscription, 'get_requires_manual_renewal')
                && (bool) $subscription->get_requires_manual_renewal(),
            'has_next_payment' => method_exists($subscription, 'get_date')
                && $this->hasScheduleValue($subscription->get_date('next_payment')),
            'has_end' => method_exists($subscription, 'get_date')
                && $this->hasScheduleValue($subscription->get_date('end')),
            'modified_gmt' => $modified ? $modified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z') : null,
            'related_orders' => $relations,
        ];
    }

    /** @param array<array-key, mixed> $values @return list<int> */
    private function positiveIds(array $values): array
    {
        $ids = [];

        foreach ($values as $value) {
            $id = is_object($value) && method_exists($value, 'get_id') ? (int) $value->get_id() : (int) $value;

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function downloadContract(string $file): string
    {
        if ($file === '') {
            return 'missing';
        }

        if (filter_var($file, FILTER_VALIDATE_URL) !== false) {
            $uploads = $this->uploads;

            if ($uploads === null && function_exists('wp_upload_dir')) {
                $uploads = (array) wp_upload_dir();
            }

            $baseUrl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
            $baseDir = rtrim((string) ($uploads['basedir'] ?? ''), DIRECTORY_SEPARATOR);

            if ($baseUrl !== '' && $baseDir !== '' && str_starts_with($file, $baseUrl . '/')) {
                $relative = rawurldecode(substr($file, strlen($baseUrl) + 1));
                $path = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

                return is_file($path) && is_readable($path) ? 'local' : 'missing';
            }

            return 'remote';
        }

        return is_file($file) && is_readable($file) ? 'local' : 'missing';
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function hasScheduleValue(mixed $value): bool
    {
        return is_object($value) || (is_string($value) && trim($value) !== '');
    }

    /** @return array{name: string, sku: string, unit_total: int, currency: string, source_created_utc?: string}|null */
    private function historicalLineShape(object $order, object $item): ?array
    {
        if (!method_exists($item, 'get_name')
            || !method_exists($item, 'get_subtotal')
            || !method_exists($item, 'get_quantity')
            || !method_exists($order, 'get_currency')) {
            return null;
        }
        $quantity = (int) $item->get_quantity();
        if ($quantity <= 0) {
            return null;
        }
        try {
            $subtotal = MoneyHelper::decimalToCents((string) $item->get_subtotal());
        } catch (\Throwable) {
            return null;
        }
        if ($subtotal < 0 || $subtotal % $quantity !== 0) {
            return null;
        }
        $name = trim((string) $item->get_name());
        $currency = strtoupper(trim((string) $order->get_currency()));
        if ($name === '' || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            return null;
        }
        $shape = [
            'name' => $name,
            'sku' => method_exists($item, 'get_meta') ? trim((string) $item->get_meta('_sku', true)) : '',
            'unit_total' => intdiv($subtotal, $quantity),
            'currency' => $currency,
        ];
        $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
        if ($created instanceof \DateTimeInterface) {
            $shape['source_created_utc'] = \DateTimeImmutable::createFromInterface($created)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        return $shape;
    }
}
