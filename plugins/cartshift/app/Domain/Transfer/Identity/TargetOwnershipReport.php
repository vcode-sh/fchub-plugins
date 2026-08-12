<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TargetOwnershipReport
{
    /**
     * @param array<string, int> $mappingCountsByEntity
     * @param array<string, int> $legacyMappingCounts
     * @param array<string, int> $missingTargetCounts
     * @param array<string, int> $duplicateTargetOwnershipCounts
     * @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers
     */
    public function __construct(
        public string $sourceKey,
        public array $mappingCountsByEntity,
        public array $legacyMappingCounts,
        public array $missingTargetCounts,
        public array $duplicateTargetOwnershipCounts,
        public int $invoiceCollisionCount,
        public int $unfingerprintedMappingCount,
        public int $receiptCoverageCount,
        public int $ownedOrderCount,
        public array $blockers,
        public string $fingerprint,
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
    }

    /** @return list<string> */
    public function reasonCodes(): array
    {
        return array_values(array_unique(array_column($this->blockers, 'code')));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'mapping_counts_by_entity' => $this->mappingCountsByEntity,
            'legacy_mapping_counts' => $this->legacyMappingCounts,
            'missing_target_counts' => $this->missingTargetCounts,
            'duplicate_target_ownership_counts' => $this->duplicateTargetOwnershipCounts,
            'invoice_collision_count' => $this->invoiceCollisionCount,
            'unfingerprinted_mapping_count' => $this->unfingerprintedMappingCount,
            'receipt_coverage_count' => $this->receiptCoverageCount,
            'blockers' => $this->blockers,
            'fingerprint' => $this->fingerprint,
        ];
    }

    /** @param array<string, mixed> $document */
    public static function fingerprint(array $document): string
    {
        unset($document['fingerprint']);

        return CanonicalJson::fingerprint($document);
    }
}
