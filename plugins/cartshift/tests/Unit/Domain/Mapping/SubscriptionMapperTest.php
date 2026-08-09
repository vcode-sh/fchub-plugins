<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Mapping;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionLifecycleProjector;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * The mapper turns a decided subscription into a `fct_subscriptions` payload,
 * and it decides nothing itself.
 *
 * That is the whole of the change. It used to read a live `WC_Subscription`,
 * branch on the gateway slug, infer a setup fee from `parent total - recurring
 * total`, read the finite term off the *current* product, hard-code the
 * quantity, and mark every record `automatic` — six of the plan's P1 defects in
 * one method. It now takes a `SubscriptionRecord` and a `SubscriptionAssessment`
 * and copies what they already decided. A fourth payment strategy therefore
 * needs a strategy class, a registry entry and its tests; it needs nothing here.
 */
final class SubscriptionMapperTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private SubscriptionRecordFactory $factory;

    private SubscriptionMapper $mapper;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->shapes  = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
        $this->factory = new SubscriptionRecordFactory();
        $this->mapper  = new SubscriptionMapper();
    }

    // ──────────────────────────────────────────────
    // No gateway branch
    // ──────────────────────────────────────────────

    public function testTheCollectionMethodIsThePaymentDecisionsAndNotTheGatewaySlugs(): void
    {
        $mapped = $this->map('monthlyPln29', $this->manualDecision());

        $this->assertSame('manual', $mapped['collection_method']);
        $this->assertNotSame(
            'automatic',
            $mapped['collection_method'],
            '`automatic` means a gateway owns a remote schedule. The mapper does not get a vote.',
        );
    }

    public function testTheVendorIdentifiersComeFromTheDecisionRatherThanFromSourceMeta(): void
    {
        $mapped = $this->map('stripePaymentMethod', $this->systemDecision());

        $this->assertSame('cus_synthetic_fixture_0001', $mapped['vendor_customer_id']);
        $this->assertNull($mapped['vendor_subscription_id']);
        $this->assertSame('stripe', $mapped['current_payment_method']);
    }

    /**
     * The confirmed PayPal defect: a subscription ID assigned as the customer
     * ID. It is now impossible to express — `PaymentMigrationDecision` refuses
     * the combination at construction — and the mapper simply copies the three
     * fields it is handed.
     */
    public function testAManualDecisionCarriesNoVendorMandateAtAll(): void
    {
        $mapped = $this->map('paypalGateway', $this->manualDecision());

        $this->assertNull($mapped['vendor_customer_id']);
        $this->assertNull($mapped['vendor_plan_id']);
        $this->assertNull($mapped['vendor_subscription_id']);
        $this->assertSame(
            '',
            $mapped['current_payment_method'],
            'The invented slug `manual` is not a FluentCart gateway; the neutral value is the empty string.',
        );
    }

    // ──────────────────────────────────────────────
    // The contract, from the subscription
    // ──────────────────────────────────────────────

    public function testTheRecurringFieldsAreTheSubscriptionsOwn(): void
    {
        $mapped = $this->map('monthlyPln24', $this->manualDecision());

        $this->assertSame(2400, $mapped['recurring_total']);
        $this->assertSame(0, $mapped['recurring_tax_total']);
        $this->assertSame(2400, $mapped['recurring_amount']);
        $this->assertSame('monthly', $mapped['billing_interval']);
    }

    public function testTheYearlyContractKeepsItsCadenceRatherThanCollapsingToMonthly(): void
    {
        $mapped = $this->map('yearlyPln290', $this->manualDecision());

        $this->assertSame('yearly', $mapped['billing_interval']);
        $this->assertSame(29000, $mapped['recurring_total']);
    }

    public function testTheSetupFeeIsTheExplicitMetadataValueAndZeroWhenThereIsNone(): void
    {
        $this->assertSame(0, $this->map('monthlyPln29', $this->manualDecision())['signup_fee']);

        $mapped = $this->map(
            'monthlyPln29',
            $this->manualDecision(),
            ['meta' => ['_subscription_sign_up_fee' => '50.00']],
        );

        $this->assertSame(5000, $mapped['signup_fee']);
    }

    public function testTheQuantityIsTheLineItemsOwn(): void
    {
        $this->assertSame(1, $this->map('monthlyPln29', $this->manualDecision())['quantity']);
    }

    // ──────────────────────────────────────────────
    // References and dates come from the assessment
    // ──────────────────────────────────────────────

    public function testTheRequiredReferencesAreTheResolvedDestinationIds(): void
    {
        $mapped = $this->map('monthlyPln29', $this->manualDecision());

        $this->assertSame(501, $mapped['customer_id']);
        $this->assertSame(601, $mapped['parent_order_id']);
        $this->assertSame(701, $mapped['product_id']);
        $this->assertSame(801, $mapped['variation_id']);
        $this->assertSame('Monthly membership (fixture)', $mapped['item_name']);
    }

    public function testTheDatesAreTheProjectedOnesIncludingItsNulls(): void
    {
        $mapped = $this->map('cancelled', $this->manualDecision());

        $this->assertSame('canceled', $mapped['status']);
        $this->assertNull($mapped['next_billing_date'], 'Nothing may invent a date for a dead record.');
        $this->assertSame('2024-02-19 12:41:00', $mapped['canceled_at']);
    }

    public function testTheStartTimeIsCarriedForTheWriterToSetDirectly(): void
    {
        $mapped = $this->map('monthlyPln29', $this->manualDecision());

        $this->assertSame('2023-04-11 09:15:00', $mapped['created_at']);
    }

    // ──────────────────────────────────────────────
    // Config
    // ──────────────────────────────────────────────

    public function testTheConfigIsAnArrayCarryingTheSourceIdentityAndStrategy(): void
    {
        $record = $this->record('monthlyPln29');
        $mapped = $this->mapper->map($record, $this->assessment($record, $this->manualDecision()));

        $this->assertIsArray($mapped['config']);
        $this->assertSame('lapka', $mapped['config']['source_key']);
        $this->assertSame($record->sourceSubscriptionId, $mapped['config']['source_subscription_id']);
        $this->assertSame('stripe', $mapped['config']['source_gateway']);
        $this->assertSame('manual', $mapped['config']['payment_strategy']);
        $this->assertSame('active', $mapped['config']['intended_status']);
    }

    public function testASystemDecisionStampsFluentCartsStoreManagedConfigKey(): void
    {
        $mapped = $this->map('monthlyPln29', $this->systemDecision());

        $this->assertSame('store_managed', $mapped['config']['management_mode']);
    }

    public function testAManualDecisionStampsNoManagementMode(): void
    {
        $this->assertArrayNotHasKey(
            'management_mode',
            $this->map('monthlyPln29', $this->manualDecision())['config'],
        );
    }

    // ──────────────────────────────────────────────
    // The filter is still the filter
    // ──────────────────────────────────────────────

    public function testTheMapperFilterStillRunsOverThePayload(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['item_name'] = 'renamed by a filter';

            return $mapped;
        });

        $this->assertSame(
            'renamed by a filter',
            $this->map('monthlyPln29', $this->manualDecision())['item_name'],
        );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function map(string $shape, PaymentMigrationDecision $payment, array $overrides = []): array
    {
        $record = $this->record($shape, $overrides);

        return $this->mapper->map($record, $this->assessment($record, $payment));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function record(string $shape, array $overrides = []): SubscriptionRecord
    {
        $record = $this->factory->subscriptionFromWoo('lapka', $this->shapes[$shape]($overrides));

        $this->assertNotInstanceOf(InvalidSourceRecord::class, $record, $shape);

        /** @var SubscriptionRecord $record */
        return $record;
    }

    private function assessment(
        SubscriptionRecord $record,
        PaymentMigrationDecision $payment,
    ): SubscriptionAssessment {
        $item = $record->items[0];

        return new SubscriptionAssessment(
            SubscriptionAssessment::OUTCOME_READY,
            [],
            [],
            [
                'customer_id'     => 501,
                'parent_order_id' => 601,
                'product_id'      => 701,
                'variation_id'    => 801,
                'item_name'       => (string) $item['name'],
                'quantity'        => (int) $item['quantity'],
            ],
            $payment,
            (new SubscriptionLifecycleProjector())->project($record, null),
        );
    }

    private function manualDecision(): PaymentMigrationDecision
    {
        return new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_MANUAL,
            outcome: PaymentMigrationDecision::OUTCOME_READY,
            collectionMethod: PaymentMigrationDecision::COLLECTION_MANUAL,
            currentPaymentMethod: '',
            nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_MANUAL,
            vendorCustomerId: null,
            vendorPlanId: null,
            vendorSubscriptionId: null,
            activePaymentMethod: [],
            reasonCodes: [],
        );
    }

    private function systemDecision(): PaymentMigrationDecision
    {
        return new PaymentMigrationDecision(
            strategy: PaymentMigrationDecision::STRATEGY_STRIPE,
            outcome: PaymentMigrationDecision::OUTCOME_READY,
            collectionMethod: PaymentMigrationDecision::COLLECTION_SYSTEM,
            currentPaymentMethod: 'stripe',
            nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_SYSTEM,
            vendorCustomerId: 'cus_synthetic_fixture_0001',
            vendorPlanId: null,
            vendorSubscriptionId: null,
            activePaymentMethod: ['vendor_method_id' => 'pm_synthetic_fixture_0001'],
            reasonCodes: [],
        );
    }
}
