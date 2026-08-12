<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

use CartShift\Domain\Transfer\Execution\FilesystemSagaRepository;

defined('ABSPATH') || exit;

final readonly class StageContext
{
    public string $packageDirectory;

    /**
     * @param array<string, array{attachment_id: int, file: string, source_runtime_fingerprint: string}> $approvedMediaLinks
     */
    public function __construct(
        string $packageDirectory,
        public string $migrationId,
        public string $sourceRuntimeFingerprint,
        public array $approvedMediaLinks = [],
        public int $generation = 1,
        public ?FilesystemSagaRepository $filesystemSaga = null,
    ) {
        $canonical = realpath($packageDirectory);
        if ($canonical === false || !is_dir($canonical) || is_link($packageDirectory)) {
            throw new \InvalidArgumentException('Stage package directory must be a real local directory.');
        }
        $this->packageDirectory = rtrim($canonical, '/');
        if (preg_match('/\A[a-z0-9][a-z0-9-]{2,35}\z/D', $migrationId) !== 1) {
            throw new \InvalidArgumentException('Migration ID is invalid.');
        }
        if ($sourceRuntimeFingerprint === '') {
            throw new \InvalidArgumentException('Source runtime fingerprint is required.');
        }
        if ($generation < 1) {
            throw new \InvalidArgumentException('Migration generation is invalid.');
        }
    }

    public function assetPath(\CartShift\Domain\Transfer\Package\AssetManifestEntry $asset): string
    {
        if (realpath($this->packageDirectory) !== $this->packageDirectory) {
            throw new \RuntimeException('Stage package directory changed after approval.');
        }
        return $this->packageDirectory . '/assets/' . $asset->sha256;
    }
}
