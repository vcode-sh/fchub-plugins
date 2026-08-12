<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Subscription\Source\SubscriptionRecord;
use CartShift\Domain\Transfer\Customer\CustomerAddressRecord;
use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\Order\AddressRecord;
use CartShift\Domain\Transfer\Order\CouponLineRecord;
use CartShift\Domain\Transfer\Order\FeeLineRecord;
use CartShift\Domain\Transfer\Order\OrderLineRecord;
use CartShift\Domain\Transfer\Order\OrderNoteRecord;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\Order\PaymentEventRecord;
use CartShift\Domain\Transfer\Order\ShippingLineRecord;
use CartShift\Domain\Transfer\Order\TaxRateRecord;
use CartShift\Domain\Transfer\Product\AssetReference;
use CartShift\Domain\Transfer\Product\AttributeRecord;
use CartShift\Domain\Transfer\Product\DownloadReference;
use CartShift\Domain\Transfer\Product\PriceRecord;
use CartShift\Domain\Transfer\Product\ProductRecord;
use CartShift\Domain\Transfer\Product\StockOwnership;
use CartShift\Domain\Transfer\Product\StockProfile;
use CartShift\Domain\Transfer\Product\TaxonomyAssignment;
use CartShift\Domain\Transfer\Product\TaxProfile;
use CartShift\Domain\Transfer\Product\VariationRecord;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Reconstructs typed records from package bytes and rejects any lossy or extra payload field. */
final class TransferRecordHydrator
{
    public function product(RecordEnvelope $envelope): ProductRecord
    {
        $this->kind($envelope, 'product');
        $p = $envelope->payload;
        $record = new ProductRecord(
            $this->identity($p['identity']),
            $p['product_type'], $p['status'], $p['name'], $p['slug'], $p['description'], $p['short_description'],
            $p['sku'], $p['created_utc'], $p['modified_utc'], $p['menu_order'], $p['featured'],
            $p['catalog_visibility'], $p['purchase_note'], $p['reviews_allowed'], $p['review_count'],
            $p['average_rating'], $p['rating_distribution'], $p['total_sales'], $p['global_unique_id'],
            $p['fulfilment_type'], $p['password_protected'], $p['shipping_class_slug'], $p['type_configuration'],
            $this->tax($p['tax']), $this->stock($p['stock']),
            array_map($this->variation(...), $p['variations']),
            array_map($this->attribute(...), $p['attributes']),
            array_map($this->taxonomy(...), $p['taxonomies']),
            array_map($this->media(...), $p['media']),
            array_map($this->download(...), $p['downloads']),
            array_map($this->identity(...), $p['upsell_products']),
            array_map($this->identity(...), $p['cross_sell_products']),
            $p['approved_meta'], $p['field_registry_version'], $p['allowed_loss_ledger'],
        );
        $this->roundTrip($envelope, $record->toArray());
        return $record;
    }

    public function customer(RecordEnvelope $envelope): CustomerRecord
    {
        $this->kind($envelope, 'customer');
        $p = $envelope->payload;
        $record = CustomerRecord::create(
            $this->identity($p['identity']), $p['source_user_id'], $p['classification'], $p['first_name'],
            $p['last_name'], $p['email'], $p['status_intent'], array_map($this->customerAddress(...), $p['addresses']),
            $p['created_utc'], $p['updated_utc'], $p['provenance'], array_map($this->identity(...), $p['dependencies']),
        );
        $this->roundTrip($envelope, $record->toArray());
        return $record;
    }

    public function order(RecordEnvelope $envelope): OrderRecord
    {
        $this->kind($envelope, 'order');
        $p = $envelope->payload;
        $record = new OrderRecord(
            $this->identity($p['identity']), $this->nullableIdentity($p['customer']), $this->nullableIdentity($p['parent_order']),
            $p['relationship_type'], $p['source_status'], $p['source_store_currency'], $p['currency'],
            $p['target_base_currency'], $p['exchange_rate_decimal'], $p['exchange_rate_evidence'], $p['prices_include_tax'],
            $p['subtotal'], $p['coupon_discount_total'], $p['manual_discount_total'], $p['discount_tax'],
            $p['shipping_total'], $p['shipping_tax'], $p['fee_total'], $p['fee_tax'], $p['cart_tax'],
            $p['gross_total'], $p['refunded_total'], $p['created_utc'], $p['modified_utc'], $p['paid_utc'],
            $p['completed_utc'], $p['refunded_utc'],
            array_map($this->orderLine(...), $p['product_lines']),
            array_map($this->feeLine(...), $p['fee_lines']),
            array_map($this->shippingLine(...), $p['shipping_lines']),
            array_map($this->couponLine(...), $p['coupon_lines']),
            array_map($this->taxRate(...), $p['tax_rates']),
            array_map($this->orderAddress(...), $p['addresses']),
            array_map($this->paymentEvent(...), $p['payment_events']),
            array_map($this->orderNote(...), $p['notes']),
            $p['approved_meta'],
        );
        $this->roundTrip($envelope, $record->toArray());
        return $record;
    }

    public function subscription(RecordEnvelope $envelope): SubscriptionRecord
    {
        $this->kind($envelope, 'subscription');
        $p = $envelope->payload;
        $record = new SubscriptionRecord(
            $this->identity($p['identity']),
            $this->identity($p['customer_identity']),
            $p['status'], $p['currency'], $p['items'], $p['contract'], $p['related_orders'],
            $p['schedule'], $p['payment_ownership'], $p['dependencies'],
        );
        $this->roundTrip($envelope, $record->toArray());
        return $record;
    }

    /** @param array<string,mixed> $p */
    private function variation(array $p): VariationRecord
    {
        return new VariationRecord(
            $this->identity($p['identity']), $this->identity($p['parent_identity']), $p['status'], $p['created_utc'],
            $p['modified_utc'], $p['menu_order'], $p['sku'], $p['global_unique_id'], $p['attribute_assignments'],
            $this->price($p['price']), $this->tax($p['tax']), $this->stock($p['stock']), $p['cost'], $p['dimensions'],
            $p['fulfilment_type'], $p['description'], array_map($this->media(...), $p['media']),
            array_map($this->download(...), $p['downloads']), $p['type_configuration'], $p['shipping_class_slug'],
            $p['defined_cost'], $p['cost_is_additive'],
        );
    }

    /** @param array<string,mixed> $p */
    private function price(array $p): PriceRecord
    {
        return new PriceRecord($p['active_price'], $p['regular_price'], $p['sale_price'], $p['sale_starts_utc'], $p['sale_ends_utc'], $p['currency']);
    }

    /** @param array<string,mixed> $p */
    private function tax(array $p): TaxProfile
    {
        return new TaxProfile($p['status'], $p['class_slug'], $p['prices_include_tax']);
    }

    /** @param array<string,mixed> $p */
    private function stock(array $p): StockProfile
    {
        return new StockProfile(
            StockOwnership::from($p['ownership']), $this->nullableIdentity($p['owner']), $p['quantity'], $p['status'],
            $p['backorders'], $p['sold_individually'], $p['low_stock_threshold'],
        );
    }

    /** @param array<string,mixed> $p */
    private function attribute(array $p): AttributeRecord
    {
        return new AttributeRecord($p['source_key'], $p['display_name'], $p['kind'], $p['variation'], $p['visible'], $p['position'], $p['default_value'], $p['values'], $p['value_labels']);
    }

    /** @param array<string,mixed> $p */
    private function taxonomy(array $p): TaxonomyAssignment
    {
        return new TaxonomyAssignment($p['taxonomy'], $this->identity($p['term_identity']), $p['name'], $p['slug'], $p['description'], $this->nullableIdentity($p['parent']), $p['order'], $p['target_disposition'], $p['assigned']);
    }

    /** @param array<string,mixed> $p */
    private function media(array $p): AssetReference
    {
        return new AssetReference($this->identity($p['identity']), $p['locator'], $p['role'], $p['mime_type'], $p['size'], $this->identity($p['owner']), $p['provenance'], $p['expected_sha256']);
    }

    /** @param array<string,mixed> $p */
    private function download(array $p): DownloadReference
    {
        return new DownloadReference($this->identity($p['identity']), $p['locator'], $p['content_sha256'], $this->identity($p['owner']), $p['name'], $p['limit'], $p['expiry_days']);
    }

    /** @param array<string,mixed> $p */
    private function customerAddress(array $p): CustomerAddressRecord
    {
        return new CustomerAddressRecord(
            $this->identity($p['identity']), $p['type'], $p['primary_intent'], $p['status'], $p['label'], $p['name'],
            $p['company'], $p['address_1'], $p['address_2'], $p['city'], $p['state'], $p['postcode'], $p['country'],
            $p['phone'], $p['email'],
        );
    }

    /** @param array<string,mixed> $p */
    private function orderLine(array $p): OrderLineRecord
    {
        return new OrderLineRecord(
            $this->identity($p['identity']), $p['source_line_id'], $this->identity($p['product']), $this->identity($p['variation']),
            $p['name'], $p['sku'], $p['attribute_snapshot'], $p['quantity'], $p['cart_index'], $p['unit_price'],
            $p['subtotal'], $p['subtotal_tax'], $p['discount_total'], $p['discount_tax'], $p['tax_total'], $p['line_total'],
            $p['refund_total'], $p['cost_disposition'], $p['fulfilled_quantity'], $p['rate'], $p['created_utc'],
            $p['tax_allocations'], $p['other_info'], $p['line_meta'],
        );
    }

    /** @param array<string,mixed> $p */
    private function feeLine(array $p): FeeLineRecord
    {
        return new FeeLineRecord($this->identity($p['identity']), $p['source_line_id'], $p['name'], $p['total'], $p['tax'], $p['tax_allocations'], $p['meta']);
    }

    /** @param array<string,mixed> $p */
    private function shippingLine(array $p): ShippingLineRecord
    {
        return new ShippingLineRecord($this->identity($p['identity']), $p['source_line_id'], $p['method_id'], $p['instance_id'], $p['title'], $p['total'], $p['tax'], $p['tax_allocations'], $p['meta']);
    }

    /** @param array<string,mixed> $p */
    private function couponLine(array $p): CouponLineRecord
    {
        return new CouponLineRecord($this->identity($p['identity']), $p['source_line_id'], $p['code'], $p['discount'], $p['discount_tax']);
    }

    /** @param array<string,mixed> $p */
    private function taxRate(array $p): TaxRateRecord
    {
        return new TaxRateRecord($this->identity($p['identity']), $p['source_line_id'], $p['source_rate_id'], $p['code'], $p['label'], $p['percentage'], $p['compound'], $p['order_tax'], $p['shipping_tax'], $p['taxable_amount'], $p['included']);
    }

    /** @param array<string,mixed> $p */
    private function orderAddress(array $p): AddressRecord
    {
        return new AddressRecord($this->identity($p['identity']), $p['type'], $p['first_name'], $p['last_name'], $p['company'], $p['address_1'], $p['address_2'], $p['city'], $p['state'], $p['postcode'], $p['country'], $p['email'], $p['phone'], $p['business_tax_id']);
    }

    /** @param array<string,mixed> $p */
    private function paymentEvent(array $p): PaymentEventRecord
    {
        return new PaymentEventRecord(
            $this->identity($p['identity']), $p['type'], $p['amount'], $p['currency'], $p['status'],
            PaymentEvidenceKind::from($p['evidence_kind']), $p['payment_method'], $p['payment_method_title'],
            $p['provider_reference'], $this->nullableIdentity($p['parent_event']), $p['occurred_utc'], $p['provenance'],
        );
    }

    /** @param array<string,mixed> $p */
    private function orderNote(array $p): OrderNoteRecord
    {
        return new OrderNoteRecord($this->identity($p['identity']), $p['source_note_id'], $p['content'], $p['created_utc'], $p['customer_visible'], $p['author_kind'], $p['public_identifier']);
    }

    private function identity(mixed $value): SourceIdentity
    {
        if (!is_string($value)) throw new \InvalidArgumentException('target_record_identity_invalid');
        return SourceIdentity::fromCanonical($value);
    }

    private function nullableIdentity(mixed $value): ?SourceIdentity
    {
        return $value === null ? null : $this->identity($value);
    }

    private function kind(RecordEnvelope $record, string $kind): void
    {
        if ($record->identity->entityType !== $kind || ($record->payload['identity'] ?? null) !== $record->identity->canonical()) {
            throw new \InvalidArgumentException('target_record_identity_invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private function roundTrip(RecordEnvelope $envelope, array $payload): void
    {
        if (CanonicalJson::encode($payload) !== CanonicalJson::encode($envelope->payload)) {
            throw new \RuntimeException('target_record_payload_roundtrip_mismatch');
        }
        $rebuilt = RecordEnvelope::forPayload($envelope->schemaVersion, $envelope->identity, $payload);
        if (!hash_equals($envelope->privateContentDigest, $rebuilt->privateContentDigest)) {
            throw new \RuntimeException('target_record_payload_fingerprint_changed');
        }
    }
}
