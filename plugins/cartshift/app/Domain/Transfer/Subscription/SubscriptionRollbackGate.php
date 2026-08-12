<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Subscription;

defined('ABSPATH') || exit;

/** Source ownership is intentionally one-way once mark-before-act begins. */
final readonly class SubscriptionRollbackGate
{
    public function __construct(private SubscriptionCutoverEvidenceRepository $evidence) {}

    public function assertAllowed(string $runId, bool $missingIsAmbiguous = false): void
    {
        try {
            $evidence = $this->evidence->get($runId);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'subscription_cutover_evidence_missing') {
                if ($missingIsAmbiguous) {
                    throw new \RuntimeException('rollback_blocked_subscription_cutover_evidence_missing:' . $runId);
                }
                return;
            }
            throw $exception;
        }
        if ($evidence->releaseStarted()) {
            throw new \RuntimeException('rollback_blocked_after_subscription_source_release:' . $runId);
        }
    }
}
