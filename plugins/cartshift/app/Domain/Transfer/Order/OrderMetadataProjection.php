<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final readonly class OrderMetadataProjection
{
    /**
     * @param array<string, mixed> $config
     * @param list<array{meta_key: string, meta_value: array<string, scalar>}> $metaRows
     * @param list<array<string, scalar|list<string>>> $shippingProvenance
     */
    private function __construct(
        public array $config,
        public array $metaRows,
        public array $shippingProvenance,
    ) {
    }

    /** @param list<AddressProjection> $addresses */
    public static function project(OrderRecord $record, array $addresses): self
    {
        $titles = [];
        $provenance = [];
        foreach ($record->shippingLines as $line) {
            $title = trim($line->title);
            if ($title !== '') {
                $titles[$title] = true;
            }
            $provenance[] = [
                'source_identity' => $line->identity->canonical(),
                'source_line_id' => $line->sourceLineId,
                'method_id' => $line->methodId,
                'instance_id' => $line->instanceId,
                'title' => $title,
                'meta_keys' => array_values(array_map('strval', array_keys($line->meta))),
            ];
        }
        if (count($titles) > 1) {
            throw new SourceRecordException(
                'target_schema_unrepresentable',
                'Multiple source shipping titles cannot fit one target shipping_method_title.',
            );
        }
        $config = $titles === [] ? [] : ['shipping_method_title' => array_key_first($titles)];

        $businessInfo = [];
        foreach ($addresses as $address) {
            if (!$address instanceof AddressProjection) {
                throw new \InvalidArgumentException('Order metadata addresses must be projections.');
            }
            if ($address->businessInfo === []) {
                continue;
            }
            if ($businessInfo !== [] && $businessInfo !== $address->businessInfo) {
                throw new SourceRecordException('order_money_mismatch', 'Projected business tax copies disagree.');
            }
            $businessInfo = $address->businessInfo;
        }
        $metaRows = $businessInfo === [] ? [] : [[
            'meta_key' => 'business_info',
            'meta_value' => $businessInfo,
        ]];
        return new self($config, $metaRows, $provenance);
    }

    /** @param list<AddressProjection> $addresses */
    public function reconciles(array $addresses): bool
    {
        $target = $this->metaRows[0]['meta_value'] ?? [];
        foreach ($addresses as $address) {
            if (!$address instanceof AddressProjection || !$address->reconcilesBusinessTaxId()) {
                return false;
            }
            if ($address->businessInfo !== [] && $address->businessInfo !== $target) {
                return false;
            }
        }
        return $target !== [] || $this->metaRows === [];
    }
}
