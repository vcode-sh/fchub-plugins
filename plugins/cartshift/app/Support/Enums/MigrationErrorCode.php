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

    /**
     * A WooCommerce subscription with several line items. FluentCart's
     * subscription row carries one product/variation contract, so the record is
     * refused rather than migrated with the first item and a note about the
     * rest. "Only the first item" is data loss with a log entry attached.
     */
    case MultiItemSubscription = 'multi_item_subscription';

    /**
     * The subscription reached the writer without one of the references
     * `fct_subscriptions` declares NOT NULL: customer, parent order, product,
     * variation, item name or quantity (FluentCart 1.6.0,
     * database/Migrations/SubscriptionsMigrator.php).
     *
     * Nothing was written. CartShift used to flip such a record to `paused` and
     * create it anyway, which produced a row that bills against a blank line the
     * moment somebody presses resume — FluentCart reads a null `variation_id` as
     * "no downloads" (Subscription::getDownloads()), hides the upgrade path
     * (canUpgrade()), and stamps every renewal invoice with a null `object_id`
     * and a blank line title (RenewalService::createRenewalOrders()). Paused is
     * a lifecycle state, not a substitute for referential integrity.
     */
    case SubscriptionRequiredReferenceMissing = 'required_reference_missing';

    /**
     * A subscription in the selection sells a product this run will not migrate.
     *
     * Raised by ScopeConsequences as a *forecast* — "run this scope and these
     * subscriptions will not come across" — before anything is migrated. The
     * migrator no longer produces it: since the required-reference gate landed,
     * that subscription is blocked at the writer and coded
     * SubscriptionRequiredReferenceMissing instead. Retained for the forecast
     * and for log rows written before the gate.
     */
    case SubscriptionPausedMissingProduct = 'subscription_paused_missing_product';

    /**
     * The subscription resolved to no FluentCart variant.
     *
     * Retained for log rows written before the required-reference gate landed;
     * that outcome is now SubscriptionRequiredReferenceMissing. Kept rather than
     * deleted so a log written by an earlier release still renders its own
     * reason instead of a blank cell.
     */
    case SubscriptionPausedMissingVariation = 'subscription_paused_missing_variation';

    /**
     * Subscription gateway with no vendor ID mapping defined.
     *
     * Retained for log rows written before the payment strategies landed. The
     * mapper used to read vendor identifiers straight out of WooCommerce meta
     * and leave this note when it recognised none of them; who owns the next
     * charge is now the strategy registry's decision, and an unrecognised
     * gateway is `unsupported_gateway` — a refusal rather than a note.
     */
    case UnmappedSubscriptionGateway = 'unmapped_subscription_gateway';

    /**
     * The source bills on a cadence FluentCart cannot express.
     *
     * `week/2`, `year/2`, `month/2` and `month/12` are real WooCommerce
     * Subscriptions schedules with no FluentCart equivalent. CartShift used to
     * collapse them — every year multiplier to yearly, every month multiplier
     * other than 3 or 6 to monthly — which bills a customer on a schedule they
     * never agreed to. Plan section 7.2's table has six rows and no fallback
     * arm; everything else blocks.
     */
    case SubscriptionUnsupportedBillingCadence = 'unsupported_billing_cadence';

    /**
     * An active subscription with no next payment date. Nothing owns its next
     * charge, and 360 of the 564 preserved Lapka records have no date at all —
     * which is exactly the population a reconciler is tempted to "help" by
     * inventing one.
     */
    case SubscriptionActiveNextDateMissing = 'active_next_date_missing';

    /** An active subscription whose next payment date has already passed. */
    case SubscriptionActiveNextDatePast = 'active_next_date_past';

    /**
     * A finite plan whose paid cycles have reached its term while the source
     * still calls it live. Either the status or the count is wrong, and picking
     * one is how somebody is billed once more than they agreed to.
     */
    case SubscriptionFiniteTermStateConflict = 'finite_term_state_conflict';

    /**
     * The source gateway has no migration strategy, so nobody can say who would
     * own the next charge. Plan section 8.1 step 6: blocked, not guessed into
     * one of the three supported buckets.
     */
    case SubscriptionUnsupportedGateway = 'unsupported_gateway';

    /**
     * The payment strategy could not certify a live mandate and the operator
     * has not accepted the alternative yet.
     *
     * One code for the whole family — an unverified vault, a store mode nobody
     * approved, a payment method the provider does not own. The log message
     * carries the exact section 9.4 codes; this is what the UI groups by, and
     * they all send the operator to the same screen.
     */
    case SubscriptionPaymentNotReady = 'subscription_payment_not_ready';

    /**
     * The subscription recorded no term of its own, so nobody can say how many
     * charges it has left.
     *
     * A refusal, not a note. Writing `bill_times = 0` would tell FluentCart to
     * bill forever on a question the source never answered, and it would also
     * disarm the finite-term conflict check, which only examines a positive
     * term. Deliberately not answered from the current product's
     * `_subscription_length` either: that value describes today's catalogue,
     * not what this subscriber agreed to, and substituting it is the plan's P1
     * defect.
     */
    case SubscriptionFiniteTermUndeclared = 'finite_term_undeclared';

    /**
     * The subscription recorded no term of its own, so the product's declared
     * length was used instead.
     *
     * Section 9.2 permits exactly this and requires it to be said out loud: the
     * current product describes today's catalogue, not what a subscriber agreed
     * to when they signed up. Migrated, and flagged.
     */
    case SubscriptionFiniteTermFromProduct = 'finite_term_from_product';

    /**
     * The subscription was renewing automatically, the change was accepted, and
     * it was migrated as a manual-invoice subscription.
     *
     * Its own case rather than sharing the refusal's, which it did for one
     * round: a record that staged successfully then logged under "Manual
     * renewal has not been accepted", with a hint reading "Nothing was
     * migrated", at blocking severity. A code a UI can group by is only worth
     * having if the group means one thing.
     */
    case SubscriptionManualRenewalAdopted = 'manual_renewal_adopted';

    /**
     * The subscription was renewing automatically and nobody has accepted that
     * FluentCart will invoice its customer instead.
     *
     * Its own case rather than a fall-through to `subscription_payment_not_ready`,
     * which was minted for provider faults. This is not a fault: it is a
     * decision nobody has taken yet, it is the reason an entire live cohort
     * stays where it is, and it needs an entry in the log a UI can group by and
     * a hint that names the decision.
     */
    case SubscriptionManualRenewalNotAccepted = 'manual_confirmation_required';

    /** The subscription has no billing email, so it identifies nobody. */
    case SubscriptionCustomerEmailMissing = 'customer_email_missing';

    /**
     * The resolved FluentCart variation is not on the resolved FluentCart
     * product.
     *
     * `fct_product_variations.id` is a global auto-increment and nothing links
     * `fct_order_items.object_id` back to it, so a stale mapping decision or a
     * hand-made POST can pair a product with another product's variant.
     * Section 9.3 requires the pairing to be checked before the subscription is
     * written, not merely to have been checked when the mapping was promoted.
     */
    case SubscriptionVariationNotOnProduct = 'target_variation_not_on_product';

    /**
     * The source row could not be decoded into a valid record at all.
     *
     * It stays in the counts rather than disappearing: a malformed record that
     * vanishes silently is a subscriber nobody goes looking for.
     */
    case SubscriptionInvalidSourceRecord = 'invalid_source_record';

    /**
     * The three paid-cycle counts disagree, so the subscription stayed paused.
     *
     * FluentCart recomputes `bill_count` from succeeded positive charge
     * transactions carrying the subscription's ID
     * (`Subscription::calculateBillCount()`), WooCommerce Subscriptions keeps
     * its own `get_payment_count()`, and the migrated history carries whatever
     * paid orders came across. When those three do not agree, writing any one
     * of them would be overwritten by the next recompute — and the difference
     * is somebody's billing history, not a rounding error.
     */
    case SubscriptionHistoryCountMismatch = 'history_count_mismatch';

    /**
     * A subscription's parent order was named and its payload was not carried.
     *
     * Section 6.2 is blunt about this: a reference is not an order. FluentCart
     * recomputes `bill_count` from succeeded charges, so a parent order that
     * arrived as a bare integer contributes nothing and the count is wrong in a
     * way nobody can see.
     */
    case SubscriptionDatasetMissingParentOrder = 'dataset_missing_parent_order';

    /** The same, for a renewal, switch or resubscribe order. */
    case SubscriptionDatasetMissingRelatedOrder = 'dataset_missing_related_order';

    /**
     * One order is claimed by two different subscription relationships.
     *
     * Section 6.2 refuses to break the tie, and so does CartShift: whichever
     * relationship happened to be read first would decide whether the order
     * becomes a FluentCart `renewal` or an ordinary purchase, and that decides
     * whether it counts towards somebody's paid cycles. The order migrates as a
     * plain checkout, which is the choice that claims nothing, and the dispute
     * is reported rather than left to be inferred from a type that is missing.
     */
    case SubscriptionAmbiguousOrderRelationship = 'dataset_ambiguous_order_relationship';

    /**
     * A package record's declared fingerprint is not the fingerprint of its own
     * payload — the line was edited, truncated or re-encoded after export.
     *
     * `SubscriptionMigrator::codeFor()` falls back to
     * `SubscriptionPaymentNotReady` for any section 9.4 code the log has no
     * heading for, which is right for the provider faults it was written for and
     * was applied to `reportInvalid()` too. So a tampered package record told the
     * operator "Payment ownership is not settled" and sent them off to check
     * their Stripe account, about a file that had been modified.
     */
    case SubscriptionDatasetChecksumMismatch = 'dataset_checksum_mismatch';

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
     * A `create` decision named a WooCommerce subscription product whose
     * billing cadence section 7.2's table has no row for, so no FluentCart
     * product was created for it and the product was dropped from the run.
     *
     * Creating it would write the NEAREST interval FluentCart can express,
     * which is a different contract from the one the subscriber agreed to —
     * every-6-weeks quietly becoming monthly is a 15% price rise nobody
     * authorised.
     *
     * This used to be reported through `Logger::error()` alone, which is PHP's
     * `error_log` and not the migration log, so the operator saw a mapping row
     * still offering "Create" and a run that silently skipped the product.
     */
    case SubscriptionCadenceUnrepresentable = 'subscription_cadence_unrepresentable';

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
            self::MultiItemSubscription       => __('Multi-item subscription blocked', 'cartshift'),
            self::SubscriptionRequiredReferenceMissing => __('Subscription is missing a required reference', 'cartshift'),
            self::SubscriptionPausedMissingProduct => __('Subscription sells a product this run leaves behind', 'cartshift'),
            self::SubscriptionPausedMissingVariation => __('Subscription had no variant to bill for', 'cartshift'),
            self::UnmappedSubscriptionGateway => __('Unmapped payment gateway', 'cartshift'),
            self::SubscriptionUnsupportedBillingCadence => __('Billing cadence has no FluentCart equivalent', 'cartshift'),
            self::SubscriptionActiveNextDateMissing => __('Active subscription with no next payment date', 'cartshift'),
            self::SubscriptionActiveNextDatePast => __('Active subscription whose next payment is overdue', 'cartshift'),
            self::SubscriptionFiniteTermStateConflict => __('Finite plan already paid to its term', 'cartshift'),
            self::SubscriptionUnsupportedGateway => __('Payment gateway is not supported', 'cartshift'),
            self::SubscriptionPaymentNotReady => __('Payment ownership is not settled', 'cartshift'),
            self::SubscriptionFiniteTermUndeclared => __('Subscription records no term of its own', 'cartshift'),
            self::SubscriptionCustomerEmailMissing => __('Subscription has no billing email', 'cartshift'),
            self::SubscriptionFiniteTermFromProduct => __('Term taken from the product, not the subscription', 'cartshift'),
            self::SubscriptionManualRenewalAdopted => __('Renewals move from the gateway to FluentCart invoices', 'cartshift'),
            self::SubscriptionManualRenewalNotAccepted => __('Manual renewal has not been accepted', 'cartshift'),
            self::SubscriptionVariationNotOnProduct => __('Variant belongs to another product', 'cartshift'),
            self::SubscriptionInvalidSourceRecord => __('Source record could not be read', 'cartshift'),
            self::SubscriptionHistoryCountMismatch => __('Paid-cycle counts disagree', 'cartshift'),
            self::SubscriptionDatasetMissingParentOrder => __('Parent order missing from the dataset', 'cartshift'),
            self::SubscriptionDatasetMissingRelatedOrder => __('Related order missing from the dataset', 'cartshift'),
            self::SubscriptionAmbiguousOrderRelationship => __('Order claimed by two subscription relationships', 'cartshift'),
            self::SubscriptionDatasetChecksumMismatch => __('Package record does not match its own checksum', 'cartshift'),
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
            self::SubscriptionCadenceUnrepresentable
                                              => __('Billing cadence cannot be expressed', 'cartshift'),
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
                'Nothing was migrated. A FluentCart subscription holds one product, so keeping the first item and dropping the rest would quietly halve what the customer pays for. Split the WooCommerce subscription into one subscription per product, then re-run.',
                'cartshift',
            ),
            self::SubscriptionRequiredReferenceMissing => __(
                'Nothing was migrated. FluentCart requires a customer, a parent order, a product, a variant, an item name and a quantity on every subscription, and this one is short of at least one of them — the log message says which. Migrate or map the missing piece, or fix the subscription in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::SubscriptionPausedMissingProduct => __(
                'This run will not bring the product those subscriptions sell, so they cannot be migrated at all. Add the product to the selection, or migrate products first, then re-run.',
                'cartshift',
            ),
            self::SubscriptionPausedMissingVariation => __(
                'Recorded by an earlier CartShift release, which migrated the subscription paused rather than refusing it. Point it at a product variant in FluentCart before resuming it — a subscription with no variant bills the customer for a blank line and hands them no downloads.',
                'cartshift',
            ),
            self::UnmappedSubscriptionGateway => __(
                'The vendor ID fields were left empty. Reconnect the subscription to its gateway by hand.',
                'cartshift',
            ),
            self::SubscriptionUnsupportedBillingCadence => __(
                'Nothing was migrated. FluentCart bills daily, weekly, monthly, quarterly, half-yearly or yearly, and this subscription bills on none of those. Collapsing it to the nearest one would charge the customer on a schedule they never agreed to, so change the source schedule to one FluentCart can hold, then re-run.',
                'cartshift',
            ),
            self::SubscriptionActiveNextDateMissing => __(
                'Nothing was migrated. The subscription is active but has no next payment date, so no part of FluentCart would own its next charge. Set the date in WooCommerce and export again, or cancel the subscription if it is no longer live.',
                'cartshift',
            ),
            self::SubscriptionActiveNextDatePast => __(
                'Nothing was migrated. The subscription is active and its next charge was due in the past, so it is not ready to be activated anywhere. Reconcile the outstanding renewal at the source first, then re-run.',
                'cartshift',
            ),
            self::SubscriptionFiniteTermStateConflict => __(
                'Nothing was migrated. The plan has a fixed number of payments, all of them have been taken, and the source still calls the subscription live. Decide which is right — close the subscription or correct the payment count — then re-run.',
                'cartshift',
            ),
            self::SubscriptionUnsupportedGateway => __(
                'Nothing was migrated. CartShift migrates standard Stripe, standard PayPal and manual renewals; this subscription uses something else, and guessing which of the three it resembles would decide how a real customer is charged. Switch it to manual renewal at the source, or wait for a strategy that supports this gateway.',
                'cartshift',
            ),
            self::SubscriptionPaymentNotReady => __(
                'Nothing was migrated yet. Who charges this customer next has not been settled — the log message names the exact reasons. Either verify the payment details against the target account, or accept that FluentCart will invoice the customer instead of charging them silently, then re-run.',
                'cartshift',
            ),
            self::SubscriptionFiniteTermUndeclared => __(
                'Nothing was migrated. Neither the subscription nor the product it sells says how many payments it runs for, and CartShift will not answer that for them — "unlimited" is a contract, not a default. Set the subscription length in WooCommerce, on the subscription or on its product, then run again. If the log says this export was made before CartShift read the product, export the source again with the current version instead.',
                'cartshift',
            ),
            self::SubscriptionCustomerEmailMissing => __(
                'Nothing was migrated. The subscription carries no billing email, which is the only thing identifying its owner across two sites. Add one in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::SubscriptionFiniteTermFromProduct => __(
                'The subscription does not say how many payments it runs for, so the length configured on the product was used. That describes what the product sells today, which is not always what an older subscriber signed up to — check it if the plan has changed since.',
                'cartshift',
            ),
            self::SubscriptionManualRenewalAdopted => __(
                'The subscription was migrated. WooCommerce was charging this customer automatically and FluentCart will raise an invoice for them instead, which is the change you accepted — nothing charges them off-session. Make sure the renewal emails say what you want them to say before the next invoice goes out.',
                'cartshift',
            ),
            self::SubscriptionManualRenewalNotAccepted => __(
                'Nothing was migrated. WooCommerce was charging this customer automatically, and FluentCart would raise an invoice for them instead. That is a change the customer will notice, so it has to be accepted before the subscription is created — accept it for this group of subscriptions, then run again.',
                'cartshift',
            ),
            self::SubscriptionVariationNotOnProduct => __(
                'Nothing was migrated. The variant this subscription would bill against sits on a different FluentCart product — most likely it was deleted or moved after the mapping was saved. Open the mapping screen, pick the variant again, then re-run.',
                'cartshift',
            ),
            self::SubscriptionInvalidSourceRecord => __(
                'Nothing was migrated. The source row could not be read as a subscription at all — the log message says which fields are missing or unreadable. Repair it in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::SubscriptionDatasetMissingParentOrder => __(
                'The subscription was staged and no order history was imported. The dataset names its parent order and does not carry the order itself, and a reference is not an order — FluentCart counts paid cycles from the charges on those orders, so importing around the hole would produce a payment count that is quietly wrong. Export the dataset again with the parent order included, then re-run.',
                'cartshift',
            ),
            self::SubscriptionDatasetMissingRelatedOrder => __(
                'The subscription was staged and no order history was imported. The dataset names a renewal, switch or resubscribe order it does not carry. Export the dataset again with that order included, then re-run.',
                'cartshift',
            ),
            self::SubscriptionDatasetChecksumMismatch => __(
                'Nothing was migrated for this record. Its declared fingerprint is not the fingerprint of the payload beside it, which means the package line was edited, truncated or re-encoded after it was exported. Do not repair the file by hand: export it again from the source and re-run.',
                'cartshift',
            ),
            self::SubscriptionAmbiguousOrderRelationship => __(
                'The order migrated, as an ordinary checkout. Two subscription relationships claim it — a renewal and a switch, say — and CartShift will not pick one: the choice decides whether the order counts towards a subscriber\'s paid cycles. Settle the relationship in WooCommerce, then re-run.',
                'cartshift',
            ),
            self::SubscriptionHistoryCountMismatch => __(
                'The subscription was staged and left paused, and its payment count was NOT written. WooCommerce, the imported history and FluentCart each counted a different number of paid cycles — the log message gives all three and names the orders involved. Usually a renewal order that did not come across. Migrate the missing orders, then re-run; picking a number here would only be overwritten the next time FluentCart recounted.',
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
            self::SubscriptionCadenceUnrepresentable => __(
                'FluentCart has no interval for this product\'s billing schedule, and creating it would write the nearest one instead — a different contract from the one the subscriber agreed to. Change the source schedule to one FluentCart can express, or link this product to a compatible FluentCart subscription variation by hand, then re-run.',
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
            self::SubscriptionPausedMissingVariation,
            self::UnmappedSubscriptionGateway,
            self::SubscriptionFiniteTermFromProduct,
            // Migrated, with a change the customer will notice at the next
            // renewal. A caveat on a record that exists, not a refusal.
            self::SubscriptionManualRenewalAdopted,
            // The subscription row exists — staged, paused, and honest about
            // its unknown payment count. That is a caveat rather than a
            // refusal: the owner has to go and look, not re-run a migration
            // that wrote nothing.
            self::SubscriptionHistoryCountMismatch,
            // Same shape one step earlier: the subscriber came across, their
            // invoices did not.
            self::SubscriptionDatasetMissingParentOrder,
            self::SubscriptionDatasetMissingRelatedOrder,
            // The order came across; only its relationship is unsettled.
            self::SubscriptionAmbiguousOrderRelationship,
            self::PartialCatalogVisibility,
            self::MappedFcProductMissing,
            self::OrphanVariantNotCreated,
            self::MappedVariantNotOnProduct,
            self::MappedProductHasNoDownloads => MigrationErrorSeverity::Warning,

            self::CustomerNotFound,
            self::ProductNotMapped,
            self::VariationNotMapped,
            // Blocked, not migrated-with-a-caveat. Both refuse to write a row.
            self::MultiItemSubscription,
            self::SubscriptionCadenceUnrepresentable,
            // The package line does not match its own fingerprint. Nothing is
            // written for that record, and the file is not repairable by hand.
            self::SubscriptionDatasetChecksumMismatch,
            self::SubscriptionRequiredReferenceMissing,
            self::SubscriptionPausedMissingProduct,
            self::SubscriptionUnsupportedBillingCadence,
            self::SubscriptionActiveNextDateMissing,
            self::SubscriptionActiveNextDatePast,
            self::SubscriptionFiniteTermStateConflict,
            self::SubscriptionUnsupportedGateway,
            self::SubscriptionPaymentNotReady,
            self::SubscriptionFiniteTermUndeclared,
            self::SubscriptionManualRenewalNotAccepted,
            self::SubscriptionVariationNotOnProduct,
            self::SubscriptionCustomerEmailMissing,
            self::SubscriptionInvalidSourceRecord,
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
            self::SubscriptionCustomerEmailMissing,
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
            self::SubscriptionCadenceUnrepresentable,
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
            self::SubscriptionRequiredReferenceMissing,
            self::SubscriptionPausedMissingProduct,
            self::SubscriptionPausedMissingVariation,
            self::UnmappedSubscriptionGateway,
            self::SubscriptionUnsupportedBillingCadence,
            self::SubscriptionActiveNextDateMissing,
            self::SubscriptionActiveNextDatePast,
            self::SubscriptionFiniteTermStateConflict,
            self::SubscriptionUnsupportedGateway,
            self::SubscriptionPaymentNotReady,
            self::SubscriptionFiniteTermUndeclared,
            self::SubscriptionFiniteTermFromProduct,
            self::SubscriptionManualRenewalAdopted,
            self::SubscriptionManualRenewalNotAccepted,
            self::SubscriptionVariationNotOnProduct,
            self::SubscriptionInvalidSourceRecord,
            self::SubscriptionHistoryCountMismatch,
            self::SubscriptionDatasetMissingParentOrder,
            self::SubscriptionDatasetMissingRelatedOrder,
            self::SubscriptionAmbiguousOrderRelationship,
            self::SubscriptionDatasetChecksumMismatch => MigrationErrorCategory::Subscription,

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
