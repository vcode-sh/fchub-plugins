<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

defined('ABSPATH') || exit;

final readonly class CatalogueStatusChange
{
    public function __construct(
        public string $sourceIdentity,
        public int $targetId,
        public string $beforeStatus,
        public string $afterStatus,
        public string $beforeFingerprint,
        public string $afterFingerprint,
    ) {
        $identity = \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($sourceIdentity);
        if ($identity->entityType !== 'product' || $targetId <= 0 || $beforeStatus === $afterStatus || $afterStatus !== 'publish') {
            throw new \InvalidArgumentException('Catalogue status change is invalid.');
        }
        foreach ([$beforeFingerprint, $afterFingerprint] as $hash) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) throw new \InvalidArgumentException('Catalogue status fingerprint is invalid.');
        }
    }
}
