<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class StageResult
{
    /**
     * @param list<int> $variationIds
     * @param list<int> $mediaIds
     * @param list<int> $downloadIds
     * @param list<string> $filesystemOperationIds
     * @param array<string,int> $sourceTargetIds Canonical source identity to target ID.
     */
    public function __construct(
        public int $targetId,
        public array $variationIds,
        public array $mediaIds,
        public array $downloadIds,
        public string $targetFingerprint,
        public bool $reused,
        public array $filesystemOperationIds = [],
        public array $sourceTargetIds = [],
    ) {
        if ($targetId <= 0 || preg_match('/\A[a-f0-9]{64}\z/D', $targetFingerprint) !== 1) {
            throw new \InvalidArgumentException('Stage result target identity is invalid.');
        }
        foreach ([$variationIds, $mediaIds, $downloadIds] as $ids) {
            if (!array_is_list($ids) || array_filter($ids, static fn (mixed $id): bool => !is_int($id) || $id <= 0)) {
                throw new \InvalidArgumentException('Stage result target IDs must be positive integer lists.');
            }
        }
        $sorted = $filesystemOperationIds;
        sort($sorted, SORT_STRING);
        if (!array_is_list($filesystemOperationIds) || $filesystemOperationIds !== array_values(array_unique($sorted))
            || array_filter($filesystemOperationIds, static fn (mixed $id): bool => !is_string($id) || preg_match('/\A[a-f0-9]{64}\z/D', $id) !== 1) !== []) {
            throw new \InvalidArgumentException('Stage filesystem operation IDs must be unique sorted SHA-256 values.');
        }
        foreach ($sourceTargetIds as $canonical => $id) {
            if (!is_string($canonical) || !is_int($id) || $id <= 0
                || \CartShift\Domain\Transfer\SourceIdentity::fromCanonical($canonical)->canonical() !== $canonical) {
                throw new \InvalidArgumentException('Stage source target IDs must be canonical positive mappings.');
            }
        }
        ksort($sourceTargetIds, SORT_STRING);
        if ($sourceTargetIds !== $this->sourceTargetIds) {
            throw new \InvalidArgumentException('Stage source target IDs must be canonically sorted.');
        }
    }
}
