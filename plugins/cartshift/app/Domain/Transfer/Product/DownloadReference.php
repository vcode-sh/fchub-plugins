<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class DownloadReference
{
    public function __construct(
        public SourceIdentity $identity,
        public string $locator,
        public ?string $contentSha256,
        public SourceIdentity $owner,
        public string $name,
        public int $limit,
        public int $expiryDays,
    ) {
        if ($locator === '' || $name === '' || $limit < -1 || $expiryDays < -1) {
            throw new \InvalidArgumentException('Download reference is incomplete or has an invalid policy.');
        }

        if ($contentSha256 !== null && preg_match('/\A[a-f0-9]{64}\z/D', $contentSha256) !== 1) {
            throw new \InvalidArgumentException('Download hash must be lowercase SHA-256.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(),
            'locator' => $this->locator,
            'content_sha256' => $this->contentSha256,
            'owner' => $this->owner->canonical(),
            'name' => $this->name,
            'limit' => $this->limit,
            'expiry_days' => $this->expiryDays,
        ];
    }
}
