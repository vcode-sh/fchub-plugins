<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\UtcDateTime;

defined('ABSPATH') || exit;

final readonly class HistoricalPaymentPolicy
{
    /** @param array<string, 'live'|'test'> $cohortModeDecisions */
    public function __construct(private array $cohortModeDecisions = [])
    {
        foreach ($cohortModeDecisions as $fingerprint => $mode) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1 || !in_array($mode, ['live', 'test'], true)) {
                throw new \InvalidArgumentException('Historical payment cohort decision is invalid.');
            }
        }
    }

    public function project(PaymentEventRecord $event, ?string $sourceMode, ?string $selectionFingerprint = null): PaymentProjection
    {
        $mode = in_array($sourceMode, ['live', 'test'], true) ? $sourceMode : null;
        if ($mode === null && $selectionFingerprint !== null) {
            $mode = $this->cohortModeDecisions[$selectionFingerprint] ?? null;
        }
        if ($mode === null) {
            throw new SourceRecordException(
                'target_schema_unrepresentable',
                'Historical payment mode is unknown and has no fingerprint-bound cohort decision.',
            );
        }
        $created = $event->occurredUtc;
        if ($created === null) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Historical financial event has no UTC timestamp.');
        }
        try {
            $created = UtcDateTime::targetFromCanonical($created);
        } catch (\InvalidArgumentException) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Historical financial event has no UTC timestamp.');
        }
        return new PaymentProjection(
            'wc_migrated', 'historical_provenance', $mode, $event->type, $event->status, '',
            $event->amount, $event->currency, $created,
            [
                'gateway' => $event->paymentMethod,
                'source_mode' => $sourceMode ?? 'unknown',
                'provider_reference' => $event->providerReference,
                'evidence_kind' => $event->evidenceKind->value,
                'source_event_identity' => $event->identity->canonical(),
            ],
        );
    }
}
