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
use CartShift\Support\Enums\MigrationErrorCode;
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
    /** @var int|null Highest product ID covered by the ID page fetchBatch() last read. */
    private ?int $pageEndCursor = null;

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

    /** @var bool True once the taxonomy maps above hold this migration's data */
    private bool $mapsLoaded = false;

    /** @var array<string, true> SKUs already known to be taken in FluentCart */
    private array $knownSkus = [];

    /** Upper bound on suffix attempts when de-duplicating a SKU */
    private const int SKU_SUFFIX_LIMIT = 50;

    /**
     * Base for a dry run's synthetic variation IDs.
     *
     * Kept well clear of MigrationOrchestrator::$simulatedId, which starts at
     * 900,000,001 and increments by one per validated record across every
     * entity type in the run — a store big enough to validate 500,000 records
     * in one dry run would otherwise walk that counter straight into the
     * original 900,500,000 base the brief specified. WooCommerce variation IDs
     * are WordPress post IDs, so this base has room for post IDs into the tens
     * of millions before it could ever meet the orchestrator's counter coming
     * the other way.
     */
    private const int SIMULATED_VARIATION_BASE = 950_000_000;

    /**
     * Base for a dry run's synthetic taxonomy IDs.
     *
     * Categories, brands, shipping classes and attribute terms are all WordPress
     * terms, and wp_terms IDs are unique across every taxonomy, so one base
     * serves all four without any two of them ever landing on the same number.
     *
     * Placed well below MigrationOrchestrator::$simulatedId (900,000,001 upward)
     * rather than above it, because the two bases above this one already climb
     * with WordPress post IDs and adding a third in that neighbourhood would
     * eventually squeeze them together. A store would need fifty million terms
     * to reach 900,000,000 from here.
     */
    private const int SIMULATED_TERM_BASE = 800_000_000;

    /**
     * Base for a dry run's synthetic attribute group IDs.
     *
     * Its own base because a WooCommerce attribute ID comes from
     * woocommerce_attribute_taxonomies, a sequence entirely unrelated to
     * wp_terms — attribute 12 and term 12 are different things and must not
     * simulate to the same number.
     */
    private const int SIMULATED_ATTRIBUTE_GROUP_BASE = 700_000_000;

    private ProductMapper $productMapper;
    private VariationMapper $variationMapper;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $batchSize);

        $currency = get_woocommerce_currency();
        $this->productMapper = new ProductMapper($currency);
        $this->variationMapper = new VariationMapper($currency);
    }

    /**
     * Run one-time setup: migrate categories, brands, and attributes before processing products.
     *
     * Idempotent: entities already recorded in the ID map are adopted straight from it,
     * without a second ID map row or a second log line.
     */
    #[\Override]
    public function initialize(): void
    {
        $this->migrateCategories();
        $this->migrateBrands();
        $this->migrateAttributes();
        $this->migrateShippingClasses();

        $this->mapsLoaded = true;

        // Rebuild mappers now that shipping class map is populated.
        $this->rebuildMappers();
    }

    /**
     * The read-only counterpart of initialize(), for a dry run.
     *
     * A dry run creates nothing, so initialize() is off limits — it inserts
     * WordPress terms, FluentCart attribute groups, attribute terms and shipping
     * classes. But skipping it outright, which is what the orchestrator used to
     * do, left ENTITY_CATEGORY completely unpopulated for the whole run. Every
     * `included_categories` and `excluded_categories` restriction then resolved to
     * an empty list, both of those keys are in CouponMapper's
     * WIDENING_ON_TOTAL_LOSS, and so every coupon carrying a category restriction
     * was reported as would-be-disabled whether or not it would be. The one number
     * the dry run exists to produce was therefore an upper bound wearing a precise
     * figure's clothing.
     *
     * So: resolve what a real run would find already present in FluentCart, and
     * mint a synthetic ID for what it would have to create. Nothing is written
     * outside CartShift's own ID map, and those rows carry `is_simulated = 1`.
     *
     * Idempotent by construction — every map re-reads the ID map first, so a batch
     * that died before advancing its offset re-runs this harmlessly.
     */
    #[\Override]
    public function initializeSimulated(): void
    {
        $this->categoryMap += $this->simulateTermMap(
            'product_cat',
            'product-categories',
            Constants::ENTITY_CATEGORY,
            // migrateCategories() skips this term outright, so a real run never
            // maps it and neither may the rehearsal.
            ['uncategorized'],
        );

        $brandTaxonomy = $this->getWcBrandTaxonomy();

        if ($brandTaxonomy !== null) {
            $this->brandMap += $this->simulateTermMap(
                $brandTaxonomy,
                'product-brands',
                Constants::ENTITY_BRAND,
                [],
            );
        }

        $this->shippingClassMap += $this->simulateShippingClasses();

        $this->simulateAttributes();

        $this->mapsLoaded = true;

        // Same reason as initialize(): the mappers hold a copy of the shipping
        // class map, so they have to be rebuilt once it is populated.
        $this->rebuildMappers();
    }

    /**
     * Resolve one WC taxonomy against its FluentCart counterpart without creating
     * anything, registering each result in the simulated ID map.
     *
     * @param list<string> $skipSlugs WC term slugs a real run would not migrate.
     *
     * @return array<int, int> WC term_id => FC term_id
     */
    private function simulateTermMap(
        string $wcTaxonomy,
        string $fcTaxonomy,
        string $entityType,
        array $skipSlugs,
    ): array {
        $wcTerms = get_terms([
            'taxonomy'   => $wcTaxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($wcTerms) || empty($wcTerms)) {
            return [];
        }

        $stored = $this->idMap->getMapForEntityType($entityType);
        $map    = [];

        foreach ($wcTerms as $wcTerm) {
            if (in_array($wcTerm->slug, $skipSlugs, true)) {
                continue;
            }

            $wcTermId = (int) $wcTerm->term_id;

            if (isset($stored[(string) $wcTermId])) {
                $map[$wcTermId] = (int) $stored[(string) $wcTermId];
                continue;
            }

            $existing = get_term_by('slug', $wcTerm->slug, $fcTaxonomy);
            $fcId     = $existing ? (int) $existing->term_id : self::SIMULATED_TERM_BASE + $wcTermId;

            $map[$wcTermId] = $fcId;

            $this->idMap->store(
                $entityType,
                (string) $wcTermId,
                $fcId,
                $this->migrationId(),
                !$existing,
            );
        }

        return $map;
    }

    /**
     * The shipping class map a real run would build, without building it.
     *
     * @return array<int, int> WC term_id => FC shipping class ID
     */
    private function simulateShippingClasses(): array
    {
        $wcShippingClasses = get_terms([
            'taxonomy'   => 'product_shipping_class',
            'hide_empty' => false,
        ]);

        if (is_wp_error($wcShippingClasses) || empty($wcShippingClasses)) {
            return [];
        }

        $stored = $this->idMap->getMapForEntityType(Constants::ENTITY_SHIPPING_CLASS);
        $map    = [];

        foreach ($wcShippingClasses as $wcTerm) {
            $wcTermId = (int) $wcTerm->term_id;

            if (isset($stored[(string) $wcTermId])) {
                $map[$wcTermId] = (int) $stored[(string) $wcTermId];
                continue;
            }

            $existing = ShippingClass::query()->where('name', $wcTerm->name)->first();
            $fcId     = $existing ? (int) $existing->id : self::SIMULATED_TERM_BASE + $wcTermId;

            $map[$wcTermId] = $fcId;

            $this->idMap->store(
                Constants::ENTITY_SHIPPING_CLASS,
                (string) $wcTermId,
                $fcId,
                $this->migrationId(),
                !$existing,
            );
        }

        return $map;
    }

    /**
     * The attribute group and term maps a real run would build, without building
     * them.
     *
     * Mirrors migrateAttributes() down to the table-existence guard: with no
     * FluentCart attribute tables a real run maps no attributes at all, and the
     * rehearsal has to agree with it rather than invent a rosier picture.
     */
    private function simulateAttributes(): void
    {
        global $wpdb;

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

        $storedGroups = $this->idMap->getMapForEntityType(Constants::ENTITY_ATTRIBUTE_GROUP);
        $storedTerms  = $this->idMap->getMapForEntityType(Constants::ENTITY_ATTRIBUTE_TERM);

        foreach ($wcAttributes as $wcAttr) {
            $slug      = wc_attribute_taxonomy_name($wcAttr->attribute_name);
            $groupSlug = sanitize_title($wcAttr->attribute_name);
            $wcAttrId  = (int) $wcAttr->attribute_id;

            $groupId = $storedGroups[(string) $wcAttrId] ?? null;

            if ($groupId === null) {
                $existing = AttributeGroup::query()->where('slug', $groupSlug)->first();
                $groupId  = $existing
                    ? (int) $existing->id
                    : self::SIMULATED_ATTRIBUTE_GROUP_BASE + $wcAttrId;

                $this->idMap->store(
                    Constants::ENTITY_ATTRIBUTE_GROUP,
                    (string) $wcAttrId,
                    (int) $groupId,
                    $this->migrationId(),
                    !$existing,
                );
            }

            $this->attributeGroupMap[$slug] = (int) $groupId;

            $this->simulateAttributeTerms($slug, $groupSlug, (int) $groupId, $storedTerms);
        }
    }

    /**
     * Resolve the terms of one WC attribute taxonomy without creating any.
     *
     * @param array<string, int> $storedTerms WC term_id => FC term ID already in the ID map.
     */
    private function simulateAttributeTerms(
        string $taxonomy,
        string $groupSlug,
        int $groupId,
        array $storedTerms,
    ): void {
        $wcTerms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($wcTerms) || empty($wcTerms)) {
            return;
        }

        foreach ($wcTerms as $wcTerm) {
            $termSlug     = sanitize_title($wcTerm->slug);
            $compositeKey = $groupSlug . ':' . $termSlug;
            $wcTermId     = (int) $wcTerm->term_id;

            $fcTermId = $storedTerms[(string) $wcTermId] ?? null;

            if ($fcTermId === null) {
                $existing = AttributeTerm::query()
                    ->where('group_id', $groupId)
                    ->where('slug', $termSlug)
                    ->first();

                $fcTermId = $existing
                    ? (int) $existing->id
                    : self::SIMULATED_TERM_BASE + $wcTermId;

                $this->idMap->store(
                    Constants::ENTITY_ATTRIBUTE_TERM,
                    (string) $wcTermId,
                    (int) $fcTermId,
                    $this->migrationId(),
                    !$existing,
                );
            }

            $this->attributeTermMap[$compositeKey] = (int) $fcTermId;
        }
    }

    /**
     * Make sure the taxonomy maps are populated for this PHP process.
     *
     * A fresh ProductMigrator is constructed on every REST batch request and every
     * Action Scheduler invocation, but initialize() only runs on the first batch.
     * Without this, products beyond the first batch would silently lose their
     * categories, brands, attributes and shipping classes. Everything needed is
     * already persisted in the ID map, so rebuild from there instead of replaying
     * the setup work.
     */
    private function ensureTaxonomyMaps(): void
    {
        if ($this->mapsLoaded) {
            return;
        }

        $this->mapsLoaded = true;

        // Union with in-memory precedence: anything already resolved stays.
        $this->categoryMap      += $this->rehydrateTermMap(Constants::ENTITY_CATEGORY);
        $this->brandMap         += $this->rehydrateTermMap(Constants::ENTITY_BRAND);
        $this->shippingClassMap += $this->rehydrateTermMap(Constants::ENTITY_SHIPPING_CLASS);

        $this->rehydrateAttributeMaps();

        $this->rebuildMappers();
    }

    /**
     * Rebuild a WC term_id => FC id map straight from the ID map table.
     *
     * @return array<int, int>
     */
    private function rehydrateTermMap(string $entityType): array
    {
        $map = [];

        foreach ($this->idMap->getMapForEntityType($entityType) as $wcId => $fcId) {
            $map[(int) $wcId] = (int) $fcId;
        }

        return $map;
    }

    /**
     * Rebuild the attribute group and term maps.
     *
     * Neither map is keyed by the WC ID the ID map stores: groups are keyed by the WC
     * attribute taxonomy slug ("pa_color") and terms by "groupSlug:termSlug". The
     * composite keys are re-derived from WooCommerce exactly as migrateAttributes()
     * derives them, then matched against the stored WC attribute and term IDs.
     */
    private function rehydrateAttributeMaps(): void
    {
        $storedGroups = $this->idMap->getMapForEntityType(Constants::ENTITY_ATTRIBUTE_GROUP);

        if (empty($storedGroups)) {
            // Nothing was migrated (no FC attribute tables, or no WC attributes).
            return;
        }

        $storedTerms = $this->idMap->getMapForEntityType(Constants::ENTITY_ATTRIBUTE_TERM);

        foreach (wc_get_attribute_taxonomies() ?: [] as $wcAttr) {
            $fcGroupId = $storedGroups[(string) $wcAttr->attribute_id] ?? null;

            if ($fcGroupId === null) {
                continue;
            }

            $slug      = wc_attribute_taxonomy_name($wcAttr->attribute_name);
            $groupSlug = sanitize_title($wcAttr->attribute_name);

            $this->attributeGroupMap[$slug] = (int) $fcGroupId;

            if (empty($storedTerms)) {
                continue;
            }

            $wcTerms = get_terms([
                'taxonomy'   => $slug,
                'hide_empty' => false,
            ]);

            if (is_wp_error($wcTerms) || empty($wcTerms)) {
                continue;
            }

            foreach ($wcTerms as $wcTerm) {
                $fcTermId = $storedTerms[(string) $wcTerm->term_id] ?? null;

                if ($fcTermId === null) {
                    continue;
                }

                $this->attributeTermMap[$groupSlug . ':' . sanitize_title($wcTerm->slug)] = (int) $fcTermId;
            }
        }
    }

    /**
     * Rebuild both mappers so they carry the current shipping class map.
     */
    private function rebuildMappers(): void
    {
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

        // Already-mapped terms are adopted from the ID map, so a re-run neither
        // duplicates rows nor repeats log lines.
        $stored = $this->idMap->getMapForEntityType(Constants::ENTITY_CATEGORY);

        foreach ($sorted as $wcTerm) {
            if ($wcTerm->slug === 'uncategorized') {
                continue;
            }

            if (isset($stored[(string) $wcTerm->term_id])) {
                $this->categoryMap[$wcTerm->term_id] = (int) $stored[(string) $wcTerm->term_id];
                continue;
            }

            $existing = get_term_by('slug', $wcTerm->slug, 'product-categories');

            if ($existing) {
                $this->categoryMap[$wcTerm->term_id] = $existing->term_id;
                $this->idMap->store(
                    Constants::ENTITY_CATEGORY,
                    (string) $wcTerm->term_id,
                    $existing->term_id,
                    $this->migrationId(),
                    false,
                );
                $this->writeLog(
                    $wcTerm->term_id,
                    'skipped',
                    sprintf('Category "%s" already exists in FluentCart (FC term %d).', $wcTerm->name, $existing->term_id),
                    MigrationErrorCode::AlreadyExistsInFluentCart,
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
                    MigrationErrorCode::TermCreationFailed,
                );
                continue;
            }

            $this->categoryMap[$wcTerm->term_id] = $result['term_id'];
            $this->idMap->store(
                Constants::ENTITY_CATEGORY,
                (string) $wcTerm->term_id,
                $result['term_id'],
                $this->migrationId(),
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
        $selection = $this->scopeResolver()->productPredicate('p.ID');

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
               )"
            . $selection->andSql(),
            ...[...$types, ...$selection->values()],
        ));
    }

    /**
     * Keyset pagination over product IDs.
     *
     * WC_Product_Query cannot express `ID > x`. Its whole vocabulary is the
     * array returned by get_default_query_vars() — status, type, sku, price,
     * date_created, include/exclude and friends — and `include` maps straight
     * to WP_Query's post__in, which is a set membership test, not a range.
     *
     * @see woocommerce/includes/class-wc-product-query.php::get_default_query_vars() (v11.0.0, line 26)
     * @see woocommerce/includes/data-stores/class-wc-product-data-store-cpt.php::get_wp_query_args() (v11.0.0, line 2194: 'include' => 'post__in')
     *
     * So the ID page comes from a direct indexed query that reuses countTotal()'s
     * exact type/status filtering — the two must agree, or the progress bar lies
     * — and hydration goes through wc_get_products(['include' => $ids]), which
     * primes the post caches in one pass the way the old call did.
     */
    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
        $after = max(0, (int) $cursor);

        // Loop only in the pathological case where an entire ID page fails to
        // hydrate. Returning [] there would tell the orchestrator the entity is
        // finished and silently truncate the migration, so keep walking until
        // there is something to hand back or the table runs out.
        while (true) {
            $ids = $this->fetchProductIdPage($after, $limit);

            if ($ids === []) {
                return [];
            }

            $after = (int) end($ids);
            $this->pageEndCursor = $after;

            $products = wc_get_products([
                'limit'   => count($ids),
                'include' => $ids,
                'type'    => $this->getProductTypes(),
                'status'  => ['publish', 'draft', 'private'],
                'orderby' => 'ID',
                'order'   => 'ASC',
            ]);

            $products = array_values(array_filter(
                (array) $products,
                static fn (mixed $product): bool => is_object($product),
            ));

            if ($products !== []) {
                return $products;
            }
        }
    }

    /**
     * Hydrate exactly these product IDs, for a retry run.
     *
     * The same wc_get_products() call fetchBatch() hydrates its ID page with,
     * carrying the identical type and status filter — a product that has since
     * been trashed, or whose type was changed to one this migrator does not
     * handle, is not returned rather than being migrated by a back door the
     * normal run does not have.
     *
     * The page cursor is left alone: a retry paginates an ID list, not wp_posts.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return list<\WC_Product>
     */
    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        $ids = self::normalizeIntIds($wcIds);

        if ($ids === []) {
            return [];
        }

        $products = wc_get_products([
            'limit'   => count($ids),
            'include' => $ids,
            'type'    => $this->getProductTypes(),
            'status'  => ['publish', 'draft', 'private'],
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);

        return array_values(array_filter(
            (array) $products,
            static fn (mixed $product): bool => is_object($product),
        ));
    }

    /**
     * The cursor is the end of the ID page, not the last hydrated record.
     *
     * If wc_get_products() drops a trailing ID — a corrupt row, a filter that
     * vetoes it — resuming from the last hydrated product would re-read that ID
     * for ever. The page end always moves forward.
     */
    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        return $this->pageEndCursor ?? parent::cursorFor($record);
    }

    /**
     * The next page of product IDs strictly after $afterId.
     *
     * Same joins, same post_type/post_status/product_type filtering as
     * countTotal(); only the range clause, the ordering and the projection
     * differ.
     *
     * @return list<int>
     */
    private function fetchProductIdPage(int $afterId, int $limit): array
    {
        global $wpdb;

        $types = $this->getProductTypes();
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        $selection = $this->scopeResolver()->productPredicate('p.ID');

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->prefix}wc_product_meta_lookup pml
             INNER JOIN {$wpdb->posts} p ON p.ID = pml.product_id
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'draft', 'private')
               AND p.ID > %d
               AND pml.product_id IN (
                   SELECT object_id FROM {$wpdb->term_relationships} tr
                   INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                   INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                   WHERE tt.taxonomy = 'product_type'
                     AND t.slug IN ({$placeholders})
               )"
            . $selection->andSql()
            . " ORDER BY p.ID ASC
             LIMIT %d",
            ...[$afterId, ...$types, ...$selection->values(), $limit],
        ));

        return array_map(intval(...), $ids);
    }

    /**
     * Write the mapper's warnings about the product just mapped into the log.
     *
     * Mirrors CouponMigrator and SubscriptionMigrator. Until this existed the
     * product mapper's only warning — a WooCommerce product visible in the
     * catalog but not in search, or the other way round, a distinction
     * FluentCart cannot express — was handed to a filter nobody consumes and
     * then dropped. The information loss was real and permanent; the record of
     * it lasted microseconds. `partial_catalog_visibility` was consequently a
     * failure-reason filter that could only ever return zero rows.
     */
    private function flushMapperWarnings(int|string $wcId): void
    {
        foreach ($this->productMapper->getCodedWarnings() as $warning) {
            $this->writeLog($wcId, 'warning', $warning['message'], $warning['code']);
        }
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

        // initializeSimulated() only runs on the first batch, and a dry run's
        // later batches arrive in fresh PHP processes. Everything it registered is
        // in the ID map, so rebuild from there — same reasoning as processRecord().
        $this->ensureTaxonomyMaps();

        if ($this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcId)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: already migrated, would skip.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $mapped = $this->productMapper->map($product);

        if ($mapped === null) {
            $this->writeLog($wcId, 'dry-run', sprintf(
                'dry-run: unsupported product type "%s", would skip.',
                $product->get_type(),
            ), MigrationErrorCode::UnsupportedProductType);
            return false;
        }

        $this->flushMapperWarnings($wcId);

        if (empty($name)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: product name is empty, would fail.', MigrationErrorCode::EmptyProductName);
            return false;
        }

        $variationCount = count($mapped['variations']);

        if ($variationCount === 0) {
            $this->writeLog(
                $wcId,
                'dry-run',
                'dry-run: no variations would be created, would fail.',
                MigrationErrorCode::NoVariationsMapped,
            );
            return false;
        }

        $this->writeLog($wcId, 'dry-run', sprintf(
            'dry-run: would create product "%s" with %d variation(s).',
            $name,
            $variationCount,
        ));

        if ($this->idMap->isSimulating()) {
            // Order line items and subscriptions resolve ENTITY_VARIATION, which the
            // orchestrator cannot know about — it only registers the product itself.
            // A real run maps one variation per mapped variation row; mirror that so
            // orders and subscriptions validated later resolve their variation
            // references too.
            $isVariable = $product->get_type() === 'variable';
            $wcVariationIds = $isVariable ? array_keys($this->loadVariations($product)) : [$wcId];

            foreach (array_keys($mapped['variations']) as $index) {
                $wcVariationId = $wcVariationIds[$index] ?? $wcId;
                $this->idMap->store(
                    Constants::ENTITY_VARIATION,
                    (string) $wcVariationId,
                    self::SIMULATED_VARIATION_BASE + (int) $wcVariationId,
                    $this->migrationId(),
                    true,
                );
            }
        }

        return true;
    }

    /**
     * @param \WC_Product $product
     */
    #[\Override]
    public function processRecord(mixed $product): int|false
    {
        $wcId = $product->get_id();

        // initialize() only runs on the first batch, and this instance may well be
        // a later batch in a fresh PHP process.
        $this->ensureTaxonomyMaps();

        if ($this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcId)) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $mapped = $this->productMapper->map($product);

        if ($mapped === null) {
            $this->writeLog(
                $wcId,
                'skipped',
                sprintf('Unsupported product type: %s', $product->get_type()),
                MigrationErrorCode::UnsupportedProductType,
            );
            return false;
        }

        $this->flushMapperWarnings($wcId);

        // 1. Create the WP post (FC product).
        $postId = wp_insert_post($mapped['product'], true);

        if (is_wp_error($postId)) {
            // Thrown, not logged: the orchestrator wraps this record in a
            // transaction and would roll a log row straight back out again.
            // RecordMigrationException carries the reason across that boundary.
            throw new RecordMigrationException(
                $postId->get_error_message(),
                MigrationErrorCode::ProductCreationFailed,
            );
        }

        $this->idMap->store(Constants::ENTITY_PRODUCT, (string) $wcId, $postId, $this->migrationId(), true);

        // 2. Create product detail row.
        $detailData = $mapped['detail'];
        $detailData['post_id'] = $postId;

        $detail = ProductDetail::query()->create($detailData);
        $this->idMap->store(Constants::ENTITY_PRODUCT_DETAIL, (string) $wcId, $detail->id, $this->migrationId(), true);

        // 3. Create variations.
        $minPrice = PHP_INT_MAX;
        $maxPrice = 0;
        $firstVariationId = null;
        $isVariable = $product->get_type() === 'variable';

        // Load every WC variation exactly once and pass the objects around; the
        // variation loop, attribute assignment and download migration all need them.
        $wcVariations = $isVariable ? $this->loadVariations($product) : [];
        $wcVariationIds = $isVariable ? array_keys($wcVariations) : [$wcId];

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
                $this->migrationId(),
                true,
            );

            // FIX M3: Migrate variation thumbnail.
            $wcVariation = $isVariable && isset($wcVariationIds[$index])
                ? ($wcVariations[$wcVariationIds[$index]] ?? null)
                : null;

            if ($wcVariation !== null) {
                $this->migrateVariationThumbnail($wcVariation, $fcVariation->id);

                // Collect attributes for M15 default variation matching.
                $fcVariationMap[] = [
                    'fc_id'      => $fcVariation->id,
                    'attributes' => $wcVariation->get_attributes(),
                ];
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
            $this->assignAttributes($wcVariations);
        }

        // FIX M4: Migrate downloadable files.
        // For variable products, downloadable flag lives on individual variations, not the parent.
        if ($product->is_downloadable() || $isVariable) {
            $this->migrateDownloadFiles($product, $postId, $isVariable, $wcVariations);
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
     * @param array<int, \WC_Product_Variation> $wcVariations WC variation ID => already loaded object.
     */
    private function migrateDownloadFiles(
        \WC_Product $product,
        int $fcPostId,
        bool $isVariable,
        array $wcVariations,
    ): void {
        if ($isVariable) {
            $this->migrateVariableDownloads($product, $fcPostId, $wcVariations);
        } else {
            $this->migrateSimpleDownloads($product, $fcPostId);
        }
    }

    /**
     * Load a variable product's children once, keyed by WC variation ID.
     *
     * Children that no longer resolve to a variation are dropped, which keeps the
     * index alignment with ProductMapper::map() — it filters the same way.
     *
     * @return array<int, \WC_Product_Variation>
     */
    private function loadVariations(\WC_Product $product): array
    {
        $variations = [];

        foreach ($product->get_children() as $childId) {
            $wcVariation = wc_get_product((int) $childId);

            if ($wcVariation instanceof \WC_Product_Variation) {
                $variations[(int) $childId] = $wcVariation;
            }
        }

        return $variations;
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
     * @param array<int, \WC_Product_Variation> $wcVariations WC variation ID => already loaded object.
     */
    private function migrateVariableDownloads(
        \WC_Product $product,
        int $fcPostId,
        array $wcVariations,
    ): void {
        /**
         * Group identical files across variations so a single FC download record
         * can reference multiple variation IDs (same pattern FC's own migrator uses).
         *
         * @var array<string, array{download: \WC_Product_Download, variation_ids: int[]}> $groups
         */
        $groups = [];

        foreach ($wcVariations as $wcVarId => $wcVariation) {
            if (!$wcVariation->is_downloadable()) {
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

        $stored = $this->idMap->getMapForEntityType(Constants::ENTITY_BRAND);

        foreach ($wcBrands as $wcTerm) {
            if (isset($stored[(string) $wcTerm->term_id])) {
                $this->brandMap[$wcTerm->term_id] = (int) $stored[(string) $wcTerm->term_id];
                continue;
            }

            $existing = get_term_by('slug', $wcTerm->slug, 'product-brands');

            if ($existing) {
                $this->brandMap[$wcTerm->term_id] = $existing->term_id;
                $this->idMap->store(
                    Constants::ENTITY_BRAND,
                    (string) $wcTerm->term_id,
                    $existing->term_id,
                    $this->migrationId(),
                    false,
                );
                $this->writeLog(
                    $wcTerm->term_id,
                    'skipped',
                    sprintf('Brand "%s" already exists in FluentCart (FC term %d).', $wcTerm->name, $existing->term_id),
                    MigrationErrorCode::AlreadyExistsInFluentCart,
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
                    MigrationErrorCode::TermCreationFailed,
                );
                continue;
            }

            $this->brandMap[$wcTerm->term_id] = $result['term_id'];
            $this->idMap->store(
                Constants::ENTITY_BRAND,
                (string) $wcTerm->term_id,
                $result['term_id'],
                $this->migrationId(),
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

        $storedGroups = $this->idMap->getMapForEntityType(Constants::ENTITY_ATTRIBUTE_GROUP);
        $storedTerms  = $this->idMap->getMapForEntityType(Constants::ENTITY_ATTRIBUTE_TERM);

        foreach ($wcAttributes as $wcAttr) {
            $slug = wc_attribute_taxonomy_name($wcAttr->attribute_name);
            $groupSlug = sanitize_title($wcAttr->attribute_name);

            $storedGroupId = $storedGroups[(string) $wcAttr->attribute_id] ?? null;

            if ($storedGroupId !== null) {
                // Already recorded by an earlier run — adopt without touching the ID map.
                $this->attributeGroupMap[$slug] = (int) $storedGroupId;
                $this->migrateAttributeTerms($slug, $groupSlug, (int) $storedGroupId, $storedTerms);
                continue;
            }

            // Check if group already exists in FC.
            $existing = AttributeGroup::query()->where('slug', $groupSlug)->first();

            if ($existing) {
                $this->attributeGroupMap[$slug] = (int) $existing->id;
                $this->idMap->store(
                    Constants::ENTITY_ATTRIBUTE_GROUP,
                    (string) $wcAttr->attribute_id,
                    (int) $existing->id,
                    $this->migrationId(),
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
                    $this->migrationId(),
                    true,
                );
                $this->writeLog(
                    (int) $wcAttr->attribute_id,
                    'success',
                    sprintf('Created attribute group "%s" (FC ID: %d).', $wcAttr->attribute_label, $group->id),
                );
            }

            $this->migrateAttributeTerms($slug, $groupSlug, $this->attributeGroupMap[$slug], $storedTerms);
        }
    }

    /**
     * Migrate the terms of a single WC attribute taxonomy into an FC attribute group.
     *
     * @param string             $taxonomy    WC attribute taxonomy slug, e.g. "pa_color".
     * @param string             $groupSlug   Sanitised attribute name, the composite key prefix.
     * @param int                $groupId     FC attribute group ID.
     * @param array<string, int> $storedTerms WC term_id => FC term ID already in the ID map.
     */
    private function migrateAttributeTerms(
        string $taxonomy,
        string $groupSlug,
        int $groupId,
        array $storedTerms,
    ): void {
        $wcTerms = get_terms([
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ]);

        if (is_wp_error($wcTerms) || empty($wcTerms)) {
            return;
        }

        $serial = 1;

        foreach ($wcTerms as $wcTerm) {
            $termSlug = sanitize_title($wcTerm->slug);
            $compositeKey = $groupSlug . ':' . $termSlug;

            $storedTermId = $storedTerms[(string) $wcTerm->term_id] ?? null;

            if ($storedTermId !== null) {
                $this->attributeTermMap[$compositeKey] = (int) $storedTermId;
                continue;
            }

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
                    $this->migrationId(),
                    false,
                );
                continue;
            }

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
                $this->migrationId(),
                true,
            );
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

        $stored = $this->idMap->getMapForEntityType(Constants::ENTITY_SHIPPING_CLASS);

        foreach ($wcShippingClasses as $wcTerm) {
            if (isset($stored[(string) $wcTerm->term_id])) {
                $this->shippingClassMap[$wcTerm->term_id] = (int) $stored[(string) $wcTerm->term_id];
                continue;
            }

            $existing = ShippingClass::query()->where('name', $wcTerm->name)->first();

            if ($existing) {
                $this->shippingClassMap[$wcTerm->term_id] = (int) $existing->id;
                $this->idMap->store(
                    Constants::ENTITY_SHIPPING_CLASS,
                    (string) $wcTerm->term_id,
                    (int) $existing->id,
                    $this->migrationId(),
                    false,
                );
                $this->writeLog(
                    $wcTerm->term_id,
                    'skipped',
                    sprintf('Shipping class "%s" already exists in FluentCart (FC ID %d).', $wcTerm->name, $existing->id),
                    MigrationErrorCode::AlreadyExistsInFluentCart,
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
                $this->migrationId(),
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
     * @param array<int, \WC_Product_Variation> $wcVariations WC variation ID => already loaded object.
     */
    private function assignAttributes(array $wcVariations): void
    {
        if (empty($this->attributeGroupMap)) {
            return;
        }

        foreach ($wcVariations as $wcVarId => $wcVariation) {
            $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcVarId);
            if (!$fcVariationId) {
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
     *
     * Every SKU this run has seen — found in FluentCart or handed out here — is
     * remembered, so a repeated SKU costs no second query and collisions created
     * earlier in the same run are still caught.
     */
    private function ensureUniqueSku(string $sku, int $wcId): string
    {
        if (!$this->skuExists($sku)) {
            $this->knownSkus[$sku] = true;

            return $sku;
        }

        $newSku = $sku . '-wc' . $wcId;

        for ($attempt = 2; $attempt <= self::SKU_SUFFIX_LIMIT && $this->skuExists($newSku); $attempt++) {
            $newSku = $sku . '-wc' . $wcId . '-' . $attempt;
        }

        $this->knownSkus[$newSku] = true;

        $this->writeLog($wcId, 'skipped', sprintf(
            'SKU "%s" already exists in FluentCart. Using "%s" instead.',
            $sku,
            $newSku,
        ), MigrationErrorCode::SkuCollision);

        return $newSku;
    }

    /**
     * Is this SKU already taken, either in FluentCart or by this run?
     */
    private function skuExists(string $sku): bool
    {
        if (isset($this->knownSkus[$sku])) {
            return true;
        }

        $existing = ProductVariation::query()->where('sku', $sku)->first();

        if (!$existing) {
            return false;
        }

        $this->knownSkus[$sku] = true;

        return true;
    }
}
