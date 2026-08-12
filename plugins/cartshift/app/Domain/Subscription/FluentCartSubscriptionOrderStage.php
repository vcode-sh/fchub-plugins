<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

use CartShift\Domain\Transfer\Order\OrderProjectionContext;
use CartShift\Domain\Transfer\Order\OrderRecord as CanonicalOrderRecord;
use CartShift\Domain\Transfer\Order\OrderStagePlan;
use CartShift\Domain\Transfer\Order\OrderStageResult;
use CartShift\Domain\Transfer\Order\OrderStageWriter;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\StageContext;

defined('ABSPATH') || exit;

/**
 * Binds a subscription package's typed reference to the one canonical order
 * writer. The resolver is deliberately external: a v1 payload may be used only
 * after Task 23 proves a lossless conversion to the immutable v2 order ledger.
 */
final class FluentCartSubscriptionOrderStage implements SubscriptionOrderStage
{
    private readonly \Closure $canonicalOrderResolver;
    private readonly \Closure $projectionContextResolver;
    private readonly \Closure $noteDecisionResolver;

    /**
     * @param callable(OrderRecord, string): CanonicalOrderRecord $canonicalOrderResolver
     * @param callable(CanonicalOrderRecord): OrderProjectionContext $projectionContextResolver
     * @param callable(CanonicalOrderRecord): array{canonical_customer_note:?SourceIdentity,decision_fingerprint:?string} $noteDecisionResolver
     */
    public function __construct(
        private readonly OrderStageWriter $writer,
        private readonly StageContext $context,
        callable $canonicalOrderResolver,
        callable $projectionContextResolver,
        callable $noteDecisionResolver,
    ) {
        $this->canonicalOrderResolver = $canonicalOrderResolver(...);
        $this->projectionContextResolver = $projectionContextResolver(...);
        $this->noteDecisionResolver = $noteDecisionResolver(...);
    }

    public function stage(
        OrderRecord $source,
        string $relationship,
        ?int $customerTargetId,
        ?int $parentTargetId,
        string $migrationId,
    ): OrderStageResult {
        if ($migrationId !== $this->context->migrationId) {
            throw new SourceRecordException(
                'source_fingerprint_changed',
                'Subscription order stage context does not match the approved migration.',
            );
        }
        $record = ($this->canonicalOrderResolver)($source, $relationship);
        if (!$record instanceof CanonicalOrderRecord
            || $record->identity->sourceKey !== $source->sourceKey
            || $record->identity->sourceId !== (string) $source->sourceOrderId
            || $record->relationshipType !== $relationship) {
            throw new SourceRecordException(
                'blocked_subscription_v1_conversion',
                'Subscription order did not resolve to the exact typed canonical ledger record.',
            );
        }
        $projection = ($this->projectionContextResolver)($record);
        if (!$projection instanceof OrderProjectionContext) {
            throw new \LogicException('Subscription order projection resolver returned an invalid context.');
        }
        $decision = ($this->noteDecisionResolver)($record);
        $canonicalNote = $decision['canonical_customer_note'] ?? null;
        if ($canonicalNote !== null && !$canonicalNote instanceof SourceIdentity) {
            throw new \LogicException('Subscription order note resolver returned an invalid source identity.');
        }
        $plan = OrderStagePlan::build(
            $record,
            $projection,
            customerTargetId: $customerTargetId,
            parentTargetId: $parentTargetId,
            canonicalCustomerNote: $canonicalNote,
            noteDecisionFingerprint: $decision['decision_fingerprint'] ?? null,
        );
        return $this->writer->stage($plan, $this->context);
    }
}
