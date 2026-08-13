<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final class SimpleVariationPlanner
{
    public function __construct(private readonly FluentCartSimpleVariationContract $contract = new FluentCartSimpleVariationContract())
    {
    }

    /** @param array<string,string> $skuOverrides @return list<SimpleVariationPlan> */
    public function plan(ProductRecord $product, ProductAssessmentContext $context, array $skuOverrides = []): array
    {
        $variations = $product->variations;
        $skuCounts = [];
        foreach ($variations as $variation) {
            $key = $this->skuKey($variation->sku);
            if ($key !== '') {
                $skuCounts[$key] = ($skuCounts[$key] ?? 0) + 1;
            }
        }
        usort($variations, static fn (VariationRecord $left, VariationRecord $right): int =>
            $left->identity->canonical() <=> $right->identity->canonical()
        );

        $attributes = [];
        foreach ($product->attributes as $attribute) {
            if (isset($attributes[$attribute->sourceKey])) {
                throw new SourceRecordException('variation_attribute_ambiguous', 'Product attributes are not uniquely keyed.');
            }
            $attributes[$attribute->sourceKey] = $attribute;
        }

        $plans = [];
        $seenSource = [];
        $seenIdentifier = [];
        foreach ($variations as $index => $variation) {
            $source = $variation->identity->canonical();
            if (isset($seenSource[$source])) {
                throw new SourceRecordException('variation_source_duplicate', 'A source variation occurs more than once.');
            }
            $seenSource[$source] = true;

            if ($variation->parentIdentity != $product->identity) {
                throw new SourceRecordException('variation_parent_mismatch', 'A source variation belongs to a different parent.');
            }

            if (in_array($product->productType, ['variable', 'variable-subscription'], true)
                && $variation->identity == $product->identity) {
                throw new SourceRecordException('variation_identity_missing', 'A variable product cannot use a synthetic parent variation.');
            }

            [$title, $attributeMetadata] = $this->titleAndAttributes($variation, $attributes);
            if ($this->length($title) > FluentCartSimpleVariationContract::TITLE_MAX_LENGTH) {
                throw new SourceRecordException('target_schema_unrepresentable', 'Variation title exceeds the installed target column.');
            }

            $duplicateSku = ($skuCounts[$this->skuKey($variation->sku)] ?? 0) > 1;
            $targetSku = $skuOverrides[$source] ?? ($duplicateSku ? $this->uniqueSku($variation) : null);
            if ($targetSku !== null && !is_string($targetSku)) {
                throw new SourceRecordException('target_schema_unrepresentable', 'Reviewed variation SKU mapping is invalid.');
            }
            $fields = $this->contract->baseline($variation, $context, $targetSku);
            unset($skuOverrides[$source]);
            $identifier = (string) $fields['variation_identifier'];
            if (isset($seenIdentifier[$identifier])) {
                throw new SourceRecordException('variation_identifier_duplicate', 'Two source variations resolve to one target identifier.');
            }
            $seenIdentifier[$identifier] = true;

            $otherInfo = $fields['other_info'];
            $otherInfo['source_identity'] = $source;
            $otherInfo['source_attributes'] = $attributeMetadata;
            if ($duplicateSku) {
                $otherInfo['sku_migration_exception'] = [
                    'version' => 1,
                    'type' => 'duplicate_variation_sku',
                    'source_variation' => $source,
                    'source_sku' => $variation->sku,
                    'target_sku' => $targetSku,
                    'requires_manual_resolution' => true,
                ];
            }
            $fields['other_info'] = $otherInfo;
            $fields['variation_type'] = FluentCartSimpleVariationContract::VARIATION_TYPE;
            $fields['variation_identifier'] = $identifier;
            $fields['variation_title'] = $title;
            $fields['serial_index'] = $index + 1;

            $plans[] = new SimpleVariationPlan($variation->identity, $identifier, $title, $otherInfo, $fields);
        }

        if (count($plans) !== count($product->variations)) {
            throw new SourceRecordException('variation_cardinality_mismatch', 'Target variation plan changed source cardinality.');
        }
        if ($skuOverrides !== []) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Reviewed variation SKU mapping references no source variation.');
        }

        return $plans;
    }

    /**
     * @param array<string, AttributeRecord> $attributes
     * @return array{string, list<array{source_key: string, label: string, value: string, value_label: string, kind: string}>}
     */
    private function titleAndAttributes(VariationRecord $variation, array $attributes): array
    {
        if ($variation->attributeAssignments === []) {
            return ['Default', []];
        }

        $metadata = [];
        $seen = [];
        foreach ($variation->attributeAssignments as $assignment) {
            $key = $assignment['attribute_key'];
            if ($assignment['wildcard']) {
                throw new SourceRecordException('wildcard_variation_unrepresentable', 'Wildcard variations change selection cardinality.');
            }

            if (isset($seen[$key]) || !isset($attributes[$key])) {
                throw new SourceRecordException('variation_attribute_ambiguous', 'Variation attribute assignment is duplicated or undeclared.');
            }
            $seen[$key] = true;
            $attribute = $attributes[$key];
            if ($assignment['kind'] !== $attribute->kind || !in_array($assignment['value'], $attribute->values, true)) {
                throw new SourceRecordException('variation_attribute_ambiguous', 'Variation attribute value has no exact declared label.');
            }

            $label = $attribute->valueLabels[$assignment['value']] ?? $assignment['value'];
            $metadata[] = [
                'source_key' => $key,
                'label' => $attribute->displayName,
                'value' => $assignment['value'],
                'value_label' => $label,
                'kind' => $attribute->kind,
            ];
        }

        usort($metadata, static fn (array $left, array $right): int =>
            [$attributes[$left['source_key']]->position, $left['source_key']]
            <=> [$attributes[$right['source_key']]->position, $right['source_key']]
        );

        $parts = array_map(
            static fn (array $attribute): string => $attribute['label'] . ': ' . $attribute['value_label'],
            $metadata,
        );
        return [implode(' / ', $parts), $metadata];
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private function skuKey(string $sku): string
    {
        $sku = trim($sku);
        return function_exists('mb_strtolower') ? mb_strtolower($sku, 'UTF-8') : strtolower($sku);
    }

    private function uniqueSku(VariationRecord $variation): string
    {
        return 'CS-' . strtoupper(substr(hash('sha256', $variation->identity->canonical()), 0, 20));
    }
}
