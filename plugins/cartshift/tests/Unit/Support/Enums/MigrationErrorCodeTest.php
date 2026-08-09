<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Support\Enums;

use CartShift\Support\Enums\MigrationErrorCategory;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\Enums\MigrationErrorSeverity;
use CartShift\Tests\Unit\PluginTestCase;

final class MigrationErrorCodeTest extends PluginTestCase
{
    /**
     * The enum is the single source of truth, so a case with no explanation is a
     * case the UI cannot render. match() would throw on a missing arm; this walks
     * every case so that failure surfaces here rather than in front of a user
     * halfway through a migration.
     */
    public function testEveryCaseHasALabelAHintASeverityAndACategory(): void
    {
        foreach (MigrationErrorCode::cases() as $case) {
            $this->assertNotSame('', $case->label(), "{$case->value} has no label.");
            $this->assertNotSame('', $case->hint(), "{$case->value} has no hint.");
            $this->assertInstanceOf(MigrationErrorSeverity::class, $case->severity());
            $this->assertInstanceOf(MigrationErrorCategory::class, $case->category());
        }
    }

    /**
     * The value is what is written into the log and what the UI filters on, so it
     * is a wire format. Anything other than lower snake_case would be a nuisance
     * in a query string and inconsistent with the rest of them.
     */
    public function testEveryValueIsStableLowerSnakeCase(): void
    {
        foreach (MigrationErrorCode::cases() as $case) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $case->value);
        }
    }

    /**
     * A hint that only restates the label tells the user nothing they did not
     * already know from the row.
     */
    public function testHintsSayMoreThanTheLabel(): void
    {
        foreach (MigrationErrorCode::cases() as $case) {
            $this->assertNotSame($case->label(), $case->hint(), "{$case->value} hint is just the label.");
        }
    }

    /**
     * The codes the failure sites in app/Migrator, app/Domain/Mapping and
     * app/Domain/Migration actually produce. Pinned because the value of the
     * vocabulary is that it does not drift: renaming a case breaks every saved
     * filter and every log row already written with the old value.
     */
    public function testTheDerivedVocabularyIsPinned(): void
    {
        $expected = [
            'already_migrated',
            'already_exists_in_fluentcart',
            'customer_not_found',
            'customer_rebuilt_from_order',
            'product_not_mapped',
            'variation_not_mapped',
            'product_link_missing',
            'variation_link_missing',
            'unsupported_product_type',
            'sku_collision',
            'coupon_code_missing',
            'coupon_code_too_long',
            'coupon_code_collision',
            'unknown_coupon_type',
            'coupon_disabled_missing_restrictions',
            'coupon_restrictions_narrowed',
            'multi_item_subscription',
            'required_reference_missing',
            'subscription_paused_missing_product',
            'subscription_paused_missing_variation',
            'unmapped_subscription_gateway',
            'unsupported_billing_cadence',
            'active_next_date_missing',
            'active_next_date_past',
            'finite_term_state_conflict',
            'unsupported_gateway',
            'subscription_payment_not_ready',
            'finite_term_undeclared',
            'finite_term_from_product',
            'manual_renewal_adopted',
            'manual_confirmation_required',
            'customer_email_missing',
            'target_variation_not_on_product',
            'invalid_source_record',
            'history_count_mismatch',
            'dataset_missing_parent_order',
            'dataset_missing_related_order',
            'dataset_ambiguous_order_relationship',
            'dataset_checksum_mismatch',
            'partial_catalog_visibility',
            'user_not_found',
            'no_order_for_guest',
            'order_has_no_items',
            'empty_product_name',
            'no_variations_mapped',
            'missing_email',
            'term_creation_failed',
            'product_creation_failed',
            'dry_run_validation_failed',
            'unexpected_exception',
            'migration_aborted',
            'scope_closure_too_large',
            'mapped_fc_product_missing',
            'orphan_variant_not_created',
            'subscription_cadence_unrepresentable',
            'mapped_variant_not_on_product',
            'mapped_product_has_no_downloads',
            'mapped_product_out_of_scope',
            'database_write_failed',
        ];

        $actual = array_map(
            static fn (MigrationErrorCode $case): string => $case->value,
            MigrationErrorCode::cases(),
        );

        $this->assertSame($expected, $actual);
        $this->assertSame($expected, array_values(array_unique($expected)), 'Values must be unique.');
    }

    /**
     * Housekeeping is not a failure. A run whose only "errors" are records already
     * migrated must not be presented as a run that lost data.
     */
    public function testHousekeepingReasonsAreInformationalNotErrors(): void
    {
        $this->assertSame(
            MigrationErrorSeverity::Info,
            MigrationErrorCode::AlreadyMigrated->severity(),
        );
        $this->assertSame(
            MigrationErrorSeverity::Info,
            MigrationErrorCode::AlreadyExistsInFluentCart->severity(),
        );
    }

    /**
     * Migrated-but-compromised is its own thing. The record exists in FluentCart;
     * the user still has to go and look at it.
     */
    public function testCompromisedButMigratedReasonsAreWarnings(): void
    {
        foreach (
            [
                MigrationErrorCode::SkuCollision,
                MigrationErrorCode::UnknownCouponType,
                MigrationErrorCode::CouponDisabledMissingRestrictions,
                MigrationErrorCode::CouponRestrictionsNarrowed,
                MigrationErrorCode::CustomerRebuiltFromOrder,
                MigrationErrorCode::ProductLinkMissing,
                MigrationErrorCode::UnmappedSubscriptionGateway,
                MigrationErrorCode::PartialCatalogVisibility,
                // Staged, paused, and its bill count deliberately unwritten.
                // The subscriber is in FluentCart; what is missing is a number
                // nobody may guess.
                MigrationErrorCode::SubscriptionHistoryCountMismatch,
            ] as $case
        ) {
            $this->assertSame(MigrationErrorSeverity::Warning, $case->severity(), $case->value);
        }
    }

    /**
     * The counterpart, and the reason two codes left the list above.
     *
     * `multi_item_subscription` and `subscription_paused_missing_product` used
     * to mean "migrated, with a caveat" — the first item kept and the rest
     * dropped, or the whole subscription written out paused. Both now mean
     * nothing was written at all, because `fct_subscriptions` requires
     * references neither shape can supply. A refusal is not a caveat, and a run
     * that reports one as a warning tells the owner their subscribers came
     * across when they did not.
     */
    public function testRefusalsAreErrorsRatherThanWarnings(): void
    {
        foreach (
            [
                MigrationErrorCode::MultiItemSubscription,
                MigrationErrorCode::SubscriptionRequiredReferenceMissing,
                MigrationErrorCode::SubscriptionPausedMissingProduct,
            ] as $case
        ) {
            $this->assertSame(MigrationErrorSeverity::Error, $case->severity(), $case->value);
        }
    }

    /**
     * The category is where the fix lives, not where the exception was thrown.
     * "Customer not found" is raised while migrating orders, but the user fixes it
     * by migrating customers.
     */
    public function testCategoryPointsAtTheFixNotTheFailureSite(): void
    {
        $this->assertSame(
            MigrationErrorCategory::Customer,
            MigrationErrorCode::CustomerNotFound->category(),
        );
        $this->assertSame(
            MigrationErrorCategory::Product,
            MigrationErrorCode::ProductNotMapped->category(),
        );
    }

    public function testCoerceAcceptsCasesStringsNullAndRubbish(): void
    {
        $this->assertSame(
            MigrationErrorCode::SkuCollision,
            MigrationErrorCode::coerce(MigrationErrorCode::SkuCollision),
        );
        $this->assertSame(
            MigrationErrorCode::SkuCollision,
            MigrationErrorCode::coerce('sku_collision'),
        );
        $this->assertNull(MigrationErrorCode::coerce(null));
        $this->assertNull(MigrationErrorCode::coerce(''));
        $this->assertNull(MigrationErrorCode::coerce('not_a_real_code'));
    }

    public function testToArrayCarriesEverythingTheUiNeeds(): void
    {
        $descriptor = MigrationErrorCode::CustomerNotFound->toArray();

        $this->assertSame('customer_not_found', $descriptor['code']);
        $this->assertSame('error', $descriptor['severity']);
        $this->assertSame('customer', $descriptor['category']);
        $this->assertStringContainsString('Migrate customers', $descriptor['hint']);
    }

    public function testDescriptorsCoverEveryCaseKeyedByValue(): void
    {
        $descriptors = MigrationErrorCode::descriptors();

        $this->assertCount(count(MigrationErrorCode::cases()), $descriptors);

        foreach (MigrationErrorCode::cases() as $case) {
            $this->assertArrayHasKey($case->value, $descriptors);
            $this->assertSame($case->value, $descriptors[$case->value]['code']);
        }
    }
}
