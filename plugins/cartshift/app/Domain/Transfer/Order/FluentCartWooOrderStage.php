<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\StageContext;

defined('ABSPATH') || exit;

/** Converts one live Woo order to the immutable ledger before using the shared writer. */
final class FluentCartWooOrderStage implements WooOrderStage
{
    private readonly \Closure $projectionContextResolver;
    private readonly \Closure $targetReferenceResolver;
    private readonly \Closure $noteDecisionResolver;

    /**
     * @param callable(OrderRecord): OrderProjectionContext $projectionContextResolver
     * @param callable(OrderRecord): array{customer_target_id:?int,parent_target_id:?int} $targetReferenceResolver
     * @param callable(OrderRecord): array{canonical_customer_note:?SourceIdentity,decision_fingerprint:?string} $noteDecisionResolver
     */
    public function __construct(
        private readonly string $sourceKey,
        private readonly OrderRecordFactory $factory,
        private readonly OrderStageWriter $writer,
        private readonly StageContext $context,
        callable $projectionContextResolver,
        callable $targetReferenceResolver,
        callable $noteDecisionResolver,
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $this->projectionContextResolver = $projectionContextResolver(...);
        $this->targetReferenceResolver = $targetReferenceResolver(...);
        $this->noteDecisionResolver = $noteDecisionResolver(...);
    }

    public function stage(object $wooOrder, string $migrationId): OrderStageResult
    {
        if ($migrationId !== $this->context->migrationId) {
            throw new SourceRecordException(
                'source_fingerprint_changed',
                'Woo order stage context does not match the approved migration.',
            );
        }
        $record = $this->factory->fromWooOrder($wooOrder, $this->sourceKey);
        $projection = ($this->projectionContextResolver)($record);
        $references = ($this->targetReferenceResolver)($record);
        $decision = ($this->noteDecisionResolver)($record);
        if (!$projection instanceof OrderProjectionContext) {
            throw new \LogicException('Woo order projection resolver returned an invalid context.');
        }
        $canonicalNote = $decision['canonical_customer_note'] ?? null;
        if ($canonicalNote !== null && !$canonicalNote instanceof SourceIdentity) {
            throw new \LogicException('Woo order note resolver returned an invalid source identity.');
        }
        $plan = OrderStagePlan::build(
            $record,
            $projection,
            customerTargetId: $references['customer_target_id'] ?? null,
            parentTargetId: $references['parent_target_id'] ?? null,
            canonicalCustomerNote: $canonicalNote,
            noteDecisionFingerprint: $decision['decision_fingerprint'] ?? null,
        );
        return $this->writer->stage($plan, $this->context);
    }
}
