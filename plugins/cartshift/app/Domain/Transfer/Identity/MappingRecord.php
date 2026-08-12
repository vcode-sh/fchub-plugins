<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class MappingRecord
{
    private const string SHA256 = '/\A[a-f0-9]{64}\z/D';

    public function __construct(
        public SourceIdentity $identity,
        public int $targetId,
        public ?string $sourceFingerprint,
        public ?string $targetFingerprint,
        public MapState $state,
    ) {
        if ($targetId <= 0) {
            throw new \InvalidArgumentException('Mapping target ID must be positive.');
        }

        if ($state === MapState::Legacy) {
            if ($sourceFingerprint !== null || $targetFingerprint !== null) {
                throw new \InvalidArgumentException('Legacy mappings cannot claim unverified fingerprints.');
            }

            return;
        }

        if (
            $sourceFingerprint === null
            || $targetFingerprint === null
            || preg_match(self::SHA256, $sourceFingerprint) !== 1
            || preg_match(self::SHA256, $targetFingerprint) !== 1
        ) {
            throw new \InvalidArgumentException('V2 mappings require lowercase SHA-256 source and target fingerprints.');
        }
    }

    public function isActive(): bool
    {
        return $this->state !== MapState::RolledBack;
    }

    public function isCompatibleWith(self $other): bool
    {
        return $this->identity->canonical() === $other->identity->canonical()
            && $this->targetId === $other->targetId
            && $this->sourceFingerprint === $other->sourceFingerprint
            && $this->targetFingerprint === $other->targetFingerprint
            && $this->state === $other->state;
    }
}
