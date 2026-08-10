<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\RuntimeCompatibilityProbe;
use CartShift\Domain\Subscription\RuntimeCompatibilityReport;
use CartShift\Domain\Subscription\SourceTopology;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/PreflightStubs.php';

/**
 * The gate every later task stands on.
 *
 * It proves, without writing a byte, that the installed WooCommerce /
 * Subscriptions / FluentCart builds actually expose the APIs and schema the
 * migration assumes, and it decides whether the run can happen in one runtime
 * or needs the cross-runtime package.
 *
 * Two things are deliberately awkward and deliberately kept that way. Symbol
 * presence goes through RuntimeSymbols so both branches are reachable in a
 * shared-process suite. Everything else — FluentCart's own collection-method
 * resolution, the schema read, the census, reason attribution — runs for real.
 */
final class RuntimeCompatibilityProbeTest extends PluginTestCase
{
    /**
     * The six FluentCart 1.6.0 requires, in its migration's declaration order.
     *
     * @see fluent-cart/database/Migrations/SubscriptionsMigrator.php:18-23
     *
     * @var list<string>
     */
    private const array REQUIRED_COLUMNS = [
        'customer_id',
        'parent_order_id',
        'product_id',
        'item_name',
        'quantity',
        'variation_id',
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedHealthyTarget();
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_hpos_enabled'],
            $GLOBALS['_cartshift_test_hpos_sync_enabled'],
            $GLOBALS['_cartshift_test_hpos_in_sync'],
        );

        parent::tearDown();
    }

    // ── role handling ──────────────────────────────────────

    public function testAnUnknownRoleIsRefusedRatherThanGuessed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->probe()->inspect('sidekick');
    }

    // ── WooCommerce Subscriptions public APIs ──────────────

    public function testASourceWithEveryWcsApiPresentReportsNoMissingApis(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertSame([], $report->wooCommerceSubscriptions['missing_apis']);
        $this->assertNotContains(
            RuntimeCompatibilityReport::ERROR_WCS_API_MISSING,
            $report->errors,
        );
    }

    public function testAMissingWcsFunctionStopsTheSourceProbe(): void
    {
        $symbols = $this->symbols()->withoutFunction('wcs_get_subscriptions');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertContains('wcs_get_subscriptions', $report->wooCommerceSubscriptions['missing_apis']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_WCS_API_MISSING, $report->errors);
        $this->assertFalse($report->isReady());
    }

    /**
     * `get_related_orders()` is the one WCS call the dataset closure cannot be
     * built without — the plan needs it per relationship type.
     */
    public function testAMissingWcSubscriptionMethodStopsTheSourceProbe(): void
    {
        $symbols = $this->symbols()->withoutMethod('WC_Subscription', 'get_related_orders');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertContains(
            'WC_Subscription::get_related_orders',
            $report->wooCommerceSubscriptions['missing_apis'],
        );
        $this->assertContains(RuntimeCompatibilityReport::ERROR_WCS_API_MISSING, $report->errors);
    }

    public function testAnAbsentSubscriptionsAddOnStopsTheSourceProbe(): void
    {
        $symbols = $this->symbols()->withoutClass('WC_Subscriptions');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertFalse($report->wooCommerceSubscriptions['booted']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_WCS_MISSING, $report->errors);
    }

    public function testAnAbsentWooCommerceStopsTheSourceProbe(): void
    {
        $symbols = $this->symbols()->withoutClass('WooCommerce');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertContains(RuntimeCompatibilityReport::ERROR_WOOCOMMERCE_MISSING, $report->errors);
    }

    /**
     * A source runtime with no FluentCart is the normal cross-runtime case, not
     * a fault. It must not raise the target's errors.
     */
    public function testTheSourceRoleDoesNotDemandFluentCart(): void
    {
        $symbols = $this->symbols()->withoutClass('FluentCart\App\Models\Subscription');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertNotContains(RuntimeCompatibilityReport::ERROR_FLUENTCART_MISSING, $report->errors);
    }

    // ── which half gates ───────────────────────────────────

    /**
     * A same-runtime store *is* both halves — one WordPress, one prefix — so the
     * FluentCart schema found drifted here is the schema the migration will
     * write into. Answering `ready: true` to `--role=source` with the drift
     * sitting in the report body is the precise failure this gate exists to
     * prevent: green light, broken runtime.
     */
    public function testASameRuntimeSourceIsGatedOnTheTargetHalfToo(): void
    {
        $this->seedSchema($this->columns(['parent_order_id' => ['bigint(20) unsigned', 'YES']]));

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertSame(SourceTopology::SameRuntime, $report->topology, 'guard: precondition');
        $this->assertContains(RuntimeCompatibilityReport::ERROR_FLUENTCART_SCHEMA_DRIFT, $report->errors);
        $this->assertFalse($report->isReady());
    }

    public function testThePersistedManualRenewalGetterIsRequiredForSourceRelease(): void
    {
        $symbols = $this->symbols()->withoutMethod('WC_Subscription', 'get_requires_manual_renewal');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertContains(
            'WC_Subscription::get_requires_manual_renewal',
            $report->wooCommerceSubscriptions['missing_apis'],
        );
        $this->assertFalse($report->isReady());
    }

    public function testASameRuntimeTargetIsGatedOnTheSourceHalfToo(): void
    {
        $symbols = $this->symbols()->withoutFunction('wcs_get_subscriptions');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(SourceTopology::SameRuntime, $report->topology, 'guard: precondition');
        $this->assertContains(RuntimeCompatibilityReport::ERROR_WCS_API_MISSING, $report->errors);
        $this->assertFalse($report->isReady());
    }

    /**
     * Cross-runtime, the other half is genuinely not there to gate on, so it
     * must not acquire a fault for being absent.
     */
    public function testACrossRuntimeSourceDoesNotAcquireASpuriousFluentCartGate(): void
    {
        $symbols = $this->symbols()->withoutClass('FluentCart\App\Models\Subscription');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertSame(SourceTopology::CrossRuntime, $report->topology, 'guard: precondition');
        $this->assertSame([], $report->errors);
        $this->assertTrue($report->isReady());
    }

    public function testACrossRuntimeTargetDoesNotAcquireASpuriousWooGate(): void
    {
        $symbols = $this->symbols()
            ->withoutClass('WooCommerce')
            ->withoutClass('WC_Subscriptions');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(SourceTopology::CrossRuntime, $report->topology, 'guard: precondition');
        $this->assertSame([], $report->errors);
        $this->assertTrue($report->isReady());
    }

    // ── Woo storage authority ──────────────────────────────

    public function testLegacyPostStorageIsReportedAsAuthoritativeWithoutBlocking(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = false;

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertSame('posts', $report->wooCommerce['storage_authority']);
        $this->assertTrue($report->isReady(), 'Legacy CPT storage is a fact to report, not a blocker.');
    }

    public function testHposIsReportedWhenWooCommerceSaysItIsAuthoritative(): void
    {
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertSame('hpos', $report->wooCommerce['storage_authority']);
    }

    // ── source PayPal adapter ──────────────────────────────

    public function testAnAbsentSourcePayPalPluginIsNamedRatherThanInvented(): void
    {
        $symbols = $this->symbols()->withoutClass('WooCommerce\PayPalCommerce\PluginModule');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertNull($report->paypalAdapter['name']);
        $this->assertContains('source_paypal_adapter_unknown', $report->paypalAdapter['reason_codes']);
    }

    public function testAPresentSourcePayPalPluginIsNamed(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertSame('woocommerce-paypal-payments', $report->paypalAdapter['name']);
        $this->assertSame([], $report->paypalAdapter['reason_codes']);
    }

    // ── FluentCart schema ──────────────────────────────────

    public function testAHealthyTargetPassesTheGate(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame([], $report->errors);
        $this->assertTrue($report->isReady());
    }

    public function testAllSixRequiredColumnsAreCheckedForNotNull(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        // Declaration order from SubscriptionsMigrator.php:18-23, so the report
        // reads like the migration it is checking.
        $this->assertSame(
            ['customer_id', 'parent_order_id', 'product_id', 'item_name', 'quantity', 'variation_id'],
            array_keys($report->fluentCart['schema']['required_columns']),
        );
    }

    public function testANullableRequiredColumnIsSchemaDrift(): void
    {
        $this->seedSchema($this->columns(['parent_order_id' => ['bigint(20) unsigned', 'YES']]));

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame('nullable', $report->fluentCart['schema']['required_columns']['parent_order_id']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_FLUENTCART_SCHEMA_DRIFT, $report->errors);
    }

    public function testAnAbsentRequiredColumnIsSchemaDrift(): void
    {
        $columns = $this->columns();
        unset($columns['variation_id']);
        $this->seedSchema($columns);

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame('missing', $report->fluentCart['schema']['required_columns']['variation_id']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_FLUENTCART_SCHEMA_DRIFT, $report->errors);
    }

    public function testTheCollectionMethodEnumValuesAreRead(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(
            ['automatic', 'manual', 'system'],
            $report->fluentCart['schema']['collection_method_values'],
        );
    }

    public function testACollectionMethodEnumWithoutSystemIsSchemaDrift(): void
    {
        $this->seedSchema($this->columns([
            'collection_method' => ["enum('automatic','manual')", 'NO'],
        ]));

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(['automatic', 'manual'], $report->fluentCart['schema']['collection_method_values']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_FLUENTCART_SCHEMA_DRIFT, $report->errors);
    }

    public function testAnAbsentSubscriptionsTableIsSchemaDrift(): void
    {
        $this->seedSchema([]);

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertFalse($report->fluentCart['schema']['table_present']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_FLUENTCART_SCHEMA_DRIFT, $report->errors);
    }

    // ── FluentCart model contract ──────────────────────────

    public function testAMissingCalculateBillCountStopsTheTargetProbe(): void
    {
        $symbols = $this->symbols()
            ->withoutMethod('FluentCart\App\Models\Subscription', 'calculateBillCount');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertFalse($report->fluentCart['model']['calculate_bill_count']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_FLUENTCART_MODEL_API_MISSING, $report->errors);
    }

    /**
     * The writer has to set `created_at` on the instance because mass
     * assignment drops it. That is a fact about the installed model, so the
     * probe reads it rather than trusting the plan.
     */
    public function testCreatedAtIsReportedAsExcludedFromMassAssignment(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertFalse($report->fluentCart['model']['created_at_fillable']);
        $this->assertSame([], $report->fluentCart['model']['unfillable_required_fields']);
    }

    public function testAModelThatWouldAcceptCreatedAtIsReportedAsSuch(): void
    {
        $symbols = $this->symbols()->withFillable(
            'FluentCart\App\Models\Subscription',
            [...self::REQUIRED_COLUMNS, 'created_at'],
        );

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertTrue($report->fluentCart['model']['created_at_fillable']);
        $this->assertSame([], $report->errors, 'a wider whitelist is a fact, not a fault');
    }

    /**
     * A required reference dropping out of $fillable is the quiet one: mass
     * assignment would omit it and the NOT NULL column would take the blame at
     * insert time, long after the cause.
     */
    public function testARequiredReferenceMissingFromFillableIsDrift(): void
    {
        $symbols = $this->symbols()->withFillable(
            'FluentCart\App\Models\Subscription',
            array_values(array_diff(self::REQUIRED_COLUMNS, ['variation_id', 'item_name'])),
        );

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(
            ['item_name', 'variation_id'],
            $report->fluentCart['model']['unfillable_required_fields'],
        );
        $this->assertContains(
            RuntimeCompatibilityReport::ERROR_FLUENTCART_MODEL_FILLABLE_DRIFT,
            $report->errors,
        );
    }

    public function testAModelWithoutGetFillableStopsTheTargetProbe(): void
    {
        $symbols = $this->symbols()
            ->withoutMethod('FluentCart\App\Models\Subscription', 'getFillable');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertNull($report->fluentCart['model']['created_at_fillable']);
        $this->assertSame([], $report->fluentCart['model']['unfillable_required_fields']);
        $this->assertContains(
            RuntimeCompatibilityReport::ERROR_FLUENTCART_MODEL_API_MISSING,
            $report->errors,
        );
    }

    // ── gateway registration and capability ────────────────

    public function testAnUnregisteredStripeGatewayIsReported(): void
    {
        unset($GLOBALS['_cartshift_test_fc_gateways']['stripe']);

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertFalse($report->fluentCart['gateways']['stripe']['registered']);
        $this->assertContains(
            RuntimeCompatibilityReport::ERROR_FLUENTCART_GATEWAY_UNREGISTERED,
            $report->errors,
        );
    }

    public function testAnUnregisteredPayPalGatewayIsReported(): void
    {
        unset($GLOBALS['_cartshift_test_fc_gateways']['paypal']);

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertFalse($report->fluentCart['gateways']['paypal']['registered']);
        $this->assertContains(
            RuntimeCompatibilityReport::ERROR_FLUENTCART_GATEWAY_UNREGISTERED,
            $report->errors,
        );
    }

    public function testAMissingGatewayManagerAccessorStopsTheTargetProbe(): void
    {
        $symbols = $this->symbols()->withoutMethod(
            'FluentCart\App\Modules\PaymentMethods\Core\GatewayManager',
            'gateway',
        );

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertContains(
            RuntimeCompatibilityReport::ERROR_FLUENTCART_GATEWAY_MANAGER_MISSING,
            $report->errors,
        );
        $this->assertNull($report->fluentCart['gateways']['stripe']['collection_method']);
    }

    public function testAMissingCanonicalCollectionMethodProbeStopsTheTargetProbe(): void
    {
        $symbols = $this->symbols()->withoutMethod(
            'FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode',
            'resolveCollectionMethodFor',
        );

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertContains(
            RuntimeCompatibilityReport::ERROR_FLUENTCART_COLLECTION_PROBE_MISSING,
            $report->errors,
        );
    }

    public function testAMissingManagementModeConfigKeyStopsTheTargetProbe(): void
    {
        $symbols = $this->symbols()->withoutClass(
            'FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode',
        );

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertContains(
            RuntimeCompatibilityReport::ERROR_FLUENTCART_COLLECTION_PROBE_MISSING,
            $report->errors,
        );
    }

    // ── why a store cannot collect automatically ───────────

    public function testStoreManagedAndSystemChargeOnWithACapableGatewayIsSystem(): void
    {
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame('system', $report->fluentCart['gateways']['stripe']['collection_method']);
        $this->assertSame([], $report->fluentCart['gateways']['stripe']['reason_codes']);
    }

    /**
     * Gateway-managed is global store policy. Blaming Stripe for it would send
     * an operator hunting a gateway defect that does not exist.
     */
    public function testGatewayManagedModeBlamesStorePolicyNotTheGateway(): void
    {
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'yes');

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $stripe = $report->fluentCart['gateways']['stripe'];

        $this->assertSame('manual', $stripe['collection_method']);
        $this->assertTrue($stripe['system_subscription']);
        $this->assertSame(['system_store_mode_not_approved'], $stripe['reason_codes']);
    }

    public function testDisabledSystemChargeBlamesStorePolicyNotTheGateway(): void
    {
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'no');

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(
            ['system_store_mode_not_approved'],
            $report->fluentCart['gateways']['paypal']['reason_codes'],
        );
    }

    public function testAGatewayWithoutTheFeatureIsBlamedForItself(): void
    {
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');
        $GLOBALS['_cartshift_test_fc_gateways']['stripe'] =
            \CartShiftFakeGateway::stripe()->without('system_subscription');

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $stripe = $report->fluentCart['gateways']['stripe'];

        $this->assertSame('manual', $stripe['collection_method']);
        $this->assertFalse($stripe['system_subscription']);
        $this->assertSame(['gateway_lacks_system_capability'], $stripe['reason_codes']);
    }

    public function testAnUnregisteredGatewayCannotBeProbedAtAll(): void
    {
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');
        unset($GLOBALS['_cartshift_test_fc_gateways']['paypal']);

        $paypal = $this->probe($this->symbols())
            ->inspect(RuntimeCompatibilityProbe::ROLE_TARGET)
            ->fluentCart['gateways']['paypal'];

        $this->assertSame(['system_collection_unavailable'], $paypal['reason_codes']);
        $this->assertNull($paypal['collection_method']);
    }

    /**
     * A registered, capable gateway with no probe to ask must not sit there
     * with an empty reason list looking like nothing is wrong. FluentCart could
     * not be asked, so the row says exactly that.
     */
    public function testARegisteredGatewayWithNoProbeToAskIsStillExplained(): void
    {
        $symbols = $this->symbols()->withoutClass(
            'FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode',
        );

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        foreach (['stripe', 'paypal'] as $slug) {
            $gateway = $report->fluentCart['gateways'][$slug];

            $this->assertTrue($gateway['registered'], $slug);
            $this->assertTrue($gateway['system_subscription'], $slug);
            $this->assertNull($gateway['collection_method'], $slug);
            $this->assertSame(['system_collection_unavailable'], $gateway['reason_codes'], $slug);
        }
    }

    public function testBothLimitingInputsAreReportedWhenBothApply(): void
    {
        $this->seedStoreSettings(mode: 'gateway_managed', systemCharge: 'no');
        $GLOBALS['_cartshift_test_fc_gateways']['stripe'] =
            \CartShiftFakeGateway::stripe()->without('system_subscription');

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(
            ['gateway_lacks_system_capability', 'system_store_mode_not_approved'],
            $report->fluentCart['gateways']['stripe']['reason_codes'],
        );
    }

    // ── raw vs effective settings ──────────────────────────

    public function testRawAndEffectiveSettingsAreReportedSeparately(): void
    {
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');

        add_filter('fluent_cart/subscription/management_mode', static fn (): string => 'gateway_managed');

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame('store_managed', $report->subscriptionSettings['management_mode']['raw']);
        $this->assertSame('gateway_managed', $report->subscriptionSettings['management_mode']['effective']);
        $this->assertSame('yes', $report->subscriptionSettings['system_charge']['raw']);
        $this->assertFalse($report->subscriptionSettings['system_charge']['effective']);
    }

    public function testAnUnconfiguredSettingReadsAsNullRatherThanItsDefault(): void
    {
        unset($GLOBALS['_cartshift_test_options']['fluent_cart_store_settings']);

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertNull($report->subscriptionSettings['management_mode']['raw']);
        $this->assertSame('gateway_managed', $report->subscriptionSettings['management_mode']['effective']);
    }

    /**
     * The option keys are FluentCart's constants. With the class gone there is
     * no key, and the report says so instead of naming a key it never read —
     * the run has already stopped on the missing probe anyway.
     */
    public function testAnAbsentSettingsClassLeavesTheKeysExplicitlyUnknown(): void
    {
        $symbols = $this->symbols()->withoutClass(
            'FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode',
        );

        $settings = $this->probe($symbols)
            ->inspect(RuntimeCompatibilityProbe::ROLE_TARGET)
            ->subscriptionSettings;

        $this->assertNull($settings['management_mode']['key']);
        $this->assertNull($settings['management_mode']['raw']);
        $this->assertNull($settings['management_mode']['effective']);
        $this->assertNull($settings['system_charge']['key']);
        $this->assertNull($settings['system_charge']['raw']);
        $this->assertNull($settings['system_charge']['effective']);
    }

    public function testTheSettingKeysComeFromFluentCartsOwnConstants(): void
    {
        $settings = $this->report()->subscriptionSettings;

        $this->assertSame('subscription_management_mode', $settings['management_mode']['key']);
        $this->assertSame('subscription_system_charge', $settings['system_charge']['key']);
    }

    public function testTheProbeNeverTouchesEitherStoreSetting(): void
    {
        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');
        $before = $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'];

        $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame($before, $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings']);
    }

    // ── target census ──────────────────────────────────────

    public function testTheCensusIsGroupedByStatusCollectionMethodAndStampedMode(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(9, $report->subscriptionCensus['total']);
        $this->assertSame(
            [
                [
                    'collection_method' => 'automatic',
                    'count'             => 4,
                    'management_mode'   => null,
                    'status'            => 'active',
                ],
                [
                    'collection_method' => 'system',
                    'count'             => 3,
                    'management_mode'   => 'store_managed',
                    'status'            => 'active',
                ],
                [
                    'collection_method' => 'manual',
                    'count'             => 2,
                    'management_mode'   => 'store_managed',
                    'status'            => 'cancelled',
                ],
            ],
            $report->subscriptionCensus['groups'],
        );
    }

    public function testACensusQueryFailureIsReportedRatherThanReadAsAnEmptyStore(): void
    {
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $context): string =>
            str_contains($context, 'fct_subscriptions') && !str_contains($context, 'SHOW COLUMNS')
                ? 'Unknown column'
                : '';

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertNull($report->subscriptionCensus['total']);
        $this->assertContains(RuntimeCompatibilityReport::ERROR_TARGET_CENSUS_UNAVAILABLE, $report->errors);

        unset($GLOBALS['_cartshift_test_db_error_callback']);
    }

    // ── topology ───────────────────────────────────────────

    public function testATargetWithNoWooCommerceIsCrossRuntime(): void
    {
        $symbols = $this->symbols()->withoutClass('WooCommerce')->withoutClass('WC_Subscriptions');

        $report = $this->probe($symbols)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(SourceTopology::CrossRuntime, $report->topology);
    }

    public function testEverythingBootedInOneRuntimeIsSameRuntime(): void
    {
        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertSame(SourceTopology::SameRuntime, $report->topology);
    }

    // ── redaction and fingerprint ──────────────────────────

    public function testThePrefixIsHashedRatherThanPrinted(): void
    {
        $GLOBALS['wpdb']->prefix = 'kpp_';

        $report = $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $json = json_encode($report->toArray());

        $this->assertSame(hash('sha256', 'kpp_'), $report->runtime['prefix_hash']);
        $this->assertStringNotContainsString('kpp_', (string) $json);

        $GLOBALS['wpdb']->prefix = 'wp_';
    }

    public function testVersionsAndIdentityDoNotChangeTheFingerprint(): void
    {
        $base = $this->report();

        $sameInputs = new RuntimeCompatibilityReport(
            role: $base->role,
            topology: SourceTopology::CrossRuntime,
            runtime: ['prefix_hash' => 'different', 'cartshift' => '99.0.0'],
            wooCommerce: ['booted' => false],
            wooCommerceSubscriptions: ['booted' => false],
            paypalAdapter: ['name' => 'something-else'],
            fluentCart: ['booted' => true, 'version' => '9.9.9'],
            subscriptionSettings: $base->subscriptionSettings,
            subscriptionCensus: $base->subscriptionCensus,
            errors: ['whatever'],
        );

        $this->assertSame($base->fingerprint(), $sameInputs->fingerprint());
    }

    /**
     * The failure this guards against is specific. On a cross-runtime source
     * FluentCart is absent, so the settings and census are all-null and hash to
     * one value shared by every source report in existence — a constant that
     * looks exactly like an approval token. Task 11 binds
     * `--approve-system-settings=<sha256>` to this value, so a source
     * fingerprint pasted as a target approval must not match.
     */
    public function testASourceAndTargetReportOfTheSameStoreNeverShareAFingerprint(): void
    {
        $probe = $this->probe($this->symbols());

        $this->assertNotSame(
            $probe->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE)->fingerprint(),
            $probe->inspect(RuntimeCompatibilityProbe::ROLE_TARGET)->fingerprint(),
        );
    }

    public function testTwoCrossRuntimeSourcesDoNotShareOneConstantFingerprint(): void
    {
        $withoutFluentCart = $this->symbols()->withoutClass('FluentCart\App\Models\Subscription');

        $source = $this->probe($withoutFluentCart)->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);
        $target = $this->probe($withoutFluentCart)->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        // Both are all-null in settings and census, so only the role and the
        // absent FluentCart keep these apart.
        $this->assertNotSame($source->fingerprint(), $target->fingerprint());
    }

    public function testFluentCartBeingAbsentChangesTheFingerprint(): void
    {
        $base = $this->report();

        $absent = new RuntimeCompatibilityReport(
            role: $base->role,
            topology: $base->topology,
            runtime: $base->runtime,
            wooCommerce: $base->wooCommerce,
            wooCommerceSubscriptions: $base->wooCommerceSubscriptions,
            paypalAdapter: $base->paypalAdapter,
            fluentCart: ['booted' => false],
            subscriptionSettings: $base->subscriptionSettings,
            subscriptionCensus: $base->subscriptionCensus,
            errors: $base->errors,
        );

        $this->assertNotSame($base->fingerprint(), $absent->fingerprint());
    }

    public function testADifferentCensusChangesTheFingerprint(): void
    {
        $base = $this->report();

        $changed = new RuntimeCompatibilityReport(
            role: $base->role,
            topology: $base->topology,
            runtime: $base->runtime,
            wooCommerce: $base->wooCommerce,
            wooCommerceSubscriptions: $base->wooCommerceSubscriptions,
            paypalAdapter: $base->paypalAdapter,
            fluentCart: $base->fluentCart,
            subscriptionSettings: $base->subscriptionSettings,
            subscriptionCensus: ['total' => 10, 'groups' => []],
            errors: $base->errors,
        );

        $this->assertNotSame($base->fingerprint(), $changed->fingerprint());
    }

    public function testADifferentEffectiveSettingChangesTheFingerprint(): void
    {
        $base = $this->report();

        $settings = $base->subscriptionSettings;
        $this->assertTrue($settings['system_charge']['effective'], 'guard: the seeded store allows system charging');

        $settings['system_charge']['effective'] = false;

        $changed = new RuntimeCompatibilityReport(
            role: $base->role,
            topology: $base->topology,
            runtime: $base->runtime,
            wooCommerce: $base->wooCommerce,
            wooCommerceSubscriptions: $base->wooCommerceSubscriptions,
            paypalAdapter: $base->paypalAdapter,
            fluentCart: $base->fluentCart,
            subscriptionSettings: $settings,
            subscriptionCensus: $base->subscriptionCensus,
            errors: $base->errors,
        );

        $this->assertNotSame($base->fingerprint(), $changed->fingerprint());
    }

    public function testTheFingerprintIsBlindToKeyOrder(): void
    {
        $base = $this->report();

        $reordered = new RuntimeCompatibilityReport(
            role: $base->role,
            topology: $base->topology,
            runtime: $base->runtime,
            wooCommerce: $base->wooCommerce,
            wooCommerceSubscriptions: $base->wooCommerceSubscriptions,
            paypalAdapter: $base->paypalAdapter,
            fluentCart: $base->fluentCart,
            subscriptionSettings: array_reverse($base->subscriptionSettings, preserve_keys: true),
            subscriptionCensus: array_reverse($base->subscriptionCensus, preserve_keys: true),
            errors: $base->errors,
        );

        $this->assertSame($base->fingerprint(), $reordered->fingerprint());
    }

    public function testTheFingerprintIsASha256Hash(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->report()->fingerprint());
    }

    public function testToArrayIsRecursivelySorted(): void
    {
        $array = $this->report()->toArray();

        $this->assertSame($this->sortedKeysOf($array), array_keys($array));
        $this->assertSame(
            $this->sortedKeysOf($array['subscription_settings']),
            array_keys($array['subscription_settings']),
        );
    }

    public function testTheReportCarriesTheFingerprintItWillBeApprovedBy(): void
    {
        $report = $this->report();

        $this->assertSame($report->fingerprint(), $report->toArray()['fingerprint']);
    }

    // ── error suppression ──────────────────────────────────

    /**
     * Both target reads can legitimately fail, and both failures are already
     * reported as structured findings. Unsuppressed, wpdb would also echo the
     * raw MySQL error into the `--format=json` stream whenever WP_DEBUG_DISPLAY
     * is on, breaking the JSON and the byte-identical-summary promise.
     */
    public function testEveryTargetReadRunsWithWpdbErrorPrintingOff(): void
    {
        $seen = [];

        $GLOBALS['_cartshift_test_db_error_callback'] = static function (string $context) use (&$seen): string {
            $seen[$context] = $GLOBALS['wpdb']->suppress_errors;

            return '';
        };

        $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

        $this->assertCount(2, $seen, 'expected the schema read and the census read');

        foreach ($seen as $query => $suppressed) {
            $this->assertTrue($suppressed, sprintf('errors were printable during: %s', $query));
        }
    }

    public function testThePreviousSuppressionStateIsPutBack(): void
    {
        foreach ([false, true] as $previous) {
            $GLOBALS['wpdb']->suppress_errors = $previous;

            $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);

            $this->assertSame($previous, $GLOBALS['wpdb']->suppress_errors);
        }

        $GLOBALS['wpdb']->suppress_errors = false;
    }

    // ── zero writes ────────────────────────────────────────

    public function testTheProbeWritesNothing(): void
    {
        $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);
        $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_SOURCE);

        $this->assertNoWrites();
    }

    // ── helpers ────────────────────────────────────────────

    private function probe(?FakeRuntimeSymbols $symbols = null): RuntimeCompatibilityProbe
    {
        return new RuntimeCompatibilityProbe($symbols ?? $this->symbols());
    }

    /**
     * A runtime with everything present and the versions Lapka runs.
     */
    private function symbols(): FakeRuntimeSymbols
    {
        return (new FakeRuntimeSymbols())
            ->withConstant('WC_VERSION', '11.0.0')
            ->withConstant('WCS_VERSION', '8.7.1')
            ->withConstant('FLUENTCART_VERSION', '1.6.0')
            ->withConstant('FLUENTCART_PRO_PLUGIN_VERSION', '1.6.0');
    }

    private function report(): RuntimeCompatibilityReport
    {
        return $this->probe($this->symbols())->inspect(RuntimeCompatibilityProbe::ROLE_TARGET);
    }

    private function seedHealthyTarget(): void
    {
        $GLOBALS['_cartshift_test_fc_gateways'] = [
            'stripe' => \CartShiftFakeGateway::stripe(),
            'paypal' => \CartShiftFakeGateway::paypal(),
        ];

        $this->seedStoreSettings(mode: 'store_managed', systemCharge: 'yes');
        $this->seedSchema($this->columns());
    }

    private function seedStoreSettings(string $mode, string $systemCharge): void
    {
        $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'] = [
            'subscription_management_mode' => $mode,
            'subscription_system_charge'   => $systemCharge,
        ];
    }

    /**
     * FluentCart 1.6.0's fct_subscriptions columns, as SHOW COLUMNS returns
     * them, with per-column overrides for the drift cases.
     *
     * @param array<string, array{0: string, 1: string}> $overrides
     * @return array<string, array{0: string, 1: string}>
     */
    private function columns(array $overrides = []): array
    {
        return array_merge([
            'customer_id'       => ['bigint(20) unsigned', 'NO'],
            'parent_order_id'   => ['bigint(20) unsigned', 'NO'],
            'product_id'        => ['bigint(20) unsigned', 'NO'],
            'item_name'         => ['text', 'NO'],
            'quantity'          => ['int(11)', 'NO'],
            'variation_id'      => ['bigint(20) unsigned', 'NO'],
            'collection_method' => ["enum('automatic','manual','system')", 'NO'],
            'created_at'        => ['datetime', 'YES'],
        ], $overrides);
    }

    /**
     * @param array<string, array{0: string, 1: string}> $columns
     */
    private function seedSchema(array $columns): void
    {
        $rows = [];

        foreach ($columns as $field => [$type, $null]) {
            $rows[] = (object) ['Field' => $field, 'Type' => $type, 'Null' => $null];
        }

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($rows): array {
            if (str_contains($query, 'SHOW COLUMNS')) {
                return $rows;
            }

            return [
                (object) [
                    'status'            => 'active',
                    'collection_method' => 'system',
                    'management_mode'   => 'store_managed',
                    'total'             => '3',
                ],
                (object) [
                    'status'            => 'cancelled',
                    'collection_method' => 'manual',
                    'management_mode'   => 'store_managed',
                    'total'             => '2',
                ],
                (object) [
                    'status'            => 'active',
                    'collection_method' => 'automatic',
                    'management_mode'   => null,
                    'total'             => '4',
                ],
            ];
        };
    }

    /**
     * @param array<string, mixed> $array
     * @return list<string>
     */
    private function sortedKeysOf(array $array): array
    {
        $keys = array_map(strval(...), array_keys($array));
        sort($keys);

        return $keys;
    }

    private function assertNoWrites(): void
    {
        foreach ($GLOBALS['_cartshift_test_queries'] as $recorded) {
            $this->assertNotContains(
                $recorded[0],
                ['insert', 'update', 'delete', 'replace'],
                sprintf('The compatibility gate must not write: %s', json_encode($recorded)),
            );

            if ($recorded[0] === 'query' || $recorded[0] === 'get_results' || $recorded[0] === 'get_var') {
                $this->assertMatchesRegularExpression(
                    '/^\s*(SELECT|SHOW)\b/i',
                    (string) $recorded[1],
                    'The compatibility gate must only read.',
                );
            }
        }

        $this->assertSame([], $GLOBALS['_cartshift_test_transients'] ?? []);
        $this->assertSame([], $GLOBALS['_cartshift_test_as_scheduled']);
    }
}
