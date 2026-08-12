<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class ReconcileContext
{
    /** @param array<string, int> $targetIds */
    public function __construct(
        public array $targetIds,
        public string $expectedAfterFingerprint,
        public string $runId,
        public int $generation,
        public ?string $approvedProductStatus = null,
    ) {
        if ($targetIds === [] || array_filter($targetIds, static fn (mixed $id): bool => !is_int($id) || $id <= 0) !== []) {
            throw new \InvalidArgumentException('Reconciliation target IDs are invalid.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $expectedAfterFingerprint) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $runId) !== 1 || $generation < 1
            || !in_array($approvedProductStatus, [null, 'publish'], true)) {
            throw new \InvalidArgumentException('Reconciliation context is invalid.');
        }
    }
}
