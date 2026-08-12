<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

defined('ABSPATH') || exit;

final readonly class StagedAsset
{
    public function __construct(
        public string $sha256,
        public int $bytes,
        public string $targetPath,
        public string $relativePath,
        public string $kind,
        public bool $createdByMigration,
        public string $migrationId,
        public string $sourceRuntimeFingerprint,
        public ?int $targetId = null,
        public string $driver = 'local',
        public ?string $filesystemOperationId = null,
        /** @var array<string,string> operation ID => absolute target path */
        public array $filesystemOperations = [],
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1 || $bytes < 0) {
            throw new \InvalidArgumentException('Staged asset content identity is invalid.');
        }
        if (!in_array($kind, ['media', 'download'], true) || $targetPath === '' || $relativePath === '') {
            throw new \InvalidArgumentException('Staged asset target identity is invalid.');
        }
        if ($targetId !== null && $targetId <= 0) {
            throw new \InvalidArgumentException('Staged asset target ID must be positive.');
        }
        if ($filesystemOperationId !== null && preg_match('/\A[a-f0-9]{64}\z/D', $filesystemOperationId) !== 1) {
            throw new \InvalidArgumentException('Staged asset filesystem operation ID is invalid.');
        }
        foreach ($filesystemOperations as $operationId => $path) {
            if (!is_string($operationId) || preg_match('/\A[a-f0-9]{64}\z/D', $operationId) !== 1
                || !is_string($path) || $path === '' || $path[0] !== '/') {
                throw new \InvalidArgumentException('Staged asset filesystem operation map is invalid.');
            }
        }
        if ($filesystemOperationId !== null
            && (($filesystemOperations[$filesystemOperationId] ?? null) !== $targetPath)) {
            throw new \InvalidArgumentException('Primary filesystem operation is missing from the staged asset map.');
        }
    }

    /** @return list<string> */
    public function filesystemOperationIds(): array
    {
        $ids = array_keys($this->filesystemOperations);
        sort($ids, SORT_STRING);
        return $ids;
    }
}
