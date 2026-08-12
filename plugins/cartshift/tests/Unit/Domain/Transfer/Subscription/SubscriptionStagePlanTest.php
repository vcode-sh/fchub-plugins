<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Subscription\Source\SubscriptionRecord as V2SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionContract;
use CartShift\Domain\Subscription\SubscriptionDates;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Identity\TargetClaimStore;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Order\PaymentEventRecord;
use CartShift\Domain\Transfer\Order\PaymentEvidenceKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\Subscription\SubscriptionStagePlan;
use CartShift\Domain\Transfer\Subscription\FluentCartSubscriptionWriter;
use CartShift\Domain\Transfer\Subscription\SubscriptionReconciler;
use CartShift\Domain\Transfer\Subscription\SubscriptionTargetGateway;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionStagePlanTest extends PluginTestCase
{
    public function testManualProjectionIsPausedAndHistoryCountComesFromLinkedPaymentEvidence(): void
    {
        [$envelope, $record] = $this->subscription(1);
        $order = $this->order(2400, true);
        $maps = $this->maps($record, $order);

        $plan = SubscriptionStagePlan::build(
            $envelope,
            $record,
            $this->decision($envelope, $record, true),
            $maps,
            [$order->identity->canonical() => $order],
            '2026-02-01T00:00:00Z',
        );

        self::assertSame('paused', $plan->row['status']);
        self::assertSame('manual', $plan->row['collection_method']);
        self::assertSame(
            [501 => 'subscription'],
            $plan->transactionLinks,
            'Subscription linking must demand the same FluentCart transaction type written by order staging.',
        );
        self::assertSame(['billed_cycles_offset' => 0, 'billed_cycles_deduction' => 0], $plan->corrections);
        self::assertTrue($plan->sourceReleaseRequired);
    }

    public function testPaymentCountCannotBeMadeToAgreeByCopyingTheSourceCount(): void
    {
        [$envelope, $record] = $this->subscription(2);
        $order = $this->order(2400, true);

        $this->expectExceptionMessage('target_subscription_history_count_mismatch');
        SubscriptionStagePlan::build(
            $envelope,
            $record,
            $this->decision($envelope, $record, true),
            $this->maps($record, $order),
            [$order->identity->canonical() => $order],
            '2026-02-01T00:00:00Z',
        );
    }

    public function testParentAndRenewalLinksUseTheExactTypesAlreadyWrittenOnTheirTransactions(): void
    {
        [$envelope, $record] = $this->subscription(2, [
            new SubscriptionOrderReference(41, SubscriptionOrderReference::PARENT),
            new SubscriptionOrderReference(42, SubscriptionOrderReference::RENEWAL),
        ]);
        $parent = $this->order(2400, true);
        $renewal = $this->order(2400, true, 42, SubscriptionOrderReference::RENEWAL, $parent->identity);
        $orders = [
            $parent->identity->canonical() => $parent,
            $renewal->identity->canonical() => $renewal,
        ];

        $plan = SubscriptionStagePlan::build(
            $envelope,
            $record,
            $this->decision($envelope, $record, true),
            $this->maps($record, $parent, $renewal),
            $orders,
            '2026-02-01T00:00:00Z',
        );

        self::assertSame([501 => 'subscription', 502 => 'renewal'], $plan->transactionLinks);
    }

    public function testAutomaticSourceCannotLoseItsReleaseRequirementInTheDecision(): void
    {
        [$envelope, $record] = $this->subscription(1);
        $order = $this->order(2400, true);

        $this->expectExceptionMessage('target_subscription_source_release_decision_changed');
        SubscriptionStagePlan::build(
            $envelope,
            $record,
            $this->decision($envelope, $record, false),
            $this->maps($record, $order),
            [$order->identity->canonical() => $order],
            '2026-02-01T00:00:00Z',
        );
    }

    public function testApprovedMissingSchedulePreservesAProductFallbackConflictUnderManualOwnership(): void
    {
        [$envelope, $record] = $this->subscription(
            2,
            [
                new SubscriptionOrderReference(41, SubscriptionOrderReference::PARENT),
                new SubscriptionOrderReference(42, SubscriptionOrderReference::RENEWAL),
            ],
            nextPaymentUtc: null,
            finiteCycles: null,
            sourcePlan: [
                SubscriptionRecordFactory::FINITE_CYCLES_SOURCE => SubscriptionRecordFactory::FINITE_FROM_PRODUCT,
                SubscriptionRecordFactory::PLAN_PRODUCT_LENGTH => '2',
            ],
        );
        $parent = $this->order(2400, true);
        $renewal = $this->order(2400, true, 42, SubscriptionOrderReference::RENEWAL, $parent->identity);
        $decision = $this->decision($envelope, $record, true);
        $decision['schedule_absence_decision'] = $this->scheduleAbsenceDecision($record);

        $plan = SubscriptionStagePlan::build(
            $envelope,
            $record,
            $decision,
            $this->maps($record, $parent, $renewal),
            [$parent->identity->canonical() => $parent, $renewal->identity->canonical() => $renewal],
            '2026-02-01T00:00:00Z',
        );

        self::assertNull($plan->row['next_billing_date']);
        self::assertSame('manual', $plan->row['collection_method']);
        self::assertSame('active', $plan->row['config']['intended_status']);
        self::assertSame(2, $plan->row['bill_times']);
        self::assertSame(2, $plan->row['bill_count']);
    }

    public function testMissingScheduleDecisionCannotWaiveASubscriptionDeclaredTermConflict(): void
    {
        [$envelope, $record] = $this->subscription(1, nextPaymentUtc: null, finiteCycles: 1);
        $order = $this->order(2400, true);
        $decision = $this->decision($envelope, $record, true);
        $decision['schedule_absence_decision'] = $this->scheduleAbsenceDecision($record);

        $this->expectExceptionMessage('target_subscription_lifecycle_blocked:finite_term_state_conflict');
        SubscriptionStagePlan::build(
            $envelope,
            $record,
            $decision,
            $this->maps($record, $order),
            [$order->identity->canonical() => $order],
            '2026-02-01T00:00:00Z',
        );
    }

    public function testWriterRollsBackSubscriptionLinkMapAndClaimWhenHistoryLinkFails(): void
    {
        [$envelope, $record] = $this->subscription(1);
        $order = $this->order(2400, true);
        $maps = MutableSubscriptionMaps::dependencies($record, $order);
        $plan = SubscriptionStagePlan::build(
            $envelope,
            $record,
            $this->decision($envelope, $record, true),
            $maps,
            [$order->identity->canonical() => $order],
            '2026-02-01T00:00:00Z',
        );
        $gateway = new RecordingSubscriptionGateway();
        $gateway->failLink = true;
        $claims = new RecordingSubscriptionClaims();
        $writer = new FluentCartSubscriptionWriter($gateway, $maps, $claims, new SubscriptionReconciler($gateway, $maps));

        try {
            $writer->stage($plan, new StageContext(sys_get_temp_dir(), 'run-sub-26', 'runtime', generation: 1));
            self::fail('A failed history link left a staged subscription behind.');
        } catch (\RuntimeException $exception) {
            self::assertSame('fixture_subscription_link_failure', $exception->getMessage());
        }

        self::assertSame([], $gateway->subscriptions);
        self::assertSame([], $gateway->links);
        self::assertNull($maps->get($record->identity));
        self::assertSame([], $claims->claims);
        self::assertSame(0, DatabaseTransaction::depth());
    }

    public function testWriterRetryIsReadOnlyAndIndependentReconciliationCatchesTargetDrift(): void
    {
        [$envelope, $record] = $this->subscription(1);
        $order = $this->order(2400, true);
        $maps = MutableSubscriptionMaps::dependencies($record, $order);
        $plan = SubscriptionStagePlan::build(
            $envelope,
            $record,
            $this->decision($envelope, $record, true),
            $maps,
            [$order->identity->canonical() => $order],
            '2026-02-01T00:00:00Z',
        );
        $gateway = new RecordingSubscriptionGateway();
        $claims = new RecordingSubscriptionClaims();
        $writer = new FluentCartSubscriptionWriter($gateway, $maps, $claims, new SubscriptionReconciler($gateway, $maps));
        $context = new StageContext(sys_get_temp_dir(), 'run-sub-26', 'runtime', generation: 1);

        $first = $writer->stage($plan, $context);
        $writes = $gateway->writes;
        $second = $writer->stage($plan, $context);

        self::assertFalse($first->reused);
        self::assertTrue($second->reused);
        self::assertSame($first->targetId, $second->targetId);
        self::assertSame(
            [$record->identity->canonical() => $first->targetId],
            $first->sourceTargetIds,
            'A created subscription receipt must carry the canonical root mapping required for rollback ownership proof.',
        );
        self::assertSame($first->sourceTargetIds, $second->sourceTargetIds);
        self::assertSame($writes, $gateway->writes);

        $gateway->subscriptions[$first->targetId]['collection_method'] = 'automatic';
        $result = (new SubscriptionReconciler($gateway, $maps))->reconcile(
            $plan,
            $first->targetId,
            $first->targetFingerprint,
        );
        self::assertFalse($result->matches);
        self::assertContains('subscription_graph_mismatch', $result->failures);
        self::assertContains('subscription_target_fingerprint_mismatch', $result->failures);
    }

    /** @return array{\CartShift\Domain\Transfer\RecordEnvelope,V2SubscriptionRecord} */
    /** @param null|list<SubscriptionOrderReference> $relationships */
    private function subscription(
        int $paymentCount,
        ?array $relationships = null,
        ?string $nextPaymentUtc = '2026-03-01 00:00:00',
        ?int $finiteCycles = 12,
        array $sourcePlan = [SubscriptionRecordFactory::FINITE_CYCLES_SOURCE => SubscriptionRecordFactory::FINITE_FROM_SUBSCRIPTION],
    ): array
    {
        $relationships ??= [new SubscriptionOrderReference(41, SubscriptionOrderReference::PARENT)];
        $legacy = new SubscriptionRecord(
            'shop-alpha', 'subscription:77', 77, 'active', 'PLN', 'customer:9', 9, '', [], 41,
            [[
                'source_item_id' => 501, 'source_product_id' => 12, 'source_variation_id' => 13,
                'name' => 'Membership', 'quantity' => 1, 'line_total' => 2000, 'line_tax' => 400,
            ]],
            new SubscriptionContract('month', 1, 'monthly', 2000, 400, 2400, $finiteCycles, 0, 'day', 0, $sourcePlan),
            'stripe', false, ['stripe_source_id' => 'pm_fixture'],
            new SubscriptionDates('2026-01-01 00:00:00', null, $nextPaymentUtc, null, null),
            $relationships,
            $paymentCount,
            '',
        );
        $legacy = $legacy->withFingerprint(SubscriptionRecordFactory::digest($legacy->fingerprintPayload()));
        $record = V2SubscriptionRecord::fromV1($legacy, new SourceIdentity('shop-alpha', 'customer', '9'));
        return [$record->envelope(), $record];
    }

    private function order(
        int $amount,
        bool $paid,
        int $sourceId = 41,
        string $relationship = SubscriptionOrderReference::PARENT,
        ?SourceIdentity $parentOrder = null,
    ): OrderRecord
    {
        $identity = new SourceIdentity('shop-alpha', 'order', (string) $sourceId);
        $event = new PaymentEventRecord(
            new SourceIdentity('shop-alpha', 'order', $sourceId . ':charge:' . $sourceId),
            'charge', $amount, 'PLN', 'succeeded', PaymentEvidenceKind::ProviderReference,
            'stripe', 'Stripe', 'ch_fixture', null, '2026-01-01T00:00:00Z', [],
        );
        return new OrderRecord(
            $identity, new SourceIdentity('shop-alpha', 'customer', '9'), $parentOrder, $relationship, 'completed',
            'PLN', 'PLN', 'PLN', '1.0000', 'same_currency:PLN', false,
            $amount, 0, 0, 0, 0, 0, 0, 0, 0, $amount, 0,
            '2026-01-01T00:00:00Z', null, $paid ? '2026-01-01T00:00:00Z' : null, null, null,
            [], [], [], [], [], [], [$event], [], [],
        );
    }

    private function maps(V2SubscriptionRecord $record, OrderRecord ...$orders): SubscriptionPlanMaps
    {
        $item = $record->items[0];
        $targets = [
            $record->customerIdentity->canonical() => 101,
            SourceIdentity::fromCanonical($item['product_identity'])->canonical() => 201,
            SourceIdentity::fromCanonical($item['variation_identity'])->canonical() => 202,
        ];
        foreach ($orders as $index => $order) {
            $targets[$order->identity->canonical()] = 301 + $index;
            $targets[$order->paymentEvents[0]->identity->canonical()] = 501 + $index;
        }
        return new SubscriptionPlanMaps($targets);
    }

    /** @return array<string,mixed> */
    private function decision($envelope, V2SubscriptionRecord $record, bool $release): array
    {
        return [
            'action' => 'approve_subscription_manual',
            'target_collection_method' => 'manual',
            'next_action_owner' => 'target_manual',
            'source_fingerprint' => $envelope->privateContentDigest,
            'payment_reference_digest' => $record->paymentOwnership['payment_reference_digest'],
            'source_gateway' => $record->paymentOwnership['source_gateway'],
            'source_auto_renewal_release_required' => $release,
        ];
    }

    /** @return array<string,mixed> */
    private function scheduleAbsenceDecision(V2SubscriptionRecord $record): array
    {
        return [
            'action' => 'approve_mapping',
            'scope' => 'audit_finding',
            'identity' => $record->identity->canonical(),
            'finding_code' => 'subscription_schedule_absence',
            'schedule_policy' => 'preserve_absence',
        ];
    }
}

final class SubscriptionPlanMaps implements CheckedMappingStore
{
    /** @var array<string,MappingRecord> */
    private array $records = [];
    /** @param array<string,int> $targets */
    public function __construct(array $targets)
    {
        foreach ($targets as $canonical => $target) {
            $identity = SourceIdentity::fromCanonical($canonical);
            $this->records[$canonical] = new MappingRecord($identity, $target, str_repeat('a', 64), str_repeat('b', 64), MapState::Reconciled);
        }
    }
    public function get(SourceIdentity $identity): ?MappingRecord { return $this->records[$identity->canonical()] ?? null; }
    public function storeOrThrow(SourceIdentity $identity, int $targetId, string $migrationId, string $sourceFingerprint, string $targetFingerprint, MapState $state, bool $createdByMigration, int $generation = 1): MappingRecord { throw new \LogicException('unused'); }
    public function transitionOrThrow(SourceIdentity $identity, MapState $expected, MapState $next, string $expectedTargetFingerprint, string $nextTargetFingerprint): MappingRecord { throw new \LogicException('unused'); }
}

final class MutableSubscriptionMaps implements CheckedMappingStore
{
    /** @var array<string,MappingRecord> */
    public array $records = [];

    public static function dependencies(V2SubscriptionRecord $record, OrderRecord $order): self
    {
        $item = $record->items[0];
        $instance = new self();
        foreach ([
            $record->customerIdentity->canonical() => 101,
            SourceIdentity::fromCanonical($item['product_identity'])->canonical() => 201,
            SourceIdentity::fromCanonical($item['variation_identity'])->canonical() => 202,
            $order->identity->canonical() => 301,
            $order->paymentEvents[0]->identity->canonical() => 501,
        ] as $canonical => $target) {
            $identity = SourceIdentity::fromCanonical($canonical);
            $instance->records[$canonical] = new MappingRecord($identity, $target, str_repeat('a', 64), str_repeat('b', 64), MapState::Reconciled);
        }
        return $instance;
    }

    public function get(SourceIdentity $identity): ?MappingRecord { return $this->records[$identity->canonical()] ?? null; }

    public function storeOrThrow(SourceIdentity $identity, int $targetId, string $migrationId, string $sourceFingerprint, string $targetFingerprint, MapState $state, bool $createdByMigration, int $generation = 1): MappingRecord
    {
        if (isset($this->records[$identity->canonical()])) throw new \RuntimeException('fixture_duplicate_map');
        $record = new MappingRecord($identity, $targetId, $sourceFingerprint, $targetFingerprint, $state);
        $this->records[$identity->canonical()] = $record;
        DatabaseTransaction::afterRollback(function () use ($identity): void { unset($this->records[$identity->canonical()]); });
        return $record;
    }

    public function transitionOrThrow(SourceIdentity $identity, MapState $expected, MapState $next, string $expectedTargetFingerprint, string $nextTargetFingerprint): MappingRecord
    {
        $before = $this->get($identity);
        if ($before === null || $before->state !== $expected || !hash_equals((string) $before->targetFingerprint, $expectedTargetFingerprint)) {
            throw new \RuntimeException('fixture_map_transition_conflict');
        }
        $after = new MappingRecord($identity, $before->targetId, $before->sourceFingerprint, $nextTargetFingerprint, $next);
        $this->records[$identity->canonical()] = $after;
        DatabaseTransaction::afterRollback(function () use ($identity, $before): void { $this->records[$identity->canonical()] = $before; });
        return $after;
    }
}

final class RecordingSubscriptionGateway implements SubscriptionTargetGateway
{
    /** @var array<int,array<string,mixed>> */
    public array $subscriptions = [];
    /** @var array<int,array{id:int,subscription_id:int,order_type:string}> */
    public array $links = [];
    /** @var array<int,array<string,int>> */
    public array $meta = [];
    public bool $failLink = false;
    public int $writes = 0;
    private int $nextId = 700;

    public function create(array $row): int
    {
        $id = $this->nextId++;
        $this->subscriptions[$id] = ['id' => $id] + $row;
        ++$this->writes;
        DatabaseTransaction::afterRollback(function () use ($id): void { unset($this->subscriptions[$id]); });
        return $id;
    }

    public function exists(int $subscriptionId): bool { return isset($this->subscriptions[$subscriptionId]); }

    public function snapshot(int $subscriptionId): array
    {
        return [
            'subscription' => $this->subscriptions[$subscriptionId] ?? null,
            'transaction_links' => array_values(array_filter($this->links, static fn (array $link): bool => $link['subscription_id'] === $subscriptionId)),
            'meta' => $this->meta[$subscriptionId] ?? [],
        ];
    }

    public function linkTransaction(int $transactionId, int $subscriptionId, string $orderType): void
    {
        if ($this->failLink) throw new \RuntimeException('fixture_subscription_link_failure');
        $this->links[$transactionId] = ['id' => $transactionId, 'subscription_id' => $subscriptionId, 'order_type' => $orderType];
        ++$this->writes;
        DatabaseTransaction::afterRollback(function () use ($transactionId): void { unset($this->links[$transactionId]); });
    }

    public function writeCorrection(int $subscriptionId, string $key, int $value): void
    {
        if ($value === 0) return;
        $this->meta[$subscriptionId][$key] = $value;
        ++$this->writes;
        DatabaseTransaction::afterRollback(function () use ($subscriptionId, $key): void { unset($this->meta[$subscriptionId][$key]); });
    }
}

final class RecordingSubscriptionClaims implements TargetClaimStore
{
    /** @var array<string,MappingRecord> */
    public array $claims = [];

    public function claimOrThrow(SourceIdentity $identity, int $targetId, string $runId, string $sourceFingerprint, string $targetFingerprint, MapState $state): MappingRecord
    {
        $key = $identity->canonical();
        $existing = $this->claims[$key] ?? null;
        if ($existing !== null) {
            if (!$existing->isCompatibleWith(new MappingRecord($identity, $targetId, $sourceFingerprint, $targetFingerprint, $state))) {
                throw new \RuntimeException('fixture_claim_conflict');
            }
            return $existing;
        }
        $record = new MappingRecord($identity, $targetId, $sourceFingerprint, $targetFingerprint, $state);
        $this->claims[$key] = $record;
        DatabaseTransaction::afterRollback(function () use ($key): void { unset($this->claims[$key]); });
        return $record;
    }
}
