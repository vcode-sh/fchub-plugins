<?php

declare(strict_types=1);

namespace CartShift\Support\Enums;

defined('ABSPATH') || exit;

/**
 * Machine-readable reasons a record was skipped, downgraded or failed.
 *
 * The migration log has always carried a human sentence and nothing else, which
 * is fine for one row and useless for four thousand. Prose cannot be counted,
 * grouped or explained: a shop with 4,000 orders skipped because customers were
 * never migrated sees 4,000 nearly-identical sentences and no clue that there is
 * one cause and one fix. The code is that cause, in a form a machine can group
 * by and a UI can turn into "4,000 x Customer not migrated — migrate customers
 * before orders".
 *
 * The code is additive. The human message stays exactly as it was, because the
 * message carries the specifics (which SKU, which coupon code, which ID) that a
 * fixed vocabulary never can.
 *
 * Every case here corresponds to a failure the plugin can actually produce
 * today. Nothing is speculative — each was read off a real write() call site in
 * app/Migrator, app/Domain/Mapping or app/Domain/Migration. If a case ever stops
 * being reachable, delete it here rather than leaving a code the UI offers as a
 * filter that can only ever return nothing.
 */
enum MigrationErrorCode: string
{
    /** The record already has an ID map row from an earlier run. */
    case AlreadyMigrated = 'already_migrated';

    /**
     * A record with the same natural key (email, coupon code, term name) was
     * already in FluentCart. Mapped to the existing record rather than creating
     * a duplicate.
     */
    case AlreadyExistsInFluentCart = 'already_exists_in_fluentcart';

    /** An order or subscription references a customer with no ID map entry. */
    case CustomerNotFound = 'customer_not_found';

    /**
     * The order's customer was never migrated, so the buyer was rebuilt from the
     * billing details on the order itself. The order — and its revenue — came
     * across intact.
     */
    case CustomerRebuiltFromOrder = 'customer_rebuilt_from_order';

    /**
     * A subscription line item references a product with no ID map entry.
     *
     * Retained for log rows written before 1.2.1, when that outcome was a skip.
     * A subscription in that position is now migrated paused and coded
     * SubscriptionPausedMissingProduct instead.
     */
    case ProductNotMapped = 'product_not_mapped';

    /**
     * A subscription line item references a variation with no ID map entry.
     *
     * Retained for log rows written before 1.2.1. See ProductNotMapped.
     */
    case VariationNotMapped = 'variation_not_mapped';

    /**
     * An order line item's product was not migrated. The item keeps its name and
     * price — the money still adds up — but it links to no product page.
     */
    case ProductLinkMissing = 'product_link_missing';

    /**
     * An order line item's *product* resolved but its variation did not, so the
     * line was written with `object_id = 0`.
     *
     * Quieter than ProductLinkMissing and more expensive to leave unsaid.
     * FluentCart's product reporting groups by `object_id`
     * (ProductReportService), so every zeroed line across every product
     * collapses into one nameless bucket and the product's per-variant sales
     * disappear — while the order detail page still shows the right name and the
     * right money, so nothing looks wrong.
     */
    case VariationLinkMissing = 'variation_link_missing';

    /** The WooCommerce product type has no FluentCart equivalent. */
    case UnsupportedProductType = 'unsupported_product_type';

    /** The SKU was taken, so a suffixed one was used instead. */
    case SkuCollision = 'sku_collision';

    /** The coupon has no code at all, so there is nothing to migrate. */
    case CouponCodeMissing = 'coupon_code_missing';

    /** The coupon code is longer than FluentCart allows. */
    case CouponCodeTooLong = 'coupon_code_too_long';

    /** The coupon code is already used by a FluentCart coupon. */
    case CouponCodeCollision = 'coupon_code_collision';

    /** A third-party discount type with no FluentCart mapping. */
    case UnknownCouponType = 'unknown_coupon_type';

    /**
     * Every ID in a coupon restriction was lost, which FluentCart reads as "no
     * restriction". The coupon was migrated disabled rather than shop-wide.
     */
    case CouponDisabledMissingRestrictions = 'coupon_disabled_missing_restrictions';

    /** Some coupon restriction IDs were lost, so the coupon covers less than it did. */
    case CouponRestrictionsNarrowed = 'coupon_restrictions_narrowed';

    /** WooCommerce subscription with several items; FluentCart takes only one. */
    case MultiItemSubscription = 'multi_item_subscription';

    /**
     * The subscription's product was not migrated, so it came across paused
     * rather than being dropped. The subscriber and the billing history survive
     * and nothing charges until a human decides.
     */
    case SubscriptionPausedMissingProduct = 'subscription_paused_missing_product';

    /**
     * The subscription came across with nothing in `variation_id`, so it was
     * paused rather than left billing against no variant.
     *
     * Distinct from SubscriptionPausedMissingProduct because the causes and the
     * fixes are different: there the product never migrated, here the product is
     * fine and the line has no resolvable variation — a line item with no
     * product ID at all, a subscription with no items, or a filter that emptied
     * the mapped payload. FluentCart reads a null `variation_id` as "no
     * downloads" (Subscription::getDownloads()), hides the upgrade path
     * (canUpgrade()), and stamps every renewal invoice with a null `object_id`
     * and a blank line title (RenewalService::createRenewalOrders()).
     */
    case SubscriptionPausedMissingVariation = 'subscription_paused_missing_variation';

    /** Subscription gateway with no vendor ID mapping defined. */
    case UnmappedSubscriptionGateway = 'unmapped_subscription_gateway';

    /** WooCommerce catalog-only or search-only visibility, which FC lacks. */
    case PartialCatalogVisibility = 'partial_catalog_visibility';

    /** The WP user row behind a customer has gone. */
    case UserNotFound = 'user_not_found';

    /** A guest customer with no order to build the customer record from. */
    case NoOrderForGuest = 'no_order_for_guest';

    /** An order with no line items, which FluentCart will not accept. */
    case OrderHasNoItems = 'order_has_no_items';

    /** A product whose name is empty, which FluentCart will not accept. */
    case EmptyProductName = 'empty_product_name';

    /**
     * The product mapped to zero variations, so a dry run reports it as a
     * product that would arrive in FluentCart with nothing to sell.
     */
    case NoVariationsMapped = 'no_variations_mapped';

    /** A user with no email address, which FluentCart will not accept. */
    case MissingEmail = 'missing_email';

    /** wp_insert_term() refused to create a category, brand or shipping class. */
    case TermCreationFailed = 'term_creation_failed';

    /** wp_insert_post() refused to create the product post. */
    case ProductCreationFailed = 'product_creation_failed';

    /** A dry run threw while validating a record. */
    case DryRunValidationFailed = 'dry_run_validation_failed';

    /** A record threw something the migrator did not anticipate. */
    case UnexpectedException = 'unexpected_exception';

    /** The whole run died, not just one record. */
    case MigrationAborted = 'migration_aborted';

    /**
     * The scope the owner chose resolves to more records than CartShift will
     * hold in one closure. Nothing was migrated.
     *
     * Deliberately a refusal rather than a truncation: migrating a subset of
     * what was confirmed is the exact class of silent loss this release exists
     * to prevent.
     */
    case ScopeClosureTooLarge = 'scope_closure_too_large';

    /**
     * A `link` decision's FluentCart target was trashed or deleted between the
     * mapping screen and the run. Promotion falls back to creating the
     * WooCommerce product fresh, as if it had never been mapped.
     *
     * Doubles as the dedup key for MappingModule's dead-link logging: promote()
     * is idempotent and re-reports the same dead ids on every batch tick, so
     * MigrationLogRepository::hasEntryFor() checks this exact code before a
     * second warning for the same product is ever written.
     */
    case MappedFcProductMissing = 'mapped_fc_product_missing';

    /**
     * A Woo variation with no counterpart on the linked FluentCart product
     * could not have one added — the target turned out to be an
     * advanced-variation product, whose variants FluentCart regenerates and
     * prunes on every combination save, or the INSERT itself failed.
     *
     * The link still stands and every variation that did pair up resolves
     * normally; only this one variation's order lines are left unresolvable.
     * Logged rather than fatal for that reason: refusing the whole link would
     * duplicate a product the owner deliberately built by hand.
     */
    case OrphanVariantNotCreated = 'orphan_variant_not_created';

    /**
     * A `link` decision mapped a Woo variation onto a FluentCart variant that
     * belongs to some other product. The mapping was dropped rather than
     * promoted.
     *
     * `fct_product_variations.id` is a global auto-increment and FluentCart has
     * no foreign key from `fct_order_items.object_id` back to it, so a stale
     * decision — the owner tidied their product between mapping and running —
     * or a hand-made POST would otherwise attach this product's order lines to
     * someone else's variant, silently and permanently.
     */
    case MappedVariantNotOnProduct = 'mapped_variant_not_on_product';

    /**
     * The WooCommerce product carries downloadable files and the FluentCart
     * product it was linked to carries none.
     *
     * A mapped product is skipped by ProductMigrator, so its downloads are never
     * migrated — deliberately, because the linked product is the owner's and
     * writing files into it is exactly the unrequested write mapping exists to
     * avoid. The consequence lands on the customer rather than the owner: the
     * order page, the receipt and the paid/shipped emails all read
     * Order::getDownloads(), which finds nothing.
     */
    case MappedProductHasNoDownloads = 'mapped_product_has_no_downloads';

    /**
     * A `link` decision exists for a WooCommerce product this run's scope does
     * not select, so promotion left it alone.
     *
     * The staging table outlives a run: decisions drafted against one selection
     * are still sitting there when the owner runs a narrower one. Promoting
     * them regardless created variants inside FluentCart products the run never
     * migrated, and filed them under its migration id — so rolling the run back
     * deleted variants it had no business creating.
     *
     * Info, not a warning. Nothing is broken and nothing is lost: the decision
     * stays in the staging table and a wider run promotes it. It is recorded so
     * that a link the owner drafted and then did not see happen is
     * distinguishable from one that failed.
     */
    case MappedProductOutOfScope = 'mapped_product_out_of_scope';

    /**
     * MySQL rejected a statement CartShift sent through `$wpdb` directly.
     *
     * `$wpdb` does not throw. It records the failure in `$wpdb->last_error`,
     * returns false, and leaves the caller none the wiser — so a write that
     * never landed looked exactly like one that did, and the orchestrator's
     * error counter, which only ever counted thrown exceptions, reported zero.
     * A real run wrote ten `Unknown column 'item_count'` lines to the PHP error
     * log and finished with "Success: Migration complete. 25 migrated, 2
     * skipped", which is how that column survived unnoticed for as long as it
     * did.
     *
     * FluentCart's own models throw on failure and are caught per record by
     * MigrationOrchestrator::processBatch(); this code exists for the writes
     * that bypass them. The message carries the MySQL error verbatim, because
     * that string is the only thing that says which column or constraint.
     */
    case DatabaseWriteFailed = 'database_write_failed';

    /**
     * Human-readable summary. Short enough to be a group heading in the log UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::AlreadyMigrated             => __('Already migrated', 'cartshift'),
            self::AlreadyExistsInFluentCart   => __('Already exists in FluentCart', 'cartshift'),
            self::CustomerNotFound            => __('Customer not migrated', 'cartshift'),
            self::CustomerRebuiltFromOrder    => __('Buyer rebuilt from the order', 'cartshift'),
            self::ProductNotMapped            => __('Product not migrated', 'cartshift'),
            self::VariationNotMapped          => __('Variation not migrated', 'cartshift'),
            self::ProductLinkMissing          => __('Order items link to no product', 'cartshift'),
            self::VariationLinkMissing        => __('Order items link to no variant', 'cartshift'),
            self::UnsupportedProductType      => __('Unsupported product type', 'cartshift'),
            self::SkuCollision                => __('SKU already taken', 'cartshift'),
            self::CouponCodeMissing           => __('Coupon has no code', 'cartshift'),
            self::CouponCodeTooLong           => __('Coupon code too long', 'cartshift'),
            self::CouponCodeCollision         => __('Coupon code already taken', 'cartshift'),
            self::UnknownCouponType           => __('Unrecognised discount type', 'cartshift'),
            self::CouponDisabledMissingRestrictions => __('Coupon disabled: restrictions lost', 'cartshift'),
            self::CouponRestrictionsNarrowed  => __('Coupon restrictions partly lost', 'cartshift'),
            self::MultiItemSubscription       => __('Multi-item subscription truncated', 'cartshift'),
            self::SubscriptionPausedMissingProduct => __('Subscription paused: product not migrated', 'cartshift'),
            self::SubscriptionPausedMissingVariation => __('Subscription paused: no variant to bill for', 'cartshift'),
            self::UnmappedSubscriptionGateway => __('Unmapped payment gateway', 'cartshift'),
            self::PartialCatalogVisibility    => __('Partial catalog visibility lost', 'cartshift'),
            self::UserNotFound                => __('WordPress user missing', 'cartshift'),
            self::NoOrderForGuest             => __('No order for guest customer', 'cartshift'),
            self::OrderHasNoItems             => __('Order has no items', 'cartshift'),
            self::EmptyProductName            => __('Product name is empty', 'cartshift'),
            self::NoVariationsMapped          => __('Product has no variations', 'cartshift'),
            self::MissingEmail                => __('No email address', 'cartshift'),
            self::TermCreationFailed          => __('Could not create term', 'cartshift'),
            self::ProductCreationFailed       => __('Could not create product', 'cartshift'),
            self::DryRunValidationFailed      => __('Dry-run validation failed', 'cartshift'),
            self::UnexpectedException         => __('Unexpected error', 'cartshift'),
            self::MigrationAborted            => __('Migration aborted', 'cartshift'),
            self::ScopeClosureTooLarge        => __('Selection is too large', 'cartshift'),
            self::MappedFcProductMissing      => __('Mapped FluentCart product missing', 'cartshift'),
            self::OrphanVariantNotCreated     => __('Variant could not be added', 'cartshift'),
            self::MappedVariantNotOnProduct   => __('Mapped variant is on another product', 'cartshift'),
            self::MappedProductHasNoDownloads => __('Linked product has no files', 'cartshift'),
            self::MappedProductOutOfScope     => __('Mapped product is outside this run', 'cartshift'),
            self::DatabaseWriteFailed         => __('Database rejected a write', 'cartshift'),
        };
    }

    /**
     * What the user should do about it. One sentence, imperative where possible,
     * and honest when the answer is "nothing".
     */
    public function hint(): string
    {
        return match ($this) {
            self::AlreadyMigrated => __(
                'Nothing to do. The record was migrated by an earlier run and was not duplicated.',
                'cartshift',
            ),
            self::AlreadyExistsInFluentCart => __(
                'Nothing to do. The existing FluentCart record was reused instead of creating a duplicate.',
                'cartshift',
            ),
            self::CustomerNotFound => __(
                'Migrate customers before orders and subscriptions, then re-run.',
                'cartshift',
            ),
            self::CustomerRebuiltFromOrder => __(
                'Nothing to do. The order kept its revenue and the buyer was recreated from the billing details on the order. Merge the buyer with an existing customer if you migrate that customer later.',
                'cartshift',
            ),
            self::ProductNotMapped => __(
                'Migrate products before subscriptions, then re-run.',
                'cartshift',
            ),
            self::VariationNotMapped => __(
                'Migrate products (including variations) before subscriptions, then re-run.',
                'cartshift',
            ),
            self::ProductLinkMissing => __(
                'The order still shows what was bought and what it cost; those items just do not link to a product page. Migrate the missing products and re-run to restore the links.',
                'cartshift',
            ),
            self::VariationLinkMissing => __(
                'The order shows the right item at the right price, but it points at no variant — so per-variant sales reporting will not count it. Migrate the missing variation, or map it on the mapping screen, then re-run.',
                'cartshift',
            ),
            self::UnsupportedProductType => __(
                'FluentCart has no equivalent for this product type. Recreate it by hand if you need it.',
                'cartshift',
            ),
            self::SkuCollision => __(
                'A suffixed SKU was used. Rename it in FluentCart if the generated SKU matters to you.',
                'cartshift',
            ),
            self::CouponCodeMissing => __(
                'Give the coupon a code in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::CouponCodeTooLong => __(
                'Shorten the coupon code in WooCommerce, then re-run. It was skipped rather than truncated.',
                'cartshift',
            ),
            self::CouponCodeCollision => __(
                'Rename either coupon so the codes differ, then re-run.',
                'cartshift',
            ),
            self::UnknownCouponType => __(
                'The coupon was migrated as a fixed 0 discount. Set the amount by hand in FluentCart.',
                'cartshift',
            ),
            self::CouponDisabledMissingRestrictions => __(
                'None of the restricted products or categories were migrated, so the coupon would have discounted the whole shop. Restore the restrictions from the coupon notes, then set it back to active.',
                'cartshift',
            ),
            self::CouponRestrictionsNarrowed => __(
                'The coupon is active but covers fewer products than in WooCommerce. Add the missing products or categories from the coupon notes.',
                'cartshift',
            ),
            self::MultiItemSubscription => __(
                'Only the first item was migrated. Add the remaining items in FluentCart by hand.',
                'cartshift',
            ),
            self::SubscriptionPausedMissingProduct => __(
                'The subscriber and their billing history came across, but nothing will be charged while the subscription is paused. Migrate the product, point the subscription at it, then resume it.',
                'cartshift',
            ),
            self::SubscriptionPausedMissingVariation => __(
                'The subscriber and their billing history came across, but nothing will be charged while the subscription is paused. Point it at a product variant in FluentCart, then resume it — an active subscription with no variant bills the customer for a blank line and hands them no downloads.',
                'cartshift',
            ),
            self::UnmappedSubscriptionGateway => __(
                'The vendor ID fields were left empty. Reconnect the subscription to its gateway by hand.',
                'cartshift',
            ),
            self::PartialCatalogVisibility => __(
                'The product stayed published. FluentCart cannot hide a product from only the catalog or only search.',
                'cartshift',
            ),
            self::UserNotFound => __(
                'The WordPress user was deleted. Nothing can be migrated for it.',
                'cartshift',
            ),
            self::NoOrderForGuest => __(
                'The guest has no order to build a customer record from. Nothing to migrate.',
                'cartshift',
            ),
            self::OrderHasNoItems => __(
                'Add a line item to the order in WooCommerce, or leave it behind.',
                'cartshift',
            ),
            self::EmptyProductName => __(
                'Give the product a name in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::NoVariationsMapped => __(
                'A variable product with no published variations has nothing to sell. Add a variation in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::MissingEmail => __(
                'Give the user an email address in WordPress, then re-run.',
                'cartshift',
            ),
            self::TermCreationFailed => __(
                'Check the log message for the WordPress error, fix the term in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::ProductCreationFailed => __(
                'Check the log message for the WordPress error, then re-run.',
                'cartshift',
            ),
            self::DryRunValidationFailed => __(
                'The record would fail a real migration. Fix it in WooCommerce before running for real.',
                'cartshift',
            ),
            self::UnexpectedException => __(
                'Check the log message. Re-run once the cause is fixed; migrated records are not duplicated.',
                'cartshift',
            ),
            self::MigrationAborted => __(
                'The run stopped. Check the log message, then start a new migration or roll this one back.',
                'cartshift',
            ),
            self::ScopeClosureTooLarge => __(
                'Narrow the selection — pick fewer products or customers, or use "Everything from a date" instead — then try again. Nothing was migrated.',
                'cartshift',
            ),
            self::MappedFcProductMissing => __(
                'The linked FluentCart product no longer exists — trashed or deleted after it was chosen on the mapping screen. The WooCommerce product was created fresh instead; relink or merge them by hand if that is not what you wanted.',
                'cartshift',
            ),
            self::OrphanVariantNotCreated => __(
                'Add the missing variant to the linked FluentCart product by hand, then run the migration again. Products using Advanced Variations cannot take one: FluentCart rebuilds their variants from the attribute options and would delete it.',
                'cartshift',
            ),
            self::MappedVariantNotOnProduct => __(
                'The variant chosen on the mapping screen is not on the product it was mapped under — most likely it was deleted or moved afterwards. Open the mapping screen, pick the variant again, then re-run.',
                'cartshift',
            ),
            self::MappedProductHasNoDownloads => __(
                'Attach the files to the linked FluentCart product yourself. CartShift will not write them into a product you built by hand, and until they are there every migrated order for it shows the customer no files.',
                'cartshift',
            ),
            self::MappedProductOutOfScope => __(
                'Nothing to do. The product is mapped to a FluentCart product, but this run\'s selection does not include it, so the link was left for a later run. Widen the selection and re-run if you meant to include it.',
                'cartshift',
            ),
            self::DatabaseWriteFailed => __(
                'The database refused part of this record, so some of it is missing. The log message carries the MySQL error verbatim — send it on if it names a column or a constraint you do not recognise. Fix the cause, then roll back and re-run: a half-written record is not repaired by running again.',
                'cartshift',
            ),
        };
    }

    /**
     * How much the user should care. See MigrationErrorSeverity — this is not the
     * log row's status.
     */
    public function severity(): MigrationErrorSeverity
    {
        return match ($this) {
            self::AlreadyMigrated,
            self::AlreadyExistsInFluentCart,
            self::MappedProductOutOfScope => MigrationErrorSeverity::Info,

            self::SkuCollision,
            self::UnknownCouponType,
            self::CouponDisabledMissingRestrictions,
            self::CouponRestrictionsNarrowed,
            self::CustomerRebuiltFromOrder,
            self::ProductLinkMissing,
            self::VariationLinkMissing,
            self::SubscriptionPausedMissingProduct,
            self::SubscriptionPausedMissingVariation,
            self::MultiItemSubscription,
            self::UnmappedSubscriptionGateway,
            self::PartialCatalogVisibility,
            self::MappedFcProductMissing,
            self::OrphanVariantNotCreated,
            self::MappedVariantNotOnProduct,
            self::MappedProductHasNoDownloads => MigrationErrorSeverity::Warning,

            self::CustomerNotFound,
            self::ProductNotMapped,
            self::VariationNotMapped,
            self::UnsupportedProductType,
            self::CouponCodeMissing,
            self::CouponCodeTooLong,
            self::CouponCodeCollision,
            self::UserNotFound,
            self::NoOrderForGuest,
            self::OrderHasNoItems,
            self::EmptyProductName,
            self::NoVariationsMapped,
            self::MissingEmail,
            self::TermCreationFailed,
            self::ProductCreationFailed,
            self::DryRunValidationFailed,
            self::UnexpectedException,
            self::MigrationAborted,
            self::DatabaseWriteFailed,
            self::ScopeClosureTooLarge => MigrationErrorSeverity::Error,
        };
    }

    /**
     * Where the fix lives, which is not always where the failure was raised.
     */
    public function category(): MigrationErrorCategory
    {
        return match ($this) {
            self::CustomerNotFound,
            self::CustomerRebuiltFromOrder,
            self::UserNotFound,
            self::NoOrderForGuest,
            self::MissingEmail => MigrationErrorCategory::Customer,

            self::ProductLinkMissing,
            self::VariationLinkMissing,
            self::ProductNotMapped,
            self::VariationNotMapped,
            self::UnsupportedProductType,
            self::SkuCollision,
            self::EmptyProductName,
            self::NoVariationsMapped,
            self::PartialCatalogVisibility,
            self::ProductCreationFailed,
            self::MappedFcProductMissing,
            self::OrphanVariantNotCreated,
            self::MappedVariantNotOnProduct,
            self::MappedProductHasNoDownloads,
            self::MappedProductOutOfScope => MigrationErrorCategory::Product,

            self::CouponCodeMissing,
            self::CouponCodeTooLong,
            self::CouponCodeCollision,
            self::UnknownCouponType,
            self::CouponDisabledMissingRestrictions,
            self::CouponRestrictionsNarrowed => MigrationErrorCategory::Coupon,

            self::OrderHasNoItems => MigrationErrorCategory::Order,

            self::MultiItemSubscription,
            self::SubscriptionPausedMissingProduct,
            self::SubscriptionPausedMissingVariation,
            self::UnmappedSubscriptionGateway => MigrationErrorCategory::Subscription,

            self::TermCreationFailed => MigrationErrorCategory::Taxonomy,

            self::AlreadyMigrated,
            self::AlreadyExistsInFluentCart,
            self::DryRunValidationFailed,
            self::UnexpectedException,
            self::MigrationAborted,
            self::DatabaseWriteFailed,
            self::ScopeClosureTooLarge => MigrationErrorCategory::System,
        };
    }

    /**
     * Everything the UI needs about one code, in one shape.
     *
     * @return array{code: string, label: string, hint: string, severity: string, category: string}
     */
    public function toArray(): array
    {
        return [
            'code'     => $this->value,
            'label'    => $this->label(),
            'hint'     => $this->hint(),
            'severity' => $this->severity()->value,
            'category' => $this->category()->value,
        ];
    }

    /**
     * Coerce whatever a caller has to a case, or null.
     *
     * Accepts a case (passed straight back), the string value, or null. Anything
     * else — an unknown string, an empty string — is null, so callers can hand
     * user input straight in without a guard.
     */
    public static function coerce(self|string|null $code): self|null
    {
        if ($code instanceof self) {
            return $code;
        }

        if ($code === null || $code === '') {
            return null;
        }

        return self::tryFrom($code);
    }

    /**
     * Every code as a UI-ready descriptor, keyed by code.
     *
     * @return array<string, array{code: string, label: string, hint: string, severity: string, category: string}>
     */
    public static function descriptors(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->toArray();
        }

        return $out;
    }
}
