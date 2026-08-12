<?php

// WP-CLI eval-file evaluates this entrypoint after its own bootstrap code, so
// a strict_types declaration would no longer be the first statement. Keep the
// required fixture files strict and this tiny adapter compatible with WP-CLI.

if (!defined('ABSPATH')) {
    throw new RuntimeException('The installed contract must run inside WordPress.');
}

$case = (string) ($args[0] ?? '');

require_once dirname(__DIR__) . '/fixtures/catalogue.php';
require_once dirname(__DIR__) . '/fixtures/historical-order.php';
require_once dirname(__DIR__) . '/fixtures/subscriptions.php';

if (!function_exists('cartshift_contract_reset_outgoing_spies')) {
    function cartshift_contract_reset_outgoing_spies(): void
    {
        foreach (array_keys($GLOBALS['cartshift_contract_spies'] ?? []) as $sink) {
            $GLOBALS['cartshift_contract_spies'][$sink] = 0;
        }
        $GLOBALS['cartshift_contract_action_scheduler_traces'] = [];
    }
}

if ($case === 'wcs-subscription-source-contract') {
    $fixture = cartshift_contract_seed_wcs_subscription();
    cartshift_contract_reset_outgoing_spies();
    $subscription = wcs_get_subscription($fixture['subscription_id']);
    if (!$subscription instanceof WC_Subscription) {
        throw new RuntimeException('Installed WCS could not reload its public-API fixture.');
    }
    $factory = new \CartShift\Domain\Subscription\Source\WooDatasetRecordFactory();
    $relationships = $factory->relatedOrdersByType($subscription);
    $selection = new \CartShift\Domain\Transfer\TransferSelection(
        'contract-wcs',
        \CartShift\Domain\Transfer\SelectionClause::none(),
        \CartShift\Domain\Transfer\SelectionClause::none(),
        \CartShift\Domain\Transfer\SelectionClause::none(),
        \CartShift\Domain\Transfer\SelectionClause::ids([$fixture['subscription_id']]),
    );
    $records = iterator_to_array(
        (new \CartShift\Domain\Subscription\Source\WooSubscriptionRecordSource())->records($selection),
        false,
    );
    if (count($records) !== 1) {
        throw new RuntimeException('Installed WCS source did not return exactly one selected subscription.');
    }
    $payload = $records[0]->payload;
    $product = wc_get_product($fixture['product_id']);
    if (!$product instanceof WC_Product_Subscription) {
        throw new RuntimeException('Installed WCS could not reload its subscription-product fixture.');
    }
    $productData = $product->get_data();
    $productRecord = (new \CartShift\Domain\Transfer\Product\ProductRecordFactory())
        ->fromWooProduct($product, 'contract-wcs');
    $configurationStrings = array_map('strval', $productRecord->typeConfiguration);
    $variationConfigurationStrings = array_map('strval', $productRecord->variations[0]->typeConfiguration);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'source_is_wc_subscription' => $subscription instanceof WC_Subscription,
        'identity' => $records[0]->identity->canonical(),
        'relationship_keys' => array_keys($relationships),
        'parent_ids' => $relationships['parent'],
        'renewal_ids' => $relationships['renewal'],
        'switch_ids' => $relationships['switch'],
        'resubscribe_ids' => $relationships['resubscribe'],
        'expected_parent_id' => $fixture['parent_order_id'],
        'expected_renewal_id' => $fixture['renewal_order_id'],
        'canonical_relationships' => $payload['related_orders'],
        'next_payment_utc' => $payload['schedule']['next_payment_utc'],
        'end_utc' => $payload['schedule']['end_utc'],
        'period' => $payload['contract']['period'],
        'multiplier' => $payload['contract']['multiplier'],
        'currency' => $payload['currency'],
        'item_count' => count($payload['items']),
        'product_dependency' => 'contract-wcs:product:' . $fixture['product_id'],
        'dependencies' => $payload['dependencies'],
        'product_is_wc_subscription' => $product instanceof WC_Product_Subscription,
        'product_data_has_subscription_fields' => array_values(array_intersect([
            'subscription_price',
            'subscription_period',
            'subscription_period_interval',
            'subscription_length',
            'subscription_sign_up_fee',
            'subscription_trial_length',
            'subscription_trial_period',
        ], array_keys($productData))),
        'product_configuration' => $configurationStrings,
        'synthetic_variation_configuration' => $variationConfigurationStrings,
    ]);
    return;
}

if ($case === 'woo-product-source-matrix') {
    $originalManageStock = get_option('woocommerce_manage_stock', 'no');
    update_option('woocommerce_manage_stock', 'yes');
    $fixture = cartshift_contract_seed_product_matrix();
    cartshift_contract_reset_outgoing_spies();
    $factory = new \CartShift\Domain\Transfer\Product\ProductRecordFactory();
    $records = [];
    foreach ($fixture as $key => $value) {
        if (!is_int($value)) {
            continue;
        }
        $product = wc_get_product($value);
        if (!$product instanceof WC_Product) {
            throw new RuntimeException('Installed WooCommerce could not hydrate product-matrix fixture ' . $key . '.');
        }
        $records[$key] = $factory->fromWooProduct($product, 'contract-products');
    }

    $none = \CartShift\Domain\Transfer\SelectionClause::none();
    $source = new \CartShift\Domain\Transfer\Product\WooProductRecordSource($factory);
    $allSelection = new \CartShift\Domain\Transfer\TransferSelection(
        'contract-products',
        \CartShift\Domain\Transfer\SelectionClause::all(),
        $none,
        $none,
        $none,
    );

    global $wpdb;
    $lookupTable = $wpdb->prefix . 'wc_product_meta_lookup';
    $wpdb->delete($lookupTable, ['product_id' => $fixture['pending']], ['%d']);
    $allSourceIds = [];
    foreach ($source->records($allSelection) as $envelope) {
        $allSourceIds[] = (int) $envelope->identity->sourceId;
    }
    sort($allSourceIds, SORT_NUMERIC);
    $expectedRootIds = array_values(array_filter(
        $fixture,
        static fn (mixed $value, string $key): bool => is_int($value)
            && !in_array($key, ['grouped_child'], true),
        ARRAY_FILTER_USE_BOTH,
    ));
    $expectedRootIds[] = (int) $fixture['grouped_child'];
    sort($expectedRootIds, SORT_NUMERIC);

    if (!class_exists('CartShift_Contract_Broken_Product', false)) {
        class CartShift_Contract_Broken_Product extends WC_Product_Simple
        {
            public function __construct($product = 0)
            {
                throw new Exception('Deliberate installed hydration failure.');
            }
        }
    }
    $brokenId = wp_insert_post([
        'post_type' => 'product',
        'post_status' => 'publish',
        'post_title' => 'Broken hydration contract',
    ]);
    if (!is_int($brokenId) || $brokenId <= 0 || is_wp_error(wp_set_object_terms($brokenId, 'simple', 'product_type'))) {
        throw new RuntimeException('Installed hydration-negative fixture could not be created.');
    }
    $breakHydration = static fn (string $className, string $productType, string $context, int $productId): string =>
        $productId === $brokenId ? CartShift_Contract_Broken_Product::class : $className;
    add_filter('woocommerce_product_class', $breakHydration, 100, 4);
    $hydrationFailure = null;
    try {
        iterator_to_array($source->records(new \CartShift\Domain\Transfer\TransferSelection(
            'contract-products',
            \CartShift\Domain\Transfer\SelectionClause::ids([$brokenId]),
            $none,
            $none,
            $none,
        )));
    } catch (\CartShift\Domain\Transfer\SourceRecordException $exception) {
        $hydrationFailure = $exception->reasonCode;
    } finally {
        remove_filter('woocommerce_product_class', $breakHydration, 100);
    }

    $originalTaxSetting = get_option('woocommerce_prices_include_tax', 'no');
    $originalTaxEnabled = get_option('woocommerce_calc_taxes', 'no');
    try {
        update_option('woocommerce_calc_taxes', 'yes');
        update_option('woocommerce_prices_include_tax', 'yes');
        $inclusiveTax = $factory->fromWooProduct(wc_get_product($fixture['simple']), 'contract-products')->tax;
        update_option('woocommerce_prices_include_tax', 'no');
        $exclusiveTax = $factory->fromWooProduct(wc_get_product($fixture['simple']), 'contract-products')->tax;
    } finally {
        update_option('woocommerce_prices_include_tax', $originalTaxSetting);
        update_option('woocommerce_calc_taxes', $originalTaxEnabled);
    }

    $context = new \CartShift\Domain\Transfer\Product\ProductAssessmentContext(
        ['standard', 'none'],
        [],
        \CartShift\Domain\Transfer\Product\ProductFieldDecisionSet::all(
            \CartShift\Domain\Transfer\Product\ProductFieldDisposition::Migrate,
        ),
    );
    $assessor = new \CartShift\Domain\Transfer\Product\ProductCapabilityAssessor();
    $unsupported = [];
    foreach (['external', 'grouped', 'course'] as $key) {
        $assessment = $assessor->assess($records[$key], $context);
        $unsupported[$key] = [
            'outcome' => $assessment->outcome->value,
            'reason' => $assessment->reasonCode,
            'source_type' => $assessment->context['source_type'] ?? null,
        ];
    }

    $parentStockAssessment = $assessor->assess(
        $records['stock_parent'],
        new \CartShift\Domain\Transfer\Product\ProductAssessmentContext(
            ['standard', 'none'],
            [
                'exact_price_x100' => true,
                'stock_purchase_path' => true,
                'asset_hash_roundtrip' => true,
                'simple_variations' => true,
                'shared_parent_stock' => false,
            ],
            \CartShift\Domain\Transfer\Product\ProductFieldDecisionSet::all(
                \CartShift\Domain\Transfer\Product\ProductFieldDisposition::Migrate,
            ),
        ),
    );

    $simplePrice = $records['simple']->variations[0]->price;
    $blankPrice = $records['blank']->variations[0]->price;
    $digitalDownload = $records['digital']->downloads[0] ?? null;
    $scheduled = [];
    foreach (['before', 'during', 'after'] as $phase) {
        $price = $records['scheduled_' . $phase]->variations[0]->price;
        $scheduled[$phase] = [
            'effective' => $price->activePrice,
            'regular' => $price->regularPrice,
            'sale' => $price->salePrice,
            'starts' => $price->saleStartsUtc !== null,
            'ends' => $price->saleEndsUtc !== null,
        ];
    }
    $stockModes = array_map(
        static fn ($variation): string => $variation->stock->ownership->value,
        $records['stock_parent']->variations,
    );
    sort($stockModes, SORT_STRING);
    $sourceStockValues = [];
    foreach ($fixture['stock_variations'] as $variationId) {
        $sourceStockValues[] = wc_get_product($variationId)->get_manage_stock();
    }
    $variationAssets = [];
    foreach ($records['stock_parent']->variations as $variation) {
        $variationAssets[$variation->stock->ownership->value] = [
            'media' => array_map(static fn ($asset): array => [
                'role' => $asset->role,
                'provenance' => $asset->provenance,
                'owner' => $asset->owner->canonical(),
                'hash' => $asset->expectedSha256,
            ], $variation->media),
            'downloads' => array_map(static fn ($download): array => [
                'limit' => $download->limit,
                'expiry_days' => $download->expiryDays,
                'hash' => $download->contentSha256,
            ], $variation->downloads),
        ];
    }
    ksort($variationAssets, SORT_STRING);
    $policyDirectory = sys_get_temp_dir() . '/cartshift-download-policy-' . bin2hex(random_bytes(6));
    if (!mkdir($policyDirectory, 0700)) {
        throw new RuntimeException('Download-policy contract directory could not be created.');
    }
    $downloadPolicyReason = null;
    try {
        (new \CartShift\Domain\Transfer\Product\FluentCartDownloadStager($policyDirectory, 'local'))
            ->settings(2, 14);
    } catch (\CartShift\Domain\Transfer\SourceRecordException $exception) {
        $downloadPolicyReason = $exception->reasonCode;
    } finally {
        rmdir($policyDirectory);
    }

    // This case deliberately creates unsupported, trashed and lookup-corrupt
    // products, but the installed contract suite shares one disposable
    // database. Remove only this case's products so later zero-write audits are
    // challenged by their own source state instead of negative-control debris.
    $cleanupProductIds = [$brokenId];
    foreach ($fixture as $key => $value) {
        if (is_int($value) && $key !== 'unmanaged_stock_parent') {
            $cleanupProductIds[] = $value;
        }
    }
    $cleanupProductIds[] = (int) $fixture['stock_variations'][0];
    $cleanupProductIds[] = (int) $fixture['stock_variations'][1];
    foreach (array_unique($cleanupProductIds) as $cleanupProductId) {
        if (wp_delete_post((int) $cleanupProductId, true) === false) {
            throw new RuntimeException('Product-matrix negative control could not remove its fixture.');
        }
    }
    update_option('woocommerce_manage_stock', $originalManageStock);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'expected_root_ids' => $expectedRootIds,
        'census_contains_every_fixture' => array_diff($expectedRootIds, $allSourceIds) === [],
        'missing_lookup_product_selected' => in_array((int) $fixture['pending'], $allSourceIds, true),
        'hydration_failure' => $hydrationFailure,
        'types' => array_map(static fn ($record): string => $record->productType, $records),
        'statuses' => array_map(static fn ($record): string => $record->status, $records),
        'simple_name' => $records['simple']->name,
        'simple_description' => $records['simple']->description,
        'simple_short_description' => $records['simple']->shortDescription,
        'simple_catalogue' => [
            'visibility' => $records['simple']->catalogVisibility,
            'featured' => $records['simple']->featured,
            'menu_order' => $records['simple']->menuOrder,
            'purchase_note' => $records['simple']->purchaseNote,
        ],
        'simple_dimensions' => $records['simple']->variations[0]->dimensions,
        'simple_media' => array_map(static fn ($asset): array => [
            'role' => $asset->role,
            'size' => $asset->size,
            'hash' => $asset->expectedSha256,
        ], $records['simple']->media),
        'zero_price' => [
            'effective' => $simplePrice->activePrice,
            'regular' => $simplePrice->regularPrice,
            'sale' => $simplePrice->salePrice,
        ],
        'blank_price' => [
            'effective' => $blankPrice->activePrice,
            'regular' => $blankPrice->regularPrice,
            'sale' => $blankPrice->salePrice,
        ],
        'digital' => [
            'fulfilment' => $records['digital']->fulfilmentType,
            'download_count' => count($records['digital']->downloads),
            'download_limit' => $digitalDownload?->limit,
            'download_expiry_days' => $digitalDownload?->expiryDays,
            'download_hash' => $digitalDownload?->contentSha256,
            'download_path_hash' => hash_file('sha256', $fixture['download_path']),
        ],
        'scheduled' => $scheduled,
        'tax_settings' => [
            'inclusive' => $inclusiveTax->pricesIncludeTax,
            'exclusive' => $exclusiveTax->pricesIncludeTax,
        ],
        'stock_modes' => $stockModes,
        'source_stock_values' => $sourceStockValues,
        'parent_stock_quantity' => $records['stock_parent']->stock->quantity,
        'parent_stock_block_reason' => $parentStockAssessment->reasonCode,
        'variation_assets' => $variationAssets,
        'positive_day_expiry_reason' => $downloadPolicyReason,
        'unsupported' => $unsupported,
    ]);
    return;
}

if ($case === 'variable-product-simple-variations') {
    $fixture = cartshift_contract_seed_variable_product();
    cartshift_contract_reset_outgoing_spies();
    $source = wc_get_product($fixture['product_id']);
    if (!$source instanceof WC_Product_Variable) {
        throw new RuntimeException('Installed Woo variable product could not be reloaded.');
    }

    foreach (get_posts([
        'post_type' => 'fluent-products',
        'post_status' => 'any',
        'meta_key' => '_cartshift_contract_target',
        'meta_value' => 'variable-product-simple-variations-v1',
        'numberposts' => -1,
        'fields' => 'ids',
    ]) as $oldTargetId) {
        wp_delete_post((int) $oldTargetId, true);
    }

    $record = (new \CartShift\Domain\Transfer\Product\ProductRecordFactory())
        ->fromWooProduct($source, 'contract-source');
    $context = new \CartShift\Domain\Transfer\Product\ProductAssessmentContext(
        ['standard', 'none'],
        [],
        \CartShift\Domain\Transfer\Product\ProductFieldDecisionSet::all(
            \CartShift\Domain\Transfer\Product\ProductFieldDisposition::Migrate,
        ),
        targetShippingClasses: ['none' => 0],
    );
    $plans = (new \CartShift\Domain\Transfer\Product\SimpleVariationPlanner())->plan($record, $context);

    $targetPostId = wp_insert_post([
        'post_type' => 'fluent-products',
        // This disposable contract exercises the storefront/cart behaviour.
        // The production writer remains draft-first and is tested in Task 13.
        'post_status' => 'publish',
        'post_title' => $record->name,
        'post_name' => $record->slug,
        'post_content' => $record->description,
        'post_excerpt' => $record->shortDescription,
    ], true);
    if (is_wp_error($targetPostId)) {
        throw new RuntimeException($targetPostId->get_error_message());
    }
    $targetPostId = (int) $targetPostId;
    update_post_meta($targetPostId, '_cartshift_contract_target', 'variable-product-simple-variations-v1');

    $detail = \FluentCart\App\Models\ProductDetail::query()->create([
        'post_id' => $targetPostId,
        'fulfillment_type' => $record->fulfilmentType,
        'variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_SIMPLE_VARIATION,
        'manage_stock' => 0,
        'stock_availability' => 'in-stock',
        'manage_downloadable' => 0,
        'other_info' => ['group_pricing_by' => 'none'],
    ]);
    if (!$detail) {
        throw new RuntimeException('Installed FluentCart rejected the simple-variation detail.');
    }

    $targetRows = [];
    foreach ($plans as $plan) {
        $fields = $plan->targetFields;
        unset($fields['variation_type']);
        $fields['post_id'] = $targetPostId;
        $row = \FluentCart\App\Models\ProductVariation::query()->create($fields);
        if (!$row) {
            throw new RuntimeException('Installed FluentCart rejected a planned simple variation.');
        }
        $targetRows[] = $row;
    }

    $prices = array_map(static fn ($row): int => (int) $row->item_price, $targetRows);
    $detail->update([
        'default_variation_id' => (int) $targetRows[0]->id,
        'min_price' => min($prices),
        'max_price' => max($prices),
    ]);

    $target = \FluentCart\App\Models\Product::find($targetPostId);
    if (!$target) {
        throw new RuntimeException('Installed FluentCart target product could not be reloaded.');
    }
    $target->load(['detail', 'variants']);
    ob_start();
    (new \FluentCart\App\Services\Renderer\ProductRenderer($target))->renderBuySection();
    $html = (string) ob_get_clean();

    $sourceByTarget = [];
    $mappedSourceIds = [];
    $targetVariationIds = [];
    $identifiers = [];
    foreach ($target->variants as $variant) {
        $sourceIdentity = (string) ($variant->other_info['source_identity'] ?? '');
        $parts = explode(':variation:', $sourceIdentity);
        $sourceId = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($sourceId <= 0) {
            throw new RuntimeException('Planned source variation identity did not survive target reload.');
        }
        $sourceByTarget[(int) $variant->id] = $sourceId;
        $mappedSourceIds[] = $sourceId;
        $targetVariationIds[] = (int) $variant->id;
        $identifiers[] = (string) $variant->variation_identifier;
    }

    $cartableSourceIds = [];
    $checkoutObjectIds = [];
    foreach ($targetVariationIds as $targetVariationId) {
        $cart = \FluentCart\Api\Resource\FrontendResource\CartResource::generateCartForInstantCheckout(
            $targetVariationId,
            1,
        );
        if (is_wp_error($cart)) {
            throw new RuntimeException('Installed cart rejected a planned simple variation: ' . $cart->get_error_message());
        }
        if (!$cart) {
            throw new RuntimeException('Installed cart returned no checkout payload for a planned simple variation.');
        }
        $cartData = $cart->cart_data;
        if (count($cartData) !== 1 || (int) ($cartData[0]['object_id'] ?? 0) !== $targetVariationId) {
            throw new RuntimeException('Selected target variation did not survive the checkout payload.');
        }
        $cartableSourceIds[] = $sourceByTarget[$targetVariationId];
        $checkoutObjectIds[] = (int) $cartData[0]['object_id'];
    }

    sort($mappedSourceIds);
    sort($cartableSourceIds);
    sort($targetVariationIds);
    sort($checkoutObjectIds);
    $sourceVariationIds = $fixture['variation_ids'];
    sort($sourceVariationIds);
    $bulkPayload = [
        'ID' => $targetPostId,
        'post_title' => $record->name,
        'post_status' => 'published',
        'detail' => [
            'fulfillment_type' => $record->fulfilmentType,
            'variation_type' => 'simple_variations',
        ],
        'variants' => array_map(static fn ($plan): array => $plan->targetFields, $plans),
    ];
    $insertValidator = new class extends \FluentCart\App\Services\BulkProductInsertService {
        public function errors(array $payload): ?array
        {
            return $this->validateProduct($payload);
        }
    };
    $updateValidator = new class extends \FluentCart\App\Services\BulkProductUpdateService {
        public function errors(array $payload): ?array
        {
            return $this->validateProduct($payload);
        }
    };
    $missingTitlePayload = $bulkPayload;
    $missingTitlePayload['variants'][0]['variation_title'] = '';
    $updatePayload = $bulkPayload;
    $updatePayload['post_status'] = 'publish';
    $missingUpdateTitlePayload = $updatePayload;
    $missingUpdateTitlePayload['variants'][0]['variation_title'] = '';

    $wooMigratorSource = file_get_contents(
        WP_PLUGIN_DIR . '/fluent-cart/app/Modules/WooCommerceMigrator/WooCommerceMigratorCli.php',
    );
    if (!is_string($wooMigratorSource)) {
        throw new RuntimeException('Installed FluentCart Woo migrator source could not be read.');
    }

    $detail->update([
        'variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_ADVANCE_VARIATION,
        'other_info' => ['group_pricing_by' => 'none'],
    ]);
    $advanced = \FluentCart\App\Models\Product::find($targetPostId);
    $advanced->load(['detail', 'variants']);
    ob_start();
    (new \FluentCart\App\Services\Renderer\ProductRenderer($advanced))->renderBuySection();
    $advancedHtml = (string) ob_get_clean();

    global $wpdb;
    $relations = $wpdb->prefix . 'fct_atts_relations';
    $relationCount = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$relations} WHERE object_id IN (" . implode(',', array_fill(0, count($targetVariationIds), '%d')) . ')',
        ...$targetVariationIds,
    ));
    $columns = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}fct_product_variations", ARRAY_A);
    $columnLengths = [];
    foreach ($columns as $column) {
        if (preg_match('/varchar\((\d+)\)/i', (string) $column['Type'], $matches) === 1) {
            $columnLengths[(string) $column['Field']] = (int) $matches[1];
        }
    }

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'helper_constant' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_SIMPLE_VARIATION,
        'variation_type' => $target->detail->variation_type,
        'identifier_column_length' => $columnLengths['variation_identifier'] ?? null,
        'sku_column_length' => $columnLengths['sku'] ?? null,
        'source_variation_ids' => $sourceVariationIds,
        'mapped_source_variation_ids' => $mappedSourceIds,
        'target_variation_ids' => $targetVariationIds,
        'checkout_object_ids' => $checkoutObjectIds,
        'buy_section_rendered' => str_contains($html, 'data-fluent-cart-product-pricing-section'),
        'rendered_variant_count' => substr_count($html, 'data-fluent-cart-product-variant'),
        'cartable_source_variation_ids' => $cartableSourceIds,
        'target_variation_count' => count($target->variants),
        'has_attribute_config' => array_key_exists('attribute_config', (array) $target->detail->other_info),
        'advanced_relation_count' => $relationCount,
        'identifiers_unique_and_bounded' => count($identifiers) === count(array_unique($identifiers, SORT_STRING))
            && array_filter($identifiers, static fn (string $identifier): bool => strlen($identifier) > 100) === [],
        'bulk_insert_accepts_simple_variations' => $insertValidator->errors($bulkPayload) === null,
        'bulk_insert_requires_titles' => $insertValidator->errors($missingTitlePayload) !== null,
        'bulk_update_accepts_simple_variations' => $updateValidator->errors($updatePayload) === null,
        'bulk_update_requires_titles' => $updateValidator->errors($missingUpdateTitlePayload) !== null,
        'woo_migrator_uses_source_variation_id' => str_contains(
            $wooMigratorSource,
            "'variation_identifier' => \$variation->ID",
        ) && str_contains($wooMigratorSource, "'variation_type' => 'simple_variations'"),
        'advanced_constant' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_ADVANCE_VARIATION,
        'unconfigured_advanced_buy_section_hidden' => !str_contains(
            $advancedHtml,
            'data-fluent-cart-product-pricing-section',
        ),
    ]);
    return;
}

if ($case === 'local-download-delivery') {
    $uploads = wp_get_upload_dir();
    $uploadRoot = (string) ($uploads['basedir'] ?? '');
    if ($uploadRoot === '' || !is_dir($uploadRoot)) {
        throw new RuntimeException('Installed WordPress upload root is unavailable.');
    }

    $contractId = 'cartshift-download-' . str_replace('-', '', wp_generate_uuid4());
    $sourceDirectory = $uploadRoot . '/' . $contractId;
    $packageDirectory = sys_get_temp_dir() . '/' . $contractId . '-package';
    if (!mkdir($sourceDirectory, 0700, true) || !mkdir($packageDirectory, 0700, true)) {
        throw new RuntimeException('Installed download contract directories could not be created.');
    }
    $sourcePath = $sourceDirectory . '/manual.pdf';
    $sourceBytes = "%PDF-1.4\nCartShift exact delivery contract\n%%EOF\n";
    if (file_put_contents($sourcePath, $sourceBytes) !== strlen($sourceBytes)) {
        throw new RuntimeException('Installed download source fixture could not be written.');
    }

    $manifest = (new \CartShift\Domain\Transfer\Package\AssetExporter(
        $packageDirectory,
        $uploadRoot,
    ))->export($sourcePath);
    $driver = new \FluentCart\App\Services\FileSystem\Drivers\Local\LocalDriver();
    $localRoot = rtrim($driver->getFilePath(''), '/');
    $stager = new \CartShift\Domain\Transfer\Product\FluentCartDownloadStager($localRoot, 'local');
    $context = new \CartShift\Domain\Transfer\StageContext(
        $packageDirectory,
        'contract-download',
        'installed-runtime-contract',
    );
    $staged = $stager->stage($manifest, $context);
    $settings = $stager->settings(2, -1);

    $download = \FluentCart\App\Models\ProductDownload::query()->create([
        'post_id' => 0,
        'product_variation_id' => [],
        'download_identifier' => wp_generate_uuid4(),
        'title' => 'Manual',
        'type' => 'application/pdf',
        'driver' => 'local',
        'file_name' => 'manual.pdf',
        'file_path' => $staged->relativePath,
        'file_url' => '',
        'file_size' => $manifest->bytes,
        'settings' => $settings,
        'serial' => 1,
    ]);
    if (!$download) {
        throw new RuntimeException('Installed FluentCart rejected the staged download record.');
    }

    $resolved = (new \FluentCart\App\Services\FileSystem\DownloadService())->getDownloadablePath([
        'driver' => 'local',
        'file' => $staged->relativePath,
        'download_id' => (int) $download->id,
    ]);
    if (!is_string($resolved) || !is_file($resolved)) {
        throw new RuntimeException('Installed DownloadService did not resolve the staged local file.');
    }
    $deliveredHash = hash_file('sha256', $resolved);
    $deliveredBytes = filesize($resolved);
    $reloaded = \FluentCart\App\Models\ProductDownload::query()->find((int) $download->id);
    if (!$reloaded) {
        throw new RuntimeException('Installed FluentCart download record could not be reloaded.');
    }

    $customerHelperSource = file_get_contents(WP_PLUGIN_DIR . '/fluent-cart/app/Helpers/CustomerHelper.php');
    if (!is_string($customerHelperSource)) {
        throw new RuntimeException('Installed FluentCart download permission source could not be read.');
    }
    $result = [
        'driver' => (string) $reloaded->driver,
        'file_path' => (string) $reloaded->file_path,
        'manifest_sha256' => $manifest->sha256,
        'manifest_bytes' => $manifest->bytes,
        'delivered_sha256' => $deliveredHash,
        'delivered_bytes' => $deliveredBytes,
        'download_service_resolved_staged_path' => realpath($resolved) === realpath($staged->targetPath),
        'unstaged_basename_exists' => is_file($localRoot . '/manual.pdf'),
        'settings' => $reloaded->settings,
        'installed_expiry_unit_is_months' => str_contains(
            $customerHelperSource,
            "\$downloadExpiryDate->modify('+'. \$productDownloadExpiry .' months')",
        ),
    ];

    $download->delete();
    $stager->rollback($staged);
    $result['rollback_removed_unchanged_file'] = !file_exists($staged->targetPath);
    unlink($sourcePath);
    rmdir($sourceDirectory);
    unlink($packageDirectory . '/assets/' . $manifest->sha256);
    rmdir($packageDirectory . '/assets');
    rmdir($packageDirectory);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode($result);
    return;
}

if ($case === 'transactional-product-writer') {
    if ((string) get_option('cartshift_db_version', '0') === '7'
        && !\CartShift\Support\Migrations::upgradeExplicit('7', '8')) {
        throw new RuntimeException('Transactional writer contract could not prepare the explicit v8 schema.');
    }
    $fixture = cartshift_contract_seed_variable_product();
    cartshift_contract_reset_outgoing_spies();
    $source = wc_get_product($fixture['product_id']);
    if (!$source instanceof WC_Product_Variable) {
        throw new RuntimeException('Transactional writer source product could not be reloaded.');
    }
    $record = (new \CartShift\Domain\Transfer\Product\ProductRecordFactory())
        ->fromWooProduct($source, 'contract-source');
    $assessmentContext = new \CartShift\Domain\Transfer\Product\ProductAssessmentContext(
        ['standard', 'none'],
        [],
        \CartShift\Domain\Transfer\Product\ProductFieldDecisionSet::all(
            \CartShift\Domain\Transfer\Product\ProductFieldDisposition::Migrate,
        ),
        targetShippingClasses: ['none' => 0],
    );
    $plan = \CartShift\Domain\Transfer\Product\ProductStagePlan::build($record, $assessmentContext);
    $packageDirectory = sys_get_temp_dir() . '/cartshift-writer-' . str_replace('-', '', wp_generate_uuid4());
    if (!mkdir($packageDirectory . '/assets', 0700, true)) {
        throw new RuntimeException('Transactional writer package directory could not be created.');
    }
    $stageContext = new \CartShift\Domain\Transfer\StageContext(
        $packageDirectory,
        'contract-writer',
        'installed-runtime-contract',
    );
    $gateway = new \CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway();
    $maps = new \CartShift\Storage\IdMapRepository('contract-source');
    $writer = new \CartShift\Domain\Transfer\Product\FluentCartProductWriter(
        $gateway,
        $maps,
        new \CartShift\Domain\Transfer\Product\ProductReconciler($gateway, $maps),
    );

    global $wpdb;
    $rowCounts = static function () use ($wpdb): array {
        return [
            'products' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'fluent-products'",
            ),
            'details' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_details"),
            'variations' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_variations"),
            'terms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}"),
            'term_taxonomies' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy}"),
            'term_relationships' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships}"),
            'maps' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_id_map WHERE source_key = %s AND is_simulated = 0",
                'contract-source',
            )),
        ];
    };
    $beforeForcedFailure = $rowCounts();
    $failingMaps = new class($maps) implements \CartShift\Domain\Transfer\Identity\CheckedMappingStore {
        public function __construct(
            private readonly \CartShift\Domain\Transfer\Identity\CheckedMappingStore $delegate,
        ) {
        }

        public function get(
            \CartShift\Domain\Transfer\SourceIdentity $identity,
        ): ?\CartShift\Domain\Transfer\Identity\MappingRecord {
            return $this->delegate->get($identity);
        }

        public function storeOrThrow(
            \CartShift\Domain\Transfer\SourceIdentity $identity,
            int $targetId,
            string $migrationId,
            string $sourceFingerprint,
            string $targetFingerprint,
            \CartShift\Domain\Transfer\Identity\MapState $state,
            bool $createdByMigration,
            int $generation = 1,
        ): \CartShift\Domain\Transfer\Identity\MappingRecord {
            if (str_contains($identity->sourceId, ':variation:')) {
                throw new RuntimeException('forced installed variation-map failure');
            }
            return $this->delegate->storeOrThrow(
                $identity,
                $targetId,
                $migrationId,
                $sourceFingerprint,
                $targetFingerprint,
                $state,
                $createdByMigration,
                $generation,
            );
        }

        public function transitionOrThrow(
            \CartShift\Domain\Transfer\SourceIdentity $identity,
            \CartShift\Domain\Transfer\Identity\MapState $expected,
            \CartShift\Domain\Transfer\Identity\MapState $next,
            string $expectedTargetFingerprint,
            string $nextTargetFingerprint,
        ): \CartShift\Domain\Transfer\Identity\MappingRecord {
            return $this->delegate->transitionOrThrow(
                $identity,
                $expected,
                $next,
                $expectedTargetFingerprint,
                $nextTargetFingerprint,
            );
        }
    };
    $forcedFailure = null;
    try {
        (new \CartShift\Domain\Transfer\Product\FluentCartProductWriter(
            $gateway,
            $failingMaps,
            new \CartShift\Domain\Transfer\Product\ProductReconciler($gateway, $failingMaps),
        ))->stage($plan, $stageContext);
    } catch (RuntimeException $exception) {
        $forcedFailure = $exception->getMessage();
    }
    $afterForcedFailure = $rowCounts();

    $first = $writer->stage($plan, $stageContext);

    $mappingRows = $wpdb->get_results($wpdb->prepare(
        "SELECT wc_id, fc_id, record_state, source_fingerprint, target_fingerprint
         FROM {$wpdb->prefix}cartshift_id_map
         WHERE source_key = %s AND migration_id = %s AND is_simulated = 0
         ORDER BY wc_id ASC",
        'contract-source',
        'contract-writer',
    ), ARRAY_A);
    $beforeRetry = \CartShift\Support\CanonicalJson::fingerprint([
        'target' => $gateway->snapshot($first->targetId),
        'maps' => $mappingRows,
    ]);
    $retry = $writer->stage($plan, $stageContext);
    $mappingRowsAfter = $wpdb->get_results($wpdb->prepare(
        "SELECT wc_id, fc_id, record_state, source_fingerprint, target_fingerprint
         FROM {$wpdb->prefix}cartshift_id_map
         WHERE source_key = %s AND migration_id = %s AND is_simulated = 0
         ORDER BY wc_id ASC",
        'contract-source',
        'contract-writer',
    ), ARRAY_A);
    $afterRetry = \CartShift\Support\CanonicalJson::fingerprint([
        'target' => $gateway->snapshot($retry->targetId),
        'maps' => $mappingRowsAfter,
    ]);
    $snapshot = $gateway->snapshot($first->targetId);
    $mappingStates = array_values(array_unique(array_column($mappingRows, 'record_state')));
    sort($mappingStates);
    $stagingSideEffects = $GLOBALS['cartshift_contract_spies'] ?? [];
    $stagingActionSchedulerTraces = $GLOBALS['cartshift_contract_action_scheduler_traces'] ?? [];
    cartshift_contract_reset_outgoing_spies();

    $draftVariationIds = $first->variationIds;
    sort($draftVariationIds);
    foreach ($draftVariationIds as $variationId) {
        if ($wpdb->update(
            $wpdb->prefix . 'fct_product_variations',
            ['item_status' => 'draft'],
            ['id' => $variationId, 'post_id' => $first->targetId],
        ) !== 1) {
            throw new RuntimeException('Draft behaviour contract could not deactivate a variation.');
        }
    }
    $draftCartRowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_carts");
    $draftBehaviour = $gateway->behaviour($first->targetId, $draftVariationIds);
    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_carts") !== $draftCartRowsBefore) {
        throw new RuntimeException('Draft behaviour verification leaked a temporary cart.');
    }
    $draftRestored = $gateway->snapshot($first->targetId);
    $draftSideEffects = $GLOBALS['cartshift_contract_spies'] ?? [];
    cartshift_contract_reset_outgoing_spies();

    if ($wpdb->update($wpdb->posts, ['post_status' => 'private'], ['ID' => $first->targetId]) !== 1) {
        throw new RuntimeException('Private behaviour contract could not set product visibility.');
    }
    $privateCartRowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_carts");
    $privateBehaviour = $gateway->behaviour($first->targetId, $draftVariationIds);
    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_carts") !== $privateCartRowsBefore) {
        throw new RuntimeException('Private behaviour verification leaked a temporary cart.');
    }
    $privateRestored = $gateway->snapshot($first->targetId);
    $privateSideEffects = $GLOBALS['cartshift_contract_spies'] ?? [];
    cartshift_contract_reset_outgoing_spies();

    $historicalIdentity = new \CartShift\Domain\Transfer\SourceIdentity(
        'contract-source',
        'product',
        '404',
    );
    $historicalLine = [
        'name' => 'Deleted contract product',
        'sku' => 'DELETED-404',
        'unit_total' => 2500,
        'currency' => 'PLN',
    ];
    $historicalPlan = (new \CartShift\Domain\Transfer\Product\HistoricalProductPlaceholder())->plan(
        $historicalIdentity,
        $historicalLine,
        $assessmentContext,
        \CartShift\Domain\Transfer\Product\HistoricalProductPlaceholder::approvalFingerprint(
            $historicalIdentity,
            $historicalLine,
        ),
    );
    $historicalResult = $writer->stage(
        $historicalPlan,
        new \CartShift\Domain\Transfer\StageContext(
            $packageDirectory,
            'contract-history',
            'installed-runtime-contract',
        ),
    );
    $historicalSnapshot = $gateway->snapshot($historicalResult->targetId);
    $historicalCartRowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_carts");
    $historicalBehaviour = $gateway->behaviour(
        $historicalResult->targetId,
        $historicalResult->variationIds,
    );
    if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_carts") !== $historicalCartRowsBefore) {
        throw new RuntimeException('Historical behaviour verification leaked a temporary cart.');
    }
    $historicalSideEffects = $GLOBALS['cartshift_contract_spies'] ?? [];

    rmdir($packageDirectory . '/assets');
    rmdir($packageDirectory);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'post_status' => $snapshot['product']['post_status'] ?? null,
        'variation_type' => $snapshot['detail']['variation_type'] ?? null,
        'variation_count' => count($snapshot['variations'] ?? []),
        'mapping_count' => count($mappingRows),
        'expected_mapping_count' => count($plan->sourceIdentities()),
        'mapping_states' => $mappingStates,
        'first_target_id' => $first->targetId,
        'retry_target_id' => $retry->targetId,
        'first_fingerprint' => $first->targetFingerprint,
        'retry_fingerprint' => $retry->targetFingerprint,
        'retry_reused' => $retry->reused,
        'retry_database_unchanged' => $beforeRetry === $afterRetry,
        'forced_failure' => $forcedFailure,
        'forced_failure_left_no_rows' => $beforeForcedFailure === $afterForcedFailure,
        'draft_variation_ids' => $draftVariationIds,
        'draft_buy_section_rendered' => $draftBehaviour['buy_section_rendered'] ?? null,
        'draft_cartable_variation_ids' => $draftBehaviour['cartable_variation_ids'] ?? null,
        'draft_checkout_object_ids' => $draftBehaviour['checkout_object_ids'] ?? null,
        'draft_restored_post_status' => $draftRestored['product']['post_status'] ?? null,
        'draft_restored_variation_statuses' => array_values(array_unique(array_column(
            $draftRestored['variations'] ?? [],
            'item_status',
        ))),
        'private_variation_ids' => $draftVariationIds,
        'private_buy_section_rendered' => $privateBehaviour['buy_section_rendered'] ?? null,
        'private_cartable_variation_ids' => $privateBehaviour['cartable_variation_ids'] ?? null,
        'private_checkout_object_ids' => $privateBehaviour['checkout_object_ids'] ?? null,
        'private_restored_post_status' => $privateRestored['product']['post_status'] ?? null,
        'private_restored_variation_statuses' => array_values(array_unique(array_column(
            $privateRestored['variations'] ?? [],
            'item_status',
        ))),
        'historical_post_status' => $historicalSnapshot['product']['post_status'] ?? null,
        'historical_variation_status' => $historicalSnapshot['variations'][0]['item_status'] ?? null,
        'historical_stock_status' => $historicalSnapshot['variations'][0]['stock_status'] ?? null,
        'historical_buy_section_rendered' => $historicalBehaviour['buy_section_rendered'] ?? null,
        'historical_cartable_variation_ids' => $historicalBehaviour['cartable_variation_ids'] ?? null,
        'historical_checkout_object_ids' => $historicalBehaviour['checkout_object_ids'] ?? null,
        'staging_side_effects' => $stagingSideEffects,
        'staging_action_scheduler_traces' => $stagingActionSchedulerTraces,
        'draft_side_effects' => $draftSideEffects,
        'private_side_effects' => $privateSideEffects,
        'historical_side_effects' => $historicalSideEffects,
    ]);
    return;
}

if ($case === 'rich-product-roundtrip') {
    if ((string) get_option('cartshift_db_version', '0') === '7'
        && !\CartShift\Support\Migrations::upgradeExplicit('7', '8')) {
        throw new RuntimeException('Rich product contract could not prepare the explicit v8 schema.');
    }
    update_option('woocommerce_weight_unit', 'kg');
    update_option('woocommerce_dimension_unit', 'cm');
    $sourceId = cartshift_contract_seed_rich_roundtrip_product();
    cartshift_contract_reset_outgoing_spies();
    $source = wc_get_product($sourceId);
    if (!$source instanceof WC_Product_Simple) {
        throw new RuntimeException('Installed WooCommerce could not reload the rich product fixture.');
    }
    $record = (new \CartShift\Domain\Transfer\Product\ProductRecordFactory())
        ->fromWooProduct($source, 'contract-rich');
    $context = new \CartShift\Domain\Transfer\Product\ProductAssessmentContext(
        ['standard', 'none'],
        [],
        \CartShift\Domain\Transfer\Product\ProductFieldDecisionSet::all(
            \CartShift\Domain\Transfer\Product\ProductFieldDisposition::Migrate,
        ),
        targetShippingClasses: ['none' => 0],
    );
    $plan = \CartShift\Domain\Transfer\Product\ProductStagePlan::build($record, $context);
    $packageDirectory = sys_get_temp_dir() . '/cartshift-rich-' . bin2hex(random_bytes(8));
    if (!mkdir($packageDirectory . '/assets', 0700, true)) {
        throw new RuntimeException('Rich product package fixture could not be created.');
    }
    $stageContext = new \CartShift\Domain\Transfer\StageContext(
        $packageDirectory,
        'contract-rich-writer',
        'installed-runtime-contract',
    );
    $gateway = new \CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway();
    $maps = new \CartShift\Storage\IdMapRepository('contract-rich');
    $writer = new \CartShift\Domain\Transfer\Product\FluentCartProductWriter(
        $gateway,
        $maps,
        new \CartShift\Domain\Transfer\Product\ProductReconciler($gateway, $maps),
    );
    $first = $writer->stage($plan, $stageContext);
    $beforeRetry = \CartShift\Support\CanonicalJson::encode($gateway->snapshot($first->targetId));
    $retry = $writer->stage($plan, $stageContext);
    $snapshot = $gateway->snapshot($first->targetId);
    $afterRetry = \CartShift\Support\CanonicalJson::encode($snapshot);
    $variation = $snapshot['variations'][0] ?? [];
    $detailInfo = $snapshot['detail']['other_info'] ?? [];
    $variationInfo = $variation['other_info'] ?? [];
    $taxonomyRoundTrip = array_map(static fn (array $row): array => [
        'taxonomy' => $row['taxonomy'] ?? null,
        'name' => $row['name'] ?? null,
        'slug' => $row['slug'] ?? null,
        'description' => $row['description'] ?? null,
        'parent' => $row['parent'] ?? null,
        'term_order' => $row['term_order'] ?? null,
    ], $snapshot['taxonomy_rows'] ?? []);
    rmdir($packageDirectory . '/assets');
    rmdir($packageDirectory);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'post' => [
            'title' => $snapshot['product']['post_title'] ?? null,
            'content' => $snapshot['product']['post_content'] ?? null,
            'excerpt' => $snapshot['product']['post_excerpt'] ?? null,
            'status' => $snapshot['product']['post_status'] ?? null,
            'menu_order' => $snapshot['product']['menu_order'] ?? null,
        ],
        'detail' => [
            'fulfillment_type' => $snapshot['detail']['fulfillment_type'] ?? null,
            'variation_type' => $snapshot['detail']['variation_type'] ?? null,
            'min_price' => $snapshot['detail']['min_price'] ?? null,
            'max_price' => $snapshot['detail']['max_price'] ?? null,
            'catalog_visibility' => $detailInfo['catalog_visibility'] ?? null,
            'featured' => $detailInfo['featured'] ?? null,
            'purchase_note' => $detailInfo['purchase_note'] ?? null,
            'source_product_sku' => $detailInfo['source_product_sku'] ?? null,
        ],
        'variation' => [
            'sku' => $variation['sku'] ?? null,
            'item_price' => $variation['item_price'] ?? null,
            'compare_price' => $variation['compare_price'] ?? null,
            'manage_stock' => $variation['manage_stock'] ?? null,
            'total_stock' => $variation['total_stock'] ?? null,
            'available' => $variation['available'] ?? null,
            'sold_individually' => $variation['sold_individually'] ?? null,
            'stock_status' => $variation['stock_status'] ?? null,
            'tax_class' => $variationInfo['tax_class'] ?? null,
            'tax_exempt' => $variationInfo['tax_exempt'] ?? null,
            'tax_inclusion' => $variationInfo['tax_inclusion'] ?? null,
            'weight' => $variationInfo['weight'] ?? null,
            'length' => $variationInfo['length'] ?? null,
            'width' => $variationInfo['width'] ?? null,
            'height' => $variationInfo['height'] ?? null,
            'weight_unit' => $variationInfo['weight_unit'] ?? null,
            'dimension_unit' => $variationInfo['dimension_unit'] ?? null,
        ],
        'taxonomies' => $taxonomyRoundTrip,
        'retry_reused' => $retry->reused,
        'retry_byte_stable' => $beforeRetry === $afterRetry,
        'side_effects' => $GLOBALS['cartshift_contract_spies'] ?? [],
    ]);
    return;
}

if ($case === 'asset-product-roundtrip') {
    if ((string) get_option('cartshift_db_version', '0') === '7'
        && !\CartShift\Support\Migrations::upgradeExplicit('7', '8')) {
        throw new RuntimeException('Asset product contract could not prepare the explicit v8 schema.');
    }
    $sourceId = cartshift_contract_seed_asset_roundtrip_product();
    cartshift_contract_reset_outgoing_spies();
    $source = wc_get_product($sourceId);
    if (!$source instanceof WC_Product_Variable) {
        throw new RuntimeException('Installed WooCommerce could not reload the asset product fixture.');
    }
    $record = (new \CartShift\Domain\Transfer\Product\ProductRecordFactory())
        ->fromWooProduct($source, 'contract-assets');
    $context = new \CartShift\Domain\Transfer\Product\ProductAssessmentContext(
        ['standard', 'none'],
        [],
        \CartShift\Domain\Transfer\Product\ProductFieldDecisionSet::all(
            \CartShift\Domain\Transfer\Product\ProductFieldDisposition::Migrate,
        ),
        targetShippingClasses: ['none' => 0],
    );
    $packageDirectory = sys_get_temp_dir() . '/cartshift-assets-' . bin2hex(random_bytes(8));
    if (!mkdir($packageDirectory, 0700, true)) {
        throw new RuntimeException('Asset product package fixture could not be created.');
    }
    $uploads = wp_get_upload_dir();
    $uploadRoot = rtrim((string) ($uploads['basedir'] ?? ''), '/');
    $uploadUrl = rtrim((string) ($uploads['baseurl'] ?? ''), '/');
    if ($uploadRoot === '' || $uploadUrl === '' || !is_dir($uploadRoot)) {
        throw new RuntimeException('Asset product contract has no exact WordPress upload root.');
    }
    $exporter = new \CartShift\Domain\Transfer\Package\AssetExporter($packageDirectory, $uploadRoot);
    $manifest = [];
    $mediaGroups = [
        $record->media,
        ...array_map(static fn ($variation): array => $variation->media, $record->variations),
    ];
    foreach ($mediaGroups as $references) {
        foreach ($references as $reference) {
            if (!str_starts_with($reference->locator, $uploadUrl . '/')) {
                throw new RuntimeException('Asset product media locator escaped the installed upload URL.');
            }
            $relative = rawurldecode(substr($reference->locator, strlen($uploadUrl) + 1));
            $entry = $exporter->export(
                $uploadRoot . '/' . $relative,
                basename($relative),
                'local',
                $reference->expectedSha256,
            );
            $manifest[$reference->identity->canonical()] = $entry;
            $manifest[$entry->sha256] = $entry;
        }
    }
    $downloadGroups = [
        $record->downloads,
        ...array_map(static fn ($variation): array => $variation->downloads, $record->variations),
    ];
    foreach ($downloadGroups as $references) {
        foreach ($references as $reference) {
            $entry = $exporter->export(
                $reference->locator,
                basename($reference->locator),
                'local',
                $reference->contentSha256,
            );
            $manifest[$reference->identity->canonical()] = $entry;
            $manifest[$entry->sha256] = $entry;
        }
    }
    $plan = \CartShift\Domain\Transfer\Product\ProductStagePlan::build(
        $record,
        $context,
        assetManifest: $manifest,
    );
    $gateway = new \CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway();
    $maps = new \CartShift\Storage\IdMapRepository('contract-assets');
    $downloadRoot = rtrim((new \FluentCart\App\Services\FileSystem\Drivers\Local\LocalDriver())->getFilePath(''), '/');
    $writer = new \CartShift\Domain\Transfer\Product\FluentCartProductWriter(
        $gateway,
        $maps,
        new \CartShift\Domain\Transfer\Product\ProductReconciler($gateway, $maps),
        new \CartShift\Domain\Transfer\Product\WordPressMediaStager($uploadRoot),
        new \CartShift\Domain\Transfer\Product\FluentCartDownloadStager($downloadRoot, 'local'),
    );
    $stageContext = new \CartShift\Domain\Transfer\StageContext(
        $packageDirectory,
        'contract-asset-writer',
        'installed-runtime-contract',
    );
    $first = $writer->stage($plan, $stageContext);
    $beforeRetry = \CartShift\Support\CanonicalJson::encode($gateway->snapshot($first->targetId));
    $retry = $writer->stage($plan, $stageContext);
    $snapshot = $gateway->snapshot($first->targetId);
    $afterRetry = \CartShift\Support\CanonicalJson::encode($snapshot);

    $sourceVariationIdentities = array_map(
        static fn ($variation): string => $variation->identity->canonical(),
        $record->variations,
    );
    sort($sourceVariationIdentities, SORT_STRING);
    $variationAttributes = [];
    foreach ($snapshot['variations'] as $targetVariation) {
        $variationAttributes[(string) ($targetVariation['variation_title'] ?? '')] =
            $targetVariation['other_info']['source_attributes'] ?? null;
    }
    ksort($variationAttributes, SORT_STRING);
    $targetVariationIds = [];
    foreach ($sourceVariationIdentities as $identity) {
        $targetVariationIds[] = $first->sourceTargetIds[$identity];
    }
    sort($targetVariationIds, SORT_NUMERIC);
    $variationMedia = array_values(array_filter(
        $snapshot['media'],
        static fn (array $media): bool => ($media['role'] ?? null) === 'variation',
    ));
    $variationMediaProvenance = array_column($variationMedia, 'provenance');
    $variationMediaOwners = array_column($variationMedia, 'owner_identity');
    sort($variationMediaProvenance, SORT_STRING);
    sort($variationMediaOwners, SORT_STRING);
    $productMedia = array_values(array_filter(
        $snapshot['media'],
        static fn (array $media): bool => ($media['role'] ?? null) !== 'variation',
    ));
    usort($productMedia, static fn (array $left, array $right): int =>
        ((string) ($left['role'] ?? '')) <=> ((string) ($right['role'] ?? '')));
    $productMediaRoles = array_column($productMedia, 'role');
    $productMediaOwners = array_column($productMedia, 'owner_identity');
    $productMediaProvenance = array_column($productMedia, 'provenance');
    $stockOwnership = [];
    $stockQuantities = [];
    foreach ($record->variations as $variation) {
        $stockOwnership[] = $variation->stock->ownership->value;
        $stockQuantities[] = $variation->stock->quantity;
    }
    sort($stockOwnership, SORT_STRING);
    rsort($stockQuantities, SORT_NUMERIC);
    $downloadVariationIds = [];
    $downloadSettings = [];
    $targetDownloadHashes = [];
    foreach ($snapshot['downloads'] as $download) {
        $ids = array_values(array_map('intval', (array) $download['product_variation_id']));
        if (count($ids) !== 1) {
            throw new RuntimeException('Variation download did not retain exactly one target owner.');
        }
        $downloadVariationIds[] = $ids[0];
        $downloadSettings[] = $download['settings'];
        $targetDownloadHashes[] = $download['file_content']['sha256'];
    }
    sort($downloadVariationIds, SORT_NUMERIC);
    usort($downloadSettings, static fn (array $left, array $right): int => $left <=> $right);
    sort($targetDownloadHashes, SORT_STRING);
    $sourceDownloadHashes = [];
    foreach ($record->variations as $variation) {
        foreach ($variation->downloads as $download) {
            $sourceDownloadHashes[] = $download->contentSha256;
        }
    }
    sort($sourceDownloadHashes, SORT_STRING);
    $mediaSourceIdentities = array_map(
        static fn (array $item): string => $item['reference']->identity->canonical(),
        $plan->media,
    );

    foreach (glob($packageDirectory . '/assets/*') ?: [] as $assetPath) {
        unlink($assetPath);
    }
    rmdir($packageDirectory . '/assets');
    rmdir($packageDirectory);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'plan_media_count' => count($plan->media),
        'unique_media_source_count' => count(array_unique($mediaSourceIdentities)),
        'plan_download_count' => count($plan->downloads),
        'target_media_count' => count($snapshot['media']),
        'target_attachment_count' => count(array_unique(array_column($snapshot['media'], 'target_id'))),
        'variation_media_provenance' => $variationMediaProvenance,
        'variation_media_owners' => $variationMediaOwners,
        'product_media_roles' => $productMediaRoles,
        'product_media_owners' => $productMediaOwners,
        'product_media_provenance' => $productMediaProvenance,
        'source_product_identity' => $record->identity->canonical(),
        'source_variation_identities' => $sourceVariationIdentities,
        'variation_attributes' => $variationAttributes,
        'stock_ownership' => $stockOwnership,
        'stock_quantities' => $stockQuantities,
        'target_download_count' => count($snapshot['downloads']),
        'target_variation_ids' => $targetVariationIds,
        'download_variation_ids' => $downloadVariationIds,
        'download_settings' => $downloadSettings,
        'source_download_hashes' => $sourceDownloadHashes,
        'target_download_hashes' => $targetDownloadHashes,
        'first_media_ids' => $first->mediaIds,
        'retry_media_ids' => $retry->mediaIds,
        'first_download_ids' => $first->downloadIds,
        'retry_download_ids' => $retry->downloadIds,
        'retry_reused' => $retry->reused,
        'retry_byte_stable' => $beforeRetry === $afterRetry,
        'side_effects' => $GLOBALS['cartshift_contract_spies'] ?? [],
    ]);
    return;
}

if ($case === 'woo-order-ledger') {
    $fixture = cartshift_contract_seed_order_ledger();
    cartshift_contract_reset_outgoing_spies();
    $order = wc_get_order($fixture['order_id']);
    if (!$order instanceof WC_Order) {
        throw new RuntimeException('Installed Woo order-ledger fixture could not be reloaded through CRUD.');
    }
    $factory = new \CartShift\Domain\Transfer\Order\OrderRecordFactory(
        sourceStoreCurrency: 'PLN',
        targetBaseCurrency: 'PLN',
        noteIdentifierKey: 'installed-contract-run-key',
        approvedMetaKeys: ['_billing_vat_number'],
    );
    $record = $factory->fromWooOrder($order, 'contract-source');
    $selection = new \CartShift\Domain\Transfer\TransferSelection(
        'contract-source',
        \CartShift\Domain\Transfer\SelectionClause::none(),
        \CartShift\Domain\Transfer\SelectionClause::none(),
        \CartShift\Domain\Transfer\SelectionClause::ids([$order->get_id()]),
        \CartShift\Domain\Transfer\SelectionClause::none(),
    );
    $sourceRecords = iterator_to_array((new \CartShift\Domain\Transfer\Order\WooOrderRecordSource(
        $factory,
        static fn () => [$order],
    ))->records($selection));

    $negativeReason = static function (callable $operation): ?string {
        try {
            $operation();
            return null;
        } catch (\CartShift\Domain\Transfer\SourceRecordException $exception) {
            return $exception->reasonCode;
        }
    };
    $totalDrift = clone $order;
    $totalDrift->set_total('141.46');
    $taxDrift = clone $order;
    $taxDrift->set_cart_tax('21.86');
    $duplicateReason = $negativeReason(static function () use ($factory, $selection, $order): void {
        iterator_to_array((new \CartShift\Domain\Transfer\Order\WooOrderRecordSource(
            $factory,
            static fn () => [$order, $order],
        ))->records($selection));
    });
    $missingReason = $negativeReason(static function () use ($factory, $selection): void {
        iterator_to_array((new \CartShift\Domain\Transfer\Order\WooOrderRecordSource(
            $factory,
            static fn () => [],
        ))->records($selection));
    });

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'source_is_wc_order' => $order instanceof WC_Order,
        'identity' => $record->identity->canonical(),
        'subtotal' => $record->subtotal,
        'coupon_discount_total' => $record->couponDiscountTotal,
        'manual_discount_total' => $record->manualDiscountTotal,
        'discount_tax' => $record->discountTax,
        'shipping_total' => $record->shippingTotal,
        'shipping_tax' => $record->shippingTax,
        'fee_total' => $record->feeTotal,
        'fee_tax' => $record->feeTax,
        'cart_tax' => $record->cartTax,
        'gross_total' => $record->grossTotal,
        'product_line_ids' => array_column($record->toArray()['product_lines'], 'source_line_id'),
        'fee_line_count' => count($record->feeLines),
        'shipping_line_count' => count($record->shippingLines),
        'coupon_line_count' => count($record->couponLines),
        'tax_rate_count' => count($record->taxRates),
        'address_types' => array_column($record->toArray()['addresses'], 'type'),
        'note_ids' => array_column($record->toArray()['notes'], 'source_note_id'),
        'fixture_note_id' => $fixture['note_id'],
        'note_public_identifier_is_non_content' => !str_contains(
            (string) ($record->notes[0]->publicIdentifier ?? ''),
            'Private installed contract note',
        ),
        'payment_evidence' => array_column($record->toArray()['payment_events'], 'evidence_kind'),
        'source_record_count' => count($sourceRecords),
        'source_record_digest_matches' => ($sourceRecords[0]->privateContentDigest ?? null) === $record->envelope()->privateContentDigest,
        'total_drift_reason' => $negativeReason(static fn () => $factory->fromWooOrder($totalDrift, 'contract-source')),
        'tax_drift_reason' => $negativeReason(static fn () => $factory->fromWooOrder($taxDrift, 'contract-source')),
        'duplicate_selection_reason' => $duplicateReason,
        'missing_selection_reason' => $missingReason,
    ]);
    return;
}

if ($case === 'woo-order-storage-parity') {
    $expectedStore = (string) ($args[1] ?? '');
    if (!in_array($expectedStore, ['cpt', 'hpos'], true)) {
        throw new RuntimeException('Order storage parity requires cpt or hpos.');
    }
    $actualStore = \CartShift\Support\WooStorage::isHposEnabled() ? 'hpos' : 'cpt';
    if ($actualStore !== $expectedStore) {
        throw new RuntimeException('WooCommerce did not switch to the requested authoritative order store.');
    }
    $fixture = cartshift_contract_seed_order_ledger('order-ledger-' . $expectedStore . '-parity-v1');
    cartshift_contract_reset_outgoing_spies();
    $order = wc_get_order($fixture['order_id']);
    if (!$order instanceof WC_Order) {
        throw new RuntimeException('WooCommerce could not reload the order-storage parity fixture.');
    }
    $record = (new \CartShift\Domain\Transfer\Order\OrderRecordFactory(
        sourceStoreCurrency: 'PLN',
        targetBaseCurrency: 'PLN',
        noteIdentifierKey: 'installed-storage-parity-key',
        approvedMetaKeys: ['_billing_vat_number'],
    ))->fromWooOrder($order, 'contract-storage');
    $payload = $record->toArray();
    global $wpdb;

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'store' => $actualStore,
        'authoritative_row_exists' => $expectedStore === 'hpos'
            ? (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id = %d AND type = 'shop_order'",
                $order->get_id(),
            )) === 1
            : (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'shop_order'",
                $order->get_id(),
            )) === 1,
        'semantic' => [
            'status' => $record->sourceStatus,
            'currency' => $record->currency,
            'prices_include_tax' => $record->pricesIncludeTax,
            'subtotal' => $record->subtotal,
            'coupon_discount_total' => $record->couponDiscountTotal,
            'shipping_total' => $record->shippingTotal,
            'shipping_tax' => $record->shippingTax,
            'fee_total' => $record->feeTotal,
            'fee_tax' => $record->feeTax,
            'cart_tax' => $record->cartTax,
            'gross_total' => $record->grossTotal,
            'line_items' => array_map(static fn (array $line): array => [
                'name' => $line['name'],
                'sku' => $line['sku'],
                'quantity' => $line['quantity'],
                'subtotal' => $line['subtotal'],
                'subtotal_tax' => $line['subtotal_tax'],
                'total' => $line['line_total'],
                'total_tax' => $line['tax_total'],
            ], $payload['product_lines']),
            'fees' => array_map(static fn (array $line): array => [
                'name' => $line['name'], 'total' => $line['total'], 'tax' => $line['tax'],
            ], $payload['fee_lines']),
            'shipping' => array_map(static fn (array $line): array => [
                'method_id' => $line['method_id'], 'title' => $line['title'],
                'total' => $line['total'], 'tax' => $line['tax'],
            ], $payload['shipping_lines']),
            'coupons' => array_map(static fn (array $line): array => [
                'code' => $line['code'], 'discount' => $line['discount'], 'discount_tax' => $line['discount_tax'],
            ], $payload['coupon_lines']),
            'tax_rates' => array_map(static fn (array $line): array => [
                'rate_code' => $line['code'], 'rate_percent' => $line['percentage'],
                'tax_total' => $line['order_tax'], 'shipping_tax_total' => $line['shipping_tax'],
            ], $payload['tax_rates']),
            'addresses' => array_map(static fn (array $address): array => [
                'type' => $address['type'], 'country' => $address['country'], 'city' => $address['city'],
            ], $payload['addresses']),
            'payment_events' => array_map(static fn (array $event): array => [
                'event_type' => $event['type'], 'amount' => $event['amount'],
                'status' => $event['status'], 'gateway' => $event['payment_method'],
            ], $payload['payment_events']),
            'note_visibility' => array_column($payload['notes'], 'customer_visible'),
        ],
    ]);
    return;
}

if ($case === 'order-money-contract') {
    $fixture = cartshift_contract_seed_order_ledger();
    cartshift_contract_reset_outgoing_spies();
    $wooOrder = wc_get_order($fixture['order_id']);
    if (!$wooOrder instanceof WC_Order) {
        throw new RuntimeException('Installed money fixture could not reload its WooCommerce order.');
    }
    $factory = new \CartShift\Domain\Transfer\Order\OrderRecordFactory(
        sourceStoreCurrency: 'PLN',
        targetBaseCurrency: 'PLN',
        noteIdentifierKey: 'installed-money-contract-key',
        approvedMetaKeys: ['_billing_vat_number'],
    );
    $source = $factory->fromWooOrder($wooOrder, 'contract-source');

    $customer = \FluentCart\App\Models\Customer::query()->create([
        'email' => 'money-contract@example.invalid',
        'first_name' => 'Money',
        'last_name' => 'Contract',
        'status' => 'active',
        'country' => 'PL',
    ]);
    $coupon = \FluentCart\App\Models\Coupon::query()->create([
        'title' => 'Money contract coupon',
        'code' => 'DOG10',
        'status' => 'active',
        'type' => 'fixed',
        'conditions' => [],
        'amount' => 1000,
        'stackable' => 'yes',
        'priority' => 1,
        'use_count' => 0,
    ]);
    if (!$customer || !$coupon) {
        throw new RuntimeException('Installed FluentCart rejected money-contract dependencies.');
    }

    $createCheckoutOrder = static function (bool $inclusive) use ($customer, $coupon): \FluentCart\App\Models\Order {
        $itemSubtotal = $inclusive ? 12300 : 10000;
        $couponDiscount = $inclusive ? 1230 : 1000;
        $feeAmount = $inclusive ? 615 : 500;
        $shippingAmount = $inclusive ? 2460 : 2000;
        $processor = new \FluentCart\App\Helpers\CheckoutProcessor([[
            'is_custom' => true,
            'post_id' => 501,
            'object_id' => 601,
            'product_title' => 'Frozen course',
            'variation_title' => 'Frozen course',
            'fulfillment_type' => 'digital',
            'quantity' => 1,
            'unit_price' => $itemSubtotal,
            'subtotal' => $itemSubtotal,
            'tax_amount' => 2070,
            'coupon_discount' => $couponDiscount,
            'manual_discount' => 0,
            'shipping_charge' => 0,
            'other_info' => ['payment_type' => 'onetime'],
            'line_meta' => ['tax_config' => ['inclusive' => $inclusive]],
        ]], [
            'customer_id' => (int) $customer->id,
            'shipping_charge' => $shippingAmount,
            'shipping_method_id' => 2,
            'shipping_method_title' => 'Courier',
            'tax_total' => 2185,
            'tax_behavior' => $inclusive ? 2 : 1,
            'store_tax_behavior' => $inclusive ? 2 : 1,
            'exclusive_tax_total' => $inclusive ? 0 : 2070,
            'fee_tax' => 115,
            'fee_tax_lines' => [['label' => 'Handling', 'tax_amount' => 115, 'inclusive' => $inclusive]],
            'shipping_tax' => 460,
            'payment_method' => 'wc_migrated',
            'applied_coupons' => [
                'DOG10' => ['code' => 'DOG10', 'id' => (int) $coupon->id, 'discount' => $couponDiscount],
            ],
            'fees' => [[
                'key' => 'handling',
                'label' => 'Handling',
                'amount' => $feeAmount,
                'source' => 'contract',
                'taxable' => true,
            ]],
        ]);
        $created = $processor->createDraftOrder();
        if (!$created instanceof \FluentCart\App\Models\Order) {
            throw new RuntimeException('Installed CheckoutProcessor rejected a normal money-contract order.');
        }
        \FluentCart\App\Modules\Tax\TaxModule::persistTaxRates(
            (int) $created->id,
            [[
                'rate_id' => 901,
                'label' => 'VAT',
                'tax_amount' => 2185,
                'rate_percent' => 23,
                'is_compound' => false,
                'taxable_amount' => 11370,
                'inclusive' => $inclusive,
            ]],
            ['tax_country' => 'PL', 'shipping_inclusive' => $inclusive],
            460,
            [['rate_id' => 901, 'shipping_tax' => 460]],
        );
        return \FluentCart\App\Models\Order::query()->find((int) $created->id);
    };

    $exclusive = $createCheckoutOrder(false);
    $inclusive = $createCheckoutOrder(true);
    $exclusive->load(['order_items', 'appliedCoupons', 'orderTaxRates']);
    $inclusive->load(['order_items', 'appliedCoupons', 'orderTaxRates']);

    $context = new \CartShift\Domain\Transfer\Order\OrderProjectionContext(
        productTargets: [
            $source->productLines[0]->identity->canonical() => [
                'post_id' => 501,
                'object_id' => 601,
                'fulfillment_type' => 'digital',
            ],
        ],
        couponTargets: [$source->couponLines[0]->identity->canonical() => (int) $coupon->id],
        taxRateTargets: [$source->taxRates[0]->identity->canonical() => 901],
        paymentMode: 'test',
        historicalPaymentTitle: 'Historical WooCommerce provenance',
        taxRoundingAtSubtotal: get_option('woocommerce_tax_round_at_subtotal', 'no') === 'yes',
    );
    $contract = new \CartShift\Domain\Transfer\Order\FluentCartOrderMoneyContract();
    $projection = $contract->project($source, $context);
    $reconciliation = $contract->reconcile($source, $projection);

    $findItem = static function (object $order, string $paymentType): ?object {
        foreach ($order->order_items as $item) {
            if ((string) $item->payment_type === $paymentType) {
                return $item;
            }
        }
        return null;
    };
    $exclusiveProduct = $findItem($exclusive, 'onetime');
    $exclusiveFee = $findItem($exclusive, 'fee');
    $inclusiveProduct = $findItem($inclusive, 'onetime');
    $inclusiveFee = $findItem($inclusive, 'fee');
    $projectedTax = $projection->taxRates[0]->row;

    $zeroProcessor = new \FluentCart\App\Helpers\CheckoutProcessor([[
        'is_custom' => true,
        'post_id' => 501,
        'object_id' => 601,
        'product_title' => 'Zero tax product',
        'variation_title' => 'Zero tax product',
        'fulfillment_type' => 'digital',
        'quantity' => 1,
        'unit_price' => 1000,
        'subtotal' => 1000,
        'other_info' => ['payment_type' => 'onetime'],
    ]], [
        'customer_id' => (int) $customer->id,
        'payment_method' => 'wc_migrated',
        'tax_behavior' => 0,
    ]);
    $zeroOrder = $zeroProcessor->createDraftOrder();
    if (!$zeroOrder instanceof \FluentCart\App\Models\Order) {
        throw new RuntimeException('Installed zero-tax checkout fixture failed.');
    }
    \FluentCart\App\Modules\Tax\TaxModule::persistTaxRates((int) $zeroOrder->id, [], [], 0);
    $zeroRows = \FluentCart\App\Models\OrderTaxRate::query()->where('order_id', (int) $zeroOrder->id)->get();

    \FluentCart\App\Modules\Tax\TaxModule::persistTaxRates(
        (int) $zeroOrder->id,
        [
            ['rate_id' => 902, 'label' => 'Primary', 'tax_amount' => 100, 'rate_percent' => 10, 'is_compound' => false, 'taxable_amount' => 1000, 'inclusive' => false],
            ['rate_id' => 903, 'label' => 'Compound', 'tax_amount' => 200, 'rate_percent' => 20, 'is_compound' => true, 'taxable_amount' => 1100, 'inclusive' => false],
        ],
        [],
        1,
        [
            ['rate_id' => 902, 'shipping_tax' => 0],
            ['rate_id' => 903, 'shipping_tax' => 0],
        ],
    );
    $compoundRows = \FluentCart\App\Models\OrderTaxRate::query()
        ->where('order_id', (int) $zeroOrder->id)
        ->orderBy('tax_rate_id')
        ->get();

    $constructor = (new ReflectionClass($source))->getConstructor();
    if (!$constructor) {
        throw new RuntimeException('OrderRecord constructor disappeared.');
    }
    $negativeArgs = [];
    foreach ($constructor->getParameters() as $parameter) {
        $negativeArgs[$parameter->getName()] = $source->{$parameter->getName()};
    }
    $negativeArgs['feeTotal'] = -100;
    $negativeArgs['feeTax'] = 0;
    $negativeArgs['feeLines'] = [new \CartShift\Domain\Transfer\Order\FeeLineRecord(
        new \CartShift\Domain\Transfer\SourceIdentity('contract-source', 'order', $source->identity->sourceId . ':fee:999'),
        999,
        'Credit-like fee',
        -100,
        0,
        [],
        [],
    )];
    $negativeRecord = (new ReflectionClass($source))->newInstanceArgs(array_values($negativeArgs));
    try {
        $contract->project($negativeRecord, $context);
        $negativeReason = null;
    } catch (\CartShift\Domain\Transfer\SourceRecordException $exception) {
        $negativeReason = $exception->reasonCode;
    }

    $exclusiveHeaderFields = ['subtotal', 'coupon_discount_total', 'shipping_total', 'shipping_tax', 'fee_total', 'tax_total', 'tax_behavior', 'total_amount'];
    $exclusiveHeaderMatches = true;
    foreach ($exclusiveHeaderFields as $field) {
        if ((int) $exclusive->{$field} !== (int) $projection->header[$field]) {
            $exclusiveHeaderMatches = false;
        }
    }
    $taxRow = $exclusive->orderTaxRates[0] ?? null;
    $couponRow = $exclusive->appliedCoupons[0] ?? null;

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'exclusive_header_matches' => $exclusiveHeaderMatches,
        'exclusive_product_item_matches' => $exclusiveProduct
            && (int) $exclusiveProduct->subtotal === (int) $projection->productItems[0]['subtotal']
            && (int) $exclusiveProduct->tax_amount === (int) $projection->productItems[0]['tax_amount']
            && (int) $exclusiveProduct->discount_total === (int) $projection->productItems[0]['discount_total']
            && (int) $exclusiveProduct->line_total === (int) $projection->productItems[0]['line_total'],
        'exclusive_fee_item_matches' => $exclusiveFee
            && (int) $exclusiveFee->subtotal === (int) $projection->fees[0]->row['subtotal']
            && (int) $exclusiveFee->tax_amount === (int) $projection->fees[0]->row['tax_amount']
            && (string) $exclusiveFee->payment_type === 'fee',
        'exclusive_coupon_matches' => $couponRow
            && (int) $couponRow->coupon_id === (int) $projection->coupons[0]->row['coupon_id']
            && (int) $couponRow->amount === (int) $projection->coupons[0]->row['amount'],
        'exclusive_tax_row_matches' => $taxRow
            && (int) $taxRow->tax_rate_id === (int) $projectedTax['tax_rate_id']
            && (int) $taxRow->order_tax === (int) $projectedTax['order_tax']
            && (int) $taxRow->shipping_tax === (int) $projectedTax['shipping_tax']
            && (int) $taxRow->total_tax === (int) $projectedTax['total_tax'],
        'inclusive_header_matches' => (int) $inclusive->subtotal === 12300
            && (int) $inclusive->coupon_discount_total === 1230
            && (int) $inclusive->fee_total === 615
            && (int) $inclusive->shipping_total === 2460
            && (int) $inclusive->total_amount === 14145,
        'inclusive_product_item_matches' => $inclusiveProduct
            && (int) $inclusiveProduct->subtotal === 12300
            && (int) $inclusiveProduct->discount_total === 1230
            && (int) $inclusiveProduct->line_total === 11070,
        'inclusive_fee_item_matches' => $inclusiveFee
            && (int) $inclusiveFee->subtotal === 615
            && (int) $inclusiveFee->tax_amount === 0,
        'zero_tax_sentinel_matches' => count($zeroRows) === 1
            && (int) $zeroRows[0]->tax_rate_id === 0
            && (int) $zeroRows[0]->total_tax === 0,
        'shipping_remainder_by_rate' => array_map(static fn ($row): int => (int) $row->shipping_tax, $compoundRows->all()),
        'compound_rate_count' => count($compoundRows),
        'projection_reconciles' => $reconciliation->matches,
        'negative_fee_reason' => $negativeReason,
    ]);
    return;
}

if ($case === 'legacy-dry-run-negative-control') {
    cartshift_contract_seed_variable_product();
    require_once dirname(__DIR__) . '/MutationSpy/MutationSpy.php';
    $spy = new \CartShift\Tests\Integration\MutationSpy\MutationSpy();
    $spy->install();

    try {
        (new ReflectionMethod(\CartShift\CLI\MigrateCommand::class, 'legacyMigrate'))->invoke(null, [], [
            'entities' => 'product',
            'dry-run' => true,
        ]);
    } catch (RuntimeException $exception) {
        $spy->uninstall();

        if ($exception->getMessage() !== 'Audit attempted mutating SQL.') {
            throw $exception;
        }

        echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
            'mutation_detected' => true,
            'message' => $exception->getMessage(),
        ]);
        return;
    }

    $spy->uninstall();
    throw new RuntimeException('Legacy dry run did not attempt a database mutation.');
}

if ($case === 'zero-write-audit') {
    cartshift_contract_seed_variable_product();
    require_once dirname(__DIR__) . '/MutationSpy/MutationSpy.php';
    $spy = new \CartShift\Tests\Integration\MutationSpy\MutationSpy();
    $spy->install();

    try {
        foreach (array_keys($GLOBALS['cartshift_contract_spies'] ?? []) as $sink) {
            $GLOBALS['cartshift_contract_spies'][$sink] = 0;
        }
        $before = $spy->snapshot();
        \CartShift\CLI\TransferCommand::audit([], [
            'role' => 'source',
            'source-key' => 'contract-source',
            'products' => 'all',
            'customers' => 'none',
            'orders' => 'none',
            'subscriptions' => 'none',
            'format' => 'json',
        ]);
        $after = $spy->snapshot();
    } finally {
        $spy->uninstall();
    }

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'unchanged' => hash_equals($before['fingerprint'], $after['fingerprint']),
        'ready' => true,
        'before_fingerprint' => $before['fingerprint'],
        'after_fingerprint' => $after['fingerprint'],
        'outgoing' => $after['outgoing'],
    ]);
    return;
}

if ($case === 'zero-write-preparation-matrix') {
    $fixture = cartshift_contract_seed_variable_product();
    if ((string) get_option('cartshift_db_version', '0') === '7'
        && !\CartShift\Support\Migrations::upgradeExplicit('7', '8')) {
        throw new RuntimeException('Zero-write preparation matrix could not prepare the explicit v8 schema.');
    }
    $root = sys_get_temp_dir() . '/cartshift-zero-write-' . bin2hex(random_bytes(8));
    $destination = $root . '/packages';
    $private = $root . '/private';
    if (!mkdir($root, 0700) || !mkdir($destination, 0700) || !mkdir($private, 0700)) {
        throw new RuntimeException('Zero-write preparation matrix could not create its private destination.');
    }
    $decisions = $root . '/decision-set.json';
    file_put_contents($decisions, \CartShift\Domain\Transfer\Decision\TransferDecisionSet::empty()->canonicalJson());
    chmod($decisions, 0600);
    $sourceKey = 'contract-preflight';
    $sourceFingerprint = (new \CartShift\Domain\Transfer\Package\LoadedSourceInstanceFingerprint())->fingerprint();
    $registry = new \CartShift\Domain\Transfer\Package\SourceInstanceRegistry(
        $root . '/source-instance-registry.json',
    );
    $registry->bindOwnerApproved(
        $sourceKey,
        $sourceFingerprint,
        \CartShift\Domain\Transfer\Package\SourceInstanceRegistry::approval($sourceKey, $sourceFingerprint),
    );

    require_once dirname(__DIR__) . '/MutationSpy/MutationSpy.php';
    $spy = new \CartShift\Tests\Integration\MutationSpy\MutationSpy();
    $capture = static function (callable $command): array {
        ob_start();
        try {
            $command();
            $output = trim((string) ob_get_clean());
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
        $lines = preg_split('/\R/', $output) ?: [];
        $document = json_decode((string) end($lines), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new RuntimeException('Zero-write command emitted no JSON document.');
        }
        return $document;
    };
    $removeTree = static function (string $path) use (&$removeTree): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $removeTree($path . '/' . $entry);
        }
        rmdir($path);
    };

    $spy->install();
    try {
        cartshift_contract_reset_outgoing_spies();
        $before = $spy->snapshot();
        $sourceCompatibility = $capture(static fn (): mixed =>
            \CartShift\CLI\TransferCommand::compatibility([], ['role' => 'source', 'format' => 'json']));
        $targetCompatibility = $capture(static fn (): mixed =>
            \CartShift\CLI\TransferCommand::compatibility([], ['role' => 'target', 'format' => 'json']));
        $selection = [
            'role' => 'source',
            'source-key' => $sourceKey,
            'products' => 'ids:' . (string) $fixture['product_id'],
            'customers' => 'none',
            'orders' => 'none',
            'subscriptions' => 'none',
            'decision-set' => $decisions,
            'format' => 'json',
        ];
        $audit = $capture(static fn (): mixed => \CartShift\CLI\TransferCommand::audit([], $selection));
        $export = $capture(static fn (): mixed => \CartShift\CLI\TransferCommand::export([], [
            ...$selection,
            'destination' => $destination,
        ]));
        $package = (string) ($export['path'] ?? '');
        $validated = $capture(static fn (): mixed => \CartShift\CLI\TransferCommand::validatePackage([], [
            'role' => 'target',
            'package' => $package,
            'format' => 'json',
        ]));
        $inspection = $capture(static fn (): mixed => \CartShift\CLI\TransferCommand::inspectTarget([], [
            'role' => 'target',
            'source-key' => $sourceKey,
            'format' => 'json',
        ]));
        $prepared = $capture(static fn (): mixed => \CartShift\CLI\TransferCommand::prepare([], [
            'role' => 'target',
            'package' => $package,
            'decision-set' => $decisions,
            'private-dir' => $private,
            'execution-context' => 'rehearsal',
            'format' => 'json',
        ]));
        $after = $spy->snapshot();
    } finally {
        $spy->uninstall();
    }

    $externalFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && !$file->isLink()) {
            $externalFiles[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($externalFiles, SORT_STRING);
    $removeTree($root);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'unchanged' => hash_equals($before['fingerprint'], $after['fingerprint']),
        'before_fingerprint' => $before['fingerprint'],
        'after_fingerprint' => $after['fingerprint'],
        'source_ready' => $sourceCompatibility['ready'] ?? false,
        'target_ready' => $targetCompatibility['ready'] ?? false,
        'audit_ready' => $audit['ready'] ?? false,
        'package_validated' => ($validated['status'] ?? null) === 'validated',
        'inspection_fingerprinted' => isset($inspection['fingerprint'])
            && preg_match('/\A[a-f0-9]{64}\z/D', (string) $inspection['fingerprint']) === 1,
        'prepared_state' => $prepared['state'] ?? null,
        'selection_fingerprint_matches' => isset($export['selection_fingerprint'], $validated['selection_fingerprint'])
            && hash_equals((string) $export['selection_fingerprint'], (string) $validated['selection_fingerprint']),
        'external_file_count' => count($externalFiles),
        'outgoing' => $after['outgoing'],
    ]);
    return;
}

if ($case === 'schema-upgrade-v8') {
    if (!defined('CARTSHIFT_TRANSFER_MAINTENANCE')) {
        define('CARTSHIFT_TRANSFER_MAINTENANCE', true);
    }

    // The installed suite shares one disposable database, so prepare an exact
    // v7 fixture rather than depending on PHPUnit file ordering. This is test
    // setup only; production downgrade is deliberately unsupported.
    global $wpdb;
    foreach ([
        'cartshift_transfer_outbox',
        'cartshift_transfer_records',
        'cartshift_transfer_runs',
        'cartshift_transfer_leases',
        'cartshift_shared_links',
        'cartshift_target_claims',
    ] as $v8Table) {
        $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}{$v8Table}`");
    }
    update_option('cartshift_db_version', '7');

    $before = (string) get_option('cartshift_db_version', '0');
    \CartShift\CLI\TransferCommand::upgradeSchema([], [
        'role' => 'target',
        'from' => '7',
        'to' => '8',
        'confirm-backup' => str_repeat('a', 64),
        'execution-context' => 'rehearsal',
        'format' => 'json',
    ]);
    $after = (string) get_option('cartshift_db_version', '0');
    $target = (new \CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe())->inspect('target');

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'before' => $before,
        'after' => $after,
        'upgraded' => $after === '8',
        'target_ready' => $target->isReady(),
        'target_errors' => $target->errors,
    ]);
    return;
}

if ($case === 'target-inspection-zero-write') {
    \CartShift\Support\Migrations::upgradeExplicit('7', '8');
    require_once dirname(__DIR__) . '/MutationSpy/MutationSpy.php';
    $spy = new \CartShift\Tests\Integration\MutationSpy\MutationSpy();
    $spy->install();

    try {
        foreach (array_keys($GLOBALS['cartshift_contract_spies'] ?? []) as $sink) {
            $GLOBALS['cartshift_contract_spies'][$sink] = 0;
        }
        $before = $spy->snapshot();
        \CartShift\CLI\TransferCommand::inspectTarget([], [
            'role' => 'target',
            'source-key' => 'contract-source',
            'format' => 'json',
        ]);
        $after = $spy->snapshot();
    } finally {
        $spy->uninstall();
    }

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'unchanged' => hash_equals($before['fingerprint'], $after['fingerprint']),
        'before_fingerprint' => $before['fingerprint'],
        'after_fingerprint' => $after['fingerprint'],
        'outgoing' => $after['outgoing'],
    ]);
    return;
}

if ($case === 'target-claim-race') {
    global $wpdb;
    \CartShift\Support\Migrations::upgradeExplicit('7', '8');
    $claims = $wpdb->prefix . 'cartshift_target_claims';
    $links = $wpdb->prefix . 'cartshift_shared_links';
    $map = $wpdb->prefix . 'cartshift_id_map';
    $outbox = $wpdb->prefix . 'cartshift_transfer_outbox';
    $wpdb->query("DELETE FROM {$claims}");
    $wpdb->query("DELETE FROM {$links}");

    $first = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $second = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $first->suppress_errors(true);
    $second->suppress_errors(true);
    $claim = [
        'entity_type' => 'order',
        'target_id' => 900,
        'source_key' => 'lapka-web',
        'source_id' => '42',
        'run_id' => 'run-web',
        'source_fingerprint' => str_repeat('a', 64),
        'target_fingerprint' => str_repeat('b', 64),
        'claim_state' => 'claimed',
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ];
    $first->query('START TRANSACTION');
    $winner = $first->insert($claims, $claim);
    $first->query('COMMIT');
    $second->query('START TRANSACTION');
    $loser = $second->insert($claims, [
        ...$claim,
        'source_key' => 'lapka-klub',
        'source_id' => '84',
        'run_id' => 'run-klub',
    ]);

    if ($loser === false) {
        $second->query('ROLLBACK');
    } else {
        $second->query('COMMIT');
    }

    $sharedWeb = $wpdb->insert($links, [
        'source_key' => 'lapka-web',
        'entity_type' => 'product',
        'source_id' => '42',
        'target_id' => 901,
        'target_fingerprint' => str_repeat('c', 64),
        'decision_fingerprint' => str_repeat('d', 64),
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $sharedClub = $wpdb->insert($links, [
        'source_key' => 'lapka-klub',
        'entity_type' => 'product',
        'source_id' => '84',
        'target_id' => 901,
        'target_fingerprint' => str_repeat('c', 64),
        'decision_fingerprint' => str_repeat('e', 64),
        'created_at' => gmdate('Y-m-d H:i:s'),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'winner' => $winner === 1,
        'loser' => $loser === false,
        'claim_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$claims} WHERE entity_type = 'order' AND target_id = 900"),
        'loser_map_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$map} WHERE source_key = 'lapka-klub' AND entity_type = 'order' AND wc_id = '84'"),
        'loser_outbox_count' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$outbox} WHERE source_identity = 'lapka-klub:order:84'"),
        'shared_links' => $sharedWeb === 1 && $sharedClub === 1
            ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$links} WHERE entity_type = 'product' AND target_id = 901")
            : 0,
        'connections_distinct' => $first !== $second,
    ]);
    return;
}

if ($case === 'historical-payment-isolation') {
    $order = \FluentCart\App\Models\Order::query()->create([
        'status' => 'completed',
        'type' => 'payment',
        'payment_method' => 'wc_migrated',
        'payment_method_title' => 'Historical WooCommerce import',
        'payment_status' => 'paid',
        'currency' => 'PLN',
        'subtotal' => 10000,
        'discount_tax' => 0,
        'manual_discount_total' => 0,
        'coupon_discount_total' => 0,
        'shipping_tax' => 0,
        'shipping_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'tax_behavior' => 0,
        'total_amount' => 10000,
        'total_paid' => 10000,
        'total_refund' => 0,
        'mode' => 'test',
        'config' => [],
    ]);
    if (!$order) {
        throw new RuntimeException('Installed FluentCart rejected the historical order fixture.');
    }

    $transaction = \FluentCart\App\Models\OrderTransaction::query()->create([
        'order_id' => (int) $order->id,
        'order_type' => 'payment',
        'vendor_charge_id' => '',
        'payment_method' => 'wc_migrated',
        'payment_mode' => 'test',
        'payment_method_type' => 'historical_provenance',
        'currency' => 'PLN',
        'transaction_type' => 'charge',
        'subscription_id' => null,
        'status' => 'succeeded',
        'total' => 10000,
        'meta' => [
            'cartshift_source_payment' => [
                'gateway' => 'stripe',
                'source_mode' => 'test',
                'provider_reference' => 'ch_source_should_never_execute',
                'evidence_kind' => 'provider_transaction_id',
            ],
        ],
    ]);
    if (!$transaction) {
        throw new RuntimeException('Installed FluentCart rejected the historical transaction fixture.');
    }

    $fakeGateway = new class implements \FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface {
        public int $refundCalls = 0;

        public function has(string $feature): bool
        {
            return $feature === 'refund';
        }

        public function meta(): array
        {
            return [];
        }

        public function makePaymentFromPaymentInstance(
            \FluentCart\App\Services\Payments\PaymentInstance $paymentInstance,
        ): mixed {
            throw new RuntimeException('Historical contract attempted a payment.');
        }

        public function handleIPN(): void
        {
        }

        public function getOrderInfo(array $data): array
        {
            return [];
        }

        public function fields(): array
        {
            return [];
        }

        public function processRefund(mixed $sourceTransaction, int $amount, array $args = []): string
        {
            ++$this->refundCalls;
            return 're_should_never_exist';
        }
    };
    \FluentCart\App\App::gateway()->register('stripe', $fakeGateway);

    $sideEffects = [
        'manual' => 0,
        'event' => 0,
    ];
    $manualSpy = static function (array $manual) use (&$sideEffects): array {
        ++$sideEffects['manual'];
        return $manual;
    };
    $eventSpy = static function () use (&$sideEffects): void {
        ++$sideEffects['event'];
    };
    add_filter('fluent_cart/order_refund_manually', $manualSpy, 1, 1);
    add_action('fluent_cart/order_refunded', $eventSpy, 1, 0);

    $beforeRows = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', (int) $order->id)
        ->orderBy('id')
        ->get()
        ->toArray();
    $beforeMeta = $transaction->meta;

    $direct = (new \FluentCart\App\Services\Payments\Refund())->processRefund($transaction, 1000);
    if (!is_wp_error($direct)) {
        throw new RuntimeException('Direct installed refund unexpectedly accepted historical provenance.');
    }

    $request = new \FluentCart\Framework\Http\Request\Request(fluentCart(), [], [
        'refund_info' => [
            'transaction_id' => (int) $transaction->id,
            'amount' => '10.00',
            'reason' => 'Installed isolation challenge',
        ],
    ]);
    $admin = (new \FluentCart\App\Http\Controllers\OrderController(fluentCart()))
        ->refundOrder($request, (int) $order->id);
    $detail = (new \FluentCart\App\Http\Controllers\OrderController(fluentCart()))
        ->getDetails((int) $order->id);
    $detailData = $detail instanceof WP_REST_Response ? $detail->get_data() : $detail;
    $findProviderReference = static function (mixed $value) use (&$findProviderReference): ?string {
        if (!is_array($value)) {
            return null;
        }
        if (array_key_exists('provider_reference', $value)) {
            return (string) $value['provider_reference'];
        }
        foreach ($value as $nested) {
            $found = $findProviderReference($nested);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    };
    $detailReference = $findProviderReference($detailData);

    $preview = \FluentCart\App\Modules\MCP\Tools\OrderTools::refundOrder([
        'order_id' => (int) $order->id,
        'transaction_id' => (int) $transaction->id,
        'amount' => '10.00',
        'dry_run' => true,
    ]);
    $previewRows = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', (int) $order->id)
        ->orderBy('id')
        ->get()
        ->toArray();
    $confirmToken = is_array($preview) ? (string) ($preview['data']['confirm_token'] ?? '') : '';
    if ($confirmToken === '') {
        throw new RuntimeException('Installed MCP refund preview supplied no confirmation token.');
    }
    $mcpExecute = \FluentCart\App\Modules\MCP\Tools\OrderTools::refundOrder([
        'order_id' => (int) $order->id,
        'transaction_id' => (int) $transaction->id,
        'amount' => '10.00',
        'confirm_token' => $confirmToken,
        'idempotency_key' => 'cartshift-historical-payment-isolation',
    ]);
    if (!is_wp_error($mcpExecute)) {
        throw new RuntimeException('Installed MCP refund unexpectedly accepted historical provenance.');
    }

    $reloaded = \FluentCart\App\Models\OrderTransaction::query()->find((int) $transaction->id);
    $afterRows = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', (int) $order->id)
        ->orderBy('id')
        ->get()
        ->toArray();

    remove_filter('fluent_cart/order_refund_manually', $manualSpy, 1);
    remove_action('fluent_cart/order_refunded', $eventSpy, 1);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'max_refundable' => $transaction->getMaxRefundableAmount(),
        'direct_error' => $direct->get_error_code(),
        'admin_status' => $admin instanceof WP_REST_Response ? $admin->get_status() : 0,
        'mcp_execute_error' => $mcpExecute->get_error_code(),
        'mcp_preview_was_inert' => $previewRows === $beforeRows,
        'transaction_rows_unchanged' => $afterRows === $beforeRows,
        'parent_meta_unchanged' => $reloaded && $reloaded->meta === $beforeMeta,
        'gateway_calls' => $fakeGateway->refundCalls,
        'manual_refund_filter_calls' => $sideEffects['manual'],
        'refund_event_calls' => $sideEffects['event'],
        'order_detail_contains_full_reference' => $detailReference === 'ch_source_should_never_execute',
        'order_detail_contains_redacted_reference' => $detailReference === '••••ecute',
        'executable_payment_method' => (string) $transaction->payment_method,
        'nested_source_gateway' => (string) ($transaction->meta['cartshift_source_payment']['gateway'] ?? ''),
        'vendor_charge_id' => (string) $transaction->vendor_charge_id,
    ]);
    return;
}

if ($case === 'refund-graph-contract') {
    $chargeIdentity = new \CartShift\Domain\Transfer\SourceIdentity(
        'contract-source',
        'order',
        '7001:event:1',
    );
    $refundIdentity = new \CartShift\Domain\Transfer\SourceIdentity(
        'contract-source',
        'order',
        '7001:event:2',
    );
    $chargeEvent = new \CartShift\Domain\Transfer\Order\PaymentEventRecord(
        $chargeIdentity,
        'charge',
        10000,
        'PLN',
        'succeeded',
        \CartShift\Domain\Transfer\Order\PaymentEvidenceKind::ProviderReference,
        'stripe',
        'Card',
        'ch_contract_private_12345',
        null,
        '2024-03-17T10:00:00Z',
        [],
    );
    $refundEvent = new \CartShift\Domain\Transfer\Order\PaymentEventRecord(
        $refundIdentity,
        'refund',
        2500,
        'PLN',
        'succeeded',
        \CartShift\Domain\Transfer\Order\PaymentEvidenceKind::ProviderReference,
        'stripe',
        'Card',
        're_contract_private_98765',
        $chargeIdentity,
        '2024-03-18T10:00:00Z',
        [],
    );
    $graph = (new \CartShift\Domain\Transfer\Order\PaymentGraphBuilder())->build([
        $refundEvent,
        $chargeEvent,
    ]);
    $projector = new \CartShift\Domain\Transfer\Order\PaymentGraphProjector(
        new \CartShift\Domain\Transfer\Order\HistoricalPaymentPolicy(),
    );
    $placeholderProjection = $projector->project(
        $graph,
        [$chargeIdentity->canonical() => 1],
        'checkout',
        'test',
    );

    $order = \FluentCart\App\Models\Order::query()->create([
        'status' => 'completed',
        'type' => 'checkout',
        'payment_method' => 'wc_migrated',
        'payment_method_title' => 'Historical WooCommerce import',
        'payment_status' => $placeholderProjection->paymentStatus,
        'currency' => 'PLN',
        'subtotal' => 10000,
        'discount_tax' => 0,
        'manual_discount_total' => 0,
        'coupon_discount_total' => 0,
        'shipping_tax' => 0,
        'shipping_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'tax_behavior' => 0,
        'total_amount' => 10000,
        'total_paid' => $placeholderProjection->grossPaid,
        'total_refund' => $placeholderProjection->totalRefunded,
        'mode' => 'test',
        'config' => ['migrated' => true],
        'created_at' => '2024-03-17 10:00:00',
    ]);
    if (!$order) {
        throw new RuntimeException('Installed FluentCart rejected the refund-graph order fixture.');
    }

    $chargeRow = $placeholderProjection->charges[0];
    $chargeRow['order_id'] = (int) $order->id;
    $chargeRow['subscription_id'] = null;
    $chargeRow['uuid'] = md5('cartshift-refund-graph-charge');
    $charge = \FluentCart\App\Models\OrderTransaction::query()->create($chargeRow);
    if (!$charge) {
        throw new RuntimeException('Installed FluentCart rejected the historical charge graph row.');
    }

    $projection = $projector->project(
        $graph,
        [$chargeIdentity->canonical() => (int) $charge->id],
        'checkout',
        'test',
    );
    $refundRow = $projection->refunds[0];
    $refundRow['order_id'] = (int) $order->id;
    $refundRow['subscription_id'] = null;
    $refundRow['uuid'] = md5('cartshift-refund-graph-refund');
    $refund = \FluentCart\App\Models\OrderTransaction::query()->create($refundRow);
    if (!$refund) {
        throw new RuntimeException('Installed FluentCart rejected the historical refund graph row.');
    }

    $fakeGateway = new class implements \FluentCart\App\Modules\PaymentMethods\Core\PaymentGatewayInterface {
        public int $refundCalls = 0;

        public function has(string $feature): bool
        {
            return $feature === 'refund';
        }

        public function meta(): array
        {
            return [];
        }

        public function makePaymentFromPaymentInstance(
            \FluentCart\App\Services\Payments\PaymentInstance $paymentInstance,
        ): mixed {
            throw new RuntimeException('Historical graph attempted a payment.');
        }

        public function handleIPN(): void
        {
        }

        public function getOrderInfo(array $data): array
        {
            return [];
        }

        public function fields(): array
        {
            return [];
        }

        public function processRefund(mixed $sourceTransaction, int $amount, array $args = []): string
        {
            ++$this->refundCalls;
            return 're_should_never_exist';
        }
    };
    \FluentCart\App\App::gateway()->register('wc_migrated', $fakeGateway);

    $refundEvents = 0;
    $eventSpy = static function () use (&$refundEvents): void {
        ++$refundEvents;
    };
    add_action('fluent_cart/order_refunded', $eventSpy, 1, 0);

    $beforeRows = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', (int) $order->id)
        ->orderBy('id')
        ->get()
        ->toArray();
    $deduplicated = \FluentCart\App\Services\Payments\Refund::createOrRecordRefund([
        'vendor_charge_id' => '',
        'total' => 2500,
        'status' => 'refunded',
    ], $charge);
    $afterDedupRows = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', (int) $order->id)
        ->orderBy('id')
        ->get()
        ->toArray();
    $direct = (new \FluentCart\App\Services\Payments\Refund())->processRefund($charge, 100);

    $report = (new \FluentCart\App\Services\Report\RefundReportService())
        ->getRefundDataGroupedBy([
            'groupKey' => 'payment_method',
            'startDate' => '2024-03-17 00:00:00',
            'endDate' => '2024-03-18 23:59:59',
            'currency' => 'PLN',
        ]);
    $reportRow = (array) ($report[0] ?? []);

    $detailResponse = (new \FluentCart\App\Http\Controllers\OrderController(fluentCart()))
        ->getDetails((int) $order->id);
    $detail = $detailResponse instanceof WP_REST_Response ? $detailResponse->get_data() : $detailResponse;
    $references = [];
    $collectReferences = static function (mixed $value) use (&$collectReferences, &$references): void {
        if (!is_array($value)) {
            return;
        }
        if (array_key_exists('provider_reference', $value)) {
            $references[] = (string) $value['provider_reference'];
        }
        foreach ($value as $nested) {
            $collectReferences($nested);
        }
    };
    $collectReferences($detail);

    $chargeReloaded = \FluentCart\App\Models\OrderTransaction::query()->find((int) $charge->id);
    $refundReloaded = \FluentCart\App\Models\OrderTransaction::query()->find((int) $refund->id);
    $orderReloaded = \FluentCart\App\Models\Order::query()->find((int) $order->id);
    $finalRows = \FluentCart\App\Models\OrderTransaction::query()
        ->where('order_id', (int) $order->id)
        ->orderBy('id')
        ->get()
        ->toArray();

    remove_action('fluent_cart/order_refunded', $eventSpy, 1);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'transaction_count' => count($finalRows),
        'charge_status' => (string) $chargeReloaded->status,
        'refund_status' => (string) $refundReloaded->status,
        'parent_refunded_total' => (int) ($chargeReloaded->meta['refunded_total'] ?? -1),
        'refund_parent_matches' => (int) ($refundReloaded->meta['parent_id'] ?? 0) === (int) $charge->id,
        'order_payment_status' => (string) $orderReloaded->payment_status,
        'reported_refund_amount' => (float) ($reportRow['totalRefundedAmount']['total'] ?? -1),
        'reported_refund_orders' => (int) ($reportRow['totalRefunded'] ?? -1),
        'dedup_returned_existing' => (int) ($deduplicated->id ?? 0) === (int) $refund->id,
        'dedup_was_read_only' => $afterDedupRows === $beforeRows && $finalRows === $beforeRows,
        'max_refundable' => $chargeReloaded->getMaxRefundableAmount(),
        'direct_refund_error' => is_wp_error($direct) ? $direct->get_error_code() : '',
        'gateway_calls' => $fakeGateway->refundCalls,
        'refund_event_calls' => $refundEvents,
        'detail_has_redacted_charge_reference' => in_array('••••12345', $references, true),
        'detail_has_redacted_refund_reference' => in_array('••••98765', $references, true),
        'detail_has_full_reference' => in_array('ch_contract_private_12345', $references, true)
            || in_array('re_contract_private_98765', $references, true),
    ]);
    return;
}

if ($case === 'order-semantics-contract') {
    if (!class_exists(\FChubFakturownia\Handler\InvoiceHandler::class)) {
        throw new RuntimeException('FCHub Fakturownia is not installed for the order-semantics contract.');
    }

    $orderIdentity = new \CartShift\Domain\Transfer\SourceIdentity('contract-source', 'order', '8001');
    $addressRecord = new \CartShift\Domain\Transfer\Order\AddressRecord(
        new \CartShift\Domain\Transfer\SourceIdentity('contract-source', 'order', '8001:address:1'),
        'billing',
        'Ada',
        'Lovelace',
        'Example Sp. z o.o.',
        'Main 1',
        '',
        'Warsaw',
        '',
        '00-001',
        'PL',
        'buyer@example.invalid',
        '+48 500 000 000',
        'PL 529-183-11-15',
    );
    $addressProjection = \CartShift\Domain\Transfer\Order\AddressProjection::project($addressRecord);
    if (!$addressProjection) {
        throw new RuntimeException('Canonical business address was unexpectedly empty.');
    }

    $lineIdentity = new \CartShift\Domain\Transfer\SourceIdentity('contract-source', 'order', '8001:item:1');
    $line = new \CartShift\Domain\Transfer\Order\OrderLineRecord(
        $lineIdentity,
        1,
        new \CartShift\Domain\Transfer\SourceIdentity('contract-source', 'product', '101'),
        new \CartShift\Domain\Transfer\SourceIdentity('contract-source', 'product', '101:variation:101'),
        'Digital course',
        '',
        [],
        2,
        0,
        5000,
        10000,
        0,
        0,
        0,
        0,
        10000,
        0,
        'unavailable',
        0,
        '1',
        '2026-08-01T10:00:00Z',
        [],
        ['source_fulfilment_type' => 'digital'],
        [],
    );
    $shippingLine = new \CartShift\Domain\Transfer\Order\ShippingLineRecord(
        new \CartShift\Domain\Transfer\SourceIdentity('contract-source', 'order', '8001:shipping:1'),
        1,
        'flat_rate',
        1,
        'Courier',
        0,
        0,
        [],
        ['private_source_value' => 'must_not_escape'],
    );
    $record = new \CartShift\Domain\Transfer\Order\OrderRecord(
        $orderIdentity,
        null,
        null,
        'checkout',
        'completed',
        'PLN',
        'PLN',
        'PLN',
        '1',
        'source_currency_equals_target',
        false,
        10000,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        0,
        10000,
        0,
        '2026-08-01T10:00:00Z',
        null,
        '2026-08-01T10:00:00Z',
        '2026-08-01T10:00:00Z',
        null,
        [$line],
        [],
        [$shippingLine],
        [],
        [],
        [$addressRecord],
        [],
        [],
        [],
    );
    $fulfilment = (new \CartShift\Domain\Transfer\Order\FulfilmentPolicy())->project($record);
    $metadata = \CartShift\Domain\Transfer\Order\OrderMetadataProjection::project(
        $record,
        [$addressProjection],
    );

    $order = \FluentCart\App\Models\Order::query()->create([
        'status' => 'completed',
        'type' => 'checkout',
        'fulfillment_type' => $fulfilment->fulfilmentType,
        'shipping_status' => $fulfilment->shippingStatus,
        'payment_method' => 'wc_migrated',
        'payment_method_title' => 'Historical WooCommerce import',
        'payment_status' => 'paid',
        'currency' => 'PLN',
        'subtotal' => 10000,
        'discount_tax' => 0,
        'manual_discount_total' => 0,
        'coupon_discount_total' => 0,
        'shipping_tax' => 0,
        'shipping_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'tax_behavior' => 0,
        'total_amount' => 10000,
        'total_paid' => 10000,
        'total_refund' => 0,
        'mode' => 'test',
        'invoice_no' => 'CONTRACT-8001',
        'uuid' => md5('cartshift-order-semantics'),
        'config' => $metadata->config,
        'created_at' => '2026-08-01 10:00:00',
        'completed_at' => '2026-08-01 10:00:00',
    ]);
    if (!$order) {
        throw new RuntimeException('Installed FluentCart rejected the order-semantics fixture.');
    }

    $addressRow = $addressProjection->row;
    unset($addressRow['source_identity']);
    $addressRow['order_id'] = (int) $order->id;
    \FluentCart\App\Models\OrderAddress::query()->create($addressRow);
    foreach ($metadata->metaRows as $metaRow) {
        \FluentCart\App\Models\OrderMeta::query()->create(['order_id' => (int) $order->id] + $metaRow);
    }
    \FluentCart\App\Models\OrderItem::query()->create([
        'order_id' => (int) $order->id,
        'payment_type' => 'onetime',
        'post_id' => 0,
        'object_id' => 0,
        'post_title' => 'Digital course',
        'title' => 'Digital course',
        'fulfillment_type' => 'digital',
        'cart_index' => 0,
        'quantity' => 2,
        'unit_price' => 5000,
        'cost' => 0,
        'subtotal' => 10000,
        'discount_total' => 0,
        'tax_amount' => 0,
        'shipping_charge' => 0,
        'line_total' => 10000,
        'refund_total' => 0,
        'rate' => 1,
        'fulfilled_quantity' => $fulfilment->fulfilledQuantities[$lineIdentity->canonical()],
        'other_info' => [],
        'line_meta' => [],
        'created_at' => '2026-08-01 10:00:00',
    ]);

    $httpCalls = 0;
    $httpSpy = static function (mixed $preempt) use (&$httpCalls): mixed {
        ++$httpCalls;
        return $preempt;
    };
    add_filter('pre_http_request', $httpSpy, 1, 1);
    $handlerReflection = new ReflectionClass(\FChubFakturownia\Handler\InvoiceHandler::class);
    $handler = $handlerReflection->newInstanceWithoutConstructor();
    $mapper = $handlerReflection->getMethod('mapOrderToInvoice');
    $invoice = $mapper->invoke($handler, $order->fresh());
    remove_filter('pre_http_request', $httpSpy, 1);

    $reloaded = \FluentCart\App\Models\Order::query()->find((int) $order->id);
    $billing = $reloaded->billing_address;
    $businessInfo = $reloaded->getBusinessInfo();
    $mcp = \FluentCart\App\Modules\MCP\Tools\OrderTools::getOrder(['order_id' => (int) $order->id]);
    $mcpData = (array) ($mcp['data'] ?? $mcp);
    $mcpItems = (array) ($mcpData['items'] ?? []);

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'native_vat_number' => (string) $billing->vat_number,
        'fakturownia_nip_alias' => (string) ($billing->meta['other_data']['nip'] ?? ''),
        'business_tax_number' => (string) ($businessInfo['tax_number'] ?? ''),
        'business_tax_validated' => (bool) ($businessInfo['tax_number_validated'] ?? false),
        'fakturownia_buyer_company' => (bool) ($invoice['buyer_company'] ?? false),
        'fakturownia_buyer_tax_no' => (string) ($invoice['buyer_tax_no'] ?? ''),
        'fakturownia_buyer_name' => (string) ($invoice['buyer_name'] ?? ''),
        'mcp_fulfilled_quantity' => (int) ($mcpItems[0]['fulfilled_qty'] ?? -1),
        'mcp_shipping_status' => (string) ($mcpData['shipping_status'] ?? ''),
        'shipping_method_title' => (string) ($reloaded->config['shipping_method_title'] ?? ''),
        'http_calls' => $httpCalls,
    ]);
    return;
}

if ($case === 'historical-order-read-model') {
    global $wpdb;
    if ((string) get_option('cartshift_db_version', '0') === '7'
        && !\CartShift\Support\Migrations::upgradeExplicit('7', '8')) {
        throw new RuntimeException('Disposable historical-order contract could not install checked schema v8.');
    }

    $seed = random_int(810000000, 899999999);
    $sourceOrderId = $seed;
    $sourceCustomerId = $seed + 1;
    $sourceProductId = $seed + 2;
    $record = cartshift_contract_historical_order_record($sourceOrderId, $sourceCustomerId, $sourceProductId);

    $customerGateway = new \CartShift\Domain\Transfer\Customer\LoadedFluentCartCustomerGateway();
    $customerId = $customerGateway->createCustomer([
        'user_id' => 1,
        'email' => 'admin@example.com',
        'first_name' => 'Admin',
        'last_name' => 'Owner',
        'status' => 'active',
        'uuid' => md5('installed-order-customer-' . $seed),
        'created_at' => '2026-08-01 09:00:00',
        'updated_at' => '2026-08-01 09:00:00',
    ]);
    $preExistingOrder = [
        'status' => 'completed',
        'parent_id' => null,
        'receipt_number' => null,
        'invoice_no' => 'PRE-EXISTING-' . substr(hash('sha256', (string) $seed), 0, 12),
        'fulfillment_type' => 'digital',
        'type' => 'payment',
        'mode' => 'test',
        'shipping_status' => 'unshippable',
        'customer_id' => $customerId,
        'payment_method' => 'wc_migrated',
        'payment_status' => 'paid',
        'payment_method_title' => 'Historical',
        'currency' => 'EUR',
        'subtotal' => 3000,
        'discount_tax' => 0,
        'manual_discount_total' => 0,
        'coupon_discount_total' => 0,
        'shipping_tax' => 0,
        'shipping_total' => 0,
        'fee_total' => 0,
        'tax_total' => 0,
        'total_amount' => 3000,
        'total_paid' => 3000,
        'total_refund' => 0,
        'rate' => '4.0000',
        'tax_behavior' => 0,
        'note' => '',
        'ip_address' => '',
        'completed_at' => '2026-07-01 10:00:00',
        'refunded_at' => null,
        'uuid' => substr(hash('sha256', 'pre-existing-order-' . $seed), 0, 32),
        'config' => wp_json_encode(['pre_existing_customer_order' => true]),
        'created_at' => '2026-07-01 10:00:00',
        'updated_at' => '2026-07-01 10:00:00',
    ];
    if ($wpdb->insert($wpdb->prefix . 'fct_orders', $preExistingOrder) !== 1) {
        throw new RuntimeException('Installed FluentCart rejected the pre-existing customer order fixture.');
    }
    $productId = wp_insert_post([
        'post_type' => 'fluent-products',
        'post_status' => 'draft',
        'post_title' => 'Historical course target',
        'post_name' => 'historical-course-' . $seed,
    ], true);
    if (is_wp_error($productId)) {
        throw new RuntimeException($productId->get_error_message());
    }
    $productId = (int) $productId;
    $detail = \FluentCart\App\Models\ProductDetail::query()->create([
        'post_id' => $productId,
        'fulfillment_type' => 'digital',
        'variation_type' => 'simple_variations',
        'manage_stock' => 0,
        'stock_availability' => 'in-stock',
        'manage_downloadable' => 1,
        'other_info' => ['historical_contract' => true],
    ]);
    $variation = \FluentCart\App\Models\ProductVariation::query()->create([
        'post_id' => $productId,
        'variation_title' => 'Historical course target',
        'variation_identifier' => 'HIST-' . $seed,
        'sku' => 'HIST-' . $seed,
        'manage_stock' => 0,
        'payment_type' => 'onetime',
        'stock_status' => 'in-stock',
        'available' => 999,
        'fulfillment_type' => 'digital',
        'item_status' => 'draft',
        'item_price' => 10000,
        'downloadable' => 1,
        'other_info' => [],
    ]);
    if (!$detail || !$variation) {
        throw new RuntimeException('Installed FluentCart rejected historical-order product dependencies.');
    }
    $download = \FluentCart\App\Models\ProductDownload::query()->create([
        'post_id' => $productId,
        'product_variation_id' => [(int) $variation->id],
        'download_identifier' => md5('historical-download-' . $seed),
        'title' => 'Historical download',
        'type' => 'application/pdf',
        'driver' => 'local',
        'file_name' => 'historical.pdf',
        'file_path' => 'historical.pdf',
        'file_url' => '',
        'file_size' => 1,
        'settings' => ['download_limit' => '', 'download_expiry' => ''],
        'serial' => 1,
    ]);
    if (!$download) {
        throw new RuntimeException('Installed FluentCart rejected the downloadable product fixture.');
    }

    $maps = new \CartShift\Storage\IdMapRepository('installed-order');
    foreach ([
        [$record->customer, $customerId],
        [$record->productLines[0]->product, $productId],
        [$record->productLines[0]->variation, (int) $variation->id],
    ] as [$identity, $targetId]) {
        $maps->storeOrThrow(
            $identity,
            $targetId,
            'installed-order-dependencies',
            str_repeat('a', 64),
            str_repeat('b', 64),
            \CartShift\Domain\Transfer\Identity\MapState::Reconciled,
            false,
        );
    }
    $projectionContext = new \CartShift\Domain\Transfer\Order\OrderProjectionContext(
        [$record->productLines[0]->identity->canonical() => [
            'post_id' => $productId,
            'object_id' => (int) $variation->id,
            'fulfillment_type' => 'digital',
        ]],
        [$record->couponLines[0]->identity->canonical() => null],
        [],
        'test',
        'Historical WooCommerce provenance',
        true,
    );
    $canonicalNote = $record->notes[0]->identity;
    $plan = \CartShift\Domain\Transfer\Order\OrderStagePlan::build(
        $record,
        $projectionContext,
        customerTargetId: $customerId,
        canonicalCustomerNote: $canonicalNote,
        noteDecisionFingerprint: \CartShift\Domain\Transfer\Order\OrderStagePlan::noteDecisionFingerprint(
            $record,
            $canonicalNote,
        ),
    );
    $gateway = new \CartShift\Domain\Transfer\Order\LoadedFluentCartOrderGateway();
    $writer = new \CartShift\Domain\Transfer\Order\FluentCartOrderWriter(
        $gateway,
        $maps,
        new \CartShift\Domain\Transfer\Order\OrderReconciler($gateway, $maps),
        new \CartShift\Domain\Transfer\Identity\TargetClaimRepository(),
    );
    $context = new \CartShift\Domain\Transfer\StageContext(
        '/var/www/html/wp-content/plugins/cartshift',
        'installed-order-19',
        str_repeat('c', 64),
    );
    if ($wpdb->insert($wpdb->prefix . 'cartshift_transfer_records', [
        'run_id' => $context->migrationId,
        'record_kind' => 'customer',
        'source_identity' => $record->customer->canonical(),
        'generation' => 1,
        'source_fingerprint' => str_repeat('d', 64),
        'target_fingerprint' => str_repeat('e', 64),
        'action' => 'reuse',
        'state' => 'reconciled',
        'target_ids' => wp_json_encode(['customer_id' => $customerId]),
        'before_hash' => null,
        'after_hash' => str_repeat('e', 64),
        'error_code' => null,
        'created_at' => '2026-08-01 09:00:00',
        'updated_at' => '2026-08-01 09:00:00',
    ]) !== 1) {
        throw new RuntimeException('Installed contract could not seed the existing customer-stage receipt.');
    }

    $sameSession = (int) $wpdb->get_var('SELECT CONNECTION_ID()')
        === (int) fluentCart('db')->scalar('SELECT CONNECTION_ID()');
    $queries = [];
    $querySpy = static function (string $query) use (&$queries): string {
        $queries[] = strtoupper(trim($query));
        return $query;
    };
    add_filter('query', $querySpy, PHP_INT_MIN, 1);
    $receiptCalls = 0;
    $invoiceCallbacks = 0;
    $sideEffects = 0;
    add_filter('fluent_cart/create_receipt_number_on_order_create', static function () use (&$receiptCalls): bool {
        ++$receiptCalls;
        return false;
    }, 1, 0);
    add_action('fluent_cart/order/invoice_number_added', static function () use (&$invoiceCallbacks): void {
        ++$invoiceCallbacks;
    }, 1, 0);
    foreach ([
        'fluent_cart/order_paid_done',
        'fluent_cart/order_refunded',
        'fluent_cart/order_status_updated',
        'fluent_cart/stock_changed',
        'fluent_cart/integration/order_integrations',
        'fluent_cart/customer_purchase_updated',
    ] as $hook) {
        add_action($hook, static function () use (&$sideEffects): void { ++$sideEffects; }, 1, 0);
    }
    add_filter('pre_wp_mail', static function () use (&$sideEffects): bool { ++$sideEffects; return true; }, 1, 0);
    add_filter('pre_http_request', static function (mixed $preempt) use (&$sideEffects): mixed {
        ++$sideEffects;
        return $preempt;
    }, 1, 1);

    $first = $writer->stage($plan, $context);
    $beforeRetry = wp_json_encode($gateway->snapshot($first->targetId));
    $second = $writer->stage($plan, $context);
    $afterRetry = wp_json_encode($gateway->snapshot($first->targetId));
    $aggregateGateway = new \CartShift\Domain\Transfer\Order\LoadedCustomerAggregateGateway();
    $aggregateProjector = new \CartShift\Domain\Transfer\Order\CustomerAggregateProjector($aggregateGateway);
    $aggregateFirst = $aggregateProjector->projectCompleteSet($record->customer, $customerId, $context->migrationId);
    $aggregateBeforeRetry = wp_json_encode($aggregateGateway->snapshot($customerId));
    $aggregateSecond = $aggregateProjector->projectCompleteSet($record->customer, $customerId, $context->migrationId);
    $aggregateAfterRetry = wp_json_encode($aggregateGateway->snapshot($customerId));
    remove_filter('query', $querySpy, PHP_INT_MIN);

    $typedSourceCustomerId = $sourceCustomerId + 100;
    $typedCustomerId = $customerGateway->createCustomer([
        'user_id' => 0,
        'email' => 'typed-history-' . $seed . '@example.invalid',
        'first_name' => 'Typed',
        'last_name' => 'History',
        'status' => 'active',
        'uuid' => md5('installed-typed-customer-' . $seed),
        'created_at' => '2026-08-01 09:00:00',
        'updated_at' => '2026-08-01 09:00:00',
    ]);
    $typedParentSourceId = $sourceOrderId + 100;
    $typedRenewalSourceId = $sourceOrderId + 101;
    $typedParentRecord = cartshift_contract_historical_order_record(
        $typedParentSourceId,
        $typedSourceCustomerId,
        $sourceProductId,
        'parent',
    );
    $typedRenewalRecord = cartshift_contract_historical_order_record(
        $typedRenewalSourceId,
        $typedSourceCustomerId,
        $sourceProductId,
        'renewal',
        $typedParentSourceId,
    );
    $maps->storeOrThrow(
        $typedParentRecord->customer,
        $typedCustomerId,
        'installed-order-dependencies',
        str_repeat('a', 64),
        str_repeat('b', 64),
        \CartShift\Domain\Transfer\Identity\MapState::Reconciled,
        false,
    );
    $typedPlan = static function (
        \CartShift\Domain\Transfer\Order\OrderRecord $typedRecord,
        ?int $parentTargetId,
    ) use ($typedCustomerId, $productId, $variation): \CartShift\Domain\Transfer\Order\OrderStagePlan {
        $typedProjection = new \CartShift\Domain\Transfer\Order\OrderProjectionContext(
            [$typedRecord->productLines[0]->identity->canonical() => [
                'post_id' => $productId,
                'object_id' => (int) $variation->id,
                'fulfillment_type' => 'digital',
            ]],
            [$typedRecord->couponLines[0]->identity->canonical() => null],
            [],
            'test',
            'Historical WooCommerce provenance',
            true,
        );
        $canonicalNote = $typedRecord->notes[0]->identity;
        return \CartShift\Domain\Transfer\Order\OrderStagePlan::build(
            $typedRecord,
            $typedProjection,
            customerTargetId: $typedCustomerId,
            parentTargetId: $parentTargetId,
            canonicalCustomerNote: $canonicalNote,
            noteDecisionFingerprint: \CartShift\Domain\Transfer\Order\OrderStagePlan::noteDecisionFingerprint(
                $typedRecord,
                $canonicalNote,
            ),
        );
    };
    $typedParent = $writer->stage($typedPlan($typedParentRecord, null), $context);
    $typedRenewal = $writer->stage($typedPlan($typedRenewalRecord, $typedParent->targetId), $context);
    $typedParentModel = \FluentCart\App\Models\Order::query()->find($typedParent->targetId);
    $typedRenewalModel = \FluentCart\App\Models\Order::query()->find($typedRenewal->targetId);
    $typedRenewalHistory = $typedParentModel?->renewals()->get();
    $stageSideEffects = $sideEffects;
    $stageReceiptCalls = $receiptCalls;
    $stageInvoiceCallbacks = $invoiceCallbacks;

    $snapshot = $gateway->snapshot($first->targetId);
    $model = \FluentCart\App\Models\Order::query()->find($first->targetId);
    if (!$model) {
        throw new RuntimeException('Staged historical order did not reload through the installed model.');
    }
    $model->load(['customer', 'order_items', 'transactions', 'billing_address', 'shipping_address', 'orderTaxRates']);
    $adminResponse = (new \FluentCart\App\Http\Controllers\OrderController(fluentCart()))
        ->getDetails($first->targetId);
    $adminData = $adminResponse instanceof WP_REST_Response ? $adminResponse->get_data() : $adminResponse;
    $customerList = \FluentCart\Api\Resource\FrontendResource\CustomerResource::getOrders(
        ['per_page' => 10],
        $customerId,
    );
    wp_set_current_user(1);
    $customerDetailResponse = (new \FluentCart\App\Http\Controllers\FrontendControllers\CustomerOrderController(fluentCart()))
        ->orderDetails((string) $model->uuid);
    $customerDetail = $customerDetailResponse->get_data();
    ob_start();
    (new \FluentCart\App\Services\Renderer\Receipt\ReceiptRenderer([
        'order' => $model,
        'user_tz' => 'UTC',
    ]))->render(true);
    $receipt = (string) ob_get_clean();
    $mcp = \FluentCart\App\Modules\MCP\Tools\OrderTools::getOrder(['order_id' => $first->targetId]);
    $aggregate = $aggregateGateway->snapshot($customerId);
    $aggregateCustomer = \FluentCart\App\Models\Customer::query()->find($customerId);
    $mcpCustomer = \FluentCart\App\Modules\MCP\Tools\CustomerTools::getCustomer([
        'customer_id' => $customerId,
        'include' => ['orders'],
    ]);
    $mcpCustomerData = (array) ($mcpCustomer['data'] ?? $mcpCustomer);
    $mcpCustomerList = \FluentCart\App\Modules\MCP\Tools\CustomerTools::listCustomers([
        'search' => 'admin@example.com',
        'min_ltv' => 241.45,
        'min_purchase_count' => 2,
    ]);
    $mcpCustomerListData = (array) ($mcpCustomerList['data'] ?? $mcpCustomerList);

    $reportParams = [
        'startDate' => '2026-08-01 00:00:00',
        'endDate' => '2026-08-31 23:59:59',
        'currency' => 'PLN',
        'groupKey' => 'payment_method',
    ];
    $revenue = (new \FluentCart\App\Services\Report\RevenueReportService())->revenueByGroup($reportParams);
    $orders = (new \FluentCart\App\Services\Report\OrderReportService())->groupBy($reportParams)->toArray();
    $refunds = (new \FluentCart\App\Services\Report\RefundReportService())->getRefundDataGroupedBy($reportParams);
    $productReport = (new \FluentCart\App\Services\Report\ProductReportService())->getProductTopChart([
        ...$reportParams,
        'groupKey' => 'monthly',
        'variationIds' => [(int) $variation->id],
    ]);
    $customerReport = (new \FluentCart\App\Services\Report\CustomerReportService())->getCustomerReportData([
        'startDate' => new \FluentCart\App\Services\DateTime\DateTime('2026-08-01 00:00:00'),
        'endDate' => new \FluentCart\App\Services\DateTime\DateTime('2026-08-31 23:59:59'),
        'groupKey' => 'daily',
    ]);
    $downloadPermission = \FluentCart\App\Helpers\CustomerHelper::checkDownloadPermissionAndStoreLog([
        'order' => (string) $model->uuid,
        'download_id' => (int) $download->id,
        'variation_id' => (int) $variation->id,
    ]);
    $downloadPermissionRow = \FluentCart\App\Models\OrderDownloadPermission::query()
        ->where('order_id', $first->targetId)
        ->where('download_id', (int) $download->id)
        ->where('variation_id', (int) $variation->id)
        ->first();
    $transactions = $snapshot['transactions'];
    $charge = $transactions[0] ?? [];
    $refund = $transactions[1] ?? [];
    $provenance = [];
    foreach ($snapshot['meta'] as $row) {
        if (($row['meta_key'] ?? null) === 'cartshift_order_provenance') {
            $provenance = (array) $row['meta_value'];
        }
    }
    $startCount = count(array_filter($queries, static fn (string $query): bool => $query === 'START TRANSACTION'));
    $commitCount = count(array_filter($queries, static fn (string $query): bool => $query === 'COMMIT'));
    $rollbackCount = count(array_filter($queries, static fn (string $query): bool => $query === 'ROLLBACK'));

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'same_database_session' => $sameSession,
        'start_transaction_count' => $startCount,
        'commit_count' => $commitCount,
        'rollback_count' => $rollbackCount,
        'retry_reused' => $second->reused,
        'retry_byte_stable' => $beforeRetry === $afterRetry,
        'aggregate_retry_reused' => !$aggregateFirst->reused && $aggregateSecond->reused,
        'aggregate_retry_byte_stable' => $aggregateBeforeRetry === $aggregateAfterRetry,
        'purchase_value' => $aggregate['purchase_value'],
        'purchase_count' => $aggregate['purchase_count'],
        'ltv' => $aggregate['ltv'],
        'aov' => $aggregate['aov'],
        'first_purchase_date' => $aggregate['first_purchase_date'],
        'last_purchase_date' => $aggregate['last_purchase_date'],
        'customer_stage_receipts' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_transfer_records WHERE run_id = %s AND record_kind = 'customer' AND source_identity = %s",
            $context->migrationId,
            $record->customer->canonical(),
        )),
        'customer_aggregate_receipts' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_transfer_records WHERE run_id = %s AND record_kind = 'customer_aggregate' AND source_identity = %s",
            $context->migrationId,
            $record->customer->canonical(),
        )),
        'customer_model_aggregate_readable' => $aggregateCustomer !== null
            && (int) $aggregateCustomer->purchase_count === 2
            && (int) $aggregateCustomer->ltv === 24145,
        'customer_mcp_aggregate_readable' => (int) ($mcpCustomerData['metrics']['purchase_count'] ?? -1) === 2
            && (int) ($mcpCustomerData['metrics']['ltv']['amount_cents'] ?? -1) === 24145
            && count((array) ($mcpCustomerData['orders'] ?? [])) === 2,
        'customer_mcp_list_readable' => count((array) ($mcpCustomerListData['customers'] ?? [])) === 1,
        'customer_report_readable' => (int) ($customerReport['summary']['customer_count'] ?? 0) >= 1,
        'typed_parent_readable' => $typedParentModel !== null
            && (string) $typedParentModel->type === 'subscription'
            && $typedParentModel->parent_id === null,
        'typed_renewal_readable' => $typedRenewalModel !== null
            && (string) $typedRenewalModel->type === 'renewal'
            && (int) $typedRenewalModel->parent_id === $typedParent->targetId,
        'parent_renewal_history_readable' => $typedRenewalHistory !== null
            && $typedRenewalHistory->count() === 1
            && (int) $typedRenewalHistory->first()->id === $typedRenewal->targetId,
        'receipt_number' => $snapshot['order']['receipt_number'],
        'receipt_allocator_calls' => $stageReceiptCalls,
        'invoice_callbacks' => $stageInvoiceCallbacks,
        'lifecycle_side_effects' => $stageSideEffects,
        'transaction_count' => count($transactions),
        'refund_parent_matches' => (int) ($refund['meta']['parent_id'] ?? 0) === (int) ($charge['id'] ?? 0),
        'max_refundable' => (int) \FluentCart\App\Models\OrderTransaction::query()->find((int) $charge['id'])->getMaxRefundableAmount(),
        'admin_detail_readable' => is_array($adminData) && $adminData !== [],
        'customer_list_readable' => is_array($customerList) && $customerList !== [],
        'customer_detail_readable' => is_array($customerDetail) && $customerDetail !== [],
        'receipt_rendered' => str_contains($receipt, 'Historical course') && str_contains($receipt, (string) $model->invoice_no),
        'mcp_readable' => is_array($mcp) && $mcp !== [],
        'revenue_report_readable' => $revenue !== [] && (float) ($revenue[0]->total_tax ?? 0) > 0,
        'order_report_readable' => $orders !== [],
        'refund_report_readable' => $refunds !== [] && (float) ($refunds[0]['totalRefundedAmount']['total'] ?? 0) > 0,
        'product_report_readable' => $productReport !== [],
        'download_permission_granted' => $downloadPermission !== null
            && $downloadPermissionRow !== null
            && (int) $downloadPermissionRow->customer_id === $customerId,
        'visible_note' => (string) $model->note,
        'private_note_reconciled' => str_contains(wp_json_encode($provenance['note_history'] ?? []), 'Private provenance note'),
        'display_identity_leaks_source_order_number' => str_contains((string) $model->invoice_no, (string) $sourceOrderId)
            || str_contains((string) $model->uuid, (string) $sourceOrderId),
    ]);
    return;
}

if ($case === 'customer-target-contract') {
    global $wpdb;
    if ((string) get_option('cartshift_db_version', '0') === '7'
        && !\CartShift\Support\Migrations::upgradeExplicit('7', '8')) {
        throw new RuntimeException('Disposable customer target contract could not install checked schema v8.');
    }
    $beforeUsers = (int) (count_users()['total_users'] ?? 0);
    $mailCalls = 0;
    $lifecycleHooks = 0;
    add_filter('pre_wp_mail', static function () use (&$mailCalls): bool { ++$mailCalls; return true; });
    foreach (['fluent_cart/customer_created', 'fluent_cart/customer_updated', 'fluent_cart/customer_address_created'] as $hook) {
        add_action($hook, static function () use (&$lifecycleHooks): void { ++$lifecycleHooks; });
    }

    $identity = new \CartShift\Domain\Transfer\SourceIdentity('installed-customer', 'customer', '1');
    $record = \CartShift\Domain\Transfer\Customer\CustomerRecord::create(
        $identity, 1, 'registered', 'Admin', 'Owner', 'admin@example.com', 'active', [
            new \CartShift\Domain\Transfer\Customer\CustomerAddressRecord(new \CartShift\Domain\Transfer\SourceIdentity('installed-customer', 'customer', '1:billing'), 'billing', true, 'active', 'Billing', 'Admin Owner', 'Example Ltd', '1 Billing Road', '', 'Billing City', '', '00-001', 'PL', '111', 'admin@example.com'),
            new \CartShift\Domain\Transfer\Customer\CustomerAddressRecord(new \CartShift\Domain\Transfer\SourceIdentity('installed-customer', 'customer', '1:shipping'), 'shipping', true, 'active', 'Shipping', 'Admin Owner', '', '2 Shipping Road', '', 'Shipping City', '', '00-002', 'PL', '222', 'admin@example.com'),
        ], '2026-08-01T10:00:00Z', '2026-08-02T10:00:00Z', ['origin' => 'source_user'], [],
    );
    $assessment = new \CartShift\Domain\Transfer\Customer\CustomerAssessment('attach_exact_same_site_user', ['user_id' => 1]);
    $maps = new \CartShift\Storage\IdMapRepository('installed-customer');
    $gateway = new \CartShift\Domain\Transfer\Customer\LoadedFluentCartCustomerGateway();
    $writer = new \CartShift\Domain\Transfer\Customer\CustomerWriter($gateway, $maps, new \CartShift\Domain\Transfer\Customer\CustomerReconciler());
    $context = new \CartShift\Domain\Transfer\StageContext('/var/www/html/wp-content/plugins/cartshift', 'installed-customer-21', str_repeat('f', 64));
    $first = $writer->stage($record, $assessment, $context);
    $beforeRetry = wp_json_encode($gateway->snapshot($first->targetId));
    $second = $writer->stage($record, $assessment, $context);
    $afterRetry = wp_json_encode($gateway->snapshot($first->targetId));
    $model = \FluentCart\App\Models\Customer::query()->find($first->targetId);
    $snapshot = $gateway->snapshot($first->targetId);
    $guestTargetIds = [];
    foreach (['91:guest', '92:guest'] as $guestSourceId) {
        $guest = \CartShift\Domain\Transfer\Customer\CustomerRecord::create(
            new \CartShift\Domain\Transfer\SourceIdentity('installed-customer', 'customer', $guestSourceId),
            null,
            'guest',
            'Guest',
            'Buyer',
            'duplicate-guest@example.test',
            'active',
            [],
            '2026-08-01T10:00:00Z',
            '2026-08-02T10:00:00Z',
            ['origin' => 'order_snapshot'],
            [],
        );
        $guestTargetIds[] = $writer->stage(
            $guest,
            new \CartShift\Domain\Transfer\Customer\CustomerAssessment('create_target_customer_unlinked'),
            $context,
        )->targetId;
    }

    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
        'user_id' => (int) $snapshot['customer']['user_id'],
        'address_types' => array_column($snapshot['addresses'], 'type'),
        'address_cities' => array_column($snapshot['addresses'], 'city'),
        'new_wordpress_users' => (int) (count_users()['total_users'] ?? 0) - $beforeUsers,
        'mail_calls' => $mailCalls,
        'customer_lifecycle_hooks' => $lifecycleHooks,
        'retry_reused' => $second->reused,
        'retry_byte_stable' => $beforeRetry === $afterRetry,
        'fluentcart_model_readable' => $model !== null && (int) $model->id === $first->targetId,
        'duplicate_guest_target_ids_distinct' => count(array_unique($guestTargetIds, SORT_NUMERIC)) === 2,
        'duplicate_guest_rows' => (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_customers WHERE email = %s AND user_id IS NULL",
            'duplicate-guest@example.test',
        )),
    ]);
    return;
}

if ($case === 'transfer-execution-journal-contract') {
    global $wpdb;
    if ((string) get_option('cartshift_db_version', '0') === '7'
        && !\CartShift\Support\Migrations::upgradeExplicit('7', '8')) {
        throw new RuntimeException('Disposable execution journal contract could not install checked schema v8.');
    }
    $directory = sys_get_temp_dir() . '/cartshift-execution-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Private execution directory could not be created.');
    chmod($directory, 0700);
    try {
        $decisions = \CartShift\Domain\Transfer\Decision\TransferDecisionSet::empty();
        $prepared = new \CartShift\Domain\Transfer\Execution\PreparedTransfer(
            'installed-journal-22',
            '/srv/private/cartshift-transfer-v2-installed-journal-package',
            str_repeat('1', 64),
            new \CartShift\Domain\Transfer\Execution\TargetStateFingerprint(
                str_repeat('1', 64),
                $decisions->fingerprint(),
                str_repeat('3', 64),
                str_repeat('4', 64),
                str_repeat('5', 64),
                str_repeat('6', 64),
                str_repeat('7', 64),
            ),
            'rehearsal',
            [],
            false,
            '2026-08-10T12:00:00Z',
            'installed-journal',
        );
        $descriptors = new \CartShift\Domain\Transfer\Execution\PreparedTransferRepository($directory);
        $descriptors->save($prepared);
        $journal = new \CartShift\Domain\Transfer\Execution\TransferJournalRepository($descriptors);
        $record = \CartShift\Domain\Transfer\RecordEnvelope::forPayload(
            1,
            new \CartShift\Domain\Transfer\SourceIdentity('installed-journal', 'product', '41'),
            ['dependencies' => [], 'private_fixture' => 'do-not-export-this-value'],
        );
        $receipt = new \CartShift\Domain\Transfer\Execution\TransferReceipt(
            $prepared->runId,
            'product',
            $record->identity->canonical(),
            1,
            $record->privateContentDigest,
            'created',
            ['primary' => 901],
            null,
            str_repeat('a', 64),
            1,
            '2026-08-10T12:00:00Z',
            '2026-08-10T12:00:01Z',
        );
        $journal->start($prepared);
        $journal->transition($prepared->runId, \CartShift\Domain\Transfer\Execution\TransferRunState::Prepared, \CartShift\Domain\Transfer\Execution\TransferRunState::Staging, true);
        \CartShift\Support\DatabaseTransaction::begin();
        try {
            $journal->commitReceipt($receipt);
            \CartShift\Support\DatabaseTransaction::commit();
        } catch (Throwable $exception) {
            \CartShift\Support\DatabaseTransaction::rollback($exception);
            throw $exception;
        }
        $roundTrip = $journal->successfulReceipt($prepared->runId, $record, 1);
        $journal->markReceiptExported($receipt);
        $journal->transition($prepared->runId, \CartShift\Domain\Transfer\Execution\TransferRunState::Staging, \CartShift\Domain\Transfer\Execution\TransferRunState::Failed);
        $payload = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}cartshift_transfer_outbox WHERE run_id = %s LIMIT 1",
            $prepared->runId,
        ));
        echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode([
            'state' => $journal->state($prepared->runId)->value,
            'attempt' => $journal->attempt($prepared->runId),
            'receipt_round_trip' => $roundTrip?->toArray() === $receipt->toArray(),
            'journal_rows' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_transfer_records WHERE run_id = %s", $prepared->runId)),
            'outbox_rows' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_transfer_outbox WHERE run_id = %s", $prepared->runId)),
            'pending_after_export' => count($journal->pendingReceipts($prepared->runId)),
            'payload_leaks_private_fixture' => str_contains($payload, 'do-not-export-this-value'),
        ]);
    } finally {
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) unlink($directory . '/' . $file);
        rmdir($directory);
    }
    return;
}

if ($case === 'concurrent-lease-worker') {
    \CartShift\Support\Migrations::upgradeExplicit('7', '8');
    $mode = (string) ($args[1] ?? 'acquire');
    $holder = (string) ($args[2] ?? 'worker-b');
    $ttl = (int) ($args[3] ?? 2);
    $hold = (int) ($args[4] ?? 0);
    $target = hash('sha256', 'cartshift-task-25-concurrent-target');
    $descriptor = $mode === 'recover-wrong'
        ? str_repeat('f', 64)
        : hash('sha256', 'cartshift-task-25-prepared-descriptor');
    $guard = new \CartShift\Domain\Transfer\TransferRunGuard(
        new \CartShift\Domain\Transfer\TransferLock(),
        new \CartShift\Domain\Transfer\TransferLease(),
    );
    $result = ['acquired' => false, 'mode' => $mode, 'reason' => null];
    try {
        if ($mode === 'acquire') {
            $guard->acquire($target, $holder, $descriptor, $ttl);
        } else {
            $guard->recover($target, $holder, $descriptor, str_repeat('e', 64), $ttl);
        }
        $result['acquired'] = true;
        echo "\nCARTSHIFT_LEASE_ACQUIRED:" . $holder . "\n";
        if (function_exists('flush')) flush();
        if ($hold > 0) sleep($hold);
        $guard->release($target, $holder, $descriptor);
    } catch (RuntimeException $exception) {
        $result['reason'] = $exception->getMessage();
    }
    echo "\nCARTSHIFT_CONTRACT_JSON:" . wp_json_encode($result);
    return;
}

throw new InvalidArgumentException('Unknown installed contract case.');
