<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\SubscriptionHistoryIndex;
use CartShift\Domain\Subscription\SubscriptionHistoryLinker;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionOrderImporter;
use CartShift\Support\Constants;

/**
 * Parent and renewal orders, imported with the types FluentCart reads, and the
 * succeeded charges linked to the subscription that owns them.
 *
 * The plan's P1: CartShift maps every order as `type = checkout` with
 * `order_type = order` and never sets `subscription_id`, so FluentCart —
 * which recomputes `bill_count` from succeeded positive charge transactions
 * carrying that column (`Subscription::calculateBillCount()`,
 * Subscription.php:1090) — counts zero and resets whatever number was copied in.
 *
 * Two rules the tests here exist to pin.
 *
 * A REFERENCE IS NOT AN ORDER. Section 6.2 requires the actual `OrderRecord`,
 * its items and its transactions for every typed reference. A package that
 * names order 880501 and does not carry it fails BEFORE anything is written,
 * rather than importing half a history and promising to find the rest later.
 *
 * RENEWAL RELATIONSHIPS NEVER COME FROM `post_parent`. WCS renewal orders have
 * no useful parent link — plan section 4.8 — so the relationship comes from the
 * typed references the dataset already carries, and the fixture renewal payload
 * carries no parent field at all to prove nothing is reading one.
 */
final class SubscriptionHistoryTest extends SubscriptionHistoryTestCase
{
    // ──────────────────────────────────────────────
    // Closure: a package ID alone must fail before import
    // ──────────────────────────────────────────────

    public function testAReferenceWithNoPayloadIsReportedBeforeAnythingIsWritten(): void
    {
        $record = $this->subscriptionRecord();

        // Parent present, renewal named and absent.
        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('parentOrderPayload'),
        ]);

        $missing = $index->missingReferences($record);

        $this->assertSame(
            [[
                'code'            => 'dataset_missing_related_order',
                'source_order_id' => 880_501,
                'relationship'    => 'renewal',
                'reason'          => SubscriptionHistoryIndex::REASON_ABSENT,
            ]],
            $missing,
        );
    }

    /**
     * An `OrderRecord` with no lines is not a satisfied reference.
     *
     * Section 6.2 requires the line items, and this is the failure that looks
     * like success: the order imports, the money is right, and the invoice has
     * nothing on it. Per-product reporting loses the sale and nothing anywhere
     * says so.
     */
    public function testAnOrderRecordCarryingNoLineItemsIsNotASatisfiedReference(): void
    {
        $record = $this->subscriptionRecord();

        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('parentOrderPayload'),
            $this->orderRecord('renewalOrderPayload', ['items' => []]),
        ]);

        $this->assertSame(
            [[
                'code'            => 'dataset_missing_related_order',
                'source_order_id' => 880_501,
                'relationship'    => 'renewal',
                'reason'          => SubscriptionHistoryIndex::REASON_NO_LINE_ITEMS,
            ]],
            $index->missingReferences($record),
        );

        $result = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        $this->assertSame([], $result['orders']);
        $this->assertSame([], \CartShiftFcModelStore::all('Order'));
    }

    /**
     * A transaction, by contrast, is NOT required — a failed renewal has none
     * and is still history. `DatasetClosureValidator` makes the narrower demand
     * that fits: a PAID order with a positive total and no succeeded charge.
     */
    public function testATransactionlessRenewalIsStillASatisfiedReference(): void
    {
        $record = $this->subscriptionRecord();

        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('parentOrderPayload'),
            $this->orderRecord('renewalOrderPayload', [
                'status'       => 'failed',
                'transactions' => [],
                'dates'        => ['created_utc' => '2023-05-11 09:15:00', 'paid_utc' => null],
            ]),
        ]);

        $this->assertSame([], $index->missingReferences($record));
    }

    /**
     * An order two relationships both claim is typed as neither.
     *
     * Picking one would decide whether it becomes a FluentCart `renewal` or an
     * ordinary purchase on the strength of which relationship happened to be
     * iterated first. `DatasetClosureValidator` reports
     * `dataset_ambiguous_order_relationship` and blocks; until then the safe
     * type is the one that claims nothing.
     */
    public function testAnOrderClaimedByTwoRelationshipsIsTypedAsNeither(): void
    {
        $record = $this->subscriptionRecord([
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                ['source_order_id' => 880_501, 'relationship' => 'switch'],
            ],
        ]);

        $index = $this->completeIndex($record);

        $this->assertNull($index->relationship(880_501));
        $this->assertNull($index->fluentCartOrderType(880_501));
        $this->assertSame('subscription', $index->fluentCartOrderType(880_001));
    }

    public function testAnIncompleteClosureImportsNothingAtAll(): void
    {
        $record = $this->subscriptionRecord();
        $index  = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('parentOrderPayload'),
        ]);

        $result = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        $this->assertNotSame([], $result['failures']);
        $this->assertSame([], $result['orders']);
        $this->assertSame([], \CartShiftFcModelStore::all('Order'));
        $this->assertSame([], \CartShiftFcModelStore::all('OrderTransaction'));
    }

    public function testAMissingParentOrderIsItsOwnCode(): void
    {
        $record = $this->subscriptionRecord();
        $index  = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('renewalOrderPayload'),
        ]);

        $codes = array_column($index->missingReferences($record), 'code');

        $this->assertContains('dataset_missing_parent_order', $codes);
    }

    // ──────────────────────────────────────────────
    // Order types
    // ──────────────────────────────────────────────

    public function testTheParentOrderIsImportedAsAFluentCartSubscriptionOrder(): void
    {
        $record = $this->subscriptionRecord();
        $result = (new SubscriptionOrderImporter($this->idMap()))->import($record, $this->completeIndex($record));

        $parent = $this->orderRowFor($result['orders'][880_001]);

        $this->assertSame('subscription', $parent->type);
        $this->assertNull($parent->parent_id);
    }

    public function testARenewalOrderIsImportedAsAFluentCartRenewalOrderParentedOnTheSubscriptionOrder(): void
    {
        $record = $this->subscriptionRecord();
        $result = (new SubscriptionOrderImporter($this->idMap()))->import($record, $this->completeIndex($record));

        $renewal = $this->orderRowFor($result['orders'][880_501]);

        $this->assertSame('renewal', $renewal->type);
        $this->assertSame($result['orders'][880_001], $renewal->parent_id);
    }

    /**
     * The fixture renewal payload has no parent field of any kind. If the
     * relationship were being inferred from one, this order would arrive as a
     * plain checkout and FluentCart would never show it under the subscription.
     */
    public function testTheRenewalRelationshipComesFromTheTypedReferenceAndNotFromPostParent(): void
    {
        $record = $this->subscriptionRecord();
        $index  = $this->completeIndex($record);

        $this->assertArrayNotHasKey(
            'parent_id',
            $this->shapes['renewalOrderPayload'](),
            'The fixture must not carry a parent link, or this test proves nothing.',
        );

        $this->assertSame(SubscriptionOrderReference::RENEWAL, $index->relationship(880_501));
        $this->assertSame('renewal', $index->fluentCartOrderType(880_501));
        $this->assertSame('subscription', $index->fluentCartOrderType(880_001));
        $this->assertNull($index->fluentCartOrderType(999_999));
    }

    /**
     * Switch and resubscribe orders are history too, and they are not renewal
     * charges. Importing them as renewals would inflate the paid-cycle evidence
     * by whatever a subscriber happened to switch plans.
     */
    public function testSwitchAndResubscribeOrdersAreNeitherRenewalsNorPaidCycles(): void
    {
        $record = $this->subscriptionRecord([
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                ['source_order_id' => 880_631, 'relationship' => 'switch'],
            ],
        ]);

        $index = $this->completeIndex($record, [
            $this->orderRecord('renewalOrderPayload', [
                'source_ref'      => 'order:880631',
                'source_order_id' => 880_631,
            ]),
        ]);

        $this->assertSame('switch', $index->relationship(880_631));
        $this->assertNull($index->fluentCartOrderType(880_631));
        $this->assertSame([880_001, 880_501], $index->paidOrderIds($record));
    }

    /**
     * Two subscriptions naming the same order as a renewal AGREE about the
     * relationship — nothing is disputed — but they disagree about which parent
     * order it hangs off. The first claim keeps the pointer, so the answer does
     * not depend on which subscription the loop reached last.
     */
    public function testADuplicatedRenewalKeepsTheFirstClaimantsParentOrder(): void
    {
        $first = $this->subscriptionRecord([
            'source_ref'             => 'subscription:910001',
            'source_subscription_id' => 910_001,
            'parent_order_id'        => 880_001,
            'related_orders'         => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
            ],
        ]);

        $second = $this->subscriptionRecord([
            'source_ref'             => 'subscription:910002',
            'source_subscription_id' => 910_002,
            'parent_order_id'        => 880_002,
            'related_orders'         => [
                ['source_order_id' => 880_002, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
            ],
        ]);

        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [$first, $second]);

        $this->assertSame('renewal', $index->relationship(880_501), 'The relationship is not disputed.');
        $this->assertFalse($index->isAmbiguous(880_501));
        $this->assertSame(880_001, $index->parentSourceOrderId(880_501));

        // And reversing the iteration order does not reverse the answer.
        $reversed = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [$second, $first]);

        $this->assertSame(880_002, $reversed->parentSourceOrderId(880_501));
        $this->assertNotSame(
            $index->parentSourceOrderId(880_501),
            $reversed->parentSourceOrderId(880_501),
            'The two datasets genuinely differ, so "first claim wins" is a real property rather than a '
            . 'coincidence of ordering.',
        );
    }

    /**
     * A disputed order is askable, not merely untyped.
     *
     * Fail-closed is half the answer; on the live order path no closure
     * validator runs, so without this a disputed order and an order with no
     * subscription at all are indistinguishable.
     */
    public function testADisputedOrderIsDistinguishableFromAnUnknownOne(): void
    {
        $record = $this->subscriptionRecord([
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                ['source_order_id' => 880_501, 'relationship' => 'switch'],
            ],
        ]);

        $index = $this->completeIndex($record);

        $this->assertTrue($index->isAmbiguous(880_501));
        $this->assertFalse($index->isAmbiguous(880_001), 'A settled order is not disputed.');
        $this->assertFalse($index->isAmbiguous(424_242), 'And neither is one nobody has mentioned.');
    }

    /**
     * A third, different claim does not un-dispute an order.
     */
    public function testAThirdClaimLeavesADisputedOrderDisputed(): void
    {
        $record = $this->subscriptionRecord([
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_501, 'relationship' => 'renewal'],
                ['source_order_id' => 880_501, 'relationship' => 'switch'],
                ['source_order_id' => 880_501, 'relationship' => 'resubscribe'],
            ],
        ]);

        $index = $this->completeIndex($record);

        $this->assertTrue($index->isAmbiguous(880_501));
        $this->assertNull($index->fluentCartOrderType(880_501));
    }

    // ──────────────────────────────────────────────
    // Linking
    // ──────────────────────────────────────────────

    public function testSucceededPositiveChargesAreLinkedToTheDestinationSubscription(): void
    {
        $record   = $this->subscriptionRecord();
        $index    = $this->completeIndex($record);
        $imported = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        $linked = (new SubscriptionHistoryLinker($this->idMap()))
            ->link($record, $index, 4242, $imported['orders']);

        $transactions = \CartShiftFcModelStore::all('OrderTransaction');

        $this->assertCount(2, $transactions);

        foreach ($transactions as $transaction) {
            $this->assertSame(4242, $transaction->subscription_id);
            $this->assertSame('charge', $transaction->transaction_type);
            $this->assertSame('succeeded', $transaction->status);
        }

        $this->assertSame(
            ['subscription', 'renewal'],
            array_column($transactions, 'order_type'),
        );
        $this->assertCount(2, $linked['linked']);
    }

    /**
     * A renewal that never took money leaves no succeeded charge behind, so it
     * contributes no paid cycle — and it is still imported, because a failed
     * renewal is part of the history the subscriber can see.
     */
    public function testAFailedRenewalIsImportedWithoutContributingAPaidCycle(): void
    {
        $record = $this->subscriptionRecord([
            'related_orders' => [
                ['source_order_id' => 880_001, 'relationship' => 'parent'],
                ['source_order_id' => 880_502, 'relationship' => 'renewal'],
            ],
            'source_payment_count' => 1,
        ]);

        $failed = $this->orderRecord('renewalOrderPayload', [
            'source_ref'      => 'order:880502',
            'source_order_id' => 880_502,
            'status'          => 'failed',
            'transactions'    => [],
            'dates'           => ['created_utc' => '2023-06-11 09:15:00', 'paid_utc' => null],
        ]);

        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $record,
            $this->orderRecord('parentOrderPayload'),
            $failed,
        ]);

        $imported = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        (new SubscriptionHistoryLinker($this->idMap()))->link($record, $index, 4242, $imported['orders']);

        $this->assertCount(2, \CartShiftFcModelStore::all('Order'));
        $this->assertCount(1, \CartShiftFcModelStore::all('OrderTransaction'));
        $this->assertSame([880_001], $index->paidOrderIds($record));

        $renewal = $this->orderRowFor($imported['orders'][880_502]);

        $this->assertSame('renewal', $renewal->type);
        $this->assertSame('failed', $renewal->status);
    }

    // ──────────────────────────────────────────────
    // Idempotency
    // ──────────────────────────────────────────────

    public function testASecondCompleteRunCreatesNoDuplicateOrdersTransactionsOrMappings(): void
    {
        $record = $this->subscriptionRecord();
        $index  = $this->completeIndex($record);

        $first = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);
        (new SubscriptionHistoryLinker($this->idMap()))->link($record, $index, 4242, $first['orders']);

        $orderRows       = \CartShiftFcModelStore::all('Order');
        $transactionRows = \CartShiftFcModelStore::all('OrderTransaction');
        $itemRows        = \CartShiftFcModelStore::all('OrderItem');

        // A fresh repository, exactly as a second run would build.
        $second = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);
        (new SubscriptionHistoryLinker($this->idMap()))->link($record, $index, 4242, $second['orders']);

        $this->assertSame($first['orders'], $second['orders'], 'A retry must not move a destination ID.');
        $this->assertSame([], $second['created']);
        $this->assertCount(count($orderRows), \CartShiftFcModelStore::all('Order'));
        $this->assertCount(count($transactionRows), \CartShiftFcModelStore::all('OrderTransaction'));
        $this->assertCount(count($itemRows), \CartShiftFcModelStore::all('OrderItem'));
    }

    public function testTheSourceOrderIdsAreUnchangedByARetry(): void
    {
        $record = $this->subscriptionRecord();
        $index  = $this->completeIndex($record);

        (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        $mapped = $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER] ?? [];

        (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        $this->assertSame($mapped, $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER] ?? []);
        // PHP casts numeric-string array keys to integers; the mapping row's
        // own `wc_id` is a string either way.
        $this->assertSame([880_001, 880_501], array_keys($mapped));
    }

    /**
     * An order CartShift already imported through the ordinary order path
     * arrives as `checkout`. It is adopted rather than duplicated, and its type
     * is corrected — otherwise FluentCart never lists it under the subscription.
     */
    public function testAnOrderAlreadyImportedAsCheckoutIsAdoptedAndRetyped(): void
    {
        $existing = \FluentCart\App\Models\Order::query()->create([
            'type'        => 'checkout',
            'invoice_no'  => 'WC-880501',
            'customer_id' => 501,
        ]);

        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_ORDER]['880501'] = (int) $existing->id;

        $record   = $this->subscriptionRecord();
        $index    = $this->completeIndex($record);
        $imported = (new SubscriptionOrderImporter($this->idMap()))->import($record, $index);

        $this->assertSame((int) $existing->id, $imported['orders'][880_501]);
        $this->assertContains(880_501, $imported['adopted']);
        $this->assertSame('renewal', $existing->type);
        $this->assertCount(2, \CartShiftFcModelStore::all('Order'));
    }

    public function testAResubscribeOrderCanBeTheNewSubscriptionsParentAndFirstCharge(): void
    {
        $old = $this->subscriptionRecord([
            'source_ref'             => 'subscription:910000',
            'source_subscription_id' => 910_000,
            'parent_order_id'        => 879_999,
            'related_orders'         => [
                ['source_order_id' => 879_999, 'relationship' => 'parent'],
                ['source_order_id' => 880_001, 'relationship' => 'resubscribe'],
            ],
        ]);
        $current = $this->subscriptionRecord();
        $index = SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, [
            $old,
            $current,
            $this->orderRecord('parentOrderPayload'),
            $this->orderRecord('renewalOrderPayload'),
        ]);

        $this->assertTrue(
            $index->isAmbiguous(880_001),
            'The standalone order view sees both roles and must remain fail-closed.',
        );

        $imported = (new SubscriptionOrderImporter($this->idMap()))->import($current, $index);
        $linked = (new SubscriptionHistoryLinker($this->idMap()))
            ->link($current, $index, 4242, $imported['orders']);

        $parent = $this->orderRowFor($imported['orders'][880_001]);
        $parentTransaction = array_values(array_filter(
            \CartShiftFcModelStore::all('OrderTransaction'),
            static fn (object $transaction): bool => (int) $transaction->order_id === (int) $parent->id,
        ))[0] ?? null;

        $this->assertSame('subscription', $parent->type);
        $this->assertNotNull($parentTransaction);
        $this->assertSame(4242, $parentTransaction->subscription_id);
        $this->assertContains((int) $parentTransaction->id, $linked['linked']);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    private function orderRowFor(int $fcOrderId): object
    {
        foreach (\CartShiftFcModelStore::all('Order') as $row) {
            if ((int) $row->id === $fcOrderId) {
                return $row;
            }
        }

        $this->fail(sprintf('No FluentCart order #%d was written.', $fcOrderId));
    }
}
