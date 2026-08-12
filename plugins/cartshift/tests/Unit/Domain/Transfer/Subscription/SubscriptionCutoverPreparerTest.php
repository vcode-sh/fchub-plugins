<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Subscription;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PreparedTransfer;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\Subscription\SubscriptionCutoverPreparer;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionCutoverPreparerTest extends PluginTestCase
{
    public function testExactRecordDecisionAndReceiptProducePausedReleasePlan(): void
    {
        [$prepared, $record, $receipt] = $this->fixture();
        $evidence = (new SubscriptionCutoverPreparer())->prepare(
            $prepared, [$record], $this->decisions($record, true), [$receipt], str_repeat('7', 64), str_repeat('8', 64), '2026-08-10T12:10:00Z',
        );

        self::assertNotNull($evidence);
        self::assertSame('rehearsal', $evidence->executionContext);
        self::assertTrue($evidence->entries[0]['source_release_required']);
        self::assertSame('pending', $evidence->entries[0]['release_state']);
        self::assertSame('paused', $evidence->entries[0]['activation_state']);
        self::assertSame('active', $evidence->entries[0]['intended_status']);
    }

    public function testConvenientReleaseDecisionDisagreementStops(): void
    {
        [$prepared, $record, $receipt] = $this->fixture();
        $this->expectExceptionMessage('subscription_cutover_decision_changed');
        (new SubscriptionCutoverPreparer())->prepare(
            $prepared, [$record], $this->decisions($record, false), [$receipt], str_repeat('7', 64), str_repeat('8', 64), '2026-08-10T12:10:00Z',
        );
    }

    public function testMissingReceiptCannotShrinkTheCutoverCohort(): void
    {
        [$prepared, $record] = $this->fixture();
        $this->expectExceptionMessage('subscription_cutover_source_receipt_coverage_changed');
        (new SubscriptionCutoverPreparer())->prepare(
            $prepared, [$record], $this->decisions($record, true), [], str_repeat('7', 64), str_repeat('8', 64), '2026-08-10T12:10:00Z',
        );
    }

    /** @return array{PreparedTransfer,RecordEnvelope,TransferReceipt} */
    private function fixture(): array
    {
        $target = new TargetStateFingerprint(str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64), str_repeat('4', 64), str_repeat('5', 64), str_repeat('6', 64), str_repeat('7', 64));
        $prepared = new PreparedTransfer('run-task-22', '/srv/private/package', str_repeat('1', 64), $target, 'rehearsal', [], false, '2026-08-10T12:00:00Z', 'shop-alpha');
        $identity = new SourceIdentity('shop-alpha', 'subscription', '31');
        $record = RecordEnvelope::forPayload(1, $identity, [
            'identity' => $identity->canonical(), 'status' => 'active', 'dependencies' => [],
            'payment_ownership' => ['source_requires_manual_renewal' => false, 'source_gateway' => 'stripe', 'payment_reference_digest' => str_repeat('9', 64)],
        ]);
        $receipt = new TransferReceipt($prepared->runId, 'subscription', $identity->canonical(), 1, $record->privateContentDigest, 'created', ['primary' => 9031], null, str_repeat('8', 64), 1, '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z');
        return [$prepared, $record, $receipt];
    }

    private function decisions(RecordEnvelope $record, bool $release): TransferDecisionSet
    {
        return TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(), 'action' => 'approve_subscription_manual',
            'target_collection_method' => 'manual', 'next_action_owner' => 'target_manual',
            'payment_reference_digest' => str_repeat('9', 64), 'source_gateway' => 'stripe',
            'source_auto_renewal_release_required' => $release, 'source_fingerprint' => $record->privateContentDigest,
            'operator' => 'owner', 'reason' => 'Exact manual ownership decision.', 'decided_at' => '2026-08-10T12:00:00Z',
        ]]);
    }
}
