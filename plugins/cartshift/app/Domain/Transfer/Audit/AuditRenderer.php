<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

defined('ABSPATH') || exit;

final class AuditRenderer
{
    public static function json(TransferAuditReport $report): string
    {
        return json_encode(self::document($report), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function document(TransferAuditReport $report): array
    {
        return [
            'source_key' => $report->sourceKey,
            'selection_fingerprint' => $report->selectionFingerprint,
            'decision_fingerprint' => $report->decisionFingerprint,
            'runtime_fingerprint' => $report->runtimeFingerprint,
            'audit_fingerprint' => $report->auditFingerprint,
            'ready' => $report->ready,
            'counts' => $report->counts,
            'capabilities' => $report->capabilities,
            'blockers' => $report->blockers,
        ];
    }

    /** @return list<array{Check: string, Result: string}> */
    public static function table(TransferAuditReport $report): array
    {
        return [
            ['Check' => 'Source key', 'Result' => $report->sourceKey],
            ['Check' => 'Selection fingerprint', 'Result' => $report->selectionFingerprint],
            ['Check' => 'Decision fingerprint', 'Result' => $report->decisionFingerprint],
            ['Check' => 'Runtime fingerprint', 'Result' => $report->runtimeFingerprint],
            ['Check' => 'Audit fingerprint', 'Result' => $report->auditFingerprint],
            ['Check' => 'Ready', 'Result' => $report->ready ? 'yes' : 'no'],
            ['Check' => 'Blockers', 'Result' => (string) count($report->blockers)],
        ];
    }
}
