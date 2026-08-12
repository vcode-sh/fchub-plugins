<?php

declare(strict_types=1);

/** @return array{product_id: int, variation_ids: list<int>} */
function cartshift_contract_seed_variable_product(): array
{
    $existing = get_posts([
        'post_type' => 'product',
        'post_status' => 'any',
        'meta_key' => '_cartshift_contract_fixture',
        'meta_value' => 'variable-product-simple-variations-v1',
        'numberposts' => 1,
        'fields' => 'ids',
    ]);

    if ($existing !== []) {
        $product = wc_get_product((int) $existing[0]);

        if ($product instanceof WC_Product_Variable) {
            $children = array_values(array_map('intval', $product->get_children()));
            sort($children);

            return ['product_id' => $product->get_id(), 'variation_ids' => $children];
        }
    }

    $attribute = new WC_Product_Attribute();
    $attribute->set_id(0);
    $attribute->set_name('Harness size');
    $attribute->set_options(['Small', 'Large']);
    $attribute->set_visible(true);
    $attribute->set_variation(true);

    $product = new WC_Product_Variable();
    $product->set_name('CartShift contract variable product');
    $product->set_status('publish');
    $product->set_attributes([$attribute]);
    $product->set_default_attributes(['harness-size' => 'Small']);
    $productId = $product->save();
    update_post_meta($productId, '_cartshift_contract_fixture', 'variable-product-simple-variations-v1');

    $variationIds = [];

    foreach ([['Small', '12.34'], ['Large', '56.78']] as [$size, $price]) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($productId);
        $variation->set_status('publish');
        $variation->set_attributes(['harness-size' => $size]);
        $variation->set_regular_price($price);
        $variation->set_price($price);
        $variationIds[] = $variation->save();
    }

    sort($variationIds);

    return ['product_id' => $productId, 'variation_ids' => $variationIds];
}

/**
 * Seed the installed WooCommerce product contract through public object APIs.
 *
 * @return array<string, int|list<int>|string>
 */
function cartshift_contract_seed_product_matrix(): array
{
    wp_set_current_user(1);
    update_option('woocommerce_weight_unit', 'kg');
    update_option('woocommerce_dimension_unit', 'cm');

    if (!class_exists('CartShift_Contract_WC_Product_Course', false)) {
        class CartShift_Contract_WC_Product_Course extends WC_Product_Simple
        {
            public function get_type(): string
            {
                return 'course';
            }
        }
    }

    add_filter(
        'woocommerce_product_class',
        static fn (string $className, string $productType): string =>
            $productType === 'course' ? CartShift_Contract_WC_Product_Course::class : $className,
        10,
        2,
    );
    register_post_status('contract-review', [
        'label' => 'Contract review',
        'public' => false,
        'internal' => true,
        'exclude_from_search' => true,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
    ]);

    foreach (get_posts([
        'post_type' => 'product',
        'post_status' => 'any',
        'meta_key' => '_cartshift_contract_matrix',
        'posts_per_page' => -1,
        'fields' => 'ids',
    ]) as $oldProductId) {
        wp_delete_post((int) $oldProductId, true);
    }

    $save = static function (WC_Product $product, string $key): int {
        $product->update_meta_data('_cartshift_contract_matrix', $key);
        $id = $product->save();
        if ($id <= 0) {
            throw new RuntimeException('WooCommerce rejected an installed product-matrix fixture.');
        }
        return $id;
    };

    $imageBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
        true,
    );
    if (!is_string($imageBytes)) {
        throw new RuntimeException('Product-matrix image fixture is invalid.');
    }
    $attachmentIds = [];
    foreach (['featured', 'gallery'] as $role) {
        $upload = wp_upload_bits('cartshift-contract-' . $role . '.png', null, $imageBytes);
        if (!empty($upload['error']) || !is_string($upload['file'] ?? null)) {
            throw new RuntimeException('WordPress rejected a product-matrix image fixture.');
        }
        $attachmentId = wp_insert_attachment([
            'post_title' => 'CartShift contract ' . $role,
            'post_status' => 'inherit',
            'post_mime_type' => 'image/png',
            'guid' => (string) $upload['url'],
        ], (string) $upload['file']);
        if (!is_int($attachmentId) || $attachmentId <= 0) {
            throw new RuntimeException('WordPress rejected a product-matrix attachment.');
        }
        $attachmentIds[] = $attachmentId;
    }

    $simple = new WC_Product_Simple();
    $simple->set_name('Zero-price Unicode Łapka 🐕');
    $simple->set_status('publish');
    $simple->set_description('Long description — exact source text.');
    $simple->set_short_description('Short description żółć.');
    $simple->set_sku('ZERO-UNICODE');
    $simple->set_regular_price('10.00');
    $simple->set_sale_price('0');
    $simple->set_price('0');
    $simple->set_catalog_visibility('hidden');
    $simple->set_featured(true);
    $simple->set_menu_order(7);
    $simple->set_purchase_note('Exact purchase note.');
    $simple->set_weight('1.25');
    $simple->set_length('12.5');
    $simple->set_width('8.5');
    $simple->set_height('3.5');
    $simple->set_image_id($attachmentIds[0]);
    $simple->set_gallery_image_ids([$attachmentIds[1]]);
    $simpleId = $save($simple, 'simple-zero-unicode');

    $blank = new WC_Product_Simple();
    $blank->set_name('Blank price contract');
    $blank->set_status('private');
    $blank->set_regular_price('');
    $blank->set_sale_price('');
    $blank->set_price('');
    $blankId = $save($blank, 'blank-price');

    $downloadUpload = wp_upload_bits(
        'cartshift-contract-manual.txt',
        null,
        "CartShift installed download bytes\n",
    );
    if (!empty($downloadUpload['error']) || !is_string($downloadUpload['file'] ?? null)) {
        throw new RuntimeException('WordPress rejected a product-matrix download fixture.');
    }
    $download = new WC_Product_Download();
    $download->set_id('contract-manual');
    $download->set_name('Contract manual');
    $download->set_file((string) $downloadUpload['file']);
    $digital = new WC_Product_Simple();
    $digital->set_name('Virtual downloadable contract');
    $digital->set_status('publish');
    $digital->set_virtual(true);
    $digital->set_downloadable(true);
    $digital->set_regular_price('25.00');
    $digital->set_price('25.00');
    $digital->set_download_limit(2);
    $digital->set_download_expiry(14);
    $digital->set_downloads([$download]);
    $digitalId = $save($digital, 'virtual-downloadable');

    $external = new WC_Product_External();
    $external->set_name('External contract');
    $external->set_status('publish');
    $external->set_regular_price('33.00');
    $external->set_price('33.00');
    $external->set_product_url('https://example.invalid/external-contract');
    $external->set_button_text('Visit vendor');
    $externalId = $save($external, 'external');

    $groupedChild = new WC_Product_Simple();
    $groupedChild->set_name('Grouped child contract');
    $groupedChild->set_status('publish');
    $groupedChild->set_regular_price('9.00');
    $groupedChild->set_price('9.00');
    $groupedChildId = $save($groupedChild, 'grouped-child');
    $grouped = new WC_Product_Grouped();
    $grouped->set_name('Grouped contract');
    $grouped->set_status('publish');
    $grouped->set_children([$groupedChildId]);
    $groupedId = $save($grouped, 'grouped');

    $draft = new WC_Product_Simple();
    $draft->set_name('Draft contract');
    $draft->set_status('draft');
    $draft->set_regular_price('5.00');
    $draft->set_price('5.00');
    $draftId = $save($draft, 'draft');

    $pending = new WC_Product_Simple();
    $pending->set_name('Pending contract');
    $pending->set_status('pending');
    $pending->set_regular_price('6.00');
    $pending->set_price('6.00');
    $pendingId = $save($pending, 'pending');

    $customStatus = new WC_Product_Simple();
    $customStatus->set_name('Custom status contract');
    $customStatus->set_status('contract-review');
    $customStatus->set_regular_price('7.00');
    $customStatus->set_price('7.00');
    $customStatusId = $save($customStatus, 'custom-status');

    $trashed = new WC_Product_Simple();
    $trashed->set_name('Trashed contract');
    $trashed->set_status('publish');
    $trashed->set_regular_price('8.00');
    $trashed->set_price('8.00');
    $trashedId = $save($trashed, 'trashed');
    if (!wp_trash_post($trashedId)) {
        throw new RuntimeException('WordPress rejected the trashed product fixture.');
    }

    $course = new CartShift_Contract_WC_Product_Course();
    $course->set_name('Unsupported course contract');
    $course->set_status('publish');
    $course->set_regular_price('40.00');
    $course->set_price('40.00');
    $courseId = $save($course, 'course');

    $now = time();
    $scheduledIds = [];
    foreach ([
        'before' => [$now + 3600, $now + 7200, '20.00'],
        'during' => [$now - 3600, $now + 3600, '12.00'],
        'after' => [$now - 7200, $now - 3600, '20.00'],
    ] as $phase => [$from, $to, $effective]) {
        $scheduled = new WC_Product_Simple();
        $scheduled->set_name(ucfirst($phase) . ' scheduled sale contract');
        $scheduled->set_status('publish');
        $scheduled->set_regular_price('20.00');
        $scheduled->set_sale_price('12.00');
        $scheduled->set_price($effective);
        $scheduled->set_date_on_sale_from((new WC_DateTime())->setTimestamp($from));
        $scheduled->set_date_on_sale_to((new WC_DateTime())->setTimestamp($to));
        $scheduledIds[$phase] = $save($scheduled, 'scheduled-' . $phase);
    }

    $stockParent = new WC_Product_Variable();
    $stockParent->set_name('Parent stock contract');
    $stockParent->set_status('publish');
    $stockParent->set_manage_stock(true);
    $stockParent->set_stock_quantity(11);
    $stockParent->set_stock_status('instock');
    $stockParent->set_reviews_allowed(false);
    $stockParent->set_image_id($attachmentIds[0]);
    $stockParentId = $save($stockParent, 'parent-stock');
    $selfStock = new WC_Product_Variation();
    $selfStock->set_parent_id($stockParentId);
    $selfStock->set_status('publish');
    $selfStock->set_regular_price('15.00');
    $selfStock->set_price('15.00');
    $selfStock->set_manage_stock(true);
    $selfStock->set_stock_quantity(3);
    $selfStock->set_image_id($attachmentIds[1]);
    $selfStock->set_downloadable(true);
    $selfStock->set_download_limit(1);
    $selfStock->set_download_expiry(3);
    $selfStock->set_downloads([$download]);
    $selfStockId = $selfStock->save();
    $parentStock = new WC_Product_Variation();
    $parentStock->set_parent_id($stockParentId);
    $parentStock->set_status('publish');
    $parentStock->set_regular_price('16.00');
    $parentStock->set_price('16.00');
    $parentStock->set_manage_stock(false);
    $parentStockId = $parentStock->save();
    if ($selfStockId <= 0 || $parentStockId <= 0) {
        throw new RuntimeException('WooCommerce rejected a stock-ownership variation fixture.');
    }
    $unmanagedStock = cartshift_contract_seed_variable_product();

    return [
        'simple' => $simpleId,
        'blank' => $blankId,
        'digital' => $digitalId,
        'external' => $externalId,
        'grouped_child' => $groupedChildId,
        'grouped' => $groupedId,
        'draft' => $draftId,
        'pending' => $pendingId,
        'custom_status' => $customStatusId,
        'trashed' => $trashedId,
        'course' => $courseId,
        'scheduled_before' => $scheduledIds['before'],
        'scheduled_during' => $scheduledIds['during'],
        'scheduled_after' => $scheduledIds['after'],
        'stock_parent' => $stockParentId,
        'unmanaged_stock_parent' => $unmanagedStock['product_id'],
        'stock_variations' => [$selfStockId, $parentStockId, $unmanagedStock['variation_ids'][0]],
        'download_path' => (string) $downloadUpload['file'],
    ];
}

function cartshift_contract_seed_rich_roundtrip_product(): int
{
    $existing = get_posts([
        'post_type' => 'product',
        'post_status' => 'any',
        'meta_key' => '_cartshift_contract_fixture',
        'meta_value' => 'rich-roundtrip-v2',
        'numberposts' => 1,
        'fields' => 'ids',
    ]);
    if (isset($existing[0]) && wc_get_product((int) $existing[0]) instanceof WC_Product_Simple) {
        return (int) $existing[0];
    }

    $product = new WC_Product_Simple();
    $product->set_name('Rich round-trip Łapka');
    $product->set_status('publish');
    $product->set_description('Exact long description with Unicode — persisted.');
    $product->set_short_description('Exact short description.');
    $product->set_sku('RICH-ROUNDTRIP');
    $product->set_regular_price('19.99');
    $product->set_sale_price('0');
    $product->set_price('0');
    $product->set_tax_status('none');
    $product->set_catalog_visibility('hidden');
    $product->set_featured(true);
    $product->set_menu_order(17);
    $product->set_purchase_note('Round-trip purchase note.');
    $product->set_reviews_allowed(false);
    $product->set_manage_stock(true);
    $product->set_stock_quantity(4);
    $product->set_stock_status('instock');
    $product->set_sold_individually(true);
    $product->set_weight('1.75');
    $product->set_length('21.5');
    $product->set_width('14.5');
    $product->set_height('2.5');
    $term = term_exists('round-trip-category', 'product_cat');
    if ($term === 0 || $term === null) {
        $term = wp_insert_term('Round-trip category', 'product_cat', [
            'slug' => 'round-trip-category',
            'description' => 'Exact category description.',
        ]);
    }
    if (is_wp_error($term)) {
        throw new RuntimeException('WordPress rejected the rich round-trip category fixture.');
    }
    $termId = is_array($term) ? (int) ($term['term_id'] ?? 0) : (int) $term;
    if ($termId <= 0) {
        throw new RuntimeException('WordPress returned no rich round-trip category identity.');
    }
    $product->set_category_ids([$termId]);
    $product->update_meta_data('_cartshift_contract_fixture', 'rich-roundtrip-v2');
    $id = $product->save();
    if ($id <= 0) {
        throw new RuntimeException('WooCommerce rejected the rich round-trip fixture.');
    }
    return $id;
}

function cartshift_contract_seed_asset_roundtrip_product(): int
{
    wp_set_current_user(1);

    $existing = get_posts([
        'post_type' => 'product',
        'post_status' => 'any',
        'meta_key' => '_cartshift_contract_fixture',
        'meta_value' => 'asset-roundtrip-v2',
        'numberposts' => 1,
        'fields' => 'ids',
    ]);
    if (isset($existing[0]) && wc_get_product((int) $existing[0]) instanceof WC_Product_Variable) {
        return (int) $existing[0];
    }

    $imageBytes = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
        true,
    );
    if (!is_string($imageBytes)) {
        throw new RuntimeException('Asset round-trip image fixture is invalid.');
    }
    $attachmentIds = [];
    foreach (['parent', 'gallery', 'variation'] as $role) {
        $upload = wp_upload_bits('cartshift-asset-roundtrip-' . $role . '.png', null, $imageBytes);
        if (!empty($upload['error']) || !is_string($upload['file'] ?? null)) {
            throw new RuntimeException('WordPress rejected an asset round-trip image.');
        }
        $attachmentId = wp_insert_attachment([
            'post_title' => 'CartShift asset round-trip ' . $role,
            'post_status' => 'inherit',
            'post_mime_type' => 'image/png',
            'guid' => (string) $upload['url'],
        ], (string) $upload['file']);
        if (!is_int($attachmentId) || $attachmentId <= 0) {
            throw new RuntimeException('WordPress rejected an asset round-trip attachment.');
        }
        $attachmentIds[] = $attachmentId;
    }

    $downloadFiles = [];
    foreach (['small', 'large'] as $name) {
        $upload = wp_upload_bits(
            'cartshift-asset-roundtrip-' . $name . '.txt',
            null,
            'Exact ' . $name . " variation download bytes\n",
        );
        if (!empty($upload['error']) || !is_string($upload['file'] ?? null)) {
            throw new RuntimeException('WordPress rejected an asset round-trip download.');
        }
        $downloadFiles[$name] = (string) $upload['file'];
    }

    $attribute = new WC_Product_Attribute();
    $attribute->set_id(0);
    $attribute->set_name('Asset size');
    $attribute->set_options(['Small', 'Large']);
    $attribute->set_visible(true);
    $attribute->set_variation(true);

    $product = new WC_Product_Variable();
    $product->set_name('CartShift asset round-trip');
    $product->set_status('publish');
    $product->set_virtual(true);
    $product->set_downloadable(true);
    $product->set_manage_stock(false);
    $product->set_stock_status('instock');
    $product->set_image_id($attachmentIds[0]);
    $product->set_gallery_image_ids([$attachmentIds[1]]);
    $product->set_attributes([$attribute]);
    $product->set_default_attributes(['asset-size' => 'Small']);
    $product->update_meta_data('_cartshift_contract_fixture', 'asset-roundtrip-v2');
    $productId = $product->save();
    if ($productId <= 0) {
        throw new RuntimeException('WooCommerce rejected the asset round-trip product.');
    }

    foreach ([
        ['Small', '31.00', true, 3, $attachmentIds[2], $downloadFiles['small']],
        ['Large', '32.00', false, null, 0, $downloadFiles['large']],
    ] as [$size, $price, $manageStock, $quantity, $imageId, $downloadFile]) {
        $download = new WC_Product_Download();
        // Deliberately repeat the Woo ID across variations. Source identity must
        // include the owning variation or one file silently replaces the other.
        $download->set_id('manual');
        $download->set_name($size . ' manual');
        $download->set_file($downloadFile);
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($productId);
        $variation->set_status('publish');
        $variation->set_attributes(['asset-size' => $size]);
        $variation->set_regular_price($price);
        $variation->set_price($price);
        $variation->set_virtual(true);
        $variation->set_downloadable(true);
        $variation->set_download_limit(2);
        $variation->set_download_expiry(-1);
        $variation->set_downloads([$download]);
        $variation->set_manage_stock($manageStock);
        if ($manageStock) {
            $variation->set_stock_quantity($quantity);
        }
        if ($imageId > 0) {
            $variation->set_image_id($imageId);
        }
        if ($variation->save() <= 0) {
            throw new RuntimeException('WooCommerce rejected an asset round-trip variation.');
        }
    }

    return $productId;
}

/** @return array{order_id: int, product_line_id: int, note_id: int} */
function cartshift_contract_seed_order_ledger(string $fixtureKey = 'order-ledger-v1'): array
{
    $existing = wc_get_orders([
        'limit' => 1,
        'type' => 'shop_order',
        'status' => 'any',
        'meta_key' => '_cartshift_contract_order_fixture',
        'meta_value' => $fixtureKey,
    ]);
    if (isset($existing[0]) && $existing[0] instanceof WC_Order) {
        $lineIds = array_keys($existing[0]->get_items('line_item'));
        $notes = wc_get_order_notes(['order_id' => $existing[0]->get_id()]);
        return [
            'order_id' => $existing[0]->get_id(),
            'product_line_id' => (int) ($lineIds[0] ?? 0),
            'note_id' => (int) ($notes[0]->id ?? 0),
        ];
    }

    $product = new WC_Product_Simple();
    $product->set_name('CartShift frozen ledger product');
    $product->set_status('publish');
    $product->set_regular_price('100.00');
    $product->set_price('100.00');
    $productId = $product->save();

    $order = wc_create_order();
    if (!$order instanceof WC_Order) {
        throw new RuntimeException('WooCommerce could not create the installed order-ledger fixture.');
    }
    $order->set_currency('PLN');
    $order->set_status('completed');
    $order->set_prices_include_tax(false);
    $order->set_payment_method('stripe');
    $order->set_payment_method_title('Card');
    $order->set_transaction_id('contract-charge-5001');
    $order->set_billing_first_name('Tom');
    $order->set_billing_last_name('Buyer');
    $order->set_billing_company('Dog Ltd');
    $order->set_billing_address_1('Main 1');
    $order->set_billing_city('Warsaw');
    $order->set_billing_postcode('00-001');
    $order->set_billing_country('PL');
    $order->set_billing_email('buyer@example.test');
    $order->set_shipping_first_name('Tom');
    $order->set_shipping_last_name('Buyer');
    $order->set_shipping_address_1('Main 1');
    $order->set_shipping_city('Warsaw');
    $order->set_shipping_postcode('00-001');
    $order->set_shipping_country('PL');
    $order->update_meta_data('_billing_vat_number', 'PL1234567890');
    $order->update_meta_data('_cartshift_contract_order_fixture', $fixtureKey);

    $productLine = new WC_Order_Item_Product();
    $productLine->set_product_id($productId);
    $productLine->set_name('Frozen course');
    $productLine->set_quantity(1);
    $productLine->set_subtotal('100.00');
    $productLine->set_subtotal_tax('23.00');
    $productLine->set_total('90.00');
    $productLine->set_total_tax('20.70');
    $productLine->set_taxes(['subtotal' => [19 => '23.00'], 'total' => [19 => '20.70']]);
    $productLine->add_meta_data('_sku', 'COURSE-OLD', true);
    $productLine->add_meta_data('Level', 'Advanced', true);
    $order->add_item($productLine);

    $fee = new WC_Order_Item_Fee();
    $fee->set_name('Handling');
    $fee->set_total('5.00');
    $fee->set_total_tax('1.15');
    $fee->set_taxes(['total' => [19 => '1.15']]);
    $order->add_item($fee);

    $shipping = new WC_Order_Item_Shipping();
    $shipping->set_method_id('flat_rate');
    $shipping->set_instance_id(2);
    $shipping->set_method_title('Courier');
    $shipping->set_total('20.00');
    $shipping->set_taxes(['total' => [19 => '4.60']]);
    $order->add_item($shipping);

    $coupon = new WC_Order_Item_Coupon();
    $coupon->set_code('DOG10');
    $coupon->set_discount('10.00');
    $coupon->set_discount_tax('2.30');
    $order->add_item($coupon);

    $tax = new WC_Order_Item_Tax();
    $tax->set_rate_id(19);
    $tax->set_rate_code('PL-VAT-23');
    $tax->set_label('VAT');
    $tax->set_compound(false);
    $tax->set_tax_total('21.85');
    $tax->set_shipping_tax_total('4.60');
    $tax->set_rate_percent('23.0000');
    $order->add_item($tax);

    $order->set_discount_total('10.00');
    $order->set_discount_tax('2.30');
    $order->set_shipping_total('20.00');
    $order->set_shipping_tax('4.60');
    $order->set_cart_tax('21.85');
    $order->set_total('141.45');
    $order->set_date_paid('2026-01-02 10:01:00');
    $order->set_date_completed('2026-01-02 10:02:00');
    $order->save();

    $noteId = $order->add_order_note('Private installed contract note', false, false);
    $lineIds = array_keys($order->get_items('line_item'));
    return ['order_id' => $order->get_id(), 'product_line_id' => (int) ($lineIds[0] ?? 0), 'note_id' => (int) $noteId];
}
