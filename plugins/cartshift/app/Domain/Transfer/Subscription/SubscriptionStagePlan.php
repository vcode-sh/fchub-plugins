<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionContract;
use CartShift\Domain\Subscription\SubscriptionDates;
use CartShift\Domain\Subscription\SubscriptionLifecycleProjector;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord as LegacySubscriptionRecord;
use CartShift\Domain\Subscription\Source\SubscriptionRecord;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Support\UtcDateTime;

defined('ABSPATH') || exit;

/** A sealed, inert subscription projection. Live records are always staged paused. */
final readonly class SubscriptionStagePlan
{
    /** @param array<string,mixed> $row @param array<int,string> $transactionLinks @param array<string,int> $corrections @param array<string,int> $dependencyTargets */
    private function __construct(
        public SubscriptionRecord $record,
        public LegacySubscriptionRecord $legacyRecord,
        public array $row,
        public array $transactionLinks,
        public array $corrections,
        public string $sourceFingerprint,
        public bool $sourceReleaseRequired,
        public array $dependencyTargets,
    ) {}

    /**
     * @param array<string,mixed> $decision
     * @param array<string,OrderRecord> $orders Canonical identity to record.
     */
    public static function build(
        RecordEnvelope $envelope,
        SubscriptionRecord $record,
        array $decision,
        CheckedMappingStore $maps,
        array $orders,
        string $evaluationUtc,
    ): self {
        if ($envelope->identity->canonical() !== $record->identity->canonical()
            || ($decision['action'] ?? null) !== 'approve_subscription_manual'
            || ($decision['target_collection_method'] ?? null) !== PaymentMigrationDecision::COLLECTION_MANUAL
            || ($decision['next_action_owner'] ?? null) !== PaymentMigrationDecision::OWNER_TARGET_MANUAL
            || !hash_equals($envelope->sourceContentDigest, (string) ($decision['source_fingerprint'] ?? ''))
            || !hash_equals((string) $record->paymentOwnership['payment_reference_digest'], (string) ($decision['payment_reference_digest'] ?? ''))
            || ($decision['source_gateway'] ?? null) !== $record->paymentOwnership['source_gateway']) {
            throw new \RuntimeException('target_subscription_payment_decision_missing_or_stale:' . $record->identity->canonical());
        }
        if (count($record->items) !== 1) {
            throw new \RuntimeException('target_subscription_multi_item_blocked');
        }

        $legacy = self::legacy($record, $envelope->privateContentDigest);
        $terminal = in_array(strtolower($record->status), ['cancelled', 'canceled', 'expired', 'switched'], true);
        $releaseRequired = !$terminal && !$legacy->requiresManualRenewal;
        if (($decision['source_auto_renewal_release_required'] ?? null) !== $releaseRequired) {
            throw new \RuntimeException('target_subscription_source_release_decision_changed');
        }

        $payment = new PaymentMigrationDecision(
            PaymentMigrationDecision::STRATEGY_MANUAL,
            PaymentMigrationDecision::OUTCOME_READY,
            PaymentMigrationDecision::COLLECTION_MANUAL,
            '',
            PaymentMigrationDecision::OWNER_TARGET_MANUAL,
            null,
            null,
            null,
            [],
            [],
        );
        $lifecycle = (new SubscriptionLifecycleProjector())->project($legacy, new \CartShift\Domain\Subscription\Payment\PaymentEnvironment(
            new \CartShift\Domain\Subscription\Payment\PaymentCapabilityProbe(),
            '',
            manualFallbackConfirmed: true,
            nowUtc: UtcDateTime::targetFromCanonical($evaluationUtc),
        ));
        $lifecycleErrors = (array) ($lifecycle['errors'] ?? []);
        if (self::approvedScheduleAbsence($record, $decision)) {
            $allowed = [SubscriptionLifecycleProjector::REASON_ACTIVE_NEXT_DATE_MISSING];
            if (($record->contract['source_plan'][\CartShift\Domain\Subscription\SubscriptionRecordFactory::FINITE_CYCLES_SOURCE] ?? null)
                === \CartShift\Domain\Subscription\SubscriptionRecordFactory::FINITE_FROM_PRODUCT) {
                $allowed[] = SubscriptionLifecycleProjector::REASON_FINITE_TERM_STATE_CONFLICT;
            }
            $lifecycleErrors = array_values(array_diff($lifecycleErrors, $allowed));
        }
        if ($lifecycleErrors !== []) {
            throw new \RuntimeException('target_subscription_lifecycle_blocked:' . implode(',', $lifecycleErrors));
        }

        $item = $record->items[0];
        $product = SourceIdentity::fromCanonical((string) $item['product_identity']);
        $variation = SourceIdentity::fromCanonical((string) $item['variation_identity']);
        $parent = self::parent($record);
        $dependencyTargets = [];
        $mapped = static function (SourceIdentity $identity, string $label) use ($maps, &$dependencyTargets): int {
            $target = self::mapped($maps, $identity, $label);
            $dependencyTargets[$identity->canonical()] = $target;
            return $target;
        };
        $references = [
            'customer_id' => $mapped($record->customerIdentity, 'customer'),
            'parent_order_id' => $mapped($parent, 'parent_order'),
            'product_id' => $mapped($product, 'product'),
            'variation_id' => $mapped($variation, 'variation'),
            'item_name' => (string) $item['name'],
            'quantity' => (int) $item['quantity'],
        ];
        $assessment = new SubscriptionAssessment(
            SubscriptionAssessment::OUTCOME_READY,
            [],
            [],
            $references,
            $payment,
            $lifecycle,
        );
        $row = (new SubscriptionMapper())->map($legacy, $assessment);
        $row['uuid'] = md5('cartshift-v2-subscription:' . $record->identity->canonical());
        $row['initial_tax_total'] = 0;

        [$links, $corrections, $historyTargets] = self::history($record, $orders, $maps, $legacy);
        $dependencyTargets += $historyTargets;
        $calculated = count($links)
            + $corrections['billed_cycles_offset']
            - $corrections['billed_cycles_deduction'];
        if ($calculated !== $legacy->sourcePaymentCount) {
            throw new \RuntimeException('target_subscription_history_count_mismatch:' . $record->identity->canonical());
        }

        ksort($dependencyTargets, SORT_STRING);
        return new self($record, $legacy, CanonicalJson::canonicalise($row), $links, $corrections, $envelope->privateContentDigest, $releaseRequired, $dependencyTargets);
    }

    /** @return LegacySubscriptionRecord */
    private static function legacy(SubscriptionRecord $record, string $fingerprint): LegacySubscriptionRecord
    {
        $contract = $record->contract;
        $items = array_map(static function (array $item): array {
            $product = SourceIdentity::fromCanonical((string) $item['product_identity']);
            $variation = SourceIdentity::fromCanonical((string) $item['variation_identity']);
            $parts = explode(':variation:', $variation->sourceId, 2);
            return [
                'source_item_id' => (int) $item['source_item_id'],
                'source_product_id' => (int) $product->sourceId,
                'source_variation_id' => isset($parts[1]) && $parts[1] !== $product->sourceId ? (int) $parts[1] : 0,
                'name' => (string) $item['name'],
                'quantity' => (int) $item['quantity'],
                'line_total' => (int) $item['line_total'],
                'line_tax' => (int) $item['line_tax'],
            ];
        }, $record->items);
        $relationships = array_map(static fn (array $reference): SubscriptionOrderReference => new SubscriptionOrderReference(
            (int) SourceIdentity::fromCanonical((string) $reference['identity'])->sourceId,
            (string) $reference['relationship'],
        ), $record->relatedOrders);
        $date = static fn (?string $value): ?string => $value === null ? null : UtcDateTime::targetFromCanonical($value);
        $sourceId = (int) $record->identity->sourceId;
        $customerNumeric = preg_match('/\A[1-9][0-9]*\z/D', $record->customerIdentity->sourceId) === 1;

        return new LegacySubscriptionRecord(
            $record->identity->sourceKey,
            'subscription:' . $record->identity->sourceId,
            $sourceId,
            $record->status,
            $record->currency,
            $record->customerIdentity->sourceId,
            $customerNumeric ? (int) $record->customerIdentity->sourceId : null,
            '',
            [],
            (int) self::parent($record)->sourceId,
            $items,
            new SubscriptionContract(
                (string) $contract['period'],
                (int) $contract['multiplier'],
                (string) $contract['target_interval'],
                (int) $contract['recurring_amount'],
                (int) $contract['recurring_tax'],
                (int) $contract['recurring_total'],
                $contract['finite_cycles'] === null ? null : (int) $contract['finite_cycles'],
                (int) $contract['trial_length'],
                (string) $contract['trial_period'],
                (int) $contract['setup_fee'],
                (array) $contract['source_plan'],
            ),
            (string) $record->paymentOwnership['source_gateway'],
            (bool) $record->paymentOwnership['source_requires_manual_renewal'],
            (array) $record->paymentOwnership['payment_references'],
            new SubscriptionDates(
                $date($record->schedule['start_utc']),
                $date($record->schedule['trial_end_utc']),
                $date($record->schedule['next_payment_utc']),
                $date($record->schedule['cancelled_utc']),
                $date($record->schedule['end_utc']),
            ),
            $relationships,
            (int) $record->paymentOwnership['source_payment_count'],
            $fingerprint,
        );
    }

    private static function parent(SubscriptionRecord $record): SourceIdentity
    {
        $parents = array_values(array_filter($record->relatedOrders, static fn (array $r): bool => $r['relationship'] === SubscriptionOrderReference::PARENT));
        if (count($parents) !== 1) {
            throw new \RuntimeException('target_subscription_parent_ambiguous');
        }
        return SourceIdentity::fromCanonical((string) $parents[0]['identity']);
    }

    /** @return array{array<int,string>,array{billed_cycles_offset:int,billed_cycles_deduction:int},array<string,int>} */
    private static function history(SubscriptionRecord $record, array $orders, CheckedMappingStore $maps, LegacySubscriptionRecord $legacy): array
    {
        $links = [];
        $targets = [];
        $offset = 0;
        $deduction = 0;
        foreach ($record->relatedOrders as $reference) {
            if (!in_array($reference['relationship'], [SubscriptionOrderReference::PARENT, SubscriptionOrderReference::RENEWAL], true)) {
                continue;
            }
            $order = $orders[(string) $reference['identity']] ?? null;
            if (!$order instanceof OrderRecord || $order->relationshipType !== $reference['relationship']) {
                throw new \RuntimeException('target_subscription_order_relationship_changed');
            }
            $positive = 0;
            foreach ($order->paymentEvents as $event) {
                if ($event->type === 'charge' && $event->status === 'succeeded' && $event->amount > 0) {
                    $target = self::mapped($maps, $event->identity, 'order_transaction');
                    $links[$target] = self::orderType($reference['relationship']);
                    $targets[$event->identity->canonical()] = $target;
                    ++$positive;
                }
            }
            if ($positive === 0 && $order->grossTotal === 0 && $order->paidUtc !== null) {
                ++$offset;
            }
            if ($reference['relationship'] === SubscriptionOrderReference::PARENT
                && $legacy->contract->trialLength > 0
                && $legacy->dates->trialEndUtc !== null
                && $legacy->contract->finiteCycles !== null
                && $legacy->contract->finiteCycles > 0
                && $legacy->contract->setupFee > 0
                && $order->grossTotal === $legacy->contract->setupFee) {
                $deduction = 1;
            }
        }
        ksort($links, SORT_NUMERIC);
        ksort($targets, SORT_STRING);
        return [$links, ['billed_cycles_offset' => $offset, 'billed_cycles_deduction' => $deduction], $targets];
    }

    private static function orderType(string $relationship): string
    {
        return match ($relationship) {
            SubscriptionOrderReference::PARENT => 'subscription',
            SubscriptionOrderReference::RENEWAL => 'renewal',
            default => throw new \LogicException('target_subscription_order_relationship_invalid'),
        };
    }

    private static function mapped(CheckedMappingStore $maps, SourceIdentity $identity, string $label): int
    {
        $mapping = $maps->get($identity);
        if ($mapping === null || !$mapping->isActive() || $mapping->targetId <= 0) {
            throw new \RuntimeException('target_subscription_dependency_mapping_missing:' . $label);
        }
        return $mapping->targetId;
    }

    /** @param array<string,mixed> $decision */
    private static function approvedScheduleAbsence(SubscriptionRecord $record, array $decision): bool
    {
        $schedule = $decision['schedule_absence_decision'] ?? null;
        return is_array($schedule)
            && ($schedule['action'] ?? null) === 'approve_mapping'
            && ($schedule['scope'] ?? null) === 'audit_finding'
            && ($schedule['identity'] ?? null) === $record->identity->canonical()
            && ($schedule['finding_code'] ?? null) === 'subscription_schedule_absence'
            && ($schedule['schedule_policy'] ?? null) === 'preserve_absence'
            && $record->status === 'active'
            && $record->schedule['next_payment_utc'] === null;
    }
}
