<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

defined('ABSPATH') || exit;

final readonly class TargetCandidate
{
    /** @param array<string, scalar|null> $signals */
    public function __construct(
        public int $targetId,
        public string $targetFingerprint,
        public string $matchReason,
        private bool $approved,
        public array $signals = [],
    ) {
        if ($targetId <= 0) {
            throw new \InvalidArgumentException('Target candidate ID must be positive.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/D', $targetFingerprint) !== 1) {
            throw new \InvalidArgumentException('Target candidate fingerprint must be a lowercase SHA-256 value.');
        }

        if (preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $matchReason) !== 1) {
            throw new \InvalidArgumentException('Target candidate match reason is invalid.');
        }
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }
}
