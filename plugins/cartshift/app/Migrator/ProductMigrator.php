<?php

namespace CartShift\Migrator;

defined('ABSPATH') or die;

use CartShift\Mapper\ProductMapper;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;

class ProductMigrator extends AbstractMigrator
{
    protected string $entityType = 'products';

    /** @var array<int, int> WC term_id => FC term_id mapping for categories */
    private array $categoryMap = [];

    /**
     * Migrate WC product_cat terms to FC product-categories taxonomy.
     * Must be called before run().
     */
    public function migrateCategories(): void
    {
        $wcCategories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
        ]);

        if (is_wp_error($wcCategories) || empty($wcCategories)) {
            return;
        }

        // Sort so parents come before children.
        usort($wcCategories, function ($a, $b) {
            return $a->parent - $b->parent;
        });

        foreach ($wcCategories as $wcTerm) {
            // Skip the default "Uncategorized" category.
            if ($wcTerm->slug === 'uncategorized') {
                continue;
            }

            // Check if a matching FC category already exists by slug.
            $existing = get_term_by('slug', $wcTerm->slug, 'product-categories');

            if ($existing) {
                $this->categoryMap[$wcTerm->term_id] = $existing->term_id;
                $this->log(
                    $wcTerm->term_id,
                    'skipped',
                    sprintf('Category "%s" already exists in FluentCart (FC term %d).', $wcTerm->name, $existing->term_id)
                );
                continue;
            }

            if ($this->dryRun) {
                $this->log(
                    $wcTerm->term_id,
                    'success',
                    sprintf('[DRY RUN] Would migrate category "%s".', $wcTerm->name)
                );
                continue;
            }

            // Resolve parent FC term.
            $fcParent = 0;
            if ($wcTerm->parent > 0 && isset($this->categoryMap[$wcTerm->parent])) {
                $fcParent = $this->categoryMap[$wcTerm->parent];
            }

            $result = wp_insert_term($wcTerm->name, 'product-categories', [
                'slug'        => $wcTerm->slug,
                'description' => $wcTerm->description,
                'parent'      => $fcParent,
            ]);

            if (is_wp_error($result)) {
                $this->log(
                    $wcTerm->term_id,
                    'error',
                    sprintf('Failed to create category "%s": %s', $wcTerm->name, $result->get_error_message())
                );
                continue;
            }

            $this->categoryMap[$wcTerm->term_id] = $result['term_id'];
            $this->idMap->store('category', $wcTerm->term_id, $result['term_id']);
            $this->log(
                $wcTerm->term_id,
                'success',
                sprintf('Migrated category "%s" (FC term %d).', $wcTerm->name, $result['term_id'])
            );
        }
    }

    protected function countTotal(): int
    {
        return count(wc_get_products([
            'limit'  => -1,
            'return' => 'ids',
            'type'   => ['simple', 'variable'],
            'status' => ['publish', 'draft', 'private'],
        ]));
    }

    protected function fetchBatch(int $page): array
    {
        return wc_get_products([
            'limit'  => $this->batchSize,
            'page'   => $page,
            'type'   => ['simple', 'variable'],
            'status' => ['publish', 'draft', 'private'],
            'orderby'=> 'ID',
            'order'  => 'ASC',
        ]);
    }

    /**
     * @param \WC_Product $product
     */
    protected function processRecord($product)
    {
        $wcId = $product->get_id();

        // Skip if already migrated.
        if ($this->idMap->getFcId('product', $wcId)) {
            $this->log($wcId, 'skipped', 'Already migrated.');
            return false;
        }

        $mapped = ProductMapper::map($product);

        if ($mapped === null) {
            $this->log($wcId, 'skipped', sprintf('Unsupported product type: %s', $product->get_type()));
            return false;
        }

        // Dry run: validate mapping only, skip writes.
        if ($this->dryRun) {
            $this->log($wcId, 'success', sprintf(
                '[DRY RUN] Would migrate product "%s" with %d variation(s). Type: %s.',
                $product->get_name(),
                count($mapped['variations']),
                $product->get_type()
            ));
            return 0;
        }

        // 1. Create the WP post (FC product).
        $postId = wp_insert_post($mapped['product'], true);

        if (is_wp_error($postId)) {
            throw new \RuntimeException($postId->get_error_message());
        }

        // Store product ID mapping.
        $this->idMap->store('product', $wcId, $postId);

        // 2. Create product detail row.
        $detailData = $mapped['detail'];
        $detailData['post_id'] = $postId;

        $detail = ProductDetail::query()->create($detailData);
        $this->idMap->store('product_detail', $wcId, $detail->id);

        // 3. Create variations.
        $minPrice = PHP_INT_MAX;
        $maxPrice = 0;
        $defaultVariationId = null;
        $isVariable = $product->get_type() === 'variable';

        $wcVariationIds = $isVariable ? $product->get_children() : [$wcId];

        foreach ($mapped['variations'] as $index => $variationData) {
            $variationData['post_id'] = $postId;

            // Handle SKU uniqueness: append suffix if duplicate.
            $skuSourceId = $wcVariationIds[$index] ?? $wcId;
            if (!empty($variationData['sku'])) {
                $variationData['sku'] = $this->ensureUniqueSku($variationData['sku'], $skuSourceId);
            }

            $fcVariation = ProductVariation::query()->create($variationData);

            // Track price range.
            $price = $variationData['item_price'];
            if ($price < $minPrice) {
                $minPrice = $price;
            }
            if ($price > $maxPrice) {
                $maxPrice = $price;
            }

            if ($index === 0) {
                $defaultVariationId = $fcVariation->id;
            }

            // Store variation mapping.
            if ($isVariable && isset($wcVariationIds[$index])) {
                $this->idMap->store('variation', $wcVariationIds[$index], $fcVariation->id);
            } else {
                $this->idMap->store('variation', $wcId, $fcVariation->id);
            }
        }

        // 4. Update detail with price range and default variation.
        if ($minPrice === PHP_INT_MAX) {
            $minPrice = 0;
        }

        $detail->min_price = $minPrice;
        $detail->max_price = $maxPrice;
        $detail->default_variation_id = $defaultVariationId;
        $detail->save();

        // 5. Copy featured image.
        $thumbnailId = get_post_thumbnail_id($wcId);
        if ($thumbnailId) {
            set_post_thumbnail($postId, $thumbnailId);
        }

        // 6. Assign product categories.
        $this->assignCategories($wcId, $postId);

        $this->log($wcId, 'success', sprintf(
            'Migrated product "%s" (FC ID: %d) with %d variation(s).',
            $product->get_name(),
            $postId,
            count($mapped['variations'])
        ));

        return $postId;
    }

    /**
     * Assign FC categories to a product based on its WC categories.
     */
    private function assignCategories(int $wcProductId, int $fcPostId): void
    {
        $wcTerms = wp_get_post_terms($wcProductId, 'product_cat', ['fields' => 'ids']);

        if (is_wp_error($wcTerms) || empty($wcTerms)) {
            return;
        }

        $fcTermIds = [];
        foreach ($wcTerms as $wcTermId) {
            if (isset($this->categoryMap[$wcTermId])) {
                $fcTermIds[] = $this->categoryMap[$wcTermId];
            }
        }

        if (!empty($fcTermIds)) {
            wp_set_object_terms($fcPostId, $fcTermIds, 'product-categories');
        }
    }

    /**
     * Ensure SKU uniqueness by appending a suffix if the SKU already exists in FC.
     */
    private function ensureUniqueSku(string $sku, int $wcId): string
    {
        $existing = ProductVariation::query()->where('sku', $sku)->first();

        if (!$existing) {
            return $sku;
        }

        // Append WC ID to make unique.
        $newSku = $sku . '-wc' . $wcId;
        $this->log($wcId, 'skipped', sprintf(
            'SKU "%s" already exists in FluentCart. Using "%s" instead.',
            $sku,
            $newSku
        ));

        return $newSku;
    }
}
