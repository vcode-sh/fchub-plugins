<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\Identity\IdentityConflict;
use CartShift\Domain\Transfer\Identity\TargetCandidate;
use CartShift\Domain\Transfer\Identity\TargetCandidateResolver;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Storage\IdMapRepository;
use CartShift\Tests\Unit\PluginTestCase;

final class TargetCandidateResolverTest extends PluginTestCase
{
    public function testInvoiceMatchIsSignalNotAdoption(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (string $query): array =>
            str_contains($query, 'fct_orders')
                ? [(object) ['id' => 900, 'currency' => 'PLN', 'total_amount' => '12900', 'created_at' => '2026-01-01 12:00:00']]
                : [];

        $candidates = (new TargetCandidateResolver())->candidates($this->orderRecord());

        self::assertCount(1, $candidates);
        self::assertFalse($candidates[0]->isApproved());
        self::assertSame('invoice_only', $candidates[0]->matchReason);
        self::assertSame(900, $candidates[0]->targetId);
    }

    public function testInvoiceCollisionCannotSatisfyApprovedLinkRequirement(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [
            (object) ['id' => 900, 'currency' => 'PLN', 'total_amount' => '12900', 'created_at' => '2026-01-01 12:00:00'],
        ];
        $candidate = (new TargetCandidateResolver())->candidates($this->orderRecord())[0];
        $this->expectException(IdentityConflict::class);

        (new TargetCandidateResolver())->requireApprovedLink(
            $this->orderRecord(),
            900,
            $candidate->targetFingerprint,
        );
    }

    public function testExplicitApprovedCandidateRequiresExactTargetAndFingerprint(): void
    {
        $fingerprint = str_repeat('b', 64);
        $resolver = new TargetCandidateResolver(static fn (): array => [
            new TargetCandidate(901, $fingerprint, 'operator_decision', true),
        ]);

        self::assertSame(901, $resolver->requireApprovedLink($this->orderRecord(), 901, $fingerprint)->targetId);

        try {
            $resolver->requireApprovedLink($this->orderRecord(), 901, str_repeat('c', 64));
            self::fail('A changed target fingerprint must invalidate approval.');
        } catch (IdentityConflict) {
            self::addToAssertionCount(1);
        }
    }

    public function testPaidOrderInvoiceMutationCannotBreakSourceIdentityMapping(): void
    {
        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query): array {
            if (str_contains($query, 'cartshift_id_map')) {
                return [(object) [
                    'fc_id' => 900,
                    'migration_id' => 'run-1',
                    'created_by_migration' => 1,
                    'source_fingerprint' => str_repeat('a', 64),
                    'target_fingerprint' => str_repeat('b', 64),
                    'record_state' => 'promoted',
                ]];
            }

            return [(object) ['id' => 901, 'currency' => 'PLN', 'total_amount' => '12900', 'created_at' => '2026-01-01 12:00:00']];
        };

        $mapping = (new IdMapRepository('lapka-web'))->get($this->orderRecord()->identity);
        $candidates = (new TargetCandidateResolver())->candidates($this->orderRecord());

        self::assertSame(900, $mapping?->targetId);
        self::assertSame(901, $candidates[0]->targetId);
        self::assertFalse($candidates[0]->isApproved());
    }

    private function orderRecord(): RecordEnvelope
    {
        return RecordEnvelope::forPayload(
            1,
            new SourceIdentity('lapka-web', 'order', '42'),
            ['currency' => 'PLN', 'gross_total' => '12900', 'created_at' => '2026-01-01 12:00:00'],
        );
    }
}
