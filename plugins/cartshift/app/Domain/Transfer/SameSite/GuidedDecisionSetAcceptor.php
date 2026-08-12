<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PrivateTransferFile;

defined('ABSPATH') || exit;

/** Atomically accepts the exact owner-reviewed decision proposal. */
final class GuidedDecisionSetAcceptor
{
    /**
     * @param array<string, mixed> $proposal As `propose-decisions` returned it.
     * @return array{accepted:int}
     */
    public function accept(array $proposal, string $decisionSetPath): array
    {
        if (($proposal['status'] ?? null) !== 'owner_review_required'
            || ($proposal['blockers'] ?? []) !== []) {
            throw new \RuntimeException('guided_decision_proposal_blocked');
        }
        $rows = $proposal['decision_set']['decisions'] ?? null;

        if (!is_array($rows)) {
            throw new \RuntimeException('guided_decision_proposal_missing: there is nothing to accept.');
        }

        $baseFingerprint = $proposal['base_decision_fingerprint'] ?? null;
        if (!is_string($baseFingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $baseFingerprint) !== 1) {
            throw new \RuntimeException('guided_decision_proposal_base_invalid');
        }

        $decisions = TransferDecisionSet::fromArray($rows);
        $current = is_file($decisionSetPath)
            ? TransferDecisionSet::fromFile($decisionSetPath)
            : TransferDecisionSet::empty();
        if (hash_equals($decisions->fingerprint(), $current->fingerprint())) {
            return ['accepted' => count($rows)];
        }
        if (!hash_equals($baseFingerprint, $current->fingerprint())) {
            throw new \RuntimeException('guided_decision_set_changed');
        }

        $bytes = $decisions->canonicalJson();
        $directory = PrivateTransferFile::directory(dirname($decisionSetPath));
        $temporary = $decisionSetPath . '.tmp-' . bin2hex(random_bytes(8));
        $stream = fopen($temporary, 'x+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException('guided_decision_set_not_written');
        }
        chmod($temporary, 0600);
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes)
                || !fflush($stream)
                || !function_exists('fsync')
                || !fsync($stream)) {
                throw new \RuntimeException('guided_decision_set_not_written');
            }
        } catch (\Throwable $failure) {
            fclose($stream);
            unlink($temporary);
            throw $failure;
        }
        fclose($stream);
        if (!rename($temporary, $decisionSetPath)) {
            unlink($temporary);
            throw new \RuntimeException('guided_decision_set_not_written');
        }
        chmod($decisionSetPath, 0600);
        PrivateTransferFile::syncDirectory($directory);

        // Read back through the validator used by every later run step.
        TransferDecisionSet::fromFile($decisionSetPath);

        return ['accepted' => count($rows)];
    }
}
