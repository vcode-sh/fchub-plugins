<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class HistoricalProductPlaceholder
{
    public const FULFILMENT_TYPE = 'digital';

    /** @param array{name: string, sku: string, unit_total: int, currency: string, source_created_utc?: string} $lineShape */
    public function plan(
        SourceIdentity $productIdentity,
        array $lineShape,
        ProductAssessmentContext $context,
        string $approvalFingerprint,
    ): ProductStagePlan {
        return ProductStagePlan::build(
            $this->record($productIdentity, $lineShape, $approvalFingerprint),
            $context,
        )->asHistoricalPlaceholder($lineShape);
    }

    /** @param array{name: string, sku: string, unit_total: int, currency: string, source_created_utc?: string} $lineShape */
    public function record(
        SourceIdentity $productIdentity,
        array $lineShape,
        string $approvalFingerprint,
    ): ProductRecord {
        $expected = self::approvalFingerprint($productIdentity, $lineShape);
        if (!hash_equals($expected, $approvalFingerprint)) {
            throw new SourceRecordException('historical_product_missing', 'Historical placeholder lacks exact owner approval.');
        }
        if (($lineShape['name'] ?? '') === ''
            || !isset($lineShape['sku'], $lineShape['unit_total'], $lineShape['currency'])
            || !is_string($lineShape['sku'])
            || !is_int($lineShape['unit_total'])
            || $lineShape['unit_total'] < 0
            || preg_match('/\A[A-Z]{3}\z/D', (string) $lineShape['currency']) !== 1) {
            throw new SourceRecordException('historical_product_missing', 'Historical product line shape is incomplete.');
        }

        $variationIdentity = new SourceIdentity(
            $productIdentity->sourceKey,
            $productIdentity->entityType,
            $productIdentity->sourceId . ':variation:' . $productIdentity->sourceId,
        );
        $price = new PriceRecord(
            $lineShape['unit_total'],
            $lineShape['unit_total'],
            null,
            null,
            null,
            $lineShape['currency'],
        );
        $stock = new StockProfile(StockOwnership::Self, $variationIdentity, 0, 'outofstock', 'no', true, null);
        $tax = new TaxProfile('none', 'none', false);
        $variation = new VariationRecord(
            $variationIdentity,
            $productIdentity,
            'draft',
            null,
            null,
            0,
            '',
            'Historical order-line placeholder',
            [],
            $price,
            $tax,
            $stock,
            null,
            ['weight' => null, 'length' => null, 'width' => null, 'height' => null, 'weight_unit' => 'kg', 'dimension_unit' => 'cm'],
            self::FULFILMENT_TYPE,
            '',
            [],
            [],
            [],
            'none',
            null,
            false,
        );
        return new ProductRecord(
            $productIdentity,
            'simple',
            'draft',
            '[Historical] ' . $lineShape['name'],
            'cartshift-historical-' . str_replace(':', '-', $productIdentity->sourceId),
            'Inert placeholder retained only for migrated historical order lines.',
            '',
            '',
            $lineShape['source_created_utc'] ?? '1970-01-01T00:00:00Z',
            null,
            0,
            false,
            'hidden',
            '',
            false,
            0,
            '0',
            [],
            0,
            '',
            self::FULFILMENT_TYPE,
            false,
            'none',
            [],
            $tax,
            $stock,
            [$variation],
            [],
            [],
            [],
            [],
            [],
            [],
            ['historical_line_shape_fingerprint' => $expected],
            ProductFieldRegistry::VERSION,
            (new ProductFieldRegistry())->allowedLossLedger(),
        );

    }

    /** @param array<string, mixed> $lineShape */
    public static function approvalFingerprint(SourceIdentity $identity, array $lineShape): string
    {
        return CanonicalJson::fingerprint([
            'source_identity' => $identity->canonical(),
            'historical_line_shape' => $lineShape,
        ]);
    }
}
