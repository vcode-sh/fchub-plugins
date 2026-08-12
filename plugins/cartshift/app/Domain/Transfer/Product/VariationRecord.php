<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class VariationRecord
{
    /**
     * @param list<array{attribute_key: string, value: string, kind: string, wildcard: bool}> $attributeAssignments
     * @param array{weight: ?string, length: ?string, width: ?string, height: ?string, weight_unit: string, dimension_unit: string} $dimensions
     * @param list<AssetReference> $media
     * @param list<DownloadReference> $downloads
     * @param array<string, scalar|null> $typeConfiguration
     */
    public function __construct(
        public SourceIdentity $identity,
        public SourceIdentity $parentIdentity,
        public string $status,
        public ?string $createdUtc,
        public ?string $modifiedUtc,
        public int $menuOrder,
        public string $sku,
        public string $globalUniqueId,
        public array $attributeAssignments,
        public PriceRecord $price,
        public TaxProfile $tax,
        public StockProfile $stock,
        public ?int $cost,
        public array $dimensions,
        public string $fulfilmentType,
        public string $description,
        public array $media,
        public array $downloads,
        public array $typeConfiguration = [],
        public string $shippingClassSlug = 'none',
        public ?int $definedCost = null,
        public bool $costIsAdditive = false,
    ) {
        if (!array_is_list($attributeAssignments) || !array_is_list($media) || !array_is_list($downloads)) {
            throw new \InvalidArgumentException('Variation collections must be lists.');
        }

        foreach ($attributeAssignments as $assignment) {
            if (
                !isset($assignment['attribute_key'], $assignment['value'], $assignment['kind'], $assignment['wildcard'])
                || !in_array($assignment['kind'], ['taxonomy', 'custom'], true)
                || !is_bool($assignment['wildcard'])
            ) {
                throw new \InvalidArgumentException('Variation attribute assignment is invalid.');
            }
        }

        if (!in_array($fulfilmentType, ['physical', 'digital'], true)) {
            throw new \InvalidArgumentException('Variation fulfilment type is invalid.');
        }

        foreach ($typeConfiguration as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && $value !== null)) {
                throw new \InvalidArgumentException('Variation type configuration must be a scalar map.');
            }
        }


        if ($shippingClassSlug === '') {
            throw new \InvalidArgumentException('Variation shipping class slug cannot be blank.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(),
            'parent_identity' => $this->parentIdentity->canonical(),
            'status' => $this->status,
            'created_utc' => $this->createdUtc,
            'modified_utc' => $this->modifiedUtc,
            'menu_order' => $this->menuOrder,
            'sku' => $this->sku,
            'global_unique_id' => $this->globalUniqueId,
            'attribute_assignments' => $this->attributeAssignments,
            'price' => $this->price->toArray(),
            'tax' => $this->tax->toArray(),
            'stock' => $this->stock->toArray(),
            'cost' => $this->cost,
            'dimensions' => $this->dimensions,
            'fulfilment_type' => $this->fulfilmentType,
            'description' => $this->description,
            'media' => array_map(static fn (AssetReference $asset): array => $asset->toArray(), $this->media),
            'downloads' => array_map(static fn (DownloadReference $download): array => $download->toArray(), $this->downloads),
            'type_configuration' => $this->typeConfiguration,
            'shipping_class_slug' => $this->shippingClassSlug,
            'defined_cost' => $this->definedCost,
            'cost_is_additive' => $this->costIsAdditive,
        ];
    }
}
