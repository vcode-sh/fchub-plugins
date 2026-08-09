<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * FluentCart 1.6.0 declares `customer_id`, `parent_order_id`, `product_id`,
 * `item_name`, `quantity` and `variation_id` NOT NULL on `fct_subscriptions`
 * (database/Migrations/SubscriptionsMigrator.php). A subscription missing any of
 * them is not a compromised record that can be nursed along in a safe status; it
 * is a row the destination schema will not hold.
 *
 * CartShift used to flip such a record to `paused` and write it anyway. Paused is
 * a lifecycle state — "this subscriber is not being charged at the moment" — not
 * a substitute for referential integrity, and using it as one produces a row that
 * bills against a blank line the moment somebody presses resume.
 *
 * So: block before write. Nothing is written, the reason is coded, and the
 * operator repairs the source or the mapping and runs again.
 */
final class SubscriptionRequiredFieldsTest extends PluginTestCase
{
    /** @var array<string, callable> */
    private array $shapes;

    private ?object $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']    = new \CartShiftTestWpdb();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_id_map'] = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

        // Every live fixture in this file is a Stripe subscription WooCommerce
        // was renewing automatically. Section 8.4 holds those at
        // `confirmation_required` until the operator accepts that FluentCart
        // will invoice the customer instead, and the migrator leaves that
        // acceptance at `false`. This file is about required references, so it
        // says the words rather than measuring the payment gate by accident.
        cartshift_test_accept_manual_fallback();

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
    }

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // The healthy shape, so the guard is not simply refusing everything
    // ──────────────────────────────────────────────

    public function testAFullyResolvedSubscriptionIsStillWritten(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);

        $result = $this->migrator()->processRecord($subscription);

        $this->assertNotFalse($result, 'A subscription with every reference resolved must migrate.');

        $written = \CartShiftFcModelStore::all('Subscription');
        $this->assertCount(1, $written);

        // Staged paused with `active` as the status it is destined for. Plan
        // section 11 Phase B creates every validated live record paused and
        // Phase D activates it once the source has released ownership of the
        // next charge; a record that arrives active is one two systems believe
        // they are billing.
        $this->assertSame('paused', $written[0]->status);
        $this->assertSame('active', $written[0]->config['intended_status']);
        $this->assertSame(2900, $written[0]->recurring_total, 'PLN 29 is 2900 minor units in FluentCart.');
    }

    public function testTheOlderMonthlyContractKeepsItsOwnAmount(): void
    {
        $subscription = $this->shapes['monthlyPln24']();
        $this->mapEverythingFor($subscription);

        $this->migrator()->processRecord($subscription);

        $this->assertSame(
            2400,
            \CartShiftFcModelStore::all('Subscription')[0]->recurring_total,
            'PLN 24 is the subscriber\'s contract, not a stale PLN 29 to be corrected on the way in.',
        );
    }

    public function testTheCurrentYearlyContractIsWrittenAtItsOwnAmountAndCadence(): void
    {
        $this->assertWrittenContract('yearlyPln290', 29000, 'yearly');
    }

    public function testTheOlderYearlyContractIsWrittenAtItsOwnAmountAndCadence(): void
    {
        $this->assertWrittenContract('yearlyPln240', 24000, 'yearly');
    }

    // ──────────────────────────────────────────────
    // Every NOT NULL reference, one at a time
    // ──────────────────────────────────────────────

    public function testAMissingCustomerBlocksBeforeCreate(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['customer']);

        $this->assertBlocked($subscription, MigrationErrorCode::CustomerNotFound);
    }

    public function testAMissingParentOrderBlocksBeforeCreate(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['order']);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testAMissingProductBlocksBeforeCreate(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['product']);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testAMissingVariationBlocksBeforeCreate(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['variation']);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testAnEmptyItemNameBlocksBeforeCreate(): void
    {
        $subscription = $this->shapes['itemWithNoName']();
        $this->mapEverythingFor($subscription);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    /**
     * `quantity` is NOT NULL and the mapper hard-codes 1, so the only way to
     * reach a zero is through `cartshift/mapper/subscription` — which is a
     * public filter, and therefore a real way for the payload to arrive broken.
     * The gate reads the payload that is about to be written, not the payload
     * the mapper wishes it had produced.
     */
    public function testAZeroQuantityFromTheMapperFilterBlocksBeforeCreate(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);

        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['quantity'] = 0;

            return $mapped;
        });

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testAFilterThatNullsAReferenceBlocksBeforeCreate(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);

        add_filter('cartshift/mapper/subscription', static function (array $mapped): array {
            $mapped['variation_id'] = null;

            return $mapped;
        });

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    // ──────────────────────────────────────────────
    // Status is not a licence
    // ──────────────────────────────────────────────

    /**
     * The heart of the change. `paused` used to be the answer to a missing
     * product; it cannot be, because the row still has to satisfy the same NOT
     * NULL columns whatever its status says.
     */
    public function testPausingDoesNotExcuseAMissingRequiredField(): void
    {
        $subscription = $this->shapes['onHoldNoNextDate']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['product']);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);

        $this->assertSame(
            0,
            $this->countLogged(MigrationErrorCode::SubscriptionPausedMissingProduct),
            'A blocked record must not be reported as one that migrated paused.',
        );
    }

    /**
     * Nor does a terminal status. A cancelled subscription is history and
     * cannot bill anybody, but `fct_subscriptions` is just as NOT NULL for it —
     * the plan admits terminal records directly in their terminal status only
     * *after* their required references validate.
     */
    public function testATerminalStatusDoesNotExcuseAMissingRequiredField(): void
    {
        $subscription = $this->shapes['cancelled']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['variation']);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testATerminalRecordWithEveryReferenceResolvedIsWrittenTerminal(): void
    {
        $subscription = $this->shapes['cancelled']();
        $this->mapEverythingFor($subscription);

        $this->migrator()->processRecord($subscription);

        $written = \CartShiftFcModelStore::all('Subscription');
        $this->assertCount(1, $written, 'History with complete references is not a hazard.');
        $this->assertSame('canceled', $written[0]->status);
    }

    // ──────────────────────────────────────────────
    // The malformed Lapka record
    // ──────────────────────────────────────────────

    /**
     * No line item, no parent order, blank gateway, a customer, a future date.
     * CartShift must not invent a product, a variation or a parent order to make
     * it fit; the operator repairs the source record.
     */
    public function testTheMalformedNoItemNoParentRecordIsBlocked(): void
    {
        $subscription = $this->shapes['malformedNoItemNoParent']();
        $this->mapEverythingFor($subscription);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testAnEmptyLiveSubscriptionIsBlockedBeforeCreate(): void
    {
        $subscription = $this->shapes['malformedNoItemNoParent'](['status' => 'active']);
        $this->mapEverythingFor($subscription);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    public function testTheBlockingMessageSaysWhichReferencesAreMissing(): void
    {
        $subscription = $this->shapes['malformedNoItemNoParent']();
        $this->mapEverythingFor($subscription);

        $this->migrator()->processRecord($subscription);

        $message = $this->firstMessageFor(MigrationErrorCode::SubscriptionRequiredReferenceMissing);

        $this->assertNotNull($message);
        $this->assertStringContainsString('910014', $message, 'The message must name the subscription.');
        $this->assertStringContainsString('no line item', $message);
    }

    public function testALineItemWithNoProductReferenceIsBlocked(): void
    {
        $subscription = $this->shapes['itemWithNoProductReference']();
        $this->mapEverythingFor($subscription);

        $this->assertBlocked($subscription, MigrationErrorCode::SubscriptionRequiredReferenceMissing);
    }

    // ──────────────────────────────────────────────
    // Multi-item
    // ──────────────────────────────────────────────

    /**
     * FluentCart's subscription row holds one product/variation contract. The
     * old behaviour kept the first item, warned, and dropped the rest — which
     * is data loss with a log entry attached, not a migration policy.
     */
    public function testAMultiItemSubscriptionIsBlockedRatherThanTruncated(): void
    {
        $subscription = $this->shapes['multiItem']();
        $this->mapEverythingFor($subscription);

        $this->assertBlocked($subscription, MigrationErrorCode::MultiItemSubscription);
    }

    /**
     * One event, one row.
     *
     * The mapper emits its own `multi_item_subscription` warning for the same
     * record, so both flushed meant two rows under one code for one
     * subscription: a diagnostic saying the subscription has two items, then a
     * refusal saying the same and adding that nothing was migrated. Anything
     * reading the first match — a UI grouping by code, this test — got the
     * diagnostic and never saw the outcome.
     */
    public function testTheMultiItemBlockIsReportedExactlyOnce(): void
    {
        $subscription = $this->shapes['multiItem']();
        $this->mapEverythingFor($subscription);

        $this->migrator()->processRecord($subscription);

        $this->assertSame(
            1,
            $this->countLogged(MigrationErrorCode::MultiItemSubscription),
            'The mapper warning and the block say the same thing. Only the block is the outcome.',
        );
    }

    public function testTheMultiItemBlockMessageSaysNothingWasMigratedAndNamesEveryItem(): void
    {
        $subscription = $this->shapes['multiItem']();
        $this->mapEverythingFor($subscription);

        $this->migrator()->processRecord($subscription);

        $message = $this->firstMessageFor(MigrationErrorCode::MultiItemSubscription);

        $this->assertNotNull($message);
        $this->assertStringContainsString(
            'Nothing was migrated',
            $message,
            'The row under this code must be the refusal, not the mapper\'s diagnostic.',
        );
        $this->assertStringContainsString('Monthly membership (fixture)', $message);
        $this->assertStringContainsString('Yearly membership (fixture)', $message);
        $this->assertStringNotContainsString(
            'only the first will be migrated',
            $message,
            'Nothing may still describe this as a truncation.',
        );
    }

    /**
     * A finding the block does not duplicate still gets through.
     *
     * The payment strategy's verdict is about a different problem with the same
     * record — nobody has certified who charges this customer next — and losing
     * it would trade one duplicate for one silence. The gateway warning this
     * test used to assert is gone with the mapper's gateway branch: whether a
     * gateway is usable is now the strategy registry's answer, not a note the
     * mapper leaves behind.
     */
    public function testAnUnrelatedPaymentFindingSurvivesTheBlock(): void
    {
        $subscription = $this->shapes['multiItem'](['payment_method' => 'ppcp-gateway']);
        $this->mapEverythingFor($subscription);

        $this->migrator()->processRecord($subscription);

        $this->assertSame(1, $this->countLogged(MigrationErrorCode::MultiItemSubscription));
        $this->assertGreaterThan(
            0,
            $this->countLogged(MigrationErrorCode::SubscriptionPaymentNotReady),
            'The PayPal cohort has no verifiable vault in the restored snapshot, and that is worth '
            . 'saying even about a record that is blocked for another reason entirely.',
        );
    }

    /**
     * A tampered package record must not tell the operator to check their
     * payment account.
     *
     * `codeFor()` falls back to `SubscriptionPaymentNotReady` for any section
     * 9.4 code the log has no heading of its own for. That is right for the
     * provider faults it was written for and it is applied to `reportInvalid()`
     * too — so `dataset_checksum_mismatch`, which means the package line was
     * edited after export, was logged as "Payment ownership is not settled."
     */
    public function testAChecksumMismatchIsNotReportedAsAPaymentProblem(): void
    {
        $codeFor = new \ReflectionMethod(
            \CartShift\Migrator\SubscriptionMigrator::class,
            'codeFor',
        );

        $this->assertSame(
            MigrationErrorCode::SubscriptionDatasetChecksumMismatch,
            $codeFor->invoke(null, \CartShift\Domain\Subscription\ClosureReport::CODE_CHECKSUM_MISMATCH),
        );

        // And the fallback the case was carved out of still works for the
        // provider faults it was written for.
        $this->assertSame(
            MigrationErrorCode::SubscriptionPaymentNotReady,
            $codeFor->invoke(null, 'some_provider_fault_with_no_heading'),
        );
    }

    // ──────────────────────────────────────────────
    // The preview has to agree with the run
    // ──────────────────────────────────────────────

    /**
     * `validateRecord()` used to check only "already migrated" and "has a
     * customer", so once the parent order became a requirement, a scope that
     * selected subscriptions without their parent orders previewed as "would
     * create" for every record and then migrated none of them.
     */
    public function testTheDryRunRefusesWhatTheRealRunRefuses(): void
    {
        $refusals = [
            'malformedNoItemNoParent' => MigrationErrorCode::SubscriptionRequiredReferenceMissing,
            'multiItem'               => MigrationErrorCode::MultiItemSubscription,
            'itemWithNoName'          => MigrationErrorCode::SubscriptionRequiredReferenceMissing,
        ];

        foreach ($refusals as $shape => $code) {
            $GLOBALS['_cartshift_test_id_map'] = [];
            $GLOBALS['_cartshift_test_queries'] = [];

            $subscription = $this->shapes[$shape]();
            $this->mapEverythingFor($subscription);

            $this->assertFalse(
                $this->migrator()->validateRecord($subscription),
                sprintf('%s: the preview must refuse what the run refuses.', $shape),
            );
            $this->assertGreaterThan(0, $this->countLogged($code), $shape);
        }
    }

    public function testTheDryRunRefusesASubscriptionWhoseParentOrderIsNotMigrated(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['order']);

        $this->assertFalse($this->migrator()->validateRecord($subscription));
        $this->assertGreaterThan(
            0,
            $this->countLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing),
        );
    }

    public function testTheDryRunStillPassesASubscriptionTheRunWouldMigrate(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);

        $this->assertTrue($this->migrator()->validateRecord($subscription));
        $this->assertSame(
            [],
            \CartShiftFcModelStore::all('Subscription'),
            'A dry run still creates no FluentCart records.',
        );
    }

    /**
     * The preview and the run must also agree on the *code*, not merely on the
     * outcome. A preview that files a refusal under a different reason than the
     * run sends the owner to fix the wrong thing.
     */
    public function testTheDryRunReportsTheSameCodeAsTheRunForAnUnresolvedCustomer(): void
    {
        $subscription = $this->shapes['monthlyPln29']();
        $this->mapEverythingFor($subscription);
        unset($GLOBALS['_cartshift_test_id_map']['customer']);

        $this->assertFalse($this->migrator()->validateRecord($subscription));
        $this->assertGreaterThan(0, $this->countLogged(MigrationErrorCode::CustomerNotFound));
        $this->assertSame(
            0,
            $this->countLogged(MigrationErrorCode::SubscriptionRequiredReferenceMissing),
            'The run calls this customer_not_found. So must the preview.',
        );
    }

    public function testTheMultiItemReasonCodeIsThePlansStableCode(): void
    {
        $this->assertSame(
            'multi_item_subscription',
            MigrationErrorCode::MultiItemSubscription->value,
            'Commands, receipts and retry logic key off this exact string.',
        );
    }

    public function testTheRequiredReferenceReasonCodeIsThePlansStableCode(): void
    {
        $this->assertSame(
            'required_reference_missing',
            MigrationErrorCode::SubscriptionRequiredReferenceMissing->value,
        );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Migrate one fixture shape whose references all resolve, and assert the row
     * carries the contract the source held rather than the catalogue's.
     */
    private function assertWrittenContract(string $shape, int $minorUnits, string $interval): void
    {
        $subscription = $this->shapes[$shape]();
        $this->mapEverythingFor($subscription);

        $this->migrator()->processRecord($subscription);

        $written = \CartShiftFcModelStore::all('Subscription');

        $this->assertCount(1, $written, $shape);
        $this->assertSame($minorUnits, $written[0]->recurring_total, $shape);
        $this->assertSame($interval, $written[0]->billing_interval, $shape);
    }

    private function migrator(): SubscriptionMigrator
    {
        return new SubscriptionMigrator(
            new IdMapRepository(),
            new MigrationLogRepository(),
            new MigrationState(),
        );
    }

    /**
     * Assert the record is refused, the reason is coded, and — the point of the
     * whole exercise — that no FluentCart subscription row was created.
     */
    private function assertBlocked(object $subscription, MigrationErrorCode $code): void
    {
        $result = $this->migrator()->processRecord($subscription);

        $this->assertFalse($result, 'A blocked subscription must not report a destination ID.');
        $this->assertSame(
            [],
            \CartShiftFcModelStore::all('Subscription'),
            'Nothing may reach Subscription::create() once the gate has refused the record.',
        );
        $this->assertGreaterThan(
            0,
            $this->countLogged($code),
            sprintf('Expected a log row coded "%s".', $code->value),
        );
    }

    /**
     * Teach the ID map every reference this subscription needs, so a test can
     * then take exactly one away and prove that one matters.
     */
    private function mapEverythingFor(object $subscription): void
    {
        $this->mapEntity('customer', [$subscription->get_customer_id()]);
        $this->mapEntity('order', [$subscription->get_parent_id()]);

        foreach ($subscription->get_items() as $item) {
            $this->mapEntity('product', [$item->get_product_id()]);
            // A simple product's FluentCart variant is keyed by the product ID —
            // what ProductMigrator and MappingPromoter both write.
            $this->mapEntity('variation', [$item->get_product_id()]);

            // And the target catalogue, stated separately. A mapping row is not
            // a catalogue row, which is the whole reason the ownership gate
            // asks the catalogue rather than the map.
            if ($item->get_product_id() > 0) {
                cartshift_test_own_variation(
                    $item->get_product_id() + 10_000,
                    $item->get_product_id() + 10_000,
                );
            }
        }
    }

    /**
     * @param list<int> $wcIds
     */
    private function mapEntity(string $entityType, array $wcIds): void
    {
        foreach ($wcIds as $wcId) {
            if ($wcId <= 0) {
                continue;
            }

            $GLOBALS['_cartshift_test_id_map'][$entityType][(string) $wcId] = $wcId + 10_000;
        }
    }

    private function firstMessageFor(MigrationErrorCode $code): ?string
    {
        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                return (string) ($query[2]['message'] ?? '');
            }
        }

        return null;
    }

    private function countLogged(MigrationErrorCode $code): int
    {
        $count = 0;

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                $count++;
            }
        }

        return $count;
    }
}
