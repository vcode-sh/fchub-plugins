<?php

/**
 * FluentCart Model Stubs for IDE autocompletion.
 *
 * These stubs mirror the actual FluentCart model classes so that the IDE
 * can resolve types, properties, and methods without requiring the full
 * FluentCart plugin to be installed as a Composer dependency.
 *
 * @noinspection PhpMultipleClassDeclarationsInspection
 * @noinspection PhpFullyQualifiedNameUsageInspection
 */

namespace FluentCart\App\Models;

if (class_exists(Model::class)) {
    return;
}

/**
 * Base Model — FluentCart's Eloquent-like ORM base.
 *
 * @method static static query()
 * @method static static|null find(int $id)
 * @method static static|null first()
 * @method static static firstOrCreate(array $attributes, array $values = [])
 * @method static static create(array $attributes)
 * @method static \FluentCart\Framework\Database\Orm\Builder where(string|array|\Closure $column, mixed $operator = null, mixed $value = null)
 * @method static \FluentCart\Framework\Database\Orm\Builder whereIn(string $column, array $values)
 * @method static \FluentCart\Framework\Database\Orm\Builder whereNotNull(string $column)
 * @method static \FluentCart\Framework\Database\Orm\Builder whereNull(string $column)
 * @method static \FluentCart\Framework\Database\Orm\Builder orderBy(string $column, string $direction = 'asc')
 * @method static \FluentCart\Framework\Database\Orm\Builder limit(int $value)
 * @method static \FluentCart\Framework\Support\Collection get(array|string $columns = ['*'])
 * @method static int count()
 * @method static mixed sum(string $column)
 * @method static mixed min(string $column)
 * @method static mixed max(string $column)
 * @method static \FluentCart\Framework\Support\Collection pluck(string $column, string|null $key = null)
 * @method static bool exists()
 * @method bool save()
 * @method bool delete()
 * @method static self|static getQuery()
 * @method self fill(array $attributes)
 * @method self load(string|array $relations)
 *
 * @property int $id
 * @property string|null $created_at
 * @property string|null $updated_at
 */
abstract class Model
{
    protected $table;
    protected $primaryKey = 'id';
    protected $fillable = [];
    protected $guarded = [];
    protected $casts = [];
    protected $appends = [];
    protected $attributes = [];

    public static function boot(): void {}
    public static function booted(): void {}
}

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $contact_id
 * @property string $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string $status
 * @property array|null $purchase_value    JSON: {"USD": 12300}
 * @property int $purchase_count
 * @property int $ltv
 * @property string|null $first_purchase_date
 * @property string|null $last_purchase_date
 * @property int $aov
 * @property string|null $notes
 * @property string $uuid
 * @property string|null $country
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property string|null $created_at
 * @property string|null $updated_at
 * @property-read string $full_name
 * @property-read string $photo
 * @property-read string $country_name
 * @property-read array $formatted_address
 * @property-read string $user_link
 */
class Customer extends Model
{
    protected $table = 'fct_customers';

    protected $fillable = [
        'user_id', 'contact_id', 'email', 'first_name', 'last_name', 'status',
        'purchase_value', 'purchase_count', 'ltv', 'first_purchase_date',
        'last_purchase_date', 'aov', 'notes', 'uuid', 'country', 'city', 'state', 'postcode',
    ];

    public function setPurchaseValueAttribute($value): void {}
    public function getPurchaseValueAttribute($value): ?array { return null; }
    public function orders() {}
    public function subscriptions() {}
    public function shipping_address() {}
    public function billing_address() {}
    public function primary_shipping_address() {}
    public function primary_billing_address() {}
    public function recountStats() { return $this; }
    public function recountStat() { return $this; }
    public function getMeta(string $metaKey, $default = null) { return $default; }
    public function updateMeta(string $metaKey, $metaValue) {}
    public function getWpUser() {}
    public function getWpUserId(bool $recheck = false): ?int { return null; }
    public function updateCustomerStatus(string $newStatus) { return $this; }
}

/**
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $is_primary
 * @property string $type
 * @property string|null $status
 * @property string|null $label
 * @property string|null $name
 * @property string|null $address_1
 * @property string|null $address_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property string|null $country
 * @property string|null $phone
 * @property string|null $email
 * @property array $meta
 * @property string|null $company_name
 */
class CustomerAddresses extends Model
{
    protected $table = 'fct_customer_addresses';

    protected $fillable = [
        'customer_id', 'is_primary', 'type', 'status', 'label', 'name',
        'address_1', 'address_2', 'city', 'state', 'postcode', 'country',
        'phone', 'email', 'meta', 'company_name',
    ];

    public function setMetaAttribute($value): void {}
    public function getMetaAttribute($value): array { return []; }
    public function setCompanyNameAttribute($value): void {}
    public function getCompanyNameAttribute(): string { return ''; }
    public function customer() {}
}

/**
 * @property int $id
 * @property string $status
 * @property int|null $parent_id
 * @property string|null $invoice_no
 * @property int|null $receipt_number
 * @property string|null $fulfillment_type
 * @property string|null $type
 * @property int $customer_id
 * @property string|null $payment_method
 * @property string|null $payment_method_title
 * @property string $payment_status
 * @property string $currency
 * @property float $subtotal
 * @property float $discount_tax
 * @property float $manual_discount_total
 * @property float $coupon_discount_total
 * @property float $shipping_tax
 * @property float $shipping_total
 * @property float $tax_total
 * @property string|null $tax_behavior
 * @property float $total_amount
 * @property float|null $rate
 * @property string|null $note
 * @property string|null $ip_address
 * @property string|null $completed_at
 * @property string|null $refunded_at
 * @property float $total_refund
 * @property string $uuid
 * @property float $total_paid
 * @property string|null $mode
 * @property string|null $shipping_status
 * @property array $config
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class Order extends Model
{
    protected $table = 'fct_orders';

    protected $fillable = [
        'status', 'parent_id', 'invoice_no', 'receipt_number', 'fulfillment_type',
        'type', 'customer_id', 'payment_method', 'payment_method_title',
        'payment_status', 'currency', 'subtotal', 'discount_tax',
        'manual_discount_total', 'coupon_discount_total', 'shipping_tax',
        'shipping_total', 'tax_total', 'tax_behavior', 'total_amount', 'rate',
        'note', 'ip_address', 'completed_at', 'refunded_at', 'total_refund',
        'uuid', 'created_at', 'total_paid', 'mode', 'shipping_status', 'config',
    ];

    public function setConfigAttribute($value): void {}
    public function getConfigAttribute($value): array { return []; }
    public function customer() {}
    public function order_items() {}
    public function transactions() {}
    public function subscriptions() {}
    public function appliedCoupons() {}
    public function shipping_address() {}
    public function billing_address() {}
    public function order_addresses() {}
    public function orderMeta() {}
    public function getMeta(string $metaKey, $defaultValue = false) { return $defaultValue; }
    public function updateMeta(string $metaKey, $value) {}
    public function deleteMeta(string $metaKey): int { return 0; }
    public function addLog(string $title, string $description = '', string $type = 'info', string $by = ''): void {}
    public function updateStatus(string $key, string $newStatus) { return $this; }
    public function updatePaymentStatus(string $newStatus) { return $this; }
    public function generateReceiptNumber() { return $this; }
}

/**
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string|null $name
 * @property string|null $address_1
 * @property string|null $address_2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $postcode
 * @property string|null $country
 * @property array $meta
 * @property-read string|null $email
 * @property-read string|null $first_name
 * @property-read string|null $last_name
 * @property-read string|null $full_name
 * @property-read array $formatted_address
 * @property-read string $company_name
 * @property-read string $phone
 * @property-read string $label
 */
class OrderAddress extends Model
{
    protected $table = 'fct_order_addresses';

    protected $fillable = [
        'id', 'order_id', 'type', 'name', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'meta',
    ];

    public function setMetaAttribute($value): void {}
    public function getMetaAttribute($value): array { return []; }
    public function setCompanyNameAttribute($value): void {}
    public function getCompanyNameAttribute(): string { return ''; }
    public function setPhoneAttribute($value): void {}
    public function getPhoneAttribute(): string { return ''; }
    public function setLabelAttribute($value): void {}
    public function getLabelAttribute(): string { return ''; }
    public function order() {}
}

/**
 * @property int $id
 * @property int $order_id
 * @property int|null $post_id
 * @property string|null $fulfillment_type
 * @property int $fulfilled_quantity
 * @property string|null $post_title
 * @property string|null $title
 * @property int|null $object_id
 * @property int|null $cart_index
 * @property int $quantity
 * @property float $unit_price
 * @property float $cost
 * @property float $subtotal
 * @property float $tax_amount
 * @property float $discount_total
 * @property float $refund_total
 * @property float $line_total
 * @property float|null $rate
 * @property array $other_info
 * @property array $line_meta
 * @property string|null $referrer
 * @property string|null $object_type
 * @property string|null $payment_type
 * @property string|null $created_at
 */
class OrderItem extends Model
{
    protected $table = 'fct_order_items';

    protected $fillable = [
        'order_id', 'post_id', 'fulfillment_type', 'fulfilled_quantity', 'post_title',
        'title', 'object_id', 'cart_index', 'quantity', 'unit_price', 'cost',
        'subtotal', 'tax_amount', 'discount_total', 'refund_total', 'line_total',
        'rate', 'other_info', 'line_meta', 'referrer', 'object_type', 'payment_type',
        'created_at',
    ];

    public function setOtherInfoAttribute($value): void {}
    public function getOtherInfoAttribute($value): array { return []; }
    public function setLineMetaAttribute($value): void {}
    public function getLineMetaAttribute($value): array { return []; }
    public function order() {}
    public function product() {}
    public function variants() {}
}

/**
 * @property int $id
 * @property int $order_id
 * @property string $meta_key
 * @property mixed $meta_value
 */
class OrderMeta extends Model
{
    protected $table = 'fct_order_meta';

    protected $fillable = ['order_id', 'meta_key', 'meta_value'];

    public function setMetaValueAttribute($value): void {}
    public function getMetaValueAttribute($value) { return $value; }
    public function order() {}
}

/**
 * @property int $id
 * @property int $order_id
 * @property string|null $order_type
 * @property string|null $vendor_charge_id
 * @property string|null $payment_method
 * @property string|null $payment_mode
 * @property string|null $payment_method_type
 * @property string $currency
 * @property string|null $transaction_type
 * @property int|null $subscription_id
 * @property string|null $card_last_4
 * @property string|null $card_brand
 * @property string $status
 * @property int $total
 * @property float|null $rate
 * @property array $meta
 * @property string $uuid
 * @property string|null $created_at
 */
class OrderTransaction extends Model
{
    protected $table = 'fct_order_transactions';

    protected $fillable = [
        'order_id', 'order_type', 'vendor_charge_id', 'payment_method',
        'payment_mode', 'payment_method_type', 'currency', 'transaction_type',
        'subscription_id', 'card_last_4', 'card_brand', 'status', 'total',
        'rate', 'meta', 'uuid', 'created_at',
    ];

    public function setMetaAttribute($value): void {}
    public function getMetaAttribute($value): array { return []; }
    public function order() {}
    public function subscription() {}
    public function updateStatus(string $newStatus, array $otherData = []) { return $this; }
    public static function bulkDeleteByOrderIds(array $ids, array $params = []): int { return 0; }
}

/**
 * @property int $id
 * @property int|null $order_id
 * @property int|null $coupon_id
 * @property string|null $code
 * @property int|null $amount
 */
class AppliedCoupon extends Model
{
    protected $table = 'fct_applied_coupons';

    protected $fillable = ['order_id', 'coupon_id', 'code', 'amount'];

    public function order() {}
    public function coupon() {}
}

/**
 * @property int $id
 * @property int $customer_id
 * @property int $parent_order_id
 * @property int $product_id
 * @property string|null $item_name
 * @property int|null $variation_id
 * @property string|null $billing_interval
 * @property int $signup_fee
 * @property int $quantity
 * @property int $recurring_amount
 * @property int $recurring_tax_total
 * @property int $recurring_total
 * @property int $bill_times
 * @property int $bill_count
 * @property string|null $expire_at
 * @property string|null $trial_ends_at
 * @property string|null $canceled_at
 * @property string|null $restored_at
 * @property string|null $collection_method
 * @property int $trial_days
 * @property string|null $vendor_customer_id
 * @property string|null $vendor_plan_id
 * @property string|null $vendor_subscription_id
 * @property string|null $next_billing_date
 * @property string $status
 * @property string|null $original_plan
 * @property string|null $vendor_response
 * @property string|null $current_payment_method
 * @property array $config
 * @property string $uuid
 * @property string|null $created_at
 * @property string|null $updated_at
 */
class Subscription extends Model
{
    protected $table = 'fct_subscriptions';

    protected $fillable = [
        'customer_id', 'parent_order_id', 'product_id', 'item_name', 'variation_id',
        'billing_interval', 'signup_fee', 'quantity', 'recurring_amount',
        'recurring_tax_total', 'recurring_total', 'bill_times', 'bill_count',
        'expire_at', 'trial_ends_at', 'canceled_at', 'restored_at', 'collection_method',
        'trial_days', 'vendor_customer_id', 'vendor_plan_id', 'vendor_subscription_id',
        'next_billing_date', 'status', 'original_plan', 'vendor_response',
        'current_payment_method', 'config',
    ];

    public function setConfigAttribute($value): void {}
    public function getConfigAttribute($value): array { return []; }
    public function customer() {}
    public function order() {}
    public function product() {}
    public function variation() {}
    public function transactions() {}
    public function meta() {}
    public function getMeta(string $metaKey, $default = null) { return $default; }
    public function updateMeta(string $metaKey, $metaValue): bool { return true; }
    public function hasAccessValidity(): bool { return false; }
    public function addLog(string $title, string $description = '', string $type = 'info', string $by = ''): void {}
}

/**
 * @property int $id
 * @property int|null $parent
 * @property string $title
 * @property string $code
 * @property string $status
 * @property string|null $type
 * @property array $conditions
 * @property int|null $amount
 * @property int|null $stackable
 * @property int|null $priority
 * @property int $use_count
 * @property string|null $notes
 * @property int|null $show_on_checkout
 * @property string|null $start_date
 * @property string|null $end_date
 * @property array $settings
 * @property array $other_info
 * @property array $categories
 * @property array $products
 */
class Coupon extends Model
{
    protected $table = 'fct_coupons';

    protected $fillable = [
        'parent', 'title', 'code', 'status', 'type', 'conditions', 'amount',
        'stackable', 'priority', 'use_count', 'notes', 'show_on_checkout',
        'start_date', 'end_date',
    ];

    public function setConditionsAttribute($value): void {}
    public function getConditionsAttribute($value): array { return []; }
    public function setSettingsAttribute($value): void {}
    public function getSettingsAttribute($value) { return $value; }
    public function setOtherInfoAttribute($value): void {}
    public function getOtherInfoAttribute($value): array { return []; }
    public function setCategoriesAttribute($value): void {}
    public function getCategoriesAttribute($value): array { return []; }
    public function setProductsAttribute($value): void {}
    public function getProductsAttribute($value): array { return []; }
    public function appliedCoupons() {}
    public function orders() {}
    public function getMeta(string $metaKey, $default = null) { return $default; }
    public function updateMeta(string $metaKey, $metaValue) {}
}

/**
 * @property int $id
 * @property int $post_id
 * @property string|null $fulfillment_type
 * @property float $min_price
 * @property float $max_price
 * @property int|null $default_variation_id
 * @property string|null $variation_type
 * @property int|null $stock_availability
 * @property array|null $other_info
 * @property array|null $default_media
 * @property int|null $manage_stock
 * @property int|null $manage_downloadable
 */
class ProductDetail extends Model
{
    protected $table = 'fct_product_details';

    protected $fillable = [
        'post_id', 'fulfillment_type', 'min_price', 'max_price',
        'default_variation_id', 'variation_type', 'stock_availability',
        'other_info', 'default_media', 'manage_stock', 'manage_downloadable',
    ];

    public function setOtherInfoAttribute($value): void {}
    public function getOtherInfoAttribute($value): ?array { return null; }
    public function setDefaultMediaAttribute($value): void {}
    public function getDefaultMediaAttribute($value): ?array { return null; }
    public function product() {}
    public function variants() {}
    public function attrMap() {}
}

/**
 * @property int $id
 * @property int $post_id
 * @property int|null $media_id
 * @property int $serial_index
 * @property int $sold_individually
 * @property string|null $variation_title
 * @property string|null $variation_identifier
 * @property string|null $sku
 * @property int|null $manage_stock
 * @property string|null $payment_type
 * @property string|null $stock_status
 * @property int $backorders
 * @property int $total_stock
 * @property int $available
 * @property int $committed
 * @property int $on_hold
 * @property string|null $fulfillment_type
 * @property string|null $item_status
 * @property int|null $manage_cost
 * @property float $item_price
 * @property float $item_cost
 * @property float $compare_price
 * @property array $other_info
 * @property int|null $downloadable
 * @property string|null $shipping_class
 */
class ProductVariation extends Model
{
    protected $table = 'fct_product_variations';

    protected $fillable = [
        'post_id', 'media_id', 'serial_index', 'sold_individually',
        'variation_title', 'variation_identifier', 'sku', 'manage_stock',
        'payment_type', 'stock_status', 'backorders', 'total_stock',
        'available', 'committed', 'on_hold', 'fulfillment_type', 'item_status',
        'manage_cost', 'item_price', 'item_cost', 'compare_price', 'other_info',
        'downloadable', 'shipping_class',
    ];

    public function getOtherInfoAttribute($value): array { return []; }
    public function product() {}
    public function product_detail() {}
    public function media() {}
    public function order_items() {}
    public function attrMap() {}
}

/**
 * @property int $id
 * @property int $post_id
 * @property array $product_variation_id
 * @property string|null $download_identifier
 * @property string|null $title
 * @property string|null $type
 * @property string|null $driver
 * @property string|null $file_name
 * @property string|null $file_path
 * @property string|null $file_url
 * @property int|null $file_size
 * @property array|string|null $settings
 * @property int|null $serial
 */
class ProductDownload extends Model
{
    protected $table = 'fct_product_downloads';

    protected $fillable = [
        'post_id', 'product_variation_id', 'download_identifier', 'title',
        'type', 'driver', 'file_name', 'file_path', 'file_url', 'file_size',
        'settings', 'serial',
    ];

    public function setSettingsAttribute($value): void {}
    public function getSettingsAttribute($value) { return $value; }
    public function setProductVariationIdAttribute($value): void {}
    public function getProductVariationIdAttribute($value): array { return []; }
    public function product() {}
}

/**
 * @property int $id
 * @property int $object_id
 * @property string $object_type
 * @property string $meta_key
 * @property mixed $meta_value
 */
class ProductMeta extends Model
{
    protected $table = 'fct_product_meta';

    protected $fillable = ['object_id', 'object_type', 'meta_key', 'meta_value'];

    public function setMetaValueAttribute($value): void {}
    public function getMetaValueAttribute($value) { return $value; }
}

/**
 * @property int $id
 * @property string $title
 * @property string|null $slug
 * @property string|null $description
 * @property array|string|null $settings
 */
class AttributeGroup extends Model
{
    protected $table = 'fct_atts_groups';

    protected $fillable = ['title', 'slug', 'description', 'settings'];

    public function setSettingsAttribute($value): void {}
    public function getSettingsAttribute($value) { return $value; }
    public function terms() {}
}

/**
 * @property int $id
 * @property int $group_id
 * @property int|null $serial
 * @property string $title
 * @property string|null $slug
 * @property string|null $description
 * @property array|string|null $settings
 */
class AttributeTerm extends Model
{
    protected $table = 'fct_atts_terms';

    protected $fillable = ['group_id', 'serial', 'title', 'slug', 'description', 'settings'];

    public function setSettingsAttribute($value): void {}
    public function getSettingsAttribute($value) { return $value; }
    public function group() {}
}

/**
 * @property int $id
 * @property int $group_id
 * @property int $term_id
 * @property int $object_id
 */
class AttributeRelation extends Model
{
    protected $table = 'fct_atts_relations';

    protected $fillable = ['group_id', 'term_id', 'object_id'];

    public function group() {}
    public function term() {}
    public function productDetails() {}
}
