<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

/** Checked direct persistence for the characterized FluentCart 1.6 product graph. */
final class LoadedFluentCartProductGateway implements ProductTargetGateway
{
    /** @param array<string, int|string|null> $plan */
    public function createTaxonomyTerm(array $plan, ?int $parentTargetId): int
    {
        global $wpdb;
        $this->insert($wpdb->terms, [
            'name' => (string) $plan['name'],
            'slug' => (string) $plan['slug'],
            'term_group' => 0,
        ]);
        $termId = (int) $wpdb->insert_id;
        $this->insert($wpdb->term_taxonomy, [
            'term_id' => $termId,
            'taxonomy' => (string) $plan['target_taxonomy'],
            'description' => (string) $plan['description'],
            'parent' => $parentTargetId ?? 0,
            'count' => 0,
        ]);
        return $termId;
    }

    public function createDraftProduct(array $fields): int
    {
        if (($fields['post_status'] ?? null) !== 'draft' || ($fields['post_type'] ?? null) !== 'fluent-products') {
            throw new SourceRecordException('target_write_failed', 'Product staging must begin as a FluentCart draft.');
        }
        global $wpdb;
        $this->insert($wpdb->posts, $fields);
        return (int) $wpdb->insert_id;
    }

    public function createProductDetail(int $productId, array $fields): int
    {
        global $wpdb;
        $now = gmdate('Y-m-d H:i:s');
        $fields['post_id'] = $productId;
        $fields['other_info'] = $this->json($fields['other_info'] ?? []);
        $fields['default_media'] = $this->json($fields['default_media'] ?? []);
        $fields['created_at'] = $now;
        $fields['updated_at'] = $now;
        $this->insert($wpdb->prefix . 'fct_product_details', $fields);
        return (int) $wpdb->insert_id;
    }

    public function createVariation(int $productId, array $fields): int
    {
        global $wpdb;
        $fields['post_id'] = $productId;
        $fields['other_info'] = $this->json($fields['other_info'] ?? []);
        $fields['created_at'] = gmdate('Y-m-d H:i:s');
        $fields['updated_at'] = $fields['created_at'];
        $this->insert($wpdb->prefix . 'fct_product_variations', $fields);
        return (int) $wpdb->insert_id;
    }

    public function finishProductDetail(int $productId, int $defaultVariationId, int $minPrice, int $maxPrice): void
    {
        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'fct_product_details',
            [
                'default_variation_id' => $defaultVariationId,
                'min_price' => $minPrice,
                'max_price' => $maxPrice,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['post_id' => $productId],
        );
        if ($updated !== 1) {
            throw new SourceRecordException('target_write_failed', 'FluentCart product detail finalization failed.');
        }
    }

    public function assignTaxonomies(int $productId, array $relations): void
    {
        global $wpdb;
        $ordersByTerm = [];
        foreach ($relations as $relation) {
            if (!is_array($relation)
                || !isset($relation['target_id'], $relation['term_order'])
                || !is_int($relation['target_id'])
                || $relation['target_id'] <= 0
                || !is_int($relation['term_order'])
                || $relation['term_order'] < 0) {
                throw new SourceRecordException('target_write_failed', 'Target taxonomy relationship is malformed.');
            }
            if (isset($ordersByTerm[$relation['target_id']])
                && $ordersByTerm[$relation['target_id']] !== $relation['term_order']) {
                throw new SourceRecordException('target_write_failed', 'Target taxonomy relationship has conflicting orders.');
            }
            $ordersByTerm[$relation['target_id']] = $relation['term_order'];
        }

        foreach ($ordersByTerm as $termId => $termOrder) {
            $taxonomyId = $wpdb->get_var($wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
                $termId,
            ));
            if ($taxonomyId === null) {
                throw new SourceRecordException('target_write_failed', 'Target taxonomy relation has no term-taxonomy row.');
            }
            $this->insert($wpdb->term_relationships, [
                'object_id' => $productId,
                'term_taxonomy_id' => (int) $taxonomyId,
                'term_order' => $termOrder,
            ], false);
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                (int) $taxonomyId,
            ));
            if ($wpdb->update($wpdb->term_taxonomy, ['count' => $count], ['term_taxonomy_id' => (int) $taxonomyId]) === false) {
                throw new SourceRecordException('target_write_failed', 'Target taxonomy count update failed.');
            }
        }
    }

    public function attachMedia(int $productId, array $variationIds, array $stagedMedia): array
    {
        global $wpdb;
        $gallery = [];
        $ids = [];
        foreach ($stagedMedia as $item) {
            $attachmentId = (int) ($item['target_id'] ?? 0);
            if ($attachmentId <= 0) {
                throw new SourceRecordException('target_write_failed', 'Staged media has no WordPress attachment ID.');
            }
            $ids[] = $attachmentId;
            $sourceIdentity = (string) $item['source_identity'];
            $role = (string) $item['role'];
            $url = wp_get_attachment_url($attachmentId);
            if (!is_string($url) || $url === '') {
                throw new SourceRecordException('target_write_failed', 'Staged media has no target URL.');
            }
            $this->claimUniquePostMeta($attachmentId, '_cartshift_source_identity', $sourceIdentity);

            $mediaValue = [[
                'id' => $attachmentId,
                'url' => $url,
                'title' => get_the_title($attachmentId),
                'source_identity' => $sourceIdentity,
                'owner_identity' => (string) $item['owner_identity'],
                'role' => $role,
                'provenance' => (string) $item['provenance'],
                'sha256' => (string) $item['sha256'],
            ]];
            if ($role === 'variation') {
                $variationId = $variationIds[(string) $item['owner_identity']] ?? null;
                if (!is_int($variationId)) {
                    throw new SourceRecordException('target_write_failed', 'Variation media owner has no target variation.');
                }
                $updated = $wpdb->update(
                    $wpdb->prefix . 'fct_product_variations',
                    ['media_id' => $attachmentId],
                    ['id' => $variationId, 'post_id' => $productId],
                );
                if ($updated !== 1) {
                    throw new SourceRecordException('target_write_failed', 'Variation media link failed.');
                }
                $this->insert($wpdb->prefix . 'fct_product_meta', [
                    'object_id' => $variationId,
                    'object_type' => 'product_variant_info',
                    'meta_key' => 'product_thumbnail',
                    'meta_value' => $this->json($mediaValue),
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            } else {
                $gallery[] = $mediaValue[0];
            }
        }
        if ($gallery !== []) {
            $this->directPostMeta($productId, 'fluent-products-gallery-image', $gallery);
        }
        return array_values(array_unique($ids));
    }

    public function createDownload(int $productId, array $variationIds, array $fields): int
    {
        global $wpdb;
        $settings = (array) ($fields['settings'] ?? []);
        $settings['_cartshift_source_identity'] = (string) $fields['source_identity'];
        $settings['_cartshift_sha256'] = (string) $fields['sha256'];
        unset($fields['source_identity'], $fields['sha256']);
        $fields['post_id'] = $productId;
        $fields['product_variation_id'] = $this->json($variationIds);
        $fields['download_identifier'] = wp_generate_uuid4();
        $fields['settings'] = $this->json($settings);
        $fields['created_at'] = gmdate('Y-m-d H:i:s');
        $fields['updated_at'] = $fields['created_at'];
        $this->insert($wpdb->prefix . 'fct_product_downloads', $fields);
        return (int) $wpdb->insert_id;
    }

    public function exists(int $productId): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'fluent-products'",
            $productId,
        )) === 1;
    }

    public function snapshot(int $productId): array
    {
        global $wpdb;
        $product = $wpdb->get_results($wpdb->prepare(
            "SELECT post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
                    post_status, comment_status, ping_status, post_password, post_name, post_modified,
                    post_modified_gmt, post_parent, menu_order, post_type, post_mime_type, comment_count
             FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
            $productId,
        ), ARRAY_A)[0] ?? null;
        if (is_array($product)) {
            foreach (['post_author', 'post_parent', 'menu_order', 'comment_count'] as $field) {
                $product[$field] = (int) $product[$field];
            }
        }
        $detail = $wpdb->get_results($wpdb->prepare(
            "SELECT fulfillment_type, min_price, max_price, default_variation_id, variation_type,
                    stock_availability, other_info, default_media, manage_stock, manage_downloadable
             FROM {$wpdb->prefix}fct_product_details WHERE post_id = %d LIMIT 1",
            $productId,
        ), ARRAY_A)[0] ?? null;
        if (is_array($detail)) {
            $detail['other_info'] = $this->decode($detail['other_info'] ?? null);
            $detail['default_media'] = $this->decode($detail['default_media'] ?? null);
            foreach (['min_price', 'max_price'] as $field) {
                $detail[$field] = (int) $detail[$field];
            }
            foreach (['default_variation_id', 'manage_stock', 'manage_downloadable'] as $field) {
                $detail[$field] = (int) $detail[$field];
            }
        }
        $variations = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, media_id, serial_index, sold_individually, variation_title,
                    variation_identifier, sku, manage_stock, payment_type, stock_status, backorders,
                    total_stock, available, committed, on_hold, fulfillment_type, item_status,
                    manage_cost, item_price, item_cost, compare_price, shipping_class, downloadable, other_info
             FROM {$wpdb->prefix}fct_product_variations WHERE post_id = %d ORDER BY id ASC",
            $productId,
        ), ARRAY_A);
        foreach ($variations as &$variation) {
            $variation['other_info'] = $this->decode($variation['other_info'] ?? null);
            foreach (['id', 'post_id', 'media_id', 'serial_index', 'sold_individually', 'manage_stock', 'backorders',
                'total_stock', 'available', 'committed', 'on_hold', 'item_price', 'item_cost', 'compare_price',
                'shipping_class'] as $field) {
                $variation[$field] = $variation[$field] === null ? null : (int) $variation[$field];
            }
        }
        unset($variation);
        $taxonomies = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT tt.term_id FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d ORDER BY tt.term_id ASC",
            $productId,
        )));
        $taxonomyRows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.term_taxonomy_id, tt.term_id, tt.taxonomy, tt.description, tt.parent, tt.count,
                    tr.term_order,
                    t.name, t.slug, t.term_group
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tr.object_id = %d ORDER BY tt.term_id ASC",
            $productId,
        ), ARRAY_A);
        foreach ($taxonomyRows as &$taxonomyRow) {
            foreach (['term_taxonomy_id', 'term_id', 'parent', 'count', 'term_group', 'term_order'] as $field) {
                $taxonomyRow[$field] = (int) $taxonomyRow[$field];
            }
        }
        unset($taxonomyRow);

        $media = $this->mediaSnapshot($productId, $variations);
        $downloads = $wpdb->get_results($wpdb->prepare(
            "SELECT id, post_id, product_variation_id, title, type, driver, file_name, file_path,
                    file_url, file_size, settings, serial
             FROM {$wpdb->prefix}fct_product_downloads WHERE post_id = %d ORDER BY id ASC",
            $productId,
        ), ARRAY_A);
        foreach ($downloads as &$download) {
            $settings = $this->decode($download['settings'] ?? null);
            $download['source_identity'] = (string) ($settings['_cartshift_source_identity'] ?? '');
            $download['sha256'] = (string) ($settings['_cartshift_sha256'] ?? '');
            unset($settings['_cartshift_source_identity'], $settings['_cartshift_sha256']);
            $download['settings'] = $settings;
            $download['product_variation_id'] = $this->decode($download['product_variation_id'] ?? null);
            foreach (['id', 'post_id', 'file_size', 'serial'] as $field) {
                $download[$field] = (int) $download[$field];
            }
            $downloadPath = rtrim((string) (wp_get_upload_dir()['basedir'] ?? ''), '/')
                . '/fluent-cart/' . ltrim((string) $download['file_path'], '/');
            $actualHash = is_file($downloadPath) && !is_link($downloadPath) ? hash_file('sha256', $downloadPath) : false;
            $actualBytes = is_file($downloadPath) && !is_link($downloadPath) ? filesize($downloadPath) : false;
            $download['file_content'] = [
                'sha256' => is_string($actualHash) ? $actualHash : null,
                'bytes' => is_int($actualBytes) ? $actualBytes : null,
            ];
        }
        unset($download);

        return [
            'product' => $product,
            'detail' => $detail,
            'variations' => $variations,
            'taxonomies' => $taxonomies,
            'taxonomy_rows' => $taxonomyRows,
            'media' => $media,
            'downloads' => $downloads,
        ];
    }

    public function behaviour(int $productId, array $variationIds): array
    {
        global $wpdb;
        $before = $this->snapshot($productId);
        $originalPostStatus = (string) ($before['product']['post_status'] ?? '');
        $isHistorical = ($before['detail']['other_info']['historical_placeholder'] ?? false) === true;
        $shouldTemporarilyPublish = in_array($originalPostStatus, ['draft', 'private'], true) && !$isHistorical;
        $productStatusChanged = false;
        $temporarilyActivated = [];
        $generatedCartHashes = [];
        try {
            if ($shouldTemporarilyPublish) {
                $updated = $wpdb->update($wpdb->posts, ['post_status' => 'publish'], ['ID' => $productId]);
                if ($updated !== 1) {
                    throw new SourceRecordException('target_reconciliation_failed', 'Hidden product could not enter transactional cart verification.');
                }
                $productStatusChanged = true;
            }
            if (!$isHistorical) {
                $requested = array_fill_keys(array_map('intval', $variationIds), true);
                foreach ((array) ($before['variations'] ?? []) as $variation) {
                    $variationId = (int) ($variation['id'] ?? 0);
                    if ($variationId <= 0 || !isset($requested[$variationId])) {
                        continue;
                    }
                    $originalStatus = $variation['item_status'] ?? null;
                    if (!is_string($originalStatus) || $originalStatus === '') {
                        throw new SourceRecordException('target_reconciliation_failed', 'Variation status could not be restored safely.');
                    }
                    if ($originalStatus === 'active') {
                        continue;
                    }
                    $updated = $wpdb->update(
                        $wpdb->prefix . 'fct_product_variations',
                        ['item_status' => 'active'],
                        ['id' => $variationId, 'post_id' => $productId],
                    );
                    if ($updated !== 1) {
                        throw new SourceRecordException('target_reconciliation_failed', 'Variation could not enter transactional cart verification.');
                    }
                    $temporarilyActivated[$variationId] = $originalStatus;
                }
            }

            // Use the same published scope as the storefront. Product::find()
            // deliberately includes drafts, so feeding it directly to the
            // renderer would make a historical placeholder look purchasable in
            // this contract even though no public product query may return it.
            $product = \FluentCart\App\Models\Product::query()->published()->find($productId);
            $buySectionHtml = '';
            $galleryHtml = '';
            if ($product) {
                $product->load(['detail', 'variants']);
                $renderer = new \FluentCart\App\Services\Renderer\ProductRenderer($product);
                ob_start();
                $renderer->renderBuySection();
                $buySectionHtml = (string) ob_get_clean();
                ob_start();
                $renderer->renderGallery();
                $galleryHtml = (string) ob_get_clean();
            }

            $cartable = [];
            $checkout = [];
            // Probe by variation ID even when the product is a draft. If the
            // cart helper accepts a hidden historical row, draft visibility is
            // not an adequate safety boundary and reconciliation must refuse it.
            foreach ($variationIds as $variationId) {
                $cart = \FluentCart\Api\Resource\FrontendResource\CartResource::generateCartForInstantCheckout($variationId, 1);
                if (is_wp_error($cart) || !$cart) {
                    continue;
                }
                $cartHash = (string) ($cart->cart_hash ?? '');
                if ($cartHash === '' || strlen($cartHash) > 192) {
                    throw new SourceRecordException('target_reconciliation_failed', 'Transactional cart verification returned no removable cart identity.');
                }
                $generatedCartHashes[$cartHash] = true;
                if (count((array) $cart->cart_data) !== 1) continue;
                $objectId = (int) ($cart->cart_data[0]['object_id'] ?? 0);
                if ($objectId === $variationId) {
                    $cartable[] = $variationId;
                    $checkout[] = $objectId;
                }
            }
            $snapshot = $this->snapshot($productId);
            $mediaHashes = [];
            foreach ((array) $snapshot['media'] as $media) {
                if (isset($media['url']) && str_contains($galleryHtml, (string) $media['url'])) {
                    $mediaHashes[] = (string) $media['sha256'];
                }
            }
            $downloadHashes = [];
            $root = rtrim((string) wp_get_upload_dir()['basedir'], '/') . '/fluent-cart';
            foreach ((array) $snapshot['downloads'] as $download) {
                $path = $root . '/' . (string) $download['file_path'];
                $hash = is_file($path) ? hash_file('sha256', $path) : false;
                if ($hash !== false && hash_equals((string) $download['sha256'], $hash)) {
                    $downloadHashes[] = $hash;
                }
            }
            return [
                'buy_section_rendered' => str_contains($buySectionHtml, 'data-fluent-cart-product-pricing-section'),
                'cartable_variation_ids' => $cartable,
                'checkout_object_ids' => $checkout,
                'rendered_media_hashes' => $mediaHashes,
                'readable_download_hashes' => $downloadHashes,
            ];
        } finally {
            foreach (array_keys($generatedCartHashes) as $cartHash) {
                $deleted = $wpdb->delete($wpdb->prefix . 'fct_carts', ['cart_hash' => $cartHash], ['%s']);
                if ($deleted !== 1) {
                    throw new SourceRecordException('target_reconciliation_failed', 'Transactional cart verification could not remove its temporary cart.');
                }
            }
            foreach ($temporarilyActivated as $variationId => $originalStatus) {
                $restored = $wpdb->update(
                    $wpdb->prefix . 'fct_product_variations',
                    ['item_status' => $originalStatus],
                    ['id' => $variationId, 'post_id' => $productId],
                );
                if ($restored !== 1) {
                    throw new SourceRecordException('target_reconciliation_failed', 'Transactional cart verification could not restore variation status.');
                }
            }
            if ($productStatusChanged) {
                $restored = $wpdb->update($wpdb->posts, ['post_status' => $originalPostStatus], ['ID' => $productId]);
                if ($restored !== 1) {
                    throw new SourceRecordException('target_reconciliation_failed', 'Transactional cart verification could not restore product visibility.');
                }
            }
        }
    }

    /** @param list<array<string, mixed>> $variations @return list<array<string, mixed>> */
    private function mediaSnapshot(int $productId, array $variations): array
    {
        global $wpdb;
        $rows = [];
        $galleryRaw = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
            $productId,
            'fluent-products-gallery-image',
        ));
        $gallery = function_exists('maybe_unserialize') ? maybe_unserialize($galleryRaw) : $galleryRaw;
        if (is_array($gallery)) {
            foreach ($gallery as $item) {
                if (is_array($item)) {
                    $attachmentId = (int) ($item['id'] ?? 0);
                    $rows[] = $item + [
                        'target_id' => $attachmentId,
                        'attachment' => $this->attachmentSnapshot($attachmentId),
                    ];
                }
            }
        }
        foreach ($variations as $variation) {
            if ((int) ($variation['media_id'] ?? 0) <= 0) {
                continue;
            }
            $meta = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}fct_product_meta
                 WHERE object_id = %d AND meta_key = %s ORDER BY id ASC LIMIT 1",
                (int) $variation['id'],
                'product_thumbnail',
            ));
            $items = $this->decode($meta);
            foreach ($items as $item) {
                if (is_array($item)) {
                    $attachmentId = (int) ($item['id'] ?? 0);
                    $rows[] = $item + [
                        'target_id' => $attachmentId,
                        'attachment' => $this->attachmentSnapshot($attachmentId),
                    ];
                }
            }
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function attachmentSnapshot(int $attachmentId): array
    {
        global $wpdb;
        $post = $wpdb->get_results($wpdb->prepare(
            "SELECT post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
                    post_status, comment_status, ping_status, post_password, post_name, post_modified,
                    post_modified_gmt, post_parent, menu_order, post_type, post_mime_type, comment_count
             FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment' LIMIT 1",
            $attachmentId,
        ), ARRAY_A)[0] ?? null;
        if (is_array($post)) {
            foreach (['post_author', 'post_parent', 'menu_order', 'comment_count'] as $field) {
                $post[$field] = (int) $post[$field];
            }
        }
        $meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key ASC, meta_id ASC",
            $attachmentId,
        ), ARRAY_A);
        $termRelationships = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d ORDER BY term_taxonomy_id ASC",
            $attachmentId,
        )));
        $comments = $wpdb->get_results($wpdb->prepare(
            "SELECT comment_ID, comment_post_ID, comment_author, comment_author_email, comment_author_url,
                    comment_author_IP, comment_date, comment_date_gmt, comment_content, comment_karma,
                    comment_approved, comment_agent, comment_type, comment_parent, user_id
             FROM {$wpdb->comments} WHERE comment_post_ID = %d ORDER BY comment_ID ASC",
            $attachmentId,
        ), ARRAY_A);
        foreach ($comments as &$comment) {
            foreach (['comment_ID', 'comment_post_ID', 'comment_karma', 'comment_parent', 'user_id'] as $field) {
                $comment[$field] = (int) $comment[$field];
            }
            $comment['meta'] = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->commentmeta} WHERE comment_id = %d ORDER BY meta_key ASC, meta_id ASC",
                $comment['comment_ID'],
            ), ARRAY_A);
        }
        unset($comment);
        $files = [];
        foreach ((new LoadedWordPressMediaGateway())->files($attachmentId) as $path) {
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            $bytes = is_file($path) && !is_link($path) ? filesize($path) : false;
            $files[] = [
                'path_hash' => hash('sha256', $path),
                'sha256' => is_string($hash) ? $hash : null,
                'bytes' => is_int($bytes) ? $bytes : null,
            ];
        }
        usort($files, static fn (array $left, array $right): int => $left['path_hash'] <=> $right['path_hash']);
        return [
            'post' => $post,
            'meta' => $meta,
            'term_relationships' => $termRelationships,
            'comments' => $comments,
            'files' => $files,
        ];
    }

    private function directPostMeta(int $postId, string $key, mixed $value): void
    {
        global $wpdb;
        $stored = is_array($value) && function_exists('maybe_serialize') ? maybe_serialize($value) : $value;
        $this->insert($wpdb->postmeta, ['post_id' => $postId, 'meta_key' => $key, 'meta_value' => $stored]);
    }

    private function claimUniquePostMeta(int $postId, string $key, string $value): void
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC",
            $postId,
            $key,
        ), ARRAY_A);
        if (!is_array($rows) || count($rows) > 1) {
            throw new SourceRecordException('target_write_failed', 'Target post metadata ownership is ambiguous.');
        }
        if ($rows !== []) {
            if (!hash_equals((string) ($rows[0]['meta_value'] ?? ''), $value)) {
                throw new SourceRecordException('target_write_failed', 'Target post metadata ownership changed.');
            }
            return;
        }
        $this->directPostMeta($postId, $key, $value);
    }

    /** @param array<string, mixed> $data */
    private function insert(string $table, array $data, bool $requiresGeneratedId = true): void
    {
        global $wpdb;
        if ($wpdb->insert($table, $data) !== 1 || ($requiresGeneratedId && (int) $wpdb->insert_id <= 0)) {
            throw new SourceRecordException('target_write_failed', 'Checked target insert failed.');
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
