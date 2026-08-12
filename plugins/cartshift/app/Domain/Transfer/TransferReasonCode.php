<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

enum TransferReasonCode: string
{
    case RuntimeContractMismatch = 'runtime_contract_mismatch';
    case SourceKeyInvalid = 'source_key_invalid';
    case SourceIdentityConflict = 'source_identity_conflict';
    case SelectionDrift = 'selection_drift';
    case SelectionIdentityMissing = 'selection_identity_missing';
    case ProductCensusDuplicate = 'product_census_duplicate';
    case OrderCensusDuplicate = 'order_census_duplicate';
    case SubscriptionCensusDuplicate = 'subscription_census_duplicate';
    case SourceCensusUnaccounted = 'source_census_unaccounted';
    case ProductHydrationFailed = 'product_hydration_failed';
    case OrderHydrationFailed = 'order_hydration_failed';
    case SubscriptionHydrationFailed = 'subscription_hydration_failed';
    case ProductSemanticEnumerationMismatch = 'product_semantic_enumeration_mismatch';
    case ProductLookupMissing = 'product_lookup_missing';
    case ProductLookupStale = 'product_lookup_stale';
    case OrderItemParentMissing = 'order_item_parent_missing';
    case OrderItemParentTypeMismatch = 'order_item_parent_type_mismatch';
    case RecordFingerprintMismatch = 'record_fingerprint_mismatch';
    case UnsupportedProductType = 'unsupported_product_type';
    case UnsupportedProductDependency = 'unsupported_product_dependency';
    case SubscriptionScheduleAbsence = 'subscription_schedule_absence';
    case UnsupportedProductStatus = 'unsupported_product_status';
    case UnsupportedAttributeContract = 'unsupported_attribute_contract';
    case UnsupportedTaxClass = 'unsupported_tax_class';
    case UnsupportedSaleSchedule = 'unsupported_sale_schedule';
    case UnsupportedStockContract = 'unsupported_stock_contract';
    case AssetMissing = 'asset_missing';
    case AssetHashMismatch = 'asset_hash_mismatch';
    case UnsupportedDownloadPolicy = 'unsupported_download_policy';
    case HistoricalProductMissing = 'historical_product_missing';
    case OrderMoneyMismatch = 'order_money_mismatch';
    case OrderTaxMismatch = 'order_tax_mismatch';
    case OrderFeeMismatch = 'order_fee_mismatch';
    case ChargeParentMissing = 'charge_parent_missing';
    case RefundParentAmbiguous = 'refund_parent_ambiguous';
    case ExecutablePaymentReference = 'executable_payment_reference';
    case TargetSchemaUnrepresentable = 'target_schema_unrepresentable';
    case TargetWriteFailed = 'target_write_failed';
    case TargetReconciliationFailed = 'target_reconciliation_failed';
}
