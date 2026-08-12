<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Runtime;

defined('ABSPATH') || exit;

use CartShift\Support\CanonicalJson;

final class TransferRuntimeProbe implements TransferRuntimeInspector
{
    public const string ROLE_SOURCE = 'source';
    public const string ROLE_TARGET = 'target';

    private const array SOURCE_FUNCTIONS = [
        'wc_get_products',
        'wc_get_product',
        'wc_get_orders',
        'wc_get_order',
    ];

    private const array SOURCE_CLASSES = [
        'WooCommerce',
        'WC_Product',
        'WC_Product_Variation',
        'WC_Order',
        'WC_Order_Refund',
    ];

    private const array WCS_FUNCTIONS = [
        'wcs_get_subscriptions',
        'wcs_get_subscription',
        'wcs_get_subscription_statuses',
    ];

    private const array WCS_METHODS = [
        'get_related_orders',
        'get_payment_count',
        'get_date',
        'get_parent',
        'get_billing_period',
        'get_billing_interval',
        'is_manual',
        'get_requires_manual_renewal',
    ];

    private const array TARGET_TABLES = [
        'posts',
        'postmeta',
        'terms',
        'term_taxonomy',
        'term_relationships',
        'fct_product_details',
        'fct_product_variations',
        'fct_product_downloads',
        'fct_atts_groups',
        'fct_atts_terms',
        'fct_atts_relations',
        'fct_orders',
        'fct_order_items',
        'fct_order_transactions',
        'fct_order_tax_rate',
        'fct_order_addresses',
        'fct_applied_coupons',
        'fct_order_meta',
        'fct_customers',
        'fct_customer_addresses',
        'cartshift_id_map',
        'cartshift_target_claims',
        'cartshift_shared_links',
        'cartshift_transfer_leases',
        'cartshift_transfer_runs',
        'cartshift_transfer_records',
        'cartshift_transfer_outbox',
    ];

    private const array REQUIRED_COLUMNS = [
        'posts' => ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'post_name', 'post_modified', 'post_modified_gmt', 'post_parent', 'guid', 'menu_order', 'post_type'],
        'postmeta' => ['meta_id', 'post_id', 'meta_key', 'meta_value'],
        'terms' => ['term_id', 'name', 'slug', 'term_group'],
        'term_taxonomy' => ['term_taxonomy_id', 'term_id', 'taxonomy', 'description', 'parent', 'count'],
        'term_relationships' => ['object_id', 'term_taxonomy_id', 'term_order'],
        'fct_product_details' => ['id', 'post_id', 'fulfillment_type', 'min_price', 'max_price', 'default_variation_id', 'default_media', 'manage_stock', 'stock_availability', 'variation_type', 'manage_downloadable', 'other_info', 'created_at', 'updated_at'],
        'fct_product_variations' => ['id', 'post_id', 'media_id', 'serial_index', 'sold_individually', 'variation_title', 'variation_identifier', 'sku', 'manage_stock', 'payment_type', 'stock_status', 'backorders', 'total_stock', 'on_hold', 'committed', 'available', 'fulfillment_type', 'item_status', 'manage_cost', 'item_price', 'item_cost', 'compare_price', 'shipping_class', 'other_info', 'downloadable', 'created_at', 'updated_at'],
        'fct_product_downloads' => ['id', 'post_id', 'product_variation_id', 'download_identifier', 'title', 'type', 'driver', 'file_name', 'file_path', 'file_url', 'file_size', 'settings', 'serial', 'created_at', 'updated_at'],
        'fct_atts_groups' => ['id', 'title', 'slug', 'description', 'settings', 'serial', 'is_system', 'created_at', 'updated_at'],
        'fct_atts_terms' => ['id', 'group_id', 'title', 'slug', 'description', 'settings', 'serial', 'created_at', 'updated_at'],
        'fct_atts_relations' => ['id', 'group_id', 'term_id', 'object_id', 'created_at', 'updated_at'],
        'fct_orders' => ['id', 'invoice_no', 'receipt_number', 'parent_id', 'type', 'status', 'payment_status', 'shipping_status', 'mode', 'payment_method', 'payment_method_title', 'currency', 'subtotal', 'discount_tax', 'coupon_discount_total', 'manual_discount_total', 'shipping_total', 'fee_total', 'tax_total', 'shipping_tax', 'total_amount', 'total_paid', 'total_refund', 'rate', 'tax_behavior', 'note', 'uuid', 'config', 'created_at', 'updated_at'],
        'fct_order_items' => ['id', 'order_id', 'payment_type', 'post_id', 'object_id', 'cart_index', 'quantity', 'unit_price', 'subtotal', 'discount_total', 'tax_amount', 'shipping_charge', 'line_total', 'refund_total', 'rate', 'fulfilled_quantity', 'other_info', 'line_meta', 'created_at'],
        'fct_order_transactions' => ['id', 'order_id', 'order_type', 'transaction_type', 'payment_method', 'payment_mode', 'payment_method_type', 'vendor_charge_id', 'currency', 'status', 'total', 'rate', 'meta', 'uuid', 'created_at'],
        'fct_order_tax_rate' => ['id', 'order_id', 'tax_rate_id', 'order_tax', 'shipping_tax', 'total_tax', 'meta', 'created_at', 'updated_at'],
        'fct_order_addresses' => ['id', 'order_id', 'type', 'name', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'meta', 'created_at', 'updated_at'],
        'fct_applied_coupons' => ['id', 'order_id', 'coupon_id', 'code', 'amount', 'created_at', 'updated_at'],
        'fct_order_meta' => ['id', 'order_id', 'meta_key', 'meta_value', 'created_at', 'updated_at'],
        'fct_customers' => ['id', 'user_id', 'email', 'first_name', 'last_name', 'status', 'purchase_value', 'purchase_count', 'ltv', 'first_purchase_date', 'last_purchase_date', 'aov', 'uuid', 'created_at', 'updated_at'],
        'fct_customer_addresses' => ['id', 'customer_id', 'is_primary', 'type', 'status', 'label', 'name', 'address_1', 'address_2', 'city', 'state', 'phone', 'email', 'postcode', 'country', 'meta', 'created_at', 'updated_at'],
        'cartshift_id_map' => ['id', 'source_key', 'entity_type', 'wc_id', 'fc_id', 'migration_id', 'created_by_migration', 'is_simulated'],
        'cartshift_target_claims' => ['id', 'entity_type', 'target_id', 'source_key', 'source_id', 'run_id', 'source_fingerprint', 'target_fingerprint', 'claim_state', 'created_at', 'updated_at'],
        'cartshift_shared_links' => ['id', 'source_key', 'entity_type', 'source_id', 'target_id', 'target_fingerprint', 'decision_fingerprint', 'created_at', 'updated_at'],
        'cartshift_transfer_leases' => ['target_fingerprint', 'holder_id', 'descriptor_hash', 'expires_at', 'heartbeat_at'],
        'cartshift_transfer_runs' => ['run_id', 'descriptor_hash', 'package_hash', 'decision_hash', 'runtime_hash', 'settings_hash', 'target_hash', 'state', 'resume_state', 'attempt', 'generation', 'created_at', 'updated_at'],
        'cartshift_transfer_records' => ['id', 'run_id', 'record_kind', 'source_identity', 'generation', 'source_fingerprint', 'target_fingerprint', 'action', 'state', 'target_ids', 'before_hash', 'after_hash', 'error_code', 'created_at', 'updated_at'],
        'cartshift_transfer_outbox' => ['id', 'run_id', 'record_kind', 'source_identity', 'generation', 'payload', 'payload_hash', 'exported_at', 'created_at'],
    ];

    private const array TARGET_MODELS = [
        'FluentCart\\App\\Models\\ProductDetail',
        'FluentCart\\App\\Models\\ProductVariation',
        'FluentCart\\App\\Models\\ProductDownload',
        'FluentCart\\App\\Models\\Order',
        'FluentCart\\App\\Models\\OrderItem',
        'FluentCart\\App\\Models\\OrderTransaction',
        'FluentCart\\App\\Models\\OrderTaxRate',
        'FluentCart\\App\\Models\\OrderAddress',
        'FluentCart\\App\\Models\\AppliedCoupon',
        'FluentCart\\App\\Models\\OrderMeta',
        'FluentCart\\App\\Models\\Customer',
        'FluentCart\\App\\Models\\CustomerAddresses',
    ];

    public function __construct(
        private readonly TransferRuntimeSymbols $symbols = new LoadedTransferRuntimeSymbols(),
        private readonly TransferSchemaInspector $schemas = new WpdbTransferSchemaInspector(),
    ) {
    }

    public function inspect(string $role): TransferRuntimeReport
    {
        if (!in_array($role, [self::ROLE_SOURCE, self::ROLE_TARGET], true)) {
            throw new \InvalidArgumentException('Transfer runtime role must be source or target.');
        }

        $versions = $this->versions($role);
        $schemaFingerprints = [];
        $errors = [];
        $warnings = [];

        if ($role === self::ROLE_SOURCE) {
            $this->inspectSource($schemaFingerprints, $errors, $warnings);
        } else {
            $this->inspectTarget($schemaFingerprints, $errors);
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        sort($errors);
        sort($warnings);
        ksort($versions);
        ksort($schemaFingerprints);

        $fingerprint = CanonicalJson::fingerprint([
            'role' => $role,
            'cartshift' => CARTSHIFT_VERSION,
            'cartshift_db' => CARTSHIFT_DB_VERSION,
            'versions' => $versions,
            'schemas' => $schemaFingerprints,
            'errors' => $errors,
            'warnings' => $warnings,
        ]);

        return new TransferRuntimeReport(
            $role,
            $fingerprint,
            $versions,
            $schemaFingerprints,
            $errors,
            $warnings,
        );
    }

    /** @return array<string, string> */
    private function versions(string $role): array
    {
        $components = ['php', 'wordpress', 'cartshift', 'cartshift_db'];
        $components[] = $role === self::ROLE_SOURCE ? 'woocommerce' : 'fluentcart';

        if ($role === self::ROLE_SOURCE) {
            $components[] = 'wcs';
        }

        $versions = [];

        foreach ($components as $component) {
            $version = $this->symbols->runtimeVersion($component);

            if ($version !== null && $version !== '') {
                $versions[$component] = $version;
            }
        }

        return $versions;
    }

    /** @param array<string, string> $schemaFingerprints @param list<string> $errors @param list<string> $warnings */
    private function inspectSource(array &$schemaFingerprints, array &$errors, array &$warnings): void
    {
        foreach (self::SOURCE_FUNCTIONS as $function) {
            if (!$this->symbols->functionExists($function)) {
                $errors[] = 'source_woocommerce_api_missing';
            }
        }

        foreach (self::SOURCE_CLASSES as $class) {
            if (!$this->symbols->classExists($class)) {
                $errors[] = 'source_woocommerce_api_missing';
            }
        }

        if ($this->symbols->runtimeVersion('woocommerce') === null) {
            $errors[] = 'source_woocommerce_version_missing';
        }

        $wcsDetected = $this->symbols->classExists('WC_Subscriptions')
            || $this->symbols->classExists('WC_Subscription')
            || $this->symbols->functionExists('wcs_get_subscriptions');

        if (!$wcsDetected) {
            $warnings[] = 'source_wcs_not_installed';
            return;
        }

        foreach (self::WCS_FUNCTIONS as $function) {
            if (!$this->symbols->functionExists($function)) {
                $errors[] = 'source_wcs_api_missing';
            }
        }

        foreach (self::WCS_METHODS as $method) {
            if (!$this->symbols->methodExists('WC_Subscription', $method)) {
                $errors[] = 'source_wcs_api_missing';
            }
        }

        if ($this->symbols->runtimeVersion('wcs') === null) {
            $errors[] = 'source_wcs_version_missing';
        }

        $wcsDigest = $this->symbols->runtimeDigest('wcs');

        if ($wcsDigest === null || preg_match('/\A[a-f0-9]{64}\z/', $wcsDigest) !== 1) {
            $errors[] = 'source_wcs_source_digest_missing';
        } else {
            $schemaFingerprints['source:wcs_tree'] = $wcsDigest;
        }
    }

    /** @param array<string, string> $schemaFingerprints @param list<string> $errors */
    private function inspectTarget(array &$schemaFingerprints, array &$errors): void
    {
        if ($this->symbols->runtimeVersion('fluentcart') === null) {
            $errors[] = 'target_fluentcart_version_missing';
        }

        $expectedTypes = [
            'FluentCart\\App\\Helpers\\Helper::PRODUCT_TYPE_SIMPLE' => 'simple',
            'FluentCart\\App\\Helpers\\Helper::PRODUCT_TYPE_SIMPLE_VARIATION' => 'simple_variations',
            'FluentCart\\App\\Helpers\\Helper::PRODUCT_TYPE_ADVANCE_VARIATION' => 'advanced_variations',
        ];

        foreach ($expectedTypes as $constant => $expected) {
            if ($this->symbols->constantValue($constant) !== $expected) {
                $errors[] = 'target_product_contract_mismatch';
            }
        }

        $tables = $this->schemas->inspect(self::TARGET_TABLES);

        foreach (self::TARGET_TABLES as $table) {
            if (!isset($tables[$table])) {
                $errors[] = $this->isV8Table($table) ? 'schema_upgrade_required' : 'target_schema_missing';
                continue;
            }

            $schema = $tables[$table];
            $schemaFingerprints['table:' . $table] = CanonicalJson::fingerprint($schema);

            if (strcasecmp((string) ($schema['engine'] ?? ''), 'InnoDB') !== 0) {
                $errors[] = 'target_schema_non_transactional';
            }

            foreach (self::REQUIRED_COLUMNS[$table] ?? [] as $column) {
                if (!isset($schema['columns'][$column])) {
                    $errors[] = $this->isV8Table($table) ? 'schema_upgrade_required' : 'target_schema_missing';
                }
            }
        }

        foreach (self::TARGET_MODELS as $model) {
            if (!$this->symbols->classExists($model)) {
                $errors[] = 'target_fluentcart_model_missing';
                continue;
            }

            $contract = [
                'fillable' => $this->symbols->modelFillable($model),
                'casts' => $this->symbols->modelCasts($model),
            ];
            sort($contract['fillable']);
            ksort($contract['casts']);
            $schemaFingerprints['model:' . $model] = CanonicalJson::fingerprint($contract);
        }

        $this->validateTargetWidths($tables, $errors);
        $this->validateTargetMoneyColumns($tables, $errors);
    }

    /** @param array<string, array<string, mixed>> $tables @param list<string> $errors */
    private function validateTargetWidths(array $tables, array &$errors): void
    {
        foreach ([
            ['fct_product_variations', 'sku', 30],
            ['fct_product_variations', 'variation_identifier', 100],
        ] as [$table, $column, $minimum]) {
            $type = $this->columnType($tables, $table, $column);

            if ($type !== null
                && (preg_match('/\Avarchar\((\d+)\)/', $type, $matches) !== 1 || (int) $matches[1] < $minimum)) {
                $errors[] = 'target_schema_unrepresentable';
            }
        }
    }

    /** @param array<string, array<string, mixed>> $tables @param list<string> $errors */
    private function validateTargetMoneyColumns(array $tables, array &$errors): void
    {
        $orderRate = $this->columnType($tables, 'fct_orders', 'rate');

        if ($orderRate !== null
            && (preg_match('/\Adecimal\((\d+),(\d+)\)/', $orderRate, $matches) !== 1
                || (int) $matches[1] < 12
                || (int) $matches[2] < 4)) {
            $errors[] = 'target_schema_unrepresentable';
        }

        foreach ([['fct_order_items', 'rate'], ['fct_order_transactions', 'rate']] as [$table, $column]) {
            $type = $this->columnType($tables, $table, $column);

            if ($type !== null && preg_match('/\Abigint(?:\(\d+\))?/', $type) !== 1) {
                $errors[] = 'target_schema_unrepresentable';
            }
        }

        $mode = $this->columnType($tables, 'fct_orders', 'mode');
        if ($mode !== null && $mode !== "enum('live','test')") {
            $errors[] = 'target_schema_unrepresentable';
        }

        $taxBehavior = $this->columnType($tables, 'fct_orders', 'tax_behavior');
        if ($taxBehavior !== null && !str_starts_with($taxBehavior, 'tinyint(1)')) {
            $errors[] = 'target_schema_unrepresentable';
        }
    }

    private function isV8Table(string $table): bool
    {
        return str_starts_with($table, 'cartshift_transfer_')
            || in_array($table, ['cartshift_target_claims', 'cartshift_shared_links'], true);
    }

    /** @param array<string, array<string, mixed>> $tables */
    private function columnType(array $tables, string $table, string $column): ?string
    {
        $type = $tables[$table]['columns'][$column]['type'] ?? null;

        return is_string($type) ? strtolower($type) : null;
    }
}
