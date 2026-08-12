<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

defined('ABSPATH') || exit;

final readonly class AssetManifestEntry
{
    public function __construct(
        public string $sha256,
        public int $bytes,
        public string $mimeType,
        public string $originalName,
        public string $sourceKind,
    ) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new \InvalidArgumentException('Asset manifest hash must be lowercase SHA-256.');
        }

        if ($bytes < 0 || trim($mimeType) === '' || trim($originalName) === '' || trim($sourceKind) === '') {
            throw new \InvalidArgumentException('Asset manifest entry is incomplete.');
        }
    }

    /** @return array{sha256: string, bytes: int, mime_type: string, original_name: string, source_kind: string} */
    public function toArray(): array
    {
        return [
            'sha256' => $this->sha256,
            'bytes' => $this->bytes,
            'mime_type' => $this->mimeType,
            'original_name' => $this->originalName,
            'source_kind' => $this->sourceKind,
        ];
    }
}
