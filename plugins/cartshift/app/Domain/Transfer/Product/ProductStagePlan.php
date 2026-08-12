<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Package\AssetManifestEntry;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class ProductStagePlan
{
    /**
     * @param list<array<string, int|string|null>> $taxonomyPlans
     * @param list<SimpleVariationPlan> $variations
     * @param list<array{reference: AssetReference, asset: AssetManifestEntry}> $media
     * @param list<array{reference: DownloadReference, asset: AssetManifestEntry}> $downloads
     * @param array<string, mixed> $productFields
     * @param array<string, mixed> $detailFields
     */
    private function __construct(
        public ProductRecord $record,
        public array $taxonomyPlans,
        public array $variations,
        public array $media,
        public array $downloads,
        public array $productFields,
        public array $detailFields,
        public string $sourceFingerprint,
        public string $planFingerprint,
        public bool $historicalPlaceholder = false,
    ) {
    }

    /**
     * @param list<array{taxonomy: string, slug: string, name: string, parent_source: ?string, target_id: int}> $targetTerms
     * @param array<string, AssetManifestEntry> $assetManifest keyed by canonical source identity or SHA-256
     * @param array<string,string> $skuOverrides keyed by canonical source variation identity
     */
    public static function build(
        ProductRecord $record,
        ProductAssessmentContext $context,
        array $targetTerms = [],
        array $assetManifest = [],
        array $skuOverrides = [],
    ): self {
        $variations = (new SimpleVariationPlanner())->plan($record, $context, $skuOverrides);
        if ($variations === []) {
            throw new SourceRecordException('variation_cardinality_mismatch', 'Every target product requires one explicit variation.');
        }

        $taxonomies = (new TaxonomyPlanner())->plan($record->taxonomies, $targetTerms);
        $media = [];
        foreach ([$record->media, ...array_map(static fn (VariationRecord $variation): array => $variation->media, $record->variations)] as $references) {
            foreach ($references as $reference) {
                $asset = self::asset($reference->identity, $reference->expectedSha256, $assetManifest);
                $media[] = ['reference' => $reference, 'asset' => $asset];
            }
        }
        $downloads = [];
        foreach ([$record->downloads, ...array_map(static fn (VariationRecord $variation): array => $variation->downloads, $record->variations)] as $references) {
            foreach ($references as $reference) {
                $asset = self::asset($reference->identity, $reference->contentSha256, $assetManifest);
                $downloads[] = ['reference' => $reference, 'asset' => $asset];
            }
        }

        $prices = array_map(static fn (SimpleVariationPlan $plan): int => (int) $plan->targetFields['item_price'], $variations);
        $created = self::mysqlTime($record->createdUtc);
        $modified = self::mysqlTime($record->modifiedUtc ?? $record->createdUtc);
        $productFields = [
            'post_author' => 0,
            'post_date' => $created,
            'post_date_gmt' => $created,
            'post_content' => $record->description,
            'post_title' => $record->name,
            'post_excerpt' => $record->shortDescription,
            'post_status' => 'draft',
            'comment_status' => $record->reviewsAllowed ? 'open' : 'closed',
            'ping_status' => 'closed',
            'post_password' => '',
            'post_name' => $record->slug,
            'post_modified' => $modified,
            'post_modified_gmt' => $modified,
            'post_parent' => 0,
            'menu_order' => $record->menuOrder,
            'post_type' => 'fluent-products',
            'post_mime_type' => '',
            'comment_count' => 0,
        ];
        $detailFields = [
            'fulfillment_type' => $record->fulfilmentType,
            'min_price' => min($prices),
            'max_price' => max($prices),
            'default_variation_id' => 0,
            'variation_type' => FluentCartSimpleVariationContract::VARIATION_TYPE,
            'stock_availability' => 'in-stock',
            'other_info' => [
                'group_pricing_by' => 'none',
                'source_identity' => $record->identity->canonical(),
                'catalog_visibility' => $record->catalogVisibility,
                'featured' => $record->featured,
                'purchase_note' => $record->purchaseNote,
                'approved_meta' => $record->approvedMeta,
                'allowed_loss_ledger' => $record->allowedLossLedger,
                'source_product_sku' => $record->sku,
                'global_unique_id_provenance' => $record->globalUniqueId,
                'review_provenance' => [
                    'reviews_allowed' => $record->reviewsAllowed,
                    'review_count' => $record->reviewCount,
                    'average_rating' => $record->averageRating,
                    'rating_distribution' => $record->ratingDistribution,
                ],
                'sales_provenance' => ['total_sales' => $record->totalSales],
                'relation_provenance' => [
                    'upsell_products' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $record->upsellProducts),
                    'cross_sell_products' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $record->crossSellProducts),
                ],
                'password_protection_excluded' => $record->passwordProtected,
                'type_configuration' => $record->typeConfiguration,
                'field_registry_version' => $record->fieldRegistryVersion,
            ],
            'default_media' => [],
            'manage_stock' => 0,
            'manage_downloadable' => $downloads === [] ? 0 : 1,
        ];
        $sourceFingerprint = $record->envelope()->privateContentDigest;
        $planData = [
            'source_fingerprint' => $sourceFingerprint,
            'product' => $productFields,
            'detail' => $detailFields,
            'taxonomies' => $taxonomies,
            'variations' => array_map(static fn (SimpleVariationPlan $plan): array => $plan->targetFields, $variations),
            'media' => array_map(static fn (array $plan): array => [
                'reference' => $plan['reference']->toArray(),
                'asset' => $plan['asset']->toArray(),
            ], $media),
            'downloads' => array_map(static fn (array $plan): array => [
                'reference' => $plan['reference']->toArray(),
                'asset' => $plan['asset']->toArray(),
            ], $downloads),
        ];

        return new self(
            $record,
            $taxonomies,
            $variations,
            $media,
            $downloads,
            $productFields,
            $detailFields,
            $sourceFingerprint,
            CanonicalJson::fingerprint($planData),
        );
    }

    /** @param array<string, scalar|null> $historicalLineShape */
    public function asHistoricalPlaceholder(array $historicalLineShape): self
    {
        $variations = [];
        foreach ($this->variations as $variation) {
            $fields = $variation->targetFields;
            $fields['item_status'] = 'draft';
            $fields['manage_stock'] = 1;
            $fields['stock_status'] = 'out-of-stock';
            $fields['total_stock'] = 0;
            $fields['available'] = 0;
            $fields['committed'] = 0;
            $fields['on_hold'] = 0;
            $fields['other_info']['historical_placeholder'] = true;
            $variations[] = new SimpleVariationPlan(
                $variation->sourceVariation,
                $variation->variationIdentifier,
                $variation->targetTitle,
                $fields['other_info'],
                $fields,
            );
        }
        $detail = $this->detailFields;
        $detail['other_info']['historical_placeholder'] = true;
        $detail['other_info']['historical_line_shape'] = $historicalLineShape;
        $product = $this->productFields;
        $product['post_status'] = 'draft';
        $planFingerprint = CanonicalJson::fingerprint([
            'base_plan' => $this->planFingerprint,
            'historical_line_shape' => $historicalLineShape,
        ]);
        return new self(
            $this->record,
            $this->taxonomyPlans,
            $variations,
            $this->media,
            $this->downloads,
            $product,
            $detail,
            $this->sourceFingerprint,
            $planFingerprint,
            true,
        );
    }

    /** @return list<SourceIdentity> */
    public function sourceIdentities(): array
    {
        $identities = [$this->record->identity];
        foreach ($this->taxonomyPlans as $taxonomy) {
            if ($taxonomy['action'] !== 'provenance') {
                $identities[] = self::identityFromCanonical((string) $taxonomy['source_identity']);
            }
        }
        foreach ($this->variations as $variation) {
            $identities[] = $variation->sourceVariation;
        }
        foreach ($this->media as $media) {
            $identities[] = $media['reference']->identity;
        }
        foreach ($this->downloads as $download) {
            $identities[] = $download['reference']->identity;
        }

        $unique = [];
        foreach ($identities as $identity) {
            $unique[$identity->canonical()] = $identity;
        }
        ksort($unique);
        return array_values($unique);
    }

    /** @param array<string, AssetManifestEntry> $manifest */
    private static function asset(SourceIdentity $identity, ?string $expectedHash, array $manifest): AssetManifestEntry
    {
        if ($expectedHash === null) {
            throw new SourceRecordException('asset_hash_mismatch', 'Product asset has no content hash.');
        }
        $asset = $manifest[$identity->canonical()] ?? $manifest[$expectedHash] ?? null;
        if (!$asset instanceof AssetManifestEntry || !hash_equals($expectedHash, $asset->sha256)) {
            throw new SourceRecordException('asset_missing', 'Product asset is absent from the approved package manifest.');
        }
        return $asset;
    }

    private static function mysqlTime(string $utc): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $utc, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Product timestamp is not canonical UTC.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private static function identityFromCanonical(string $canonical): SourceIdentity
    {
        $parts = explode(':', $canonical, 3);
        if (count($parts) !== 3) {
            throw new \LogicException('Stage plan contains a malformed canonical source identity.');
        }
        return new SourceIdentity($parts[0], $parts[1], $parts[2]);
    }
}
