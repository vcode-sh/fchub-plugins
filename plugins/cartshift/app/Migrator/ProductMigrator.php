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
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingClass;

final class ProductMigrator extends AbstractMigrator
{
    /** @var array<int, int> WC term_id => FC term_id mapping for categories */
    private array $categoryMap = [];

    /** @var array<int, int> WC term_id => FC term_id mapping for brands */
    private array $brandMap = [];

    /** @var array<string, int> WC attribute slug => FC attribute group ID */
    private array $attributeGroupMap = [];

    /** @var array<string, int> WC attribute term slug => FC attribute term ID */
    private array $attributeTermMap = [];

    /** @var array<int, int> WC shipping class term_id => FC shipping class ID */
    private array $shippingClassMap = [];

    private ProductMapper $productMapper;
    private VariationMapper $variationMapper;

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
     * Run one-time setup: migrate categories, brands, and attributes before processing products.
     */
    #[\Override]
    public function initialize(): void
    {
        $this->migrateCategories();
        $this->migrateBrands();
        $this->migrateAttributes();
        $this->migrateShippingClasses();

        // Rebuild mappers now that shipping class map is populated.
        $currency = get_woocommerce_currency();
        $this->productMapper = new ProductMapper($currency, $this->shippingClassMap);
        $this->variationMapper = new VariationMapper($currency, $this->shippingClassMap);
    }

    /**
     * Migrate WC product_cat terms to FC product-categories taxonomy.
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
    public function fetchBatch(int $offset, int $limit): array
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
     * Validate a product without creating any FC records.
     *
     * @param \WC_Product $product
     */
    #[\Override]
    public function validateRecord(mixed $product): bool
    {
        $wcId = $product->get_id();
        $name = $product->get_name();

        if ($this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcId)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: already migrated, would skip.');
            return false;
        }

        $mapped = $this->productMapper->map($product);

        if ($mapped === null) {
            $this->writeLog($wcId, 'dry-run', sprintf(
                'dry-run: unsupported product type "%s", would skip.',
                $product->get_type(),
            ));
            return false;
        }

        if (empty($name)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: product name is empty, would fail.');
            return false;
        }

        $variationCount = count($mapped['variations']);

        if ($variationCount === 0) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: no variations would be created, would fail.');
            return false;
        }

        $this->writeLog($wcId, 'dry-run', sprintf(
            'dry-run: would create product "%s" with %d variation(s).',
            $name,
            $variationCount,
        ));

        return true;
    }

    /**
     * @param \WC_Product $product
     */
    #[\Override]
    public function processRecord(mixed $product): int|false
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
        $firstVariationId = null;
        $isVariable = $product->get_type() === 'variable';
        $wcVariationIds = $isVariable ? $product->get_children() : [$wcId];

        /** @var array<int, array{fc_id: int, attributes: array}> FC variation ID => attributes for M15 matching */
        $fcVariationMap = [];

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
                $firstVariationId = $fcVariation->id;
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

            // FIX M3: Migrate variation thumbnail.
            if ($isVariable && isset($wcVariationIds[$index])) {
                $wcVariation = wc_get_product($wcVariationIds[$index]);
                if ($wcVariation instanceof \WC_Product_Variation) {
                    $this->migrateVariationThumbnail($wcVariation, $fcVariation->id);

                    // Collect attributes for M15 default variation matching.
                    $fcVariationMap[] = [
                        'fc_id'      => $fcVariation->id,
                        'attributes' => $wcVariation->get_attributes(),
                    ];
                }
            }
        }

        // FIX M15: Resolve default variation from WC default attributes.
        $defaultVariationId = $isVariable
            ? $this->resolveDefaultVariation($product, $fcVariationMap, $firstVariationId)
            : $firstVariationId;

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

        // FIX M3: Migrate gallery images.
        $this->migrateGalleryImages($product, $postId);

        // 6. Assign product categories.
        $this->assignCategories($wcId, $postId);

        // FIX M5: Store WC product tags as FC post meta.
        $this->assignTags($wcId, $postId);

        // FIX M14: Assign product brands.
        $this->assignBrands($wcId, $postId);

        // FIX M6: Create FC attribute relations for variations.
        if ($isVariable) {
            $this->assignAttributes($product, $postId, $wcVariationIds);
        }

        // FIX M4: Migrate downloadable files.
        // For variable products, downloadable flag lives on individual variations, not the parent.
        if ($product->is_downloadable() || $isVariable) {
            $this->migrateDownloadFiles($product, $postId, $isVariable, $wcVariationIds);
        }

        $this->writeLog($wcId, 'success', sprintf(
            'Migrated product "%s" (FC ID: %d) with %d variation(s).',
            $product->get_name(),
            $postId,
            count($mapped['variations']),
        ));

        return $postId;
    }

    /**
     * FIX M3: Write gallery image meta to the FC product post.
     *
     * Builds the gallery array from the WC product's featured image and gallery attachment IDs,
     * then stores it as `fluent-products-gallery-image` post meta.
     */
    private function migrateGalleryImages(\WC_Product $product, int $fcPostId): void
    {
        $featuredId = $product->get_image_id();
        $galleryIds = $product->get_gallery_image_ids();

        if (!$featuredId && empty($galleryIds)) {
            return;
        }

        $allIds = $featuredId
            ? array_merge([$featuredId], $galleryIds)
            : $galleryIds;

        // Deduplicate — featured image should not appear twice.
        $allIds = array_unique(array_map('intval', $allIds));

        $gallery = [];
        foreach ($allIds as $attachmentId) {
            $url = wp_get_attachment_url($attachmentId);
            if (!$url) {
                continue;
            }
            $gallery[] = [
                'id'    => $attachmentId,
                'url'   => $url,
                'title' => get_the_title($attachmentId),
            ];
        }

        if (!empty($gallery)) {
            update_post_meta($fcPostId, 'fluent-products-gallery-image', $gallery);
        }
    }

    /**
     * FIX M3: Write variation thumbnail meta to fct_product_meta.
     *
     * Creates a `product_variant_info` / `product_thumbnail` row matching the FC structure.
     */
    private function migrateVariationThumbnail(\WC_Product_Variation $variation, int $fcVariationId): void
    {
        $imageId = $variation->get_image_id();
        if (!$imageId) {
            return;
        }

        $imageUrl = wp_get_attachment_url($imageId);
        if (!$imageUrl) {
            return;
        }

        $metaValue = [[
            'id'    => (int) $imageId,
            'title' => get_the_title($imageId) ?: $variation->get_name(),
            'url'   => $imageUrl,
        ]];

        ProductMeta::query()->create([
            'object_id'   => $fcVariationId,
            'object_type' => 'product_variant_info',
            'meta_key'    => 'product_thumbnail',
            'meta_value'  => $metaValue,
        ]);
    }

    /**
     * FIX M4: Migrate WC downloadable files to fct_product_downloads.
     *
     * For simple products the downloads belong to a single variation.
     * For variable products each WC variation's downloads are mapped to the corresponding FC variation.
     *
     * @param int[] $wcVariationIds WC variation (or product) IDs in variation order.
     */
    private function migrateDownloadFiles(
        \WC_Product $product,
        int $fcPostId,
        bool $isVariable,
        array $wcVariationIds,
    ): void {
        if ($isVariable) {
            $this->migrateVariableDownloads($product, $fcPostId, $wcVariationIds);
        } else {
            $this->migrateSimpleDownloads($product, $fcPostId);
        }
    }

    /**
     * Migrate downloads for a simple (non-variable) product.
     */
    private function migrateSimpleDownloads(\WC_Product $product, int $fcPostId): void
    {
        $downloads = $product->get_downloads();
        if (empty($downloads)) {
            return;
        }

        $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $product->get_id());
        if (!$fcVariationId) {
            return;
        }

        $serial = 1;
        foreach ($downloads as $download) {
            $this->createDownloadRecord(
                $fcPostId,
                [(int) $fcVariationId],
                $download,
                $product,
                $serial++,
            );
        }
    }

    /**
     * Migrate downloads for a variable product — each WC variation's files map to its FC variation.
     *
     * @param int[] $wcVariationIds WC variation IDs in order.
     */
    private function migrateVariableDownloads(
        \WC_Product $product,
        int $fcPostId,
        array $wcVariationIds,
    ): void {
        /**
         * Group identical files across variations so a single FC download record
         * can reference multiple variation IDs (same pattern FC's own migrator uses).
         *
         * @var array<string, array{download: \WC_Product_Download, variation_ids: int[]}> $groups
         */
        $groups = [];

        foreach ($wcVariationIds as $wcVarId) {
            $wcVariation = wc_get_product($wcVarId);
            if (!$wcVariation instanceof \WC_Product_Variation || !$wcVariation->is_downloadable()) {
                continue;
            }

            $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcVarId);
            if (!$fcVariationId) {
                continue;
            }

            foreach ($wcVariation->get_downloads() as $download) {
                $fileKey = $download->get_file();
                if (!isset($groups[$fileKey])) {
                    $groups[$fileKey] = [
                        'download'      => $download,
                        'variation_ids' => [],
                    ];
                }
                $groups[$fileKey]['variation_ids'][] = (int) $fcVariationId;
            }
        }

        $serial = 1;
        foreach ($groups as $group) {
            $this->createDownloadRecord(
                $fcPostId,
                $group['variation_ids'],
                $group['download'],
                $product,
                $serial++,
            );
        }
    }

    /**
     * Insert a single FC download record from a WC_Product_Download.
     *
     * @param int[] $fcVariationIds FC variation IDs this download belongs to.
     */
    private function createDownloadRecord(
        int $fcPostId,
        array $fcVariationIds,
        \WC_Product_Download $download,
        \WC_Product $product,
        int $serial,
    ): void {
        $fileName = basename($download->get_file());

        $downloadLimit  = $product->get_download_limit();
        $downloadExpiry = $product->get_download_expiry();

        ProductDownload::query()->create([
            'post_id'               => $fcPostId,
            'product_variation_id'  => $fcVariationIds,
            'download_identifier'   => wp_generate_uuid4(),
            'title'                 => $download->get_name() ?: $fileName,
            'driver'                => 'local',
            'file_name'             => $fileName,
            'file_path'             => $fileName,
            'file_url'              => $fileName,
            'settings'              => [
                'download_limit'  => $downloadLimit > 0 ? (string) $downloadLimit : '',
                'download_expiry' => $downloadExpiry > 0 ? (string) $downloadExpiry : '',
            ],
            'serial'                => $serial,
        ]);
    }

    /**
     * FIX M15: Find the FC variation matching WC's default attributes.
     *
     * Compares each FC variation's WC attributes against the product's default attribute selection.
     * Falls back to the first variation when no match is found or no defaults are set.
     *
     * @param array<int, array{fc_id: int, attributes: array}> $fcVariationMap
     */
    private function resolveDefaultVariation(
        \WC_Product $product,
        array $fcVariationMap,
        ?int $fallbackId,
    ): ?int {
        $defaults = $product->get_default_attributes();

        if (empty($defaults) || empty($fcVariationMap)) {
            return $fallbackId;
        }

        foreach ($fcVariationMap as $entry) {
            $attrs = $entry['attributes'];
            $match = true;

            foreach ($defaults as $attrName => $defaultValue) {
                $variationValue = $attrs[$attrName]
                    ?? $attrs['pa_' . $attrName]
                    ?? '';

                // Empty variation value means "any" — still a valid match.
                if ($variationValue !== '' && $variationValue !== $defaultValue) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                return $entry['fc_id'];
            }
        }

        return $fallbackId;
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
     * FIX M5: Store WC product tags as post meta on the FC product.
     * FC doesn't fully support product-tags taxonomy yet, so we preserve them as meta.
     */
    private function assignTags(int $wcProductId, int $fcPostId): void
    {
        $wcTags = wp_get_object_terms($wcProductId, 'product_tag');

        if (is_wp_error($wcTags) || empty($wcTags)) {
            return;
        }

        $tagNames = array_map(fn(\WP_Term $t): string => $t->name, $wcTags);
        update_post_meta($fcPostId, '_wc_product_tags', $tagNames);
    }

    /**
     * FIX M14: Migrate WC product_brand terms to FC product-brands taxonomy.
     * Must be called before run().
     */
    public function migrateBrands(): void
    {
        $taxonomy = $this->getWcBrandTaxonomy();
        if ($taxonomy === null) {
            return;
        }

        $wcBrands = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($wcBrands) || empty($wcBrands)) {
            return;
        }

        foreach ($wcBrands as $wcTerm) {
            $existing = get_term_by('slug', $wcTerm->slug, 'product-brands');

            if ($existing) {
                $this->brandMap[$wcTerm->term_id] = $existing->term_id;
                $this->idMap->store(
                    Constants::ENTITY_BRAND,
                    (string) $wcTerm->term_id,
                    $existing->term_id,
                    $this->migrationId,
                    false,
                );
                $this->writeLog(
                    $wcTerm->term_id,
                    'skipped',
                    sprintf('Brand "%s" already exists in FluentCart (FC term %d).', $wcTerm->name, $existing->term_id),
                );
                continue;
            }

            $result = wp_insert_term($wcTerm->name, 'product-brands', [
                'slug'        => $wcTerm->slug,
                'description' => $wcTerm->description,
            ]);

            if (is_wp_error($result)) {
                $this->writeLog(
                    $wcTerm->term_id,
                    'error',
                    sprintf('Failed to create brand "%s": %s', $wcTerm->name, $result->get_error_message()),
                );
                continue;
            }

            $this->brandMap[$wcTerm->term_id] = $result['term_id'];
            $this->idMap->store(
                Constants::ENTITY_BRAND,
                (string) $wcTerm->term_id,
                $result['term_id'],
                $this->migrationId,
                true,
            );
            $this->writeLog(
                $wcTerm->term_id,
                'success',
                sprintf('Migrated brand "%s" (FC term %d).', $wcTerm->name, $result['term_id']),
            );
        }
    }

    /**
     * FIX M14: Assign FC brands to a product based on its WC brands.
     */
    private function assignBrands(int $wcProductId, int $fcPostId): void
    {
        $taxonomy = $this->getWcBrandTaxonomy();
        if ($taxonomy === null) {
            return;
        }

        $wcTerms = wp_get_post_terms($wcProductId, $taxonomy, ['fields' => 'ids']);

        if (is_wp_error($wcTerms) || empty($wcTerms)) {
            return;
        }

        $fcTermIds = [];
        foreach ($wcTerms as $wcTermId) {
            if (isset($this->brandMap[$wcTermId])) {
                $fcTermIds[] = $this->brandMap[$wcTermId];
            }
        }

        if (!empty($fcTermIds)) {
            wp_set_object_terms($fcPostId, $fcTermIds, 'product-brands');
        }
    }

    /**
     * FIX M14: Detect the WC brand taxonomy, if registered.
     */
    private function getWcBrandTaxonomy(): ?string
    {
        if (taxonomy_exists('product_brand')) {
            return 'product_brand';
        }

        return null;
    }

    /**
     * FIX M6: Migrate WC global product attributes to FC attribute tables.
     * Must be called before run().
     */
    public function migrateAttributes(): void
    {
        global $wpdb;

        // Check the attribute tables exist before proceeding.
        $tableExists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s",
                $wpdb->prefix . 'fct_atts_groups',
            ),
        );

        if (!$tableExists) {
            return;
        }

        $wcAttributes = wc_get_attribute_taxonomies();
        if (empty($wcAttributes)) {
            return;
        }

        foreach ($wcAttributes as $wcAttr) {
            $slug = wc_attribute_taxonomy_name($wcAttr->attribute_name);
            $groupSlug = sanitize_title($wcAttr->attribute_name);

            // Check if group already exists in FC.
            $existing = AttributeGroup::query()->where('slug', $groupSlug)->first();

            if ($existing) {
                $this->attributeGroupMap[$slug] = (int) $existing->id;
                $this->idMap->store(
                    Constants::ENTITY_ATTRIBUTE_GROUP,
                    (string) $wcAttr->attribute_id,
                    (int) $existing->id,
                    $this->migrationId,
                    false,
                );
            } else {
                $group = AttributeGroup::query()->create([
                    'title' => $wcAttr->attribute_label,
                    'slug'  => $groupSlug,
                ]);

                $this->attributeGroupMap[$slug] = (int) $group->id;
                $this->idMap->store(
                    Constants::ENTITY_ATTRIBUTE_GROUP,
                    (string) $wcAttr->attribute_id,
                    (int) $group->id,
                    $this->migrationId,
                    true,
                );
                $this->writeLog(
                    (int) $wcAttr->attribute_id,
                    'success',
                    sprintf('Created attribute group "%s" (FC ID: %d).', $wcAttr->attribute_label, $group->id),
                );
            }

            // Migrate terms for this attribute.
            $wcTerms = get_terms([
                'taxonomy'   => $slug,
                'hide_empty' => false,
            ]);

            if (is_wp_error($wcTerms) || empty($wcTerms)) {
                continue;
            }

            $groupId = $this->attributeGroupMap[$slug];
            $serial = 1;

            foreach ($wcTerms as $wcTerm) {
                $termSlug = sanitize_title($wcTerm->slug);
                $compositeKey = $groupSlug . ':' . $termSlug;

                $existingTerm = AttributeTerm::query()
                    ->where('group_id', $groupId)
                    ->where('slug', $termSlug)
                    ->first();

                if ($existingTerm) {
                    $this->attributeTermMap[$compositeKey] = (int) $existingTerm->id;
                    $this->idMap->store(
                        Constants::ENTITY_ATTRIBUTE_TERM,
                        (string) $wcTerm->term_id,
                        (int) $existingTerm->id,
                        $this->migrationId,
                        false,
                    );
                } else {
                    $fcTerm = AttributeTerm::query()->create([
                        'group_id' => $groupId,
                        'serial'   => $serial++,
                        'title'    => $wcTerm->name,
                        'slug'     => $termSlug,
                    ]);

                    $this->attributeTermMap[$compositeKey] = (int) $fcTerm->id;
                    $this->idMap->store(
                        Constants::ENTITY_ATTRIBUTE_TERM,
                        (string) $wcTerm->term_id,
                        (int) $fcTerm->id,
                        $this->migrationId,
                        true,
                    );
                }
            }
        }
    }

    /**
     * Migrate WC product_shipping_class terms to FC fct_shipping_classes table.
     */
    public function migrateShippingClasses(): void
    {
        $wcShippingClasses = get_terms([
            'taxonomy'   => 'product_shipping_class',
            'hide_empty' => false,
        ]);

        if (is_wp_error($wcShippingClasses) || empty($wcShippingClasses)) {
            return;
        }

        foreach ($wcShippingClasses as $wcTerm) {
            $existing = ShippingClass::query()->where('name', $wcTerm->name)->first();

            if ($existing) {
                $this->shippingClassMap[$wcTerm->term_id] = (int) $existing->id;
                $this->idMap->store(
                    Constants::ENTITY_SHIPPING_CLASS,
                    (string) $wcTerm->term_id,
                    (int) $existing->id,
                    $this->migrationId,
                    false,
                );
                $this->writeLog(
                    $wcTerm->term_id,
                    'skipped',
                    sprintf('Shipping class "%s" already exists in FluentCart (FC ID %d).', $wcTerm->name, $existing->id),
                );
                continue;
            }

            $fcShippingClass = ShippingClass::query()->create([
                'name'     => $wcTerm->name,
                'cost'     => '0.00',
                'per_item' => 0,
                'type'     => 'fixed',
            ]);

            $this->shippingClassMap[$wcTerm->term_id] = (int) $fcShippingClass->id;
            $this->idMap->store(
                Constants::ENTITY_SHIPPING_CLASS,
                (string) $wcTerm->term_id,
                (int) $fcShippingClass->id,
                $this->migrationId,
                true,
            );
            $this->writeLog(
                $wcTerm->term_id,
                'success',
                sprintf('Migrated shipping class "%s" (FC ID: %d).', $wcTerm->name, $fcShippingClass->id),
            );
        }
    }

    /**
     * FIX M6: Create FC attribute relations for a variable product's variations.
     *
     * @param int[] $wcVariationIds WC variation IDs in order.
     */
    private function assignAttributes(\WC_Product $product, int $fcPostId, array $wcVariationIds): void
    {
        if (empty($this->attributeGroupMap)) {
            return;
        }

        foreach ($wcVariationIds as $wcVarId) {
            $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcVarId);
            if (!$fcVariationId) {
                continue;
            }

            $wcVariation = wc_get_product($wcVarId);
            if (!$wcVariation instanceof \WC_Product_Variation) {
                continue;
            }

            $attributes = $wcVariation->get_attributes();
            foreach ($attributes as $taxonomy => $termSlug) {
                if ($termSlug === '') {
                    continue; // "Any" attribute — skip.
                }

                if (!isset($this->attributeGroupMap[$taxonomy])) {
                    continue;
                }

                $groupId = $this->attributeGroupMap[$taxonomy];
                $groupSlug = sanitize_title(str_replace('pa_', '', $taxonomy));
                $compositeKey = $groupSlug . ':' . sanitize_title($termSlug);

                if (!isset($this->attributeTermMap[$compositeKey])) {
                    continue;
                }

                $fcTermId = $this->attributeTermMap[$compositeKey];

                AttributeRelation::query()->firstOrCreate([
                    'group_id'  => $groupId,
                    'term_id'   => $fcTermId,
                    'object_id' => $fcVariationId,
                ]);
            }
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
