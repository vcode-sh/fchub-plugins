<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Product\AttributeRecord;
use CartShift\Domain\Transfer\Product\PriceRecord;
use CartShift\Domain\Transfer\Product\ProductFieldRegistry;
use CartShift\Domain\Transfer\Product\ProductRecord;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\StockProfile;
use CartShift\Domain\Transfer\Product\TaxProfile;
use CartShift\Domain\Transfer\Product\VariationRecord;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final class ProductAssessmentFixture
{
    /** @param array<string, mixed> $overrides */
    public static function product(array $overrides = []): ProductRecord
    {
        $identity = $overrides['identity'] ?? self::identity('42');
        $type = $overrides['productType'] ?? 'simple';
        $variations = $overrides['variations'] ?? [self::variation($identity, [
            'identity' => self::identity($identity->sourceId . ':variation:' . $identity->sourceId),
        ])];

        return new ProductRecord(
            $identity,
            $type,
            $overrides['status'] ?? 'publish',
            'Training plan',
            'training-plan',
            '',
            '',
            $overrides['sku'] ?? '',
            '2026-01-01T00:00:00Z',
            null,
            $overrides['menuOrder'] ?? 0,
            $overrides['featured'] ?? false,
            $overrides['catalogVisibility'] ?? 'visible',
            $overrides['purchaseNote'] ?? '',
            $overrides['reviewsAllowed'] ?? false,
            $overrides['reviewCount'] ?? 0,
            $overrides['averageRating'] ?? '0',
            $overrides['ratingDistribution'] ?? [],
            $overrides['totalSales'] ?? 0,
            $overrides['globalUniqueId'] ?? '',
            $overrides['fulfilmentType'] ?? 'physical',
            $overrides['passwordProtected'] ?? false,
            $overrides['shippingClassSlug'] ?? 'none',
            $overrides['typeConfiguration'] ?? [],
            $overrides['tax'] ?? new TaxProfile('taxable', 'standard', false),
            $overrides['stock'] ?? self::stock($identity),
            $variations,
            $overrides['attributes'] ?? [],
            $overrides['taxonomies'] ?? [],
            $overrides['media'] ?? [],
            $overrides['downloads'] ?? [],
            $overrides['upsellProducts'] ?? [],
            $overrides['crossSellProducts'] ?? [],
            $overrides['approvedMeta'] ?? [],
            ProductFieldRegistry::VERSION,
            (new ProductFieldRegistry())->allowedLossLedger(),
        );
    }

    /** @param array<string, mixed> $overrides */
    public static function variation(SourceIdentity $parent, array $overrides = []): VariationRecord
    {
        $identity = $overrides['identity'] ?? $parent;

        return new VariationRecord(
            $identity,
            $parent,
            'publish',
            null,
            null,
            0,
            $overrides['sku'] ?? '',
            '',
            $overrides['attributeAssignments'] ?? [],
            $overrides['price'] ?? new PriceRecord(1000, 1000, null, null, null, 'USD'),
            $overrides['tax'] ?? new TaxProfile('taxable', 'standard', false),
            $overrides['stock'] ?? self::stock($identity),
            null,
            ['weight' => null, 'length' => null, 'width' => null, 'height' => null, 'weight_unit' => 'kg', 'dimension_unit' => 'cm'],
            $overrides['fulfilmentType'] ?? 'physical',
            '',
            $overrides['media'] ?? [],
            $overrides['downloads'] ?? [],
            $overrides['typeConfiguration'] ?? [],
            $overrides['shippingClassSlug'] ?? 'none',
            $overrides['definedCost'] ?? null,
            $overrides['costIsAdditive'] ?? false,
        );
    }

    public static function identity(string $id): SourceIdentity
    {
        return new SourceIdentity('lapka-web', RecordKind::Product->value, $id);
    }

    public static function stock(SourceIdentity $owner, string $backorders = 'no'): StockProfile
    {
        return new StockProfile(StockOwnership::Self, $owner, 7, 'instock', $backorders, false, null);
    }

    public static function customAttribute(): AttributeRecord
    {
        return new AttributeRecord('colour', 'Colour', 'custom', true, true, 0, null, ['Red', 'Blue']);
    }
}
