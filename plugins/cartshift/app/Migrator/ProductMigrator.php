<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\ProductMapper;
use CartShift\Domain\Mapping\VariationMapper;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;

final class ProductMigrator extends AbstractMigrator
{
    /** @var array<int, int> WC term_id => FC term_id mapping for categories */
    private array $categoryMap = [];

    private readonly ProductMapper $productMapper;
    private readonly VariationMapper $variationMapper;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        string $migrationId,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $migrationId, $batchSize);

        $currency = get_woocommerce_currency();
        $this->productMapper = new ProductMapper($currency);
        $this->variationMapper = new VariationMapper($currency);
    }

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

        // FIX H3: topological sort — parents always processed before children.
        $sorted = $this->sortCategoriesByHierarchy($wcCategories);

        foreach ($sorted as $wcTerm) {
            if ($wcTerm->slug === 'uncategorized') {
                continue;
            }

            $existing = get_term_by('slug', $wcTerm->slug, 'product-categories');

            if ($existing) {
                $this->categoryMap[$wcTerm->term_id] = $existing->term_id;
                $this->idMap->store(
                    Constants::ENTITY_CATEGORY,
                    (string) $wcTerm->term_id,
                    $existing->term_id,
                    $this->migrationId,
                    false,
                );
                $this->writeLog(
                    $wcTerm->term_id,
                    'skipped',
                    sprintf('Category "%s" already exists in FluentCart (FC term %d).', $wcTerm->name, $existing->term_id),
                );
                continue;
            }

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
                $this->writeLog(
                    $wcTerm->term_id,
                    'error',
                    sprintf('Failed to create category "%s": %s', $wcTerm->name, $result->get_error_message()),
                );
                continue;
            }

            $this->categoryMap[$wcTerm->term_id] = $result['term_id'];
            $this->idMap->store(
                Constants::ENTITY_CATEGORY,
                (string) $wcTerm->term_id,
                $result['term_id'],
                $this->migrationId,
                true,
            );
            $this->writeLog(
                $wcTerm->term_id,
                'success',
                sprintf('Migrated category "%s" (FC term %d).', $wcTerm->name, $result['term_id']),
            );
        }
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_PRODUCT;
    }

    /**
     * FIX H2: use COUNT(*) SQL query, not wc_get_products with limit=-1.
     */
    #[\Override]
    protected function countTotal(): int
    {
        global $wpdb;

        $types = $this->getProductTypes();
        $placeholders = implode(',', array_fill(0, count($types), '%s'));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}wc_product_meta_lookup pml
             INNER JOIN {$wpdb->posts} p ON p.ID = pml.product_id
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND pml.product_id IN (
                   SELECT object_id FROM {$wpdb->term_relationships} tr
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                   INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                   WHERE tt.taxonomy = 'product_type'
                     AND t.slug IN ({$placeholders})
               )",
            ...$types,
        ));
    }

    #[\Override]
    protected function fetchBatch(int $offset, int $limit): array
    {
        return wc_get_products([
            'limit'   => $limit,
            'offset'  => $offset,
            'type'    => $this->getProductTypes(),
            'status'  => ['publish', 'draft', 'private'],
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);
    }

    /**
     * @param \WC_Product $product
     */
    #[\Override]
    protected function processRecord(mixed $product): int|false
    {
        $wcId = $product->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcId)) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.');
            return false;
        }

        $mapped = $this->productMapper->map($product);

        if ($mapped === null) {
            $this->writeLog($wcId, 'skipped', sprintf('Unsupported product type: %s', $product->get_type()));
            return false;
        }

        // 1. Create the WP post (FC product).
        $postId = wp_insert_post($mapped['product'], true);

        if (is_wp_error($postId)) {
            throw new \RuntimeException($postId->get_error_message());
        }

        $this->idMap->store(Constants::ENTITY_PRODUCT, (string) $wcId, $postId, $this->migrationId, true);

        // 2. Create product detail row.
        $detailData = $mapped['detail'];
        $detailData['post_id'] = $postId;

        $detail = ProductDetail::query()->create($detailData);
        $this->idMap->store(Constants::ENTITY_PRODUCT_DETAIL, (string) $wcId, $detail->id, $this->migrationId, true);

        // 3. Create variations.
        $minPrice = PHP_INT_MAX;
        $maxPrice = 0;
        $defaultVariationId = null;
        $isVariable = $product->get_type() === 'variable';
        $wcVariationIds = $isVariable ? $product->get_children() : [$wcId];

        foreach ($mapped['variations'] as $index => $variationData) {
            $variationData['post_id'] = $postId;

            $skuSourceId = $wcVariationIds[$index] ?? $wcId;
            if (!empty($variationData['sku'])) {
                $variationData['sku'] = $this->ensureUniqueSku($variationData['sku'], $skuSourceId);
            }

            $fcVariation = ProductVariation::query()->create($variationData);

            $price = $variationData['item_price'];
            $minPrice = min($minPrice, $price);
            $maxPrice = max($maxPrice, $price);

            if ($index === 0) {
                $defaultVariationId = $fcVariation->id;
            }

            $variationWcId = ($isVariable && isset($wcVariationIds[$index]))
                ? $wcVariationIds[$index]
                : $wcId;

            $this->idMap->store(
                Constants::ENTITY_VARIATION,
                (string) $variationWcId,
                $fcVariation->id,
                $this->migrationId,
                true,
            );
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

        $this->writeLog($wcId, 'success', sprintf(
            'Migrated product "%s" (FC ID: %d) with %d variation(s).',
            $product->get_name(),
            $postId,
            count($mapped['variations']),
        ));

        return $postId;
    }

    /**
     * FIX H10: include 'subscription' and 'variable-subscription' product types
     * when WC Subscriptions is active.
     *
     * @return string[]
     */
    private function getProductTypes(): array
    {
        $types = ['simple', 'variable'];

        if (class_exists('WC_Subscriptions')) {
            $types[] = 'subscription';
            $types[] = 'variable-subscription';
        }

        return $types;
    }

    /**
     * FIX H3: topological sort for categories — parents always before children.
     *
     * @param \WP_Term[] $categories
     * @return \WP_Term[]
     */
    private function sortCategoriesByHierarchy(array $categories): array
    {
        $indexed = [];
        foreach ($categories as $cat) {
            $indexed[$cat->term_id] = $cat;
        }

        $sorted = [];
        $processedIds = [];

        $addWithParents = function (\WP_Term $term) use (&$addWithParents, &$sorted, &$processedIds, $indexed): void {
            if (isset($processedIds[$term->term_id])) {
                return;
            }

            if ($term->parent > 0 && isset($indexed[$term->parent]) && !isset($processedIds[$term->parent])) {
                $addWithParents($indexed[$term->parent]);
            }

            $sorted[] = $term;
            $processedIds[$term->term_id] = true;
        };

        foreach ($indexed as $term) {
            $addWithParents($term);
        }

        return $sorted;
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

        $newSku = $sku . '-wc' . $wcId;
        $this->writeLog($wcId, 'skipped', sprintf(
            'SKU "%s" already exists in FluentCart. Using "%s" instead.',
            $sku,
            $newSku,
        ));

        return $newSku;
    }
}
