<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class ProductRecord
{
    /**
     * @param list<VariationRecord> $variations
     * @param list<AttributeRecord> $attributes
     * @param list<TaxonomyAssignment> $taxonomies
     * @param list<AssetReference> $media
     * @param list<DownloadReference> $downloads
     * @param list<SourceIdentity> $upsellProducts
     * @param list<SourceIdentity> $crossSellProducts
     * @param array<string, scalar|null> $approvedMeta
     * @param array<string, string> $allowedLossLedger
     * @param array<string, int> $ratingDistribution
     * @param array<string, scalar|list<int>|null> $typeConfiguration
     */
    public function __construct(
        public SourceIdentity $identity,
        public string $productType,
        public string $status,
        public string $name,
        public string $slug,
        public string $description,
        public string $shortDescription,
        public string $sku,
        public string $createdUtc,
        public ?string $modifiedUtc,
        public int $menuOrder,
        public bool $featured,
        public string $catalogVisibility,
        public string $purchaseNote,
        public bool $reviewsAllowed,
        public int $reviewCount,
        public string $averageRating,
        public array $ratingDistribution,
        public int $totalSales,
        public string $globalUniqueId,
        public string $fulfilmentType,
        public bool $passwordProtected,
        public string $shippingClassSlug,
        public array $typeConfiguration,
        public TaxProfile $tax,
        public StockProfile $stock,
        public array $variations,
        public array $attributes,
        public array $taxonomies,
        public array $media,
        public array $downloads,
        public array $upsellProducts,
        public array $crossSellProducts,
        public array $approvedMeta,
        public int $fieldRegistryVersion,
        public array $allowedLossLedger,
    ) {
        foreach ([$variations, $attributes, $taxonomies, $media, $downloads, $upsellProducts, $crossSellProducts] as $list) {
            if (!array_is_list($list)) {
                throw new \InvalidArgumentException('Product record collections must be lists.');
            }
        }

        if ($name === '' || $createdUtc === '') {
            throw new \InvalidArgumentException('Product name and creation time are required.');
        }

        if (!in_array($fulfilmentType, ['physical', 'digital'], true)) {
            throw new \InvalidArgumentException('Product fulfilment type is invalid.');
        }

        if ($reviewCount < 0 || $totalSales < 0 || $fieldRegistryVersion <= 0) {
            throw new \InvalidArgumentException('Product counters and registry version cannot be negative.');
        }

        foreach ($approvedMeta as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new \InvalidArgumentException('Approved metadata must be a scalar map.');
            }
        }

        foreach ($ratingDistribution as $rating => $count) {
            if (!is_string($rating) || !is_int($count) || $count < 0) {
                throw new \InvalidArgumentException('Rating distribution must be a non-negative integer map.');
            }
        }

        foreach ($typeConfiguration as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null && !is_array($value))) {
                throw new \InvalidArgumentException('Product type configuration has an invalid value.');
            }

            if (is_array($value) && (!array_is_list($value) || array_filter($value, static fn (mixed $item): bool => !is_int($item)))) {
                throw new \InvalidArgumentException('Product type configuration lists must contain integers.');
            }
        }
    }

    public function envelope(int $schemaVersion = 1): RecordEnvelope
    {
        return RecordEnvelope::forPayload($schemaVersion, $this->identity, $this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(),
            'product_type' => $this->productType,
            'status' => $this->status,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->shortDescription,
            'sku' => $this->sku,
            'created_utc' => $this->createdUtc,
            'modified_utc' => $this->modifiedUtc,
            'menu_order' => $this->menuOrder,
            'featured' => $this->featured,
            'catalog_visibility' => $this->catalogVisibility,
            'purchase_note' => $this->purchaseNote,
            'reviews_allowed' => $this->reviewsAllowed,
            'review_count' => $this->reviewCount,
            'average_rating' => $this->averageRating,
            'rating_distribution' => $this->ratingDistribution,
            'total_sales' => $this->totalSales,
            'global_unique_id' => $this->globalUniqueId,
            'fulfilment_type' => $this->fulfilmentType,
            'password_protected' => $this->passwordProtected,
            'shipping_class_slug' => $this->shippingClassSlug,
            'type_configuration' => $this->typeConfiguration,
            'tax' => $this->tax->toArray(),
            'stock' => $this->stock->toArray(),
            'variations' => array_map(static fn (VariationRecord $record): array => $record->toArray(), $this->variations),
            'attributes' => array_map(static fn (AttributeRecord $record): array => $record->toArray(), $this->attributes),
            'taxonomies' => array_map(static fn (TaxonomyAssignment $record): array => $record->toArray(), $this->taxonomies),
            'media' => array_map(static fn (AssetReference $record): array => $record->toArray(), $this->media),
            'downloads' => array_map(static fn (DownloadReference $record): array => $record->toArray(), $this->downloads),
            'upsell_products' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $this->upsellProducts),
            'cross_sell_products' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $this->crossSellProducts),
            'approved_meta' => $this->approvedMeta,
            'field_registry_version' => $this->fieldRegistryVersion,
            'allowed_loss_ledger' => $this->allowedLossLedger,
        ];
    }
}
