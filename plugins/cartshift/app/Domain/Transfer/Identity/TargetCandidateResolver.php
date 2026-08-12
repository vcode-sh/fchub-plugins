<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Migration\OrderIdentity;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class TargetCandidateResolver
{
    /** @var (\Closure(RecordEnvelope): iterable<TargetCandidate>)|null */
    private readonly ?\Closure $reader;

    /** @param (callable(RecordEnvelope): iterable<TargetCandidate>)|null $reader */
    public function __construct(?callable $reader = null)
    {
        $this->reader = $reader !== null ? $reader(...) : null;
    }

    /** @return list<TargetCandidate> */
    public function candidates(RecordEnvelope $record): array
    {
        $candidates = $this->reader !== null
            ? iterator_to_array(($this->reader)($record), false)
            : $this->loadedCandidates($record);

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof TargetCandidate) {
                throw new \RuntimeException('Target candidate reader returned an invalid value.');
            }
        }

        usort($candidates, static fn (TargetCandidate $left, TargetCandidate $right): int =>
            $left->targetId <=> $right->targetId
        );

        return array_values($candidates);
    }

    public function requireApprovedLink(
        RecordEnvelope $record,
        int $targetId,
        string $approvedTargetFingerprint,
    ): TargetCandidate {
        foreach ($this->candidates($record) as $candidate) {
            if (
                $candidate->targetId === $targetId
                && hash_equals($candidate->targetFingerprint, $approvedTargetFingerprint)
                && $candidate->isApproved()
            ) {
                return $candidate;
            }
        }

        throw IdentityConflict::forIdentity($record->identity);
    }

    /** @return list<TargetCandidate> */
    private function loadedCandidates(RecordEnvelope $record): array
    {
        if ($record->identity->kind() !== RecordKind::Order) {
            return [];
        }

        global $wpdb;

        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            throw new \RuntimeException('Target candidate storage is unavailable.');
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, currency, total_amount, created_at
             FROM {$wpdb->prefix}fct_orders
             WHERE invoice_no = %s
             ORDER BY id ASC",
            OrderIdentity::invoiceNo((int) $record->identity->sourceId),
        ));

        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('Target candidate read failed.');
        }

        return array_map(static function (object $row): TargetCandidate {
            $shape = [
                'id' => (int) $row->id,
                'currency' => (string) ($row->currency ?? ''),
                'gross_total' => (string) ($row->total_amount ?? ''),
                'created_at' => (string) ($row->created_at ?? ''),
            ];

            return new TargetCandidate(
                (int) $row->id,
                CanonicalJson::fingerprint($shape),
                'invoice_only',
                false,
                ['invoice_signal' => true],
            );
        }, is_array($rows) ? $rows : []);
    }
}
