<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\RecordAssessment;
use CartShift\Support\CanonicalJson;
use CartShift\Support\Enums\FcBillingInterval;

defined('ABSPATH') || exit;

final class ProductCapabilityAssessor
{
    public function __construct(private readonly ProductTypeAdapterRegistry $adapters = new ProductTypeAdapterRegistry())
    {
    }

    public function assess(ProductRecord $record, ProductAssessmentContext $context): RecordAssessment
    {
        $decisionAssessment = $this->operatorDecision($record, $context);
        if ($decisionAssessment !== null) {
            return $decisionAssessment;
        }

        $adapter = $this->adapters->adapterFor($record->productType);
        if ($adapter === null) {
            return $this->blocked('unsupported_product_type', $context, ['source_type' => $record->productType]);
        }

        if (!$adapter instanceof BuiltinProductTypeAdapter) {
            $adapterAssessment = $adapter->assess($record, $context);
            if ($adapterAssessment->outcome !== AssessmentOutcome::Ready) {
                return $adapterAssessment;
            }
        }

        $fieldAssessment = $this->fieldDecisions($record, $context);
        if ($fieldAssessment !== null) {
            return $fieldAssessment;
        }

        $statusAssessment = $this->status($record, $context);
        if ($statusAssessment !== null) {
            return $statusAssessment;
        }

        if (!$this->taxClassesExist($record, $context)) {
            return $this->blocked('target_tax_class_missing', $context);
        }

        if (!$context->supports('exact_price_x100')) {
            return $this->blocked('exact_price_contract_unproved', $context);
        }

        if ($this->hasManagedStock($record) && !$context->supports('stock_purchase_path')) {
            return $this->blocked('stock_purchase_path_unproved', $context);
        }

        if ($this->hasAssets($record) && !$context->supports('asset_hash_roundtrip')) {
            return $this->blocked('asset_roundtrip_unproved', $context);
        }

        if (in_array($record->productType, ['variable', 'variable-subscription'], true)) {
            if (!$context->supports('simple_variations')) {
                return $this->blocked('simple_variations_unproved', $context);
            }

            if ($record->variations === [] || array_filter(
                $record->variations,
                static fn (VariationRecord $variation): bool => $variation->identity == $record->identity,
            ) !== []) {
                return $this->blocked('variable_variations_missing', $context);
            }
        }

        foreach ($record->variations as $variation) {
            foreach ($variation->attributeAssignments as $assignment) {
                if ($assignment['wildcard']) {
                    return $this->blocked('wildcard_variation_unrepresentable', $context, [
                        'source_variation' => $variation->identity->canonical(),
                    ]);
                }
            }

            if (($variation->price->saleStartsUtc !== null || $variation->price->saleEndsUtc !== null)
                && !$context->supports('exact_sale_scheduler')) {
                return $this->blocked('scheduled_sale_unrepresentable', $context);
            }
        }

        if ($this->hasCustomVariationAttributes($record) && !$context->supports('custom_variation_attributes')) {
            return $this->blocked('custom_attribute_renderer_unproved', $context);
        }

        $backorderAssessment = $this->backorders($record, $context);
        if ($backorderAssessment !== null) {
            return $backorderAssessment;
        }

        if (in_array($record->productType, ['subscription', 'variable-subscription'], true)) {
            $cadenceAssessment = $this->subscriptionCadence($record, $context);
            if ($cadenceAssessment !== null) {
                return $cadenceAssessment;
            }
        }

        $excluded = [];
        foreach ($context->fieldDecisions->decisions as $field => $disposition) {
            if ($disposition === ProductFieldDisposition::ExcludeByPolicy) {
                $excluded[] = $field;
            }
        }

        return new RecordAssessment(AssessmentOutcome::Ready, 'product_ready', [
            'stage_status' => 'draft',
            'approved_promotion_status' => $this->promotionStatus($record, $context),
            'target_payment_type' => $adapter->targetPaymentType($record),
            'excluded_fields' => $excluded,
            'field_decision_fingerprint' => $context->fieldDecisions->fingerprint,
            'dependent_orders' => $context->dependentOrders,
            'dependent_subscriptions' => $context->dependentSubscriptions,
            'stock_exception_count' => count(array_filter(
                $record->variations,
                static fn (VariationRecord $variation): bool => $variation->stock->ownership === StockOwnership::Parent,
            )),
        ]);
    }

    private function hasAssets(ProductRecord $record): bool
    {
        if ($record->media !== [] || $record->downloads !== []) {
            return true;
        }

        foreach ($record->variations as $variation) {
            if ($variation->media !== [] || $variation->downloads !== []) {
                return true;
            }
        }

        return false;
    }

    private function operatorDecision(ProductRecord $record, ProductAssessmentContext $context): ?RecordAssessment
    {
        $decision = $context->operatorDecision;
        if ($decision === null) {
            return null;
        }

        $sourceFingerprint = $record->envelope()->privateContentDigest;
        if ($decision->source != $record->identity || !hash_equals($decision->sourceFingerprint, $sourceFingerprint)) {
            return $this->blocked('operator_decision_source_drift', $context);
        }

        if ($decision->action === ProductTransferAction::Exclude) {
            return new RecordAssessment(AssessmentOutcome::ExcludedByPolicy, $decision->reasonCode, [
                'source_fingerprint' => $sourceFingerprint,
                'dependent_orders' => $context->dependentOrders,
                'dependent_subscriptions' => $context->dependentSubscriptions,
            ]);
        }

        if ($decision->action === ProductTransferAction::Block) {
            return $this->blocked($decision->reasonCode, $context);
        }

        if ($decision->action === ProductTransferAction::Create) {
            return null;
        }

        if (!$this->validLinkedVariations($record, $decision)) {
            return $this->blocked('linked_variation_set_invalid', $context);
        }

        return new RecordAssessment(AssessmentOutcome::Linked, $decision->reasonCode, [
            'target_product_id' => $decision->targetProductId,
            'source_fingerprint' => $decision->sourceFingerprint,
            'target_fingerprint' => $decision->targetFingerprint,
            'linked_variations' => count($decision->linkedVariations),
            'dependent_orders' => $context->dependentOrders,
            'dependent_subscriptions' => $context->dependentSubscriptions,
        ]);
    }

    private function validLinkedVariations(ProductRecord $record, ProductTransferDecision $decision): bool
    {
        if (count($record->variations) !== count($decision->linkedVariations)) {
            return false;
        }

        $source = [];
        foreach ($record->variations as $variation) {
            $source[$variation->identity->canonical()] = CanonicalJson::fingerprint($variation->toArray());
        }

        $seenSource = [];
        $seenTarget = [];
        $operatorFingerprint = null;
        foreach ($decision->linkedVariations as $link) {
            $sourceKey = $link->sourceVariation->canonical();
            if (
                $link->targetProductId !== $decision->targetProductId
                || isset($seenSource[$sourceKey])
                || isset($seenTarget[$link->targetVariationId])
                || !isset($source[$sourceKey])
                || !hash_equals($source[$sourceKey], $link->sourceSemanticFingerprint)
                || ($operatorFingerprint !== null && !hash_equals($operatorFingerprint, $link->operatorDecisionFingerprint))
            ) {
                return false;
            }

            $operatorFingerprint ??= $link->operatorDecisionFingerprint;
            $seenSource[$sourceKey] = true;
            $seenTarget[$link->targetVariationId] = true;
        }

        return count($seenSource) === count($source);
    }

    private function fieldDecisions(ProductRecord $record, ProductAssessmentContext $context): ?RecordAssessment
    {
        foreach ($context->fieldDecisions->decisions as $field => $disposition) {
            if ($disposition === ProductFieldDisposition::Block) {
                return $this->blocked('product_field_blocked', $context, ['blocked_field' => $field]);
            }

            if ($disposition === ProductFieldDisposition::PreserveProvenance && !$context->supports('provenance_readback')) {
                return $this->blocked('product_field_provenance_unproved', $context, ['blocked_field' => $field]);
            }
        }

        $catalogue = ['catalog_visibility', 'featured', 'menu_order', 'purchase_note'];
        foreach ($catalogue as $field) {
            if ($context->fieldDecisions->for($field) === ProductFieldDisposition::Migrate
                && $this->catalogueFieldHasData($record, $field)
                && !$context->supports('catalogue_fields_roundtrip')) {
                return $this->blocked('catalogue_field_contract_unproved', $context, ['blocked_field' => $field]);
            }
        }

        if ($record->globalUniqueId !== ''
            && $context->fieldDecisions->for('global_unique_id') === ProductFieldDisposition::Migrate
            && !$context->supports('global_unique_id_roundtrip')) {
            return $this->blocked('global_unique_id_contract_unproved', $context);
        }

        foreach (['upsell_ids' => $record->upsellProducts, 'cross_sell_ids' => $record->crossSellProducts] as $field => $relations) {
            if ($relations !== []
                && $context->fieldDecisions->for($field) === ProductFieldDisposition::Migrate
                && !$context->supports('product_relations_roundtrip')) {
                return $this->blocked('product_relation_contract_unproved', $context, ['blocked_field' => $field]);
            }
        }

        $hasReviews = $record->reviewsAllowed || $record->reviewCount > 0 || $record->ratingDistribution !== [] || $record->averageRating !== '0';
        if ($hasReviews && !$this->fieldsExcludedOrProved(
            $context,
            ['reviews_allowed', 'review_count', 'rating_counts', 'average_rating'],
            'review_provenance_roundtrip',
        )) {
            return $this->blocked('review_contract_unproved', $context);
        }

        if ($record->totalSales > 0 && !$this->fieldsExcludedOrProved(
            $context,
            ['total_sales'],
            'sales_provenance_roundtrip',
        )) {
            return $this->blocked('sales_count_contract_unproved', $context);
        }

        if ($record->approvedMeta !== [] && !$context->supports('extension_metadata_adapter')) {
            return $this->blocked('extension_metadata_adapter_required', $context);
        }

        return null;
    }

    /** @param list<string> $fields */
    private function fieldsExcludedOrProved(ProductAssessmentContext $context, array $fields, string $capability): bool
    {
        foreach ($fields as $field) {
            if ($context->fieldDecisions->for($field) === ProductFieldDisposition::Migrate && !$context->supports($capability)) {
                return false;
            }
        }
        return true;
    }

    private function catalogueFieldHasData(ProductRecord $record, string $field): bool
    {
        return match ($field) {
            'catalog_visibility' => $record->catalogVisibility !== 'visible',
            'featured' => $record->featured,
            'menu_order' => $record->menuOrder !== 0,
            'purchase_note' => $record->purchaseNote !== '',
        };
    }

    private function status(ProductRecord $record, ProductAssessmentContext $context): ?RecordAssessment
    {
        if ($record->status === 'trash') {
            return $this->blocked('trashed_product_selection_required', $context);
        }

        if (!in_array($record->status, ['publish', 'private', 'draft'], true)
            && !in_array($record->status, $context->approvedDraftStatuses, true)) {
            return $this->blocked('product_status_policy_required', $context, ['source_status' => $record->status]);
        }

        return null;
    }

    private function promotionStatus(ProductRecord $record, ProductAssessmentContext $context): string
    {
        return in_array($record->status, ['publish', 'private', 'draft'], true) ? $record->status : 'draft';
    }

    private function taxClassesExist(ProductRecord $record, ProductAssessmentContext $context): bool
    {
        $profiles = [$record->tax, ...array_map(static fn (VariationRecord $variation): TaxProfile => $variation->tax, $record->variations)];
        foreach ($profiles as $tax) {
            $targetClass = $tax->status === 'none' ? 'none' : $tax->classSlug;
            if (!in_array($targetClass, $context->targetTaxClasses, true)) {
                return false;
            }
        }
        return true;
    }

    private function hasManagedStock(ProductRecord $record): bool
    {
        if ($record->stock->ownership !== StockOwnership::None) {
            return true;
        }
        return array_filter(
            $record->variations,
            static fn (VariationRecord $variation): bool => $variation->stock->ownership !== StockOwnership::None,
        ) !== [];
    }

    private function hasCustomVariationAttributes(ProductRecord $record): bool
    {
        foreach ($record->attributes as $attribute) {
            if ($attribute->variation && $attribute->kind === 'custom') {
                return true;
            }
        }
        return false;
    }

    private function backorders(ProductRecord $record, ProductAssessmentContext $context): ?RecordAssessment
    {
        $profiles = [$record->stock, ...array_map(static fn (VariationRecord $variation): StockProfile => $variation->stock, $record->variations)];
        foreach ($profiles as $stock) {
            // Parent-owned stock is deliberately projected unavailable with
            // backorders disabled. Its exact source setting remains in the
            // target exception evidence for the owner's post-migration setup.
            if ($stock->ownership === StockOwnership::Parent) {
                continue;
            }
            if ($stock->backorders === 'yes' && !$context->supports('backorders_yes')) {
                return $this->blocked('backorders_yes_unproved', $context);
            }
            if ($stock->backorders === 'notify' && !$context->supports('backorders_notify')) {
                return $this->blocked('backorders_notify_unproved', $context);
            }
        }
        return null;
    }

    private function subscriptionCadence(ProductRecord $record, ProductAssessmentContext $context): ?RecordAssessment
    {
        if ($record->productType === 'variable-subscription') {
            foreach ($record->variations as $variation) {
                $assessment = $this->subscriptionTerms(
                    $variation->typeConfiguration,
                    $context,
                    $variation->identity->canonical(),
                );
                if ($assessment !== null) {
                    return $assessment;
                }
            }

            return null;
        }

        return $this->subscriptionTerms($record->typeConfiguration, $context, null);
    }

    /** @param array<string, scalar|null> $configuration */
    private function subscriptionTerms(
        array $configuration,
        ProductAssessmentContext $context,
        ?string $sourceVariation,
    ): ?RecordAssessment {
        $extra = $sourceVariation === null ? [] : ['source_variation' => $sourceVariation];
        $period = $configuration['subscription_period'] ?? null;
        $interval = $this->nonNegativeInteger($configuration['subscription_period_interval'] ?? null);
        $length = $this->nonNegativeInteger($configuration['subscription_length'] ?? null);
        $trialLength = $this->nonNegativeInteger($configuration['subscription_trial_length'] ?? null);
        $trialPeriod = $configuration['subscription_trial_period'] ?? null;
        $signupFee = $configuration['subscription_sign_up_fee'] ?? null;

        if (!is_string($period) || $interval === null || $interval < 1 || $length === null
            || $trialLength === null || !is_string($trialPeriod) || !is_scalar($signupFee)) {
            return $this->blocked('subscription_contract_incomplete', $context, $extra);
        }

        if (FcBillingInterval::tryFromWooCommerce($period, $interval) === null) {
            return $this->blocked('unsupported_billing_cadence', $context, $extra);
        }

        if ($length > 0 && $length % $interval !== 0) {
            return $this->blocked('subscription_length_unrepresentable', $context, $extra);
        }

        if ($length > 0 && !$context->supports('subscription_finite_cycles')) {
            return $this->blocked('subscription_finite_cycles_unproved', $context, $extra);
        }

        if ($trialLength > 0) {
            if (!in_array($trialPeriod, ['day', 'week'], true) && !$context->supports('exact_calendar_trial')) {
                return $this->blocked('subscription_trial_unrepresentable', $context, $extra);
            }

            if (!$context->supports('subscription_trial_days')) {
                return $this->blocked('subscription_trial_contract_unproved', $context, $extra);
            }
        }

        $fee = trim((string) $signupFee);
        if (preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/D', $fee) !== 1) {
            return $this->blocked('subscription_setup_fee_unrepresentable', $context, $extra);
        }

        if ((float) $fee > 0 && !$context->supports('subscription_setup_fee')) {
            return $this->blocked('subscription_setup_fee_unproved', $context, $extra);
        }

        return null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (!is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /** @param array<string, scalar|list<scalar|null>|null> $extra */
    private function blocked(string $reasonCode, ProductAssessmentContext $context, array $extra = []): RecordAssessment
    {
        return new RecordAssessment(AssessmentOutcome::Blocked, $reasonCode, $extra + [
            'dependent_orders' => $context->dependentOrders,
            'dependent_subscriptions' => $context->dependentSubscriptions,
        ]);
    }
}
