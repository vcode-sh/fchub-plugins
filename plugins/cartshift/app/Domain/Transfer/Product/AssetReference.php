<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class AssetReference
{
    public function __construct(
        public SourceIdentity $identity,
        public string $locator,
        public string $role,
        public string $mimeType,
        public ?int $size,
        public SourceIdentity $owner,
        public string $provenance,
        public ?string $expectedSha256,
    ) {
        if (!filter_var($locator, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Asset locator must be an absolute URL.');
        }

        if (!in_array($role, ['featured', 'gallery', 'variation'], true)) {
            throw new \InvalidArgumentException('Unknown product asset role.');
        }

        if (!in_array($provenance, ['own', 'inherited'], true)) {
            throw new \InvalidArgumentException('Asset provenance must be own or inherited.');
        }

        if ($size !== null && $size < 0) {
            throw new \InvalidArgumentException('Asset size cannot be negative.');
        }

        if ($expectedSha256 !== null && preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1) {
            throw new \InvalidArgumentException('Asset hash must be lowercase SHA-256.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(),
            'locator' => $this->locator,
            'role' => $this->role,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'owner' => $this->owner->canonical(),
            'provenance' => $this->provenance,
            'expected_sha256' => $this->expectedSha256,
        ];
    }
}
