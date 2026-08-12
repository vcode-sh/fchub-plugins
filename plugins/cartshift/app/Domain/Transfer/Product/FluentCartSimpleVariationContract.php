<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\Enums\FcBillingInterval;

defined('ABSPATH') || exit;

final class FluentCartSimpleVariationContract
{
    public const string VARIATION_TYPE = 'simple_variations';
    public const int IDENTIFIER_MAX_LENGTH = 100;
    public const int SKU_MAX_LENGTH = 30;
    public const int TITLE_MAX_LENGTH = 192;

    public function identifier(SourceIdentity $sourceVariation): string
    {
        return 'cs-' . substr(hash('sha256', $sourceVariation->canonical()), 0, 40);
    }

    /** @return array<string, mixed> */
    public function baseline(VariationRecord $record, ProductAssessmentContext $context, ?string $targetSku = null): array
    {
        if ($record->price->activePrice === null) {
            throw new SourceRecordException('target_schema_unrepresentable', 'FluentCart requires an explicit target item price.');
        }

        $sku = $targetSku ?? $record->sku;
        if (($targetSku !== null && trim($targetSku) === '') || $this->length($sku) > self::SKU_MAX_LENGTH) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Source variation SKU exceeds the installed target column.');
        }

        if (!array_key_exists($record->shippingClassSlug, $context->targetShippingClasses)) {
            throw new SourceRecordException('target_shipping_class_missing', 'Source variation shipping class has no exact target mapping.');
        }

        if ($record->stock->ownership === StockOwnership::Parent) {
            throw new SourceRecordException(
                'target_shared_stock_unavailable',
                'Installed FluentCart simple variations have no quantity-bearing shared parent stock owner.',
            );
        }

        if ($record->stock->backorders !== 'no') {
            throw new SourceRecordException(
                'target_backorders_' . $record->stock->backorders . '_unavailable',
                'Installed FluentCart stores a backorder flag but has no proved simple-variation purchase consumer.',
            );
        }

        $managed = $record->stock->ownership === StockOwnership::Self;
        if ($managed && $record->stock->quantity === null) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Managed target stock requires an explicit quantity.');
        }

        $otherInfo = [
            'description' => $record->description,
            'payment_type' => 'onetime',
            'tax_class' => $record->tax->classSlug,
            'tax_exempt' => $record->tax->status === 'none' ? 'yes' : 'no',
            'tax_inclusion' => $record->tax->pricesIncludeTax ? 'included' : 'excluded',
            'weight' => $record->dimensions['weight'],
            'length' => $record->dimensions['length'],
            'width' => $record->dimensions['width'],
            'height' => $record->dimensions['height'],
            'weight_unit' => $record->dimensions['weight_unit'],
            'dimension_unit' => $record->dimensions['dimension_unit'],
            'source_price' => $record->price->toArray(),
            'source_stock_ownership' => $record->stock->ownership->value,
        ];

        if ($record->typeConfiguration !== []) {
            $otherInfo = [...$otherInfo, ...$this->subscriptionTerms($record->typeConfiguration)];
        }

        $paymentType = (string) $otherInfo['payment_type'];
        $quantity = $managed ? (int) $record->stock->quantity : 0;
        $comparePrice = $record->price->regularPrice !== null
            && $record->price->regularPrice > $record->price->activePrice
                ? $record->price->regularPrice
                : 0;

        return [
            'variation_identifier' => $this->identifier($record->identity),
            'sku' => $sku === '' ? null : $sku,
            'sold_individually' => $record->stock->soldIndividually ? 1 : 0,
            'manage_stock' => $managed ? 1 : 0,
            'payment_type' => $paymentType,
            'stock_status' => match ($record->stock->status) {
                'instock' => 'in-stock',
                'outofstock' => 'out-of-stock',
                'onbackorder' => 'backorder',
            },
            'backorders' => 0,
            'total_stock' => $quantity,
            'available' => $quantity,
            'committed' => 0,
            'on_hold' => 0,
            'fulfillment_type' => $record->fulfilmentType,
            'item_status' => $record->status === 'publish' ? 'active' : 'draft',
            'manage_cost' => $record->cost === null ? 'false' : 'true',
            'item_price' => $record->price->activePrice,
            'item_cost' => $record->cost ?? 0,
            'compare_price' => $comparePrice,
            'shipping_class' => $context->targetShippingClasses[$record->shippingClassSlug] ?: null,
            'downloadable' => $record->downloads === [] ? 'false' : 'true',
            'other_info' => $otherInfo,
        ];
    }

    /** @param array<string, scalar|null> $configuration @return array<string, int|string> */
    private function subscriptionTerms(array $configuration): array
    {
        $period = $configuration['subscription_period'] ?? null;
        $interval = $this->integer($configuration['subscription_period_interval'] ?? null);
        $length = $this->integer($configuration['subscription_length'] ?? null);
        $trialLength = $this->integer($configuration['subscription_trial_length'] ?? null);
        $trialPeriod = $configuration['subscription_trial_period'] ?? null;
        $fee = $configuration['subscription_sign_up_fee'] ?? null;
        $billing = is_string($period) && $interval !== null
            ? FcBillingInterval::tryFromWooCommerce($period, $interval)
            : null;

        if ($billing === null || $length === null || $trialLength === null || !is_string($trialPeriod) || !is_scalar($fee)) {
            throw new SourceRecordException('subscription_contract_incomplete', 'Subscription variation terms are incomplete.');
        }

        if ($length > 0 && $length % $interval !== 0) {
            throw new SourceRecordException('subscription_length_unrepresentable', 'Subscription length is not an exact number of target billing cycles.');
        }

        if ($trialLength > 0 && !in_array($trialPeriod, ['day', 'week'], true)) {
            throw new SourceRecordException('subscription_trial_unrepresentable', 'Calendar subscription trials cannot be rounded to target days.');
        }

        $signupFee = $this->money((string) $fee);

        return [
            'payment_type' => 'subscription',
            'installment' => $length > 0 ? 'yes' : 'no',
            'times' => $length > 0 ? intdiv($length, $interval) : 0,
            'repeat_interval' => $billing->value,
            'trial_days' => $trialLength * ($trialPeriod === 'week' ? 7 : 1),
            'manage_setup_fee' => $signupFee > 0 ? 'yes' : 'no',
            'signup_fee_name' => $signupFee > 0 ? 'Setup fee' : '',
            'signup_fee' => $signupFee,
            'setup_fee_per_item' => 'no',
        ];
    }

    private function integer(mixed $value): ?int
    {
        return is_int($value) && $value >= 0
            ? $value
            : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    private function money(string $value): int
    {
        if (preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/D', trim($value), $matches) !== 1) {
            throw new SourceRecordException('subscription_setup_fee_unrepresentable', 'Subscription setup fee is not exact to cents.');
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function length(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }

        $count = preg_match_all('/./us', $value, $unused);
        return $count === false ? strlen($value) : $count;
    }
}
