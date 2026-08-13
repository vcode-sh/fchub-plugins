<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\ProductFieldRegistry;
use CartShift\Domain\Transfer\Product\ProductRecordFactory;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\WooProductRecordSource;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class ProductRecordFactoryTest extends PluginTestCase
{
    private ProductRecordFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new ProductRecordFactory();
        $GLOBALS['_cartshift_test_wc_currency'] = 'USD';
    }

    public function testExplicitZeroPriceIsNotReplacedByRegularPrice(): void
    {
        $record = $this->factory->fromWooProduct($this->product([
            'get_price' => '0',
            'get_regular_price' => '29.00',
            'get_sale_price' => '',
        ]), 'lapka-web');

        self::assertSame(0, $record->variations[0]->price->activePrice);
        self::assertSame(2900, $record->variations[0]->price->regularPrice);
        self::assertNull($record->variations[0]->price->salePrice);
    }

    public function testInstalledExternalProductUrlFieldIsRecognisedAndProjected(): void
    {
        $record = $this->factory->fromWooProduct($this->product([
            'get_type' => 'external',
            'get_product_url' => 'https://example.test/training',
            'get_data' => ['id' => 42, 'product_url' => 'https://example.test/training'],
        ]), 'lapka-web');

        self::assertSame('https://example.test/training', $record->typeConfiguration['external_url']);
    }

    public function testGroupedChildrenRemainProductConfigurationAndNeverLeakIntoSyntheticVariation(): void
    {
        $record = $this->factory->fromWooProduct($this->product([
            'get_type' => 'grouped',
            'get_children' => [101, 102],
        ]), 'lapka-web');

        self::assertSame([101, 102], $record->typeConfiguration['grouped_children']);
        self::assertSame([], $record->variations[0]->typeConfiguration);
    }

    public function testUnsavedDraftSlugRemainsBlankInsteadOfBlockingTheSourceRecord(): void
    {
        $record = $this->factory->fromWooProduct($this->product([
            'get_status' => 'draft',
            'get_slug' => '',
        ]), 'lapka-web');

        self::assertSame('', $record->slug);
        self::assertSame('', $record->toArray()['slug']);
    }

    public function testRecordPreservesCustomWildcardAndGlobalAttributes(): void
    {
        $parent = $this->product([
            'get_type' => 'variable',
            'get_children' => [101],
            'get_attributes' => [
                ['name' => 'Colour label', 'taxonomy' => false, 'options' => ['Red', 'Blue'], 'variation' => true, 'visible' => true, 'position' => 0],
                ['name' => 'pa_size', 'taxonomy' => true, 'options' => ['small', 'large'], 'variation' => true, 'visible' => true, 'position' => 1],
                ['name' => 'Wildcard field', 'taxonomy' => false, 'options' => ['one', 'two'], 'variation' => true, 'visible' => false, 'position' => 2],
            ],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
            'get_id' => 101,
            'get_parent_id' => 42,
            'get_attributes' => ['colour-label' => 'Red', 'pa_size' => 'large', 'wildcard-field' => ''],
        ]);

        $record = $this->factory->fromWooProduct($parent, 'lapka-web');
        $kinds = array_map(
            static fn ($attribute): string => $attribute->kind === 'taxonomy' ? 'global' : 'custom',
            $record->attributes,
        );
        $kinds[] = $record->variations[0]->attributeAssignments[2]['wildcard'] ? 'wildcard' : 'not-wildcard';

        self::assertSame(['custom', 'global', 'custom', 'wildcard'], $kinds);
        self::assertSame('', $record->variations[0]->attributeAssignments[2]['value']);
    }

    public function testCustomAttributeWithTaxonomyPrefixUsesItsDeclaredValue(): void
    {
        $parent = $this->product([
            'get_type' => 'variable',
            'get_children' => [101],
            'get_attributes' => [[
                'name' => 'pa_color',
                'taxonomy' => false,
                'options' => ['Czarny', 'Bialy'],
                'variation' => true,
            ]],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
            'get_id' => 101,
            'get_parent_id' => 42,
            'get_attributes' => ['pa_color' => 'czarny'],
        ]);

        $record = $this->factory->fromWooProduct($parent, 'lapka-web');

        self::assertSame([
            'attribute_key' => 'pa_color',
            'value' => 'Czarny',
            'kind' => 'custom',
            'wildcard' => false,
        ], $record->variations[0]->attributeAssignments[0]);
    }

    public function testTaxonomyLabelsAndUnassignedAncestorsRemainDistinct(): void
    {
        $GLOBALS['_cartshift_test_terms_by_id'] = [
            'product_cat' => [
                10 => (object) ['name' => 'Training', 'slug' => 'training', 'description' => 'Parent', 'parent' => 0],
                11 => (object) ['name' => 'Puppies', 'slug' => 'puppies', 'description' => 'Child', 'parent' => 10],
            ],
            'pa_size' => [
                20 => (object) ['name' => 'Extra Large', 'slug' => 'xl', 'description' => '', 'parent' => 0],
            ],
        ];
        $product = $this->product([
            'get_category_ids' => [11],
            'get_attributes' => [[
                'name' => 'pa_size', 'taxonomy' => true, 'options' => [20], 'variation' => true,
            ]],
        ]);
        $GLOBALS['_cartshift_test_wc_product_terms_return']['product_cat'] = [11];

        $record = $this->factory->fromWooProduct($product, 'lapka-web');

        self::assertSame(['training', 'puppies'], array_column($record->taxonomies, 'slug'));
        self::assertSame([false, true], array_column($record->taxonomies, 'assigned'));
        self::assertSame('Extra Large', $record->attributes[0]->valueLabels['xl']);
    }

    public function testTaxonomyRelationshipOrderComesFromTheSourceRelationRatherThanTermIdentityOrder(): void
    {
        $GLOBALS['_cartshift_test_terms_by_id'] = [
            'product_cat' => [
                10 => (object) ['name' => 'First by ID', 'slug' => 'first-by-id', 'description' => '', 'parent' => 0],
                11 => (object) ['name' => 'First by relation', 'slug' => 'first-by-relation', 'description' => '', 'parent' => 0],
            ],
        ];
        $GLOBALS['_cartshift_test_wc_product_terms_return']['product_cat'] = [11, 10];

        $record = $this->factory->fromWooProduct($this->product([
            'get_category_ids' => [10, 11],
        ]), 'lapka-web');

        self::assertSame(
            ['first-by-id' => 1, 'first-by-relation' => 0],
            array_column($record->taxonomies, 'order', 'slug'),
        );
        self::assertContains([42, 'product_cat', [
            'fields' => 'ids',
            'orderby' => 'term_order',
            'order' => 'ASC',
        ]], $GLOBALS['_cartshift_test_wc_product_term_calls']);
    }

    public function testLoadedTaxonomyReaderPreservesEqualRelationshipOrdersWithoutInventingRanks(): void
    {
        $GLOBALS['_cartshift_test_terms_by_id'] = [
            'product_cat' => [
                10 => (object) ['name' => 'First', 'slug' => 'first', 'description' => '', 'parent' => 0],
                11 => (object) ['name' => 'Second', 'slug' => 'second', 'description' => '', 'parent' => 0],
            ],
        ];
        $factory = new ProductRecordFactory(
            taxonomyOrders: static fn (int $productId, string $taxonomy, array $ids): array => [
                10 => 0,
                11 => 0,
            ],
        );

        $record = $factory->fromWooProduct($this->product([
            'get_category_ids' => [10, 11],
        ]), 'lapka-web');

        self::assertSame(['first' => 0, 'second' => 0], array_column($record->taxonomies, 'order', 'slug'));
    }

    public function testSelectedTaxonomyMissingFromTheOrderedPublicApiResultBlocksTheExport(): void
    {
        $GLOBALS['_cartshift_test_terms_by_id'] = [
            'product_cat' => [
                10 => (object) ['name' => 'Orphaned getter result', 'slug' => 'orphaned', 'description' => '', 'parent' => 0],
            ],
        ];
        $GLOBALS['_cartshift_test_wc_product_terms_return']['product_cat'] = [];

        $this->expectException(SourceRecordException::class);
        $this->expectExceptionMessage('ordered taxonomy relationship set differs');

        $this->factory->fromWooProduct($this->product(['get_category_ids' => [10]]), 'lapka-web');
    }

    public function testDuplicateTaxonomyInTheOrderedPublicApiResultBlocksTheExport(): void
    {
        $GLOBALS['_cartshift_test_terms_by_id'] = [
            'product_cat' => [
                10 => (object) ['name' => 'Broken order', 'slug' => 'broken-order', 'description' => '', 'parent' => 0],
            ],
        ];
        $GLOBALS['_cartshift_test_wc_product_terms_return']['product_cat'] = [10, 10];

        $this->expectException(SourceRecordException::class);
        $this->expectExceptionMessage('occurs more than once');

        $this->factory->fromWooProduct($this->product(['get_category_ids' => [10]]), 'lapka-web');
    }

    public function testVariationPreservesParentStockOwnershipWithoutCreatingAChildPool(): void
    {
        $parent = $this->product([
            'get_type' => 'variable',
            'get_children' => [101],
            'get_manage_stock' => true,
            'get_stock_quantity' => 98,
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
            'get_id' => 101,
            'get_parent_id' => 42,
            'get_manage_stock' => 'parent',
            'get_stock_quantity' => 3,
        ]);

        $record = $this->factory->fromWooProduct($parent, 'lapka-web');

        self::assertSame(StockOwnership::Parent, $record->variations[0]->stock->ownership);
        self::assertSame($record->identity, $record->variations[0]->stock->owner);
        self::assertSame(98, $record->variations[0]->stock->quantity);
        self::assertNotSame(3, $record->variations[0]->stock->quantity);
    }

    public function testLoadedVariationReaderAvoidsWooChildrenTransientAndUsesItsExactIds(): void
    {
        $parent = $this->product([
            'get_type' => 'variable',
            'get_children' => static fn (): never => throw new \RuntimeException('Woo transient-backed reader was called.'),
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
            'get_id' => 101,
            'get_parent_id' => 42,
        ]);
        $factory = new ProductRecordFactory(
            new ProductFieldRegistry(),
            static fn (object $product): array => [(int) $product->get_id() + 59],
        );

        $record = $factory->fromWooProduct($parent, 'lapka-web');

        self::assertSame('42:variation:101', $record->variations[0]->identity->sourceId);
    }

    public function testVariationOwnDownloadPolicyIsNotSubstitutedFromParent(): void
    {
        $parent = $this->product([
            'get_type' => 'variable',
            'get_children' => [101],
            'get_download_limit' => 99,
            'get_download_expiry' => 365,
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
            'get_id' => 101,
            'get_parent_id' => 42,
            'get_downloads' => [['id' => 'manual', 'name' => 'Manual', 'file' => 'https://example.com/manual.pdf']],
            'get_download_limit' => 3,
            'get_download_expiry' => 14,
        ]);

        $download = $this->factory->fromWooProduct($parent, 'lapka-web')->variations[0]->downloads[0];

        self::assertSame(3, $download->limit);
        self::assertSame(14, $download->expiryDays);
    }

    public function testVariationDownloadsWithTheSameWooIdRemainDistinctSourceRecords(): void
    {
        $parent = $this->product([
            'get_type' => 'variable',
            'get_children' => [101, 102],
        ]);
        foreach ([101 => 'small', 102 => 'large'] as $variationId => $name) {
            $GLOBALS['_cartshift_test_wc_products'][$variationId] = $this->product([
                'get_id' => $variationId,
                'get_parent_id' => 42,
                'get_downloads' => [[
                    'id' => 'manual',
                    'name' => ucfirst($name) . ' manual',
                    'file' => 'https://example.com/' . $name . '.pdf',
                ]],
            ]);
        }

        $record = $this->factory->fromWooProduct($parent, 'lapka-web');
        $identities = array_map(
            static fn ($variation): string => $variation->downloads[0]->identity->sourceId,
            $record->variations,
        );

        self::assertSame([
            '42:variation:101:download:manual',
            '42:variation:102:download:manual',
        ], $identities);
        self::assertCount(2, array_unique($identities));
    }

    public function testInheritedVariationImageRemainsOwnedByTheVariationItMustRenderOn(): void
    {
        $parent = $this->product([
            'get_type' => 'variable',
            'get_children' => [101],
            'get_image_id' => 501,
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
            'get_id' => 101,
            'get_parent_id' => 42,
            // WC_Product_Variation::get_image_id() resolves the parent image,
            // while its own data retains zero. That distinction is the contract.
            'get_image_id' => 501,
            'get_data' => ['id' => 101, 'parent_id' => 42, 'image_id' => 0],
        ]);

        $record = $this->factory->fromWooProduct($parent, 'lapka-web');
        $media = $record->variations[0]->media[0];

        self::assertSame('inherited', $media->provenance);
        self::assertSame('42:variation:101', $media->owner->sourceId);
        self::assertSame('variation', $media->role);
    }

    public function testVariableSubscriptionPreservesEachVariationCadenceInsteadOfTheParentDefault(): void
    {
        $parent = $this->product([
            'get_type' => 'variable-subscription',
            'get_children' => [101, 102],
            'get_data' => [
                'subscription_period' => 'month',
                'subscription_period_interval' => 1,
            ],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
            'get_id' => 101,
            'get_parent_id' => 42,
            'get_type' => 'subscription_variation',
            'get_data' => [
                'id' => 101,
                'parent_id' => 42,
                'subscription_period' => 'month',
                'subscription_period_interval' => 1,
            ],
        ]);
        $GLOBALS['_cartshift_test_wc_products'][102] = $this->product([
            'get_id' => 102,
            'get_parent_id' => 42,
            'get_type' => 'subscription_variation',
            'get_data' => [
                'id' => 102,
                'parent_id' => 42,
                'subscription_period' => 'year',
                'subscription_period_interval' => 1,
            ],
        ]);

        $record = $this->factory->fromWooProduct($parent, 'lapka-web');

        self::assertSame('month', $record->variations[0]->typeConfiguration['subscription_period']);
        self::assertSame('year', $record->variations[1]->typeConfiguration['subscription_period']);
        self::assertNotSame($record->variations[0]->typeConfiguration, $record->variations[1]->typeConfiguration);
    }

    public function testSimpleSubscriptionReadsTermsMissingFromWooGetDataFromAuthoritativeMeta(): void
    {
        $configuration = [
            'subscription_length' => '5',
            'subscription_period' => 'month',
            'subscription_period_interval' => '1',
            'subscription_price' => '920',
            'subscription_sign_up_fee' => '0',
            'subscription_trial_length' => '0',
            'subscription_trial_period' => 'day',
        ];
        $meta = [];

        foreach ($configuration as $field => $value) {
            $meta['_' . $field] = $value;
        }

        $record = $this->factory->fromWooProduct($this->product([
            'get_type' => 'subscription',
            'get_data' => ['id' => 42, 'name' => 'Real WCS shape without subscription keys'],
            'meta' => $meta,
        ]), 'lapka-web');

        self::assertSame($configuration, $record->typeConfiguration);
        self::assertSame($configuration, $record->variations[0]->typeConfiguration);
    }

    public function testSubscriptionMetaOnAnOrdinaryProductIsNotMisclassifiedAsSubscriptionConfiguration(): void
    {
        $record = $this->factory->fromWooProduct($this->product([
            'get_type' => 'simple',
            'meta' => [
                '_subscription_period' => 'month',
                '_subscription_period_interval' => '1',
            ],
        ]), 'lapka-web');

        self::assertSame([], $record->typeConfiguration);
        self::assertSame([], $record->variations[0]->typeConfiguration);
    }

    public function testEveryPinnedWooFieldHasExactlyOneDisposition(): void
    {
        $expected = [
            'attribute_summary', 'attributes', 'average_rating', 'backorders', 'brand_ids', 'button_text', 'catalog_visibility', 'category_ids', 'children',
            'cogs_value', 'cogs_value_is_additive', 'cross_sell_ids', 'date_created', 'date_modified', 'date_on_sale_from',
            'date_on_sale_to', 'default_attributes', 'description', 'download_expiry', 'download_limit',
            'downloadable', 'downloads', 'featured', 'gallery_image_ids', 'global_unique_id',
            'height', 'id', 'image_id', 'length', 'low_stock_amount', 'manage_stock', 'menu_order', 'meta_data', 'name',
            'parent_id', 'post_password', 'price', 'product_url', 'purchase_note', 'rating_counts', 'regular_price',
            'review_count', 'reviews_allowed', 'sale_price', 'shipping_class_id', 'short_description', 'sku',
            'slug', 'sold_individually', 'status', 'stock_quantity', 'stock_status', 'subscription_length',
            'subscription_period', 'subscription_period_interval', 'subscription_price', 'subscription_sign_up_fee',
            'subscription_trial_length', 'subscription_trial_period', 'tag_ids', 'tax_class', 'tax_status',
            'total_sales', 'upsell_ids', 'virtual', 'weight', 'width',
        ];
        sort($expected);

        self::assertSame($expected, (new ProductFieldRegistry())->recognizedKeysWithoutDuplicates());
    }

    public function testUnknownInstalledWooFieldBlocksRatherThanDisappears(): void
    {
        $product = $this->product(['get_data' => ['id' => 42, 'new_plugin_contract' => 'value']]);
        $this->expectException(SourceRecordException::class);
        $this->expectExceptionMessage('unregistered product field');

        $this->factory->fromWooProduct($product, 'lapka-web');
    }

    public function testDuplicateVariationAndWrongParentBothBlock(): void
    {
        foreach ([[101, 101], [101]] as $children) {
            $parent = $this->product(['get_type' => 'variable', 'get_children' => $children]);
            $GLOBALS['_cartshift_test_wc_products'][101] = $this->product([
                'get_id' => 101,
                'get_parent_id' => $children === [101] ? 999 : 42,
            ]);

            try {
                $this->factory->fromWooProduct($parent, 'lapka-web');
                self::fail('Lossy variation identity was accepted.');
            } catch (SourceRecordException $exception) {
                self::assertContains($exception->reasonCode, ['product_variation_duplicate', 'product_variation_parent_mismatch']);
            }
        }
    }

    public function testNonScalarApprovedMetadataBlocksAndUnapprovedMetadataIsAbsent(): void
    {
        $product = $this->product(['meta' => ['private_array' => ['secret'], 'public_flag' => 'yes']]);
        $record = $this->factory->fromWooProduct($product, 'lapka-web');
        self::assertSame([], $record->approvedMeta);

        add_filter('cartshift/transfer/approved_product_meta_keys', static fn (): array => ['private_array']);
        $this->expectException(SourceRecordException::class);
        $this->factory->fromWooProduct($product, 'lapka-web');
    }

    public function testSourceYieldsDeterministicEnvelopesAndRejectsDuplicateIdentity(): void
    {
        $source = new WooProductRecordSource($this->factory, fn (): array => [
            $this->product(['get_id' => 43, 'get_slug' => 'second']),
            $this->product(['get_id' => 42, 'get_slug' => 'first']),
        ]);
        $selection = new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        );
        $records = iterator_to_array($source->records($selection), false);

        self::assertSame(['42', '43'], array_map(static fn ($record): string => $record->identity->sourceId, $records));
        self::assertNotSame($records[0]->privateContentDigest, $records[1]->privateContentDigest);

        $duplicates = new WooProductRecordSource($this->factory, fn (): array => [
            $this->product(),
            $this->product(),
        ]);

        try {
            iterator_to_array($duplicates->records($selection));
            self::fail('Duplicate source product was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('product_source_identity_duplicate', $exception->reasonCode);
        }
    }

    public function testUnrepresentablePriceAndMissingDownloadLocatorBlock(): void
    {
        foreach ([
            $this->product(['get_price' => '12.345']),
            $this->product(['get_downloads' => [['id' => 'broken', 'name' => 'Broken', 'file' => '']]]),
        ] as $product) {
            try {
                $this->factory->fromWooProduct($product, 'lapka-web');
                self::fail('Lossy product record was constructed.');
            } catch (SourceRecordException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function product(array $overrides = []): TestWooProduct
    {
        return new TestWooProduct($overrides + [
            'get_id' => 42,
            'get_type' => 'simple',
            'get_status' => 'publish',
            'get_name' => 'Training plan',
            'get_slug' => 'training-plan',
            'get_date_created' => new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            'get_data' => [],
        ]);
    }
}

final class TestWooProduct
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function get_id(): int
    {
        return (int) ($this->values['get_id'] ?? 0);
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($name === 'get_meta') {
            return ($this->values['meta'] ?? [])[$arguments[0] ?? ''] ?? '';
        }

        $defaults = [
            'get_attributes' => [], 'get_default_attributes' => [], 'get_children' => [], 'get_downloads' => [],
            'get_gallery_image_ids' => [], 'get_category_ids' => [], 'get_tag_ids' => [], 'get_upsell_ids' => [],
            'get_cross_sell_ids' => [], 'get_price' => '', 'get_regular_price' => '', 'get_sale_price' => '',
            'get_tax_status' => 'taxable', 'get_tax_class' => '', 'get_manage_stock' => false,
            'get_stock_quantity' => null, 'get_stock_status' => 'instock', 'get_backorders' => 'no',
            'is_sold_individually' => false, 'get_low_stock_amount' => null, 'is_virtual' => false,
            'is_downloadable' => false, 'get_image_id' => 0, 'get_download_limit' => -1,
            'get_download_expiry' => -1, 'get_date_modified' => null, 'get_date_on_sale_from' => null,
            'get_date_on_sale_to' => null, 'get_menu_order' => 0, 'is_featured' => false,
            'get_catalog_visibility' => 'visible', 'get_purchase_note' => '', 'get_reviews_allowed' => false,
            'get_review_count' => 0, 'get_average_rating' => '0', 'get_total_sales' => 0,
            'get_rating_counts' => [],
            'get_global_unique_id' => '', 'get_description' => '', 'get_short_description' => '', 'get_sku' => '',
            'get_weight' => '', 'get_length' => '', 'get_width' => '', 'get_height' => '', 'get_cogs_value' => null,
            'get_parent_id' => 0,
        ];

        $value = $this->values[$name] ?? $defaults[$name] ?? null;

        return $value instanceof \Closure ? $value(...$arguments) : $value;
    }
}
