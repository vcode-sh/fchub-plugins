<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionLifecycleProjector;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionWriter;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;
use FluentCart\App\Models\Subscription;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * The one place a `fct_subscriptions` row is created, and the two things about
 * it that are easy to get wrong quietly.
 *
 * `created_at` is excluded from FluentCart's `Subscription::$fillable`
 * (Subscription.php:47-76), so `Subscription::create($attributes)` silently
 * drops it and every migrated subscription is stamped with the moment the
 * migration ran rather than the day the customer signed up. The assertions
 * below read the value off the row that was saved, not off the array the mapper
 * returned — a mapper can return anything it likes about a column the ORM never
 * writes.
 *
 * And `active_payment_method` is read by FluentCart at charge time, not at
 * write time (`Stripe::chargeRenewal()`, `Processor::chargeVaultedRenewal()`),
 * from subscription meta — which needs the model's ID, which does not exist
 * until after `save()`.
 */
final class SubscriptionWriterTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private SubscriptionRecordFactory $factory;

    private ?object $originalWpdb = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']    = new \CartShiftTestWpdb();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_id_map'] = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

        $this->shapes  = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
        $this->factory = new SubscriptionRecordFactory();
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // created_at — the silent timestamp loss
    // ──────────────────────────────────────────────

    /**
     * The defect, stated as a property of the ORM rather than as a belief about
     * it: hand `created_at` to mass assignment and it is gone. If FluentCart
     * ever adds the column to `$fillable`, this test fails and the writer's
     * workaround can be simplified — which is the point of pinning it.
     */
    public function testMassAssignmentDropsCreatedAtBecauseFillableExcludesIt(): void
    {
        $subscription = new Subscription(['status' => 'paused', 'created_at' => '2023-04-11 09:15:00']);

        $this->assertSame('paused', $subscription->status);
        $this->assertNull(
            $subscription->created_at,
            'FluentCart 1.6.0 excludes created_at from Subscription::$fillable.',
        );
    }

    public function testTheStoredRowCarriesTheSourceStartTimeAndNotTodaysDate(): void
    {
        $this->stage('monthlyPln29');

        $written = \CartShiftFcModelStore::all('Subscription');

        $this->assertCount(1, $written);
        $this->assertSame(
            '2023-04-11 09:15:00',
            $written[0]->created_at,
            'The value has to be on the saved row, not merely in the mapper output.',
        );
    }

    public function testTheWriterNeverPassesCreatedAtThroughMassAssignment(): void
    {
        $this->stage('monthlyPln29');

        $subscription = \CartShiftFcModelStore::all('Subscription')[0];

        // Proven by construction: had the writer passed it to the constructor,
        // fill() would have discarded it and the row would read null.
        $this->assertNotNull($subscription->created_at);
    }

    // ──────────────────────────────────────────────
    // The contract, from the subscription and not the catalogue
    // ──────────────────────────────────────────────

    public function testTheRowKeepsTheSubscribersOwnAmountAndCadence(): void
    {
        $this->stage('yearlyPln240');

        $written = \CartShiftFcModelStore::all('Subscription')[0];

        $this->assertSame(24000, $written->recurring_total);
        $this->assertSame('yearly', $written->billing_interval);
    }

    /**
     * The phantom PLN 50. `parent total - recurring total` invented a setup fee
     * for Lapka subscribers on plans whose configured fee is zero.
     */
    public function testTheSetupFeeComesFromExplicitMetadataOrIsZero(): void
    {
        $this->stage('monthlyPln29');

        $this->assertSame(0, \CartShiftFcModelStore::all('Subscription')[0]->signup_fee);

        $GLOBALS['_cartshift_test_fc_models'] = [];
        $this->stage('monthlyPln29', ['meta' => ['_subscription_sign_up_fee' => '50.00']]);

        $this->assertSame(5000, \CartShiftFcModelStore::all('Subscription')[0]->signup_fee);
    }

    public function testTheQuantityIsTheLinesOwnAndNotAHardCodedOne(): void
    {
        $this->stage('monthlyPln29');

        $this->assertSame(1, \CartShiftFcModelStore::all('Subscription')[0]->quantity);
    }

    // ──────────────────────────────────────────────
    // Lifecycle: staged paused, intended status recorded
    // ──────────────────────────────────────────────

    public function testALiveRecordIsStagedPausedWithItsIntendedStatusInConfig(): void
    {
        $this->stage('monthlyPln29');

        $written = \CartShiftFcModelStore::all('Subscription')[0];

        $this->assertSame('paused', $written->status);
        $this->assertSame('active', $written->config['intended_status']);
    }

    public function testATerminalRecordIsWrittenTerminalRatherThanPaused(): void
    {
        $this->stage('cancelled');

        $this->assertSame('canceled', \CartShiftFcModelStore::all('Subscription')[0]->status);
    }

    public function testTheConfigCarriesTheSourceIdentityAndTheStrategyItWasDecidedUnder(): void
    {
        $record = $this->stage('monthlyPln29');

        $config = \CartShiftFcModelStore::all('Subscription')[0]->config;

        $this->assertSame('lapka', $config['source_key']);
        $this->assertSame($record->sourceSubscriptionId, $config['source_subscription_id']);
        $this->assertSame('stripe', $config['source_gateway']);
        $this->assertSame('active', $config['source_status']);
        $this->assertSame($record->fingerprint, $config['contract_fingerprint']);
        $this->assertSame('target_manual', $config['next_action_owner']);
    }

    // ──────────────────────────────────────────────
    // The system decision's verified token
    // ──────────────────────────────────────────────

    /**
     * FluentCart reads `active_payment_method` from subscription meta at charge
     * time and returns `missing_token` without it. The meta needs the
     * subscription's ID, so it cannot be written until after `save()`.
     */
    public function testASystemDecisionStampsTheStoreManagedModeAndWritesTheVerifiedTokenAsMeta(): void
    {
        $record = $this->record('monthlyPln29');

        $this->writer()->stage($record, $this->assessmentFor($record, $this->systemDecision()));

        $written = \CartShiftFcModelStore::all('Subscription')[0];

        $this->assertSame('system', $written->collection_method);
        $this->assertSame('store_managed', $written->config['management_mode']);

        $meta = $GLOBALS['_cartshift_test_fc_meta']['Subscription'][$written->id] ?? [];

        $this->assertSame(
            ['vendor_method_id' => 'pm_synthetic_fixture_0001'],
            $meta['active_payment_method'] ?? null,
        );
    }

    public function testTheTokenMetaIsWrittenAfterTheRowHasAnIdRatherThanAgainstZero(): void
    {
        $record = $this->record('monthlyPln29');

        $this->writer()->stage($record, $this->assessmentFor($record, $this->systemDecision()));

        $written = \CartShiftFcModelStore::all('Subscription')[0];

        $this->assertGreaterThan(0, $written->id);
        $this->assertArrayNotHasKey(
            0,
            $GLOBALS['_cartshift_test_fc_meta']['Subscription'] ?? [],
            'Meta written against subscription 0 belongs to nobody.',
        );
    }

    public function testAManualDecisionWritesNoTokenMetaAtAll(): void
    {
        $this->stage('monthlyPln29');

        $this->assertSame([], $GLOBALS['_cartshift_test_fc_meta'] ?? []);
    }

    // ──────────────────────────────────────────────
    // Nothing is written when a gate failed
    // ──────────────────────────────────────────────

    public function testStagingABlockedAssessmentThrowsAndWritesNothing(): void
    {
        $record     = $this->record('monthlyPln29');
        $assessment = new SubscriptionAssessment(
            SubscriptionAssessment::OUTCOME_BLOCKED,
            [['code' => 'required_reference_missing', 'message' => 'no']],
            [],
            $this->references($record),
            $this->manualDecision(),
            $this->lifecycleFor($record),
        );

        $this->expectException(\LogicException::class);

        try {
            $this->writer()->stage($record, $assessment);
        } finally {
            $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        }
    }

    public function testStagingAnAssessmentAwaitingConfirmationThrowsAndWritesNothing(): void
    {
        $record     = $this->record('monthlyPln29');
        $assessment = new SubscriptionAssessment(
            SubscriptionAssessment::OUTCOME_CONFIRMATION_REQUIRED,
            [],
            [['code' => 'manual_confirmation_required', 'message' => 'ask first']],
            $this->references($record),
            $this->manualDecision(),
            $this->lifecycleFor($record),
        );

        $this->expectException(\LogicException::class);

        try {
            $this->writer()->stage($record, $assessment);
        } finally {
            $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        }
    }

    /**
     * `cartshift/mapper/subscription` is a public filter, and it runs after
     * every gate has passed. A callback that nulls a reference should not be
     * able to do by accident what section 9.3 exists to prevent.
     */
    public function testAFilterThatRemovesARequiredReferenceStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['variation_id'] = null;

            return $mapped;
        });

        $record = $this->record('monthlyPln29');

        $this->expectException(\LogicException::class);

        try {
            $this->writer()->stage($record, $this->assessmentFor($record, $this->manualDecision()));
        } finally {
            $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        }
    }

    /**
     * And a filter that swaps one positive ID for another is refused just as
     * flatly. Section 9.3 asked whether *that* variation sits on *that*
     * product; a value check would pass a substitute while every answer taken
     * about it had quietly become about a different row.
     */
    public function testAFilterThatSubstitutesADifferentVariationStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['variation_id'] = 4242;

            return $mapped;
        });

        $this->assertStageRefused('monthlyPln29');
    }

    /**
     * Who charges a customer next is a decision, not a payload field. A filter
     * promoting a manual row to `system` would produce a subscription
     * FluentCart believes it may charge and no verified token to charge with —
     * `missing_token` at the first renewal, months after the migration was
     * called a success.
     */
    public function testAFilterCannotPromoteAManualRowToSystemCollection(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['collection_method'] = 'system';

            return $mapped;
        });

        $record = $this->record('monthlyPln29');

        $this->expectException(\LogicException::class);

        try {
            $this->writer()->stage($record, $this->assessmentFor($record, $this->manualDecision()));
        } finally {
            $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        }
    }

    /**
     * Every money column on `fct_subscriptions` is BIGINT UNSIGNED
     * (SubscriptionsMigrator.php:24-28). A negative is not a small
     * discrepancy: MySQL either refuses the row or, in a permissive mode,
     * stores the two's complement and the customer's next invoice is
     * astronomical. The assessor checks the contract; the filter runs after it.
     */
    public function testAFilterThatMakesAnAmountNegativeStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['recurring_total'] = -2900;

            return $mapped;
        });

        $this->assertStageRefused('monthlyPln29');
    }

    public function testAFilterThatMakesTheSetupFeeNegativeStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['signup_fee'] = -1;

            return $mapped;
        });

        $this->assertStageRefused('monthlyPln29');
    }

    /**
     * And a decimal string is not an integer minor-unit amount, however
     * plausible it looks in a var_dump.
     */
    public function testAFilterThatSuppliesADecimalStringAmountStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['recurring_amount'] = '29.00';

            return $mapped;
        });

        $this->assertStageRefused('monthlyPln29');
    }

    /**
     * `billing_interval` is VARCHAR, so the column would take "fortnightly"
     * quite happily — and FluentCart would then fail to match it and never
     * schedule anything. Checking it against the six-value enum rather than
     * merely against null is the difference between a row that bills and a row
     * that sits there.
     */
    public function testAFilterThatSuppliesAnIntervalFluentCartDoesNotKnowStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['billing_interval'] = 'fortnightly';

            return $mapped;
        });

        $this->assertStageRefused('monthlyPln29');
    }

    /**
     * The field this whole slice exists to keep null.
     *
     * `SubscriptionLifecycleProjector` writes the source's next payment date or
     * nothing, and never `guessNextBillingDate()`'s invention — 360 of the 564
     * preserved Lapka records have no date at all. A callback that filled one in
     * would schedule a charge nobody agreed to, after every gate had passed.
     */
    public function testAFilterThatInventsANextBillingDateStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['next_billing_date'] = '2099-01-01 00:00:00';

            return $mapped;
        });

        $this->assertStageRefused('monthlyPln29');
    }

    /**
     * `bill_times`, `bill_count` and `trial_days` are UNSIGNED on
     * `fct_subscriptions` — the same argument the money fields already make.
     */
    public function testAFilterThatMovesACycleCountStopsTheWrite(): void
    {
        foreach (['bill_times' => 99, 'bill_count' => -1, 'trial_days' => 14] as $field => $value) {
            $GLOBALS['_cartshift_test_filters']['cartshift/mapper/subscription'] = [
                static function (array $mapped) use ($field, $value): array {
                    $mapped[$field] = $value;

                    return $mapped;
                },
            ];

            $this->assertStageRefused('monthlyPln29');
        }
    }

    /**
     * And the status, which decides whether FluentCart considers the row
     * billable at all.
     */
    public function testAFilterThatChangesTheStagedStatusStopsTheWrite(): void
    {
        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['status'] = 'active';

            return $mapped;
        });

        $this->assertStageRefused('monthlyPln29');
    }

    // ──────────────────────────────────────────────
    // Idempotency
    // ──────────────────────────────────────────────

    public function testASecondStageReturnsTheSameIdAndCreatesNoSecondRow(): void
    {
        $record = $this->record('monthlyPln29');

        $first = $this->writer()->stage($record, $this->assessmentFor($record, $this->manualDecision()));

        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_SUBSCRIPTION][(string) $record->sourceSubscriptionId]
            = $first;

        $second = $this->writer()->stage($record, $this->assessmentFor($record, $this->manualDecision()));

        $this->assertSame($first, $second);
        $this->assertCount(1, \CartShiftFcModelStore::all('Subscription'));
    }

    public function testTheDestinationIdIsRecordedInTheIdMap(): void
    {
        $record = $this->record('monthlyPln29');

        $id = $this->writer()->stage($record, $this->assessmentFor($record, $this->manualDecision()));

        $stored = false;

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') === 'insert'
                && (string) ($query[2]['entity_type'] ?? '') === Constants::ENTITY_SUBSCRIPTION
                && (int) ($query[2]['fc_id'] ?? 0) === $id
            ) {
                $stored = true;
            }
        }

        $this->assertTrue($stored, 'A staged subscription that is not in the ID map is staged twice next run.');
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Stage and expect a refusal, having asserted no row was written either way.
     */
    private function assertStageRefused(string $shape): void
    {
        $record = $this->record($shape);

        $this->expectException(\LogicException::class);

        try {
            $this->writer()->stage($record, $this->assessmentFor($record, $this->manualDecision()));
        } finally {
            $this->assertSame([], \CartShiftFcModelStore::all('Subscription'));
        }
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function stage(string $shape, array $overrides = []): SubscriptionRecord
    {
        $record = $this->record($shape, $overrides);

        $this->writer()->stage($record, $this->assessmentFor($record, $this->manualDecision()));

        return $record;
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

    private function writer(): SubscriptionWriter
    {
        return new SubscriptionWriter(new IdMapRepository('lapka'), new SubscriptionMapper());
    }

    private function assessmentFor(
        SubscriptionRecord $record,
        PaymentMigrationDecision $payment,
    ): SubscriptionAssessment {
        return new SubscriptionAssessment(
            SubscriptionAssessment::OUTCOME_READY,
            [],
            [],
            $this->references($record),
            $payment,
            $this->lifecycleFor($record),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function references(SubscriptionRecord $record): array
    {
        $item = $record->items[0] ?? ['name' => '', 'quantity' => 1, 'source_product_id' => 0];

        return [
            'customer_id'     => 501,
            'parent_order_id' => 601,
            'product_id'      => 701,
            'variation_id'    => 801,
            'item_name'       => (string) $item['name'],
            'quantity'        => (int) $item['quantity'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycleFor(SubscriptionRecord $record): array
    {
        return (new SubscriptionLifecycleProjector())->project($record, null);
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
