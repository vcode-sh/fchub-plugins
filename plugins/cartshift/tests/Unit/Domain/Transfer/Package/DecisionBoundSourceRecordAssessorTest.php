<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\AssessmentContext;
use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Package\DecisionBoundSourceRecordAssessor;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class DecisionBoundSourceRecordAssessorTest extends PluginTestCase
{
    public function testCustomerCannotShipWithoutAnExactFingerprintBoundOwnershipDecision(): void
    {
        $record = $this->record('customer', '7');
        $assessor = new DecisionBoundSourceRecordAssessor();

        self::assertSame(
            AssessmentOutcome::Blocked,
            $assessor->assess($record, $this->context(TransferDecisionSet::empty()))->outcome,
        );

        $stale = $this->decisions($record, 'attach_exact_same_site_user', [
            'source_fingerprint' => str_repeat('0', 64),
            'user_id' => 7,
        ]);
        self::assertSame(
            AssessmentOutcome::Blocked,
            $assessor->assess($record, $this->context($stale))->outcome,
        );

        $exact = $this->decisions($record, 'attach_exact_same_site_user', ['user_id' => 7]);
        self::assertSame(
            AssessmentOutcome::Linked,
            $assessor->assess($record, $this->context($exact))->outcome,
        );
    }

    public function testPayloadCannotClaimASecondIdentityAndExcludedRecordCannotLeakIntoPackage(): void
    {
        $identity = new SourceIdentity('shop-alpha', 'product', '9');
        $liar = RecordEnvelope::forPayload(2, $identity, [
            'identity' => 'shop-alpha:product:10',
            'dependencies' => [],
        ]);
        $assessor = new DecisionBoundSourceRecordAssessor();

        self::assertSame(
            AssessmentOutcome::Blocked,
            $assessor->assess($liar, $this->context(TransferDecisionSet::empty()))->outcome,
        );

        $record = $this->record('product', '9');
        $excluded = $this->decisions($record, 'excluded_by_policy');
        self::assertSame(
            AssessmentOutcome::Blocked,
            $assessor->assess($record, $this->context($excluded))->outcome,
        );
    }

    public function testEmbeddedAssetMustRetainItsOwnCanonicalIdentity(): void
    {
        $identity = new SourceIdentity('shop-alpha', 'media_asset', '44');
        $record = RecordEnvelope::forPayload(2, $identity, [
            'identity' => 'shop-alpha:media_asset:45',
            'dependencies' => [],
            'locator' => 'https://example.test/uploads/a.jpg',
            'expected_sha256' => null,
            'size' => null,
        ]);

        self::assertSame(
            AssessmentOutcome::Blocked,
            (new DecisionBoundSourceRecordAssessor())->assess(
                $record,
                $this->context(TransferDecisionSet::empty()),
            )->outcome,
        );
    }

    private function record(string $kind, string $id): RecordEnvelope
    {
        $identity = new SourceIdentity('shop-alpha', $kind, $id);

        return RecordEnvelope::forPayload(2, $identity, [
            'identity' => $identity->canonical(),
            'dependencies' => [],
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function decisions(RecordEnvelope $record, string $action, array $extra = []): TransferDecisionSet
    {
        return TransferDecisionSet::fromArray([array_replace([
            'identity' => $record->identity->canonical(),
            'action' => $action,
            'source_fingerprint' => $record->privateContentDigest,
            'operator' => 'owner',
            'reason' => 'Exact source record reviewed.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ], $extra)]);
    }

    private function context(TransferDecisionSet $decisions): AssessmentContext
    {
        return new AssessmentContext(['decisions' => $decisions]);
    }
}
