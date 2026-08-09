<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe;
use CartShift\Domain\Subscription\Payment\PaymentEnvironment;
use CartShift\Domain\Subscription\SubscriptionLifecycleProjector;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Tests\Unit\PluginTestCase;

/**
 * What CartShift makes of each lifecycle shape, and the population statistics
 * that say whether a later reconciler invented anything.
 *
 * THIS FILE HAS CHANGED SIDES. Task 1 wrote it as CHARACTERISATION: several
 * assertions pinned behaviour the plan intended to replace — an active
 * subscription with no next-payment date was mapped `active` with a null date,
 * an active one with a date in 2024 was mapped `active` with that date, and a
 * finite plan paid to its term was written out active with the conflict intact.
 * Each of those docblocks said, in so many words, "section 9.3 says this must
 * eventually block".
 *
 * It does now. `SubscriptionLifecycleProjector` is where the three stopped
 * being descriptions of today and became rules, so the assertions below are
 * specifications rather than snapshots. The projections they exercise are the
 * projector's rather than the mapper's, because the mapper no longer makes any
 * lifecycle decision to characterise — it copies what the projector decided.
 *
 * The population half of the file is unchanged and is still characterisation in
 * the honest sense: 564 subscriptions, 349 guests, 360 with no next-payment
 * date. Those numbers exist so that a reconciler which later reports 564
 * schedules can be shown to have manufactured 360 of them.
 */
final class SubscriptionLifecycleCharacterisationTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private SubscriptionRecordFactory $factory;

    private SubscriptionLifecycleProjector $projector;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->factory   = new SubscriptionRecordFactory();
        $this->projector = new SubscriptionLifecycleProjector();
        $this->shapes    = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
    }

    // ──────────────────────────────────────────────
    // Lifecycle projection (plan section 9.3)
    // ──────────────────────────────────────────────

    public function testATerminalRecordWithNoNextDateKeepsItsStatusAndANullDate(): void
    {
        $projection = $this->project('terminalNoNextDate');

        $this->assertSame('canceled', $projection['status']);
        $this->assertNull($projection['next_billing_date'], 'Nothing may invent a date for a dead record.');
        $this->assertSame('2023-11-30 08:00:00', $projection['canceled_at']);
        $this->assertSame([], $projection['errors']);
    }

    public function testAnOnHoldRecordWithNoNextDateKeepsTheNull(): void
    {
        $projection = $this->project('onHoldNoNextDate');

        $this->assertSame('paused', $projection['status'], 'WooCommerce on-hold is FluentCart paused.');
        $this->assertNull(
            $projection['next_billing_date'],
            'A paused record preserves the source date exactly, including its absence.',
        );
        $this->assertSame([], $projection['errors'], 'And it is not blocked for having none.');
    }

    /**
     * The one live shape that passes. Section 11 Phase B stages it paused and
     * Phase D activates it once the source has released ownership, so `active`
     * travels in `intended_status` rather than being written straight away.
     */
    public function testAnActiveRecordWithAFutureDateCarriesThatDateAndStagesPaused(): void
    {
        $projection = $this->project('activeFutureDate');

        $this->assertSame('paused', $projection['status']);
        $this->assertSame('active', $projection['intended_status']);
        $this->assertSame('2099-05-11 09:15:00', $projection['next_billing_date']);
        $this->assertSame([], $projection['errors']);
        $this->assertGreaterThan(
            time(),
            (int) strtotime((string) $projection['next_billing_date']),
            'The fixture has to stay in the future or it stops describing the 76-record cohort.',
        );
    }

    /**
     * Two of the 78 active Lapka records. Task 1 recorded this as
     * CHARACTERISATION — mapped active, past date handed straight through — and
     * noted that section 9.3 calls it `active_next_date_past`. It is now the
     * rule: the date is still preserved verbatim, and the record is refused.
     */
    public function testAnActiveRecordWithAPastDateIsRefused(): void
    {
        $projection = $this->project('activePastDate');

        $this->assertContains('active_next_date_past', $projection['errors']);
        $this->assertSame(
            '2024-09-30 07:30:00',
            $projection['next_billing_date'],
            'Refused, not rewritten. The source date is evidence for whoever reconciles it.',
        );
        $this->assertLessThan(
            time(),
            (int) strtotime((string) $projection['next_billing_date']),
            'An active subscription whose next charge is in the past is not activation-ready.',
        );
    }

    /**
     * Likewise. Task 1's CHARACTERISATION was "mapped active with a null date —
     * nothing owns the next charge"; section 9.3's answer is
     * `active_next_date_missing`, and that is what this now asserts.
     */
    public function testAnActiveRecordWithNoDateIsRefused(): void
    {
        $projection = $this->project('activeMissingNextDate');

        $this->assertContains('active_next_date_missing', $projection['errors']);
        $this->assertNull($projection['next_billing_date'], 'And still nothing invents one.');
    }

    /**
     * A twelve-month contract with twelve payments already taken, still called
     * active by the source. Task 1 pinned this as "today the conflict is
     * written out rather than reported". It is now reported and nothing is
     * written.
     *
     * The term is read from the subscription's own `_subscription_length`. The
     * fixture carries it on the *product* as well, and that copy is
     * deliberately ignored — reading a historical customer's term off today's
     * catalogue is the plan's P1 defect, and it is why the override below is
     * needed to express the conflict at all.
     */
    public function testAFinitePlanPaidToItsTermWhileActiveIsRefused(): void
    {
        $projection = $this->project('finitePaidToTermConflict', [
            'meta' => ['_subscription_length' => '12'],
        ]);

        $this->assertSame(12, $projection['bill_times']);
        $this->assertSame(12, $projection['bill_count']);
        $this->assertContains('finite_term_state_conflict', $projection['errors']);
    }

    /**
     * And with the term left where the old mapper used to find it — on the
     * current product, with the subscription's own meta absent — the record is
     * refused rather than answered.
     *
     * Writing `bill_times = 0` here would tell FluentCart to bill for ever on a
     * question the source never answered, and it would also disarm the
     * conflict check immediately above, which only examines a positive term.
     * The product's twelve is still not substituted: it describes today's
     * catalogue, not what this subscriber agreed to.
     */
    public function testATermRecordedOnlyOnTheCurrentProductIsUsedAndWarnedAbout(): void
    {
        $projection = $this->project('finitePaidToTermConflict', ['status' => 'cancelled']);

        $this->assertSame(12, $projection['bill_times'], 'The product\'s twelve is the only answer there is.');
        $this->assertContains('finite_term_from_product', $projection['warnings']);
        $this->assertSame([], $projection['errors']);
    }

    /**
     * And where nobody declared a term at all — not the subscription, not the
     * product — the record is refused rather than answered. `bill_times = 0`
     * would tell FluentCart to bill for ever on a question nobody answered, and
     * it would also disarm the conflict check above, which only examines a
     * positive term.
     */
    public function testATermDeclaredNowhereIsRefused(): void
    {
        $projection = $this->project('termDeclaredNowhere');

        $this->assertSame(0, $projection['bill_times']);
        $this->assertContains('finite_term_undeclared', $projection['errors']);
    }

    /**
     * The ordinary Lapka shape: the subscription says nothing, the product says
     * unlimited. Used, warned about, not refused.
     */
    public function testTheLapkaShapeTakesItsUnlimitedTermFromTheProduct(): void
    {
        $projection = $this->project('monthlyPln29');

        $this->assertSame(0, $projection['bill_times']);
        $this->assertContains('finite_term_from_product', $projection['warnings']);
        $this->assertSame([], $projection['errors']);
    }

    // ──────────────────────────────────────────────
    // Money and cadence, since a contract is both
    // ──────────────────────────────────────────────

    public function testEachContractKeepsItsOwnAmountInFluentCartMinorUnits(): void
    {
        $expected = [
            'monthlyPln29' => [2900, 'monthly'],
            'monthlyPln24' => [2400, 'monthly'],
            'yearlyPln290' => [29000, 'yearly'],
            'yearlyPln240' => [24000, 'yearly'],
        ];

        foreach ($expected as $shape => [$minorUnits, $interval]) {
            $record = $this->record($shape);

            $this->assertSame($minorUnits, $record->contract->recurringTotal, $shape);
            $this->assertSame($interval, $record->contract->targetInterval, $shape);
        }
    }

    // ──────────────────────────────────────────────
    // Identity and gateway shapes
    // ──────────────────────────────────────────────

    public function testTheGuestShapeReallyIsAGuestWithAReachableEmail(): void
    {
        $guest = $this->shapes['guestCustomer']();

        $this->assertSame(0, $guest->get_customer_id(), '349 Lapka subscriptions have _customer_user = 0.');
        $this->assertNotSame('', $guest->get_billing_email(), 'All 349 still carry a billing email.');
        $this->assertStringEndsWith('example.invalid', $guest->get_billing_email());
    }

    public function testNoFixtureCarriesAProductionLookingPaymentIdentifier(): void
    {
        $tokens = [];

        // Selected by what the factory returns rather than by a list of names
        // to skip. The fixture file is shared — it already carries record
        // payload factories that hand back arrays — and a blacklist would have
        // to be maintained by everyone who adds one, which is another way of
        // saying it would not be.
        foreach ($this->shapes as $factory) {
            $subscription = $factory();

            if (!$subscription instanceof \CartShiftLapkaSubscription) {
                continue;
            }

            foreach (['_stripe_customer_id', '_stripe_source_id', '_ppcp_synthetic_payer_id'] as $key) {
                $value = (string) $subscription->get_meta($key);

                if ($value !== '') {
                    $tokens[] = $value;
                }
            }
        }

        $this->assertNotSame([], $tokens, 'The payment shapes must carry something, or this proves nothing.');

        foreach ($tokens as $token) {
            $this->assertMatchesRegularExpression(
                '/SYNTHETIC|synthetic/',
                $token,
                sprintf('"%s" does not announce itself as a fixture value.', $token),
            );
        }
    }

    public function testTheStripeShapesCarryNoVendorSubscriptionId(): void
    {
        // None of the 367 Lapka Stripe subscriptions has one, which is why they
        // cannot be adopted as gateway-owned remote schedules.
        foreach (['stripePaymentMethod', 'stripeLegacySource'] as $shape) {
            $this->assertArrayNotHasKey(
                'stripe_subscription_id',
                $this->record($shape)->paymentReferences,
                $shape,
            );
        }
    }

    public function testTheLegacySourceShapeIsDistinguishableFromTheModernToken(): void
    {
        $modern = $this->shapes['stripePaymentMethod']()->get_meta('_stripe_source_id');
        $legacy = $this->shapes['stripeLegacySource']()->get_meta('_stripe_source_id');

        $this->assertStringStartsWith('pm_', (string) $modern, '120 of the 367 look like this.');
        $this->assertStringStartsWith('src_', (string) $legacy, '246 of the 367 look like this.');
    }

    public function testTheManualShapesCarryTheExplicitFlagThatBeatsTheGatewaySlug(): void
    {
        foreach (['manualRenewal', 'blankGateway'] as $shape) {
            $this->assertTrue($this->shapes[$shape]()->get_requires_manual_renewal(), $shape);
        }

        $this->assertSame('bacs', $this->shapes['manualRenewal']()->get_payment_method());
        $this->assertSame(
            '',
            $this->shapes['blankGateway']()->get_payment_method(),
            'A blank gateway is what the source says, not a value waiting to be filled in.',
        );
    }

    public function testThePaypalShapeCarriesNoVendorSubscriptionOrVaultId(): void
    {
        $this->assertSame(
            [],
            $this->record('paypalGateway')->paymentReferences,
            'The restored payment-token table holds no PayPal rows; a payer reference is not a mandate, '
            . 'and Task 2 refused to guess which PPCP meta key might hold one.',
        );
    }

    // ──────────────────────────────────────────────
    // Shared parent order
    // ──────────────────────────────────────────────

    /**
     * FluentCart's renewal service assumes one subscription per parent order,
     * so two live subscriptions sharing one is
     * `shared_parent_order_requires_projection` — a block, not a tie-break.
     *
     * The fixture proves the hazard is expressible. The gate that acts on it
     * belongs to dataset closure validation, which this task does not build.
     */
    public function testTheSharedParentFixturesReallyShareOneParentOrder(): void
    {
        $first  = $this->shapes['sharedParentOrderFirst']();
        $second = $this->shapes['sharedParentOrderSecond']();

        $this->assertNotSame($first->get_id(), $second->get_id());
        $this->assertSame(
            $first->get_parent_id(),
            $second->get_parent_id(),
            'Two subscriptions, one parent order: the shape a projection has to refuse.',
        );
        $this->assertGreaterThan(0, $first->get_parent_id());
    }

    // ──────────────────────────────────────────────
    // Population characterisation
    // ──────────────────────────────────────────────

    public function testTheAggregateStatusesAccountForEverySubscription(): void
    {
        $aggregates = $this->aggregates();

        $this->assertSame(564, $aggregates['total']);
        $this->assertSame($aggregates['total'], array_sum($aggregates['statuses']));
        $this->assertSame($aggregates['total'], array_sum($aggregates['cadence']));
        $this->assertSame($aggregates['total'], array_sum($aggregates['gateways']));
        $this->assertSame($aggregates['total'], array_sum($aggregates['manual_renewal']));
        $this->assertSame($aggregates['total'], array_sum($aggregates['line_items']));
        $this->assertSame($aggregates['total'], array_sum($aggregates['currencies']));
    }

    /**
     * The 360, written down. A reconciler that later reports 564 subscriptions
     * with a next-payment date has manufactured 360 of them, and this is the
     * number that says so.
     */
    public function testThreeHundredAndSixtySubscriptionsHaveNoNextPaymentDateAtAll(): void
    {
        $aggregates = $this->aggregates();

        $this->assertSame(360, $aggregates['next_payment_dates']['missing']);
        $this->assertSame(127, $aggregates['next_payment_dates']['past']);
        $this->assertSame(77, $aggregates['next_payment_dates']['future']);
        $this->assertSame(564, array_sum($aggregates['next_payment_dates']));

        $this->assertSame(
            204,
            $aggregates['next_payment_dates']['past'] + $aggregates['next_payment_dates']['future'],
            'Only 204 subscriptions carry a date of any kind. Any later count above that is invented.',
        );
    }

    /**
     * The nowhere-declared shape differs from the ordinary one in exactly one
     * thing.
     *
     * It briefly differed in two: the null was passed in the spec, and the
     * top-level `array_merge` therefore replaced the base's `meta` wholesale
     * and took the Stripe references with it. A fixture that moves two
     * variables cannot tell you which one the assertion is about.
     */
    public function testTheNowhereDeclaredShapeChangesOnlyTheTerm(): void
    {
        $ordinary = $this->record('monthlyPln29');
        $nowhere  = $this->record('termDeclaredNowhere');

        $this->assertSame(
            $ordinary->paymentReferences,
            $nowhere->paymentReferences,
            'Same gateway evidence; only the term is missing.',
        );
        $this->assertSame('product', $ordinary->contract->sourcePlan['finite_cycles_source']);
        $this->assertSame('undeclared', $nowhere->contract->sourcePlan['finite_cycles_source']);
    }

    /**
     * And the removal mechanism the shape relies on really removes.
     *
     * `''` cannot express "WooCommerce never wrote this" — an empty string is a
     * value WCS does not write either, and absent-versus-present-and-empty is
     * the whole distinction section 9.2 turns on.
     */
    public function testANullMetaOverrideRemovesTheKeyRatherThanBlankingIt(): void
    {
        $kept    = $this->shapes['monthlyPln29']();
        $removed = $this->shapes['monthlyPln29'](['meta' => ['_stripe_source_id' => null]]);

        $this->assertNotSame('', $kept->get_meta('_stripe_source_id'));
        $this->assertSame('', $removed->get_meta('_stripe_source_id'), 'Gone, not blanked to a value.');
        $this->assertSame(
            $kept->get_meta('_stripe_customer_id'),
            $removed->get_meta('_stripe_customer_id'),
            'And the rest of the meta survives the removal.',
        );
    }

    /**
     * Where `_subscription_length` lives, counted so the next person does not
     * have to grep a 174 MB dump to find out.
     *
     * `_schedule_next_payment` is the control: it is per-subscription meta and
     * appears 1,128 times for 564 subscriptions. `_subscription_length` appears
     * four times in the entire source — on the two products and on none of the
     * subscriptions — which is why every one of the 564 depends on section
     * 9.2's product fallback rather than on its own recorded term.
     */
    public function testTheSubscriptionTermLivesOnTheProductsAndNotOnTheSubscriptions(): void
    {
        $meta = $this->aggregates()['subscription_length_meta'];

        $this->assertSame(0, $meta['on_subscriptions']);
        $this->assertSame(2, $meta['on_products']);
        $this->assertGreaterThan(
            $meta['occurrences_in_source'] * 100,
            $meta['schedule_next_payment_control'],
            'A per-subscription key would be in the thousands. This one is in single figures.',
        );
    }

    public function testOnlyTwoActiveSubscriptionsAreAlreadyPastDue(): void
    {
        $aggregates = $this->aggregates();

        $this->assertSame(2, $aggregates['active_next_payment_dates']['past']);
        $this->assertSame(76, $aggregates['active_next_payment_dates']['future']);
        $this->assertSame(
            $aggregates['statuses']['active'],
            array_sum($aggregates['active_next_payment_dates']),
        );
    }

    public function testTheStripeEvidenceAccountsForEveryStripeSubscription(): void
    {
        $aggregates = $this->aggregates();
        $evidence   = $aggregates['stripe_evidence'];

        $this->assertSame(0, $evidence['with_vendor_subscription_id'], 'Not one remote Stripe subscription.');
        $this->assertSame(
            $aggregates['gateways']['stripe'],
            $evidence['payment_method_token'] + $evidence['legacy_source_token'] + $evidence['no_usable_token'],
        );
    }

    public function testExactlyOneSubscriptionHasNoLineItemAndNoneHasMoreThanOne(): void
    {
        $aggregates = $this->aggregates();

        $this->assertSame(1, $aggregates['line_items']['none']);
        $this->assertSame(0, $aggregates['line_items']['more_than_one']);
        $this->assertSame(563, $aggregates['line_items']['exactly_one']);
    }

    public function testTheMonthlyContractCohortsAccountForEveryMonthlySubscription(): void
    {
        $monthly = $this->aggregates()['products']['monthly'];

        $this->assertSame(375, $monthly['subscriptions']);
        $this->assertSame($monthly['subscriptions'], array_sum($monthly['recurring_total_minor']));
        $this->assertSame(
            [2900 => 208, 2400 => 167],
            $monthly['recurring_total_minor'],
            'Two contracts on one product, and neither is a stale copy of the other.',
        );
    }

    public function testTheYearlyCohortRecordsItsOwnUnreconciledArithmetic(): void
    {
        $yearly = $this->aggregates()['products']['yearly'];

        $this->assertSame(189, $yearly['subscriptions']);
        $this->assertSame(188, $yearly['item_rows']);
        $this->assertSame(
            $yearly['subscriptions'],
            $yearly['item_rows'] + $yearly['malformed_no_item'],
            'The 189th yearly subscription is the malformed no-item record.',
        );
        $this->assertSame($yearly['item_rows'], array_sum($yearly['recurring_total_minor']));
        $this->assertSame(
            1,
            $yearly['zero_recurring_total_unreconciled'],
            'The verified baseline lists a zero yearly total that 112 + 76 = 188 leaves no room for. '
            . 'It is carried as an open discrepancy rather than reconciled by guesswork.',
        );
    }

    /**
     * The verified baseline never measured how many source subscriptions share
     * a parent order, so the summary says so instead of reporting a comfortable
     * zero that a later task would take for evidence.
     */
    public function testTheSummaryStatesThatParentOrderMultiplicityIsNotYetMeasured(): void
    {
        $parents = $this->aggregates()['parent_orders'];

        $this->assertFalse($parents['multiplicity_measured']);
        $this->assertNull(
            $parents['sharing_a_parent_order'],
            'Null means "nobody counted". Zero would mean "counted, and there are none".',
        );
        $this->assertSame(1, $parents['without_a_parent_order'], 'The malformed record has no parent order.');
    }

    public function testTheRenewalRelationshipStatusesAccountForEveryRelationship(): void
    {
        $renewals = $this->aggregates()['renewals'];

        $this->assertSame(4702, $renewals['relationships']);
        $this->assertSame($renewals['relationships'], array_sum($renewals['statuses']));
    }

    public function testTheGuestCohortIsNotSmallEnoughToIgnore(): void
    {
        $identity = $this->aggregates()['identity'];

        $this->assertSame(349, $identity['guest_customer_rows']);
        $this->assertSame(
            $identity['guest_customer_rows'],
            $identity['guests_with_billing_email'],
            'Every guest still resolves to an email, which is the only usable identity they have.',
        );
        $this->assertSame(564, $identity['with_resolvable_email']);
        $this->assertSame(215, $identity['unique_emails']);
        $this->assertLessThan(
            $identity['unique_emails'],
            $identity['emails_matching_a_target_user'],
            'Most subscribers have no target WordPress user, so most become guest customers.',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function project(string $shape, array $overrides = []): array
    {
        return $this->projector->project(
            $this->record($shape, $overrides),
            new PaymentEnvironment(
                capabilities: new PaymentCapabilityProbe(),
                settingsFingerprint: '',
                nowUtc: '2026-08-09 00:00:00',
            ),
        );
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

    /**
     * @return array<string, mixed>
     */
    private function aggregates(): array
    {
        return ($this->shapes['aggregates'])();
    }
}
