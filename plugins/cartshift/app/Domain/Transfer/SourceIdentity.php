<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class SourceIdentity
{
    private const string SOURCE_KEY_PATTERN = '/\A[a-z0-9][a-z0-9._-]{2,63}\z/D';
    private const string SOURCE_ID_PATTERN = '/\A[1-9][0-9]*(?::[a-z0-9][a-z0-9._-]{0,63})*\z/D';

    public function __construct(
        public string $sourceKey,
        public string $entityType,
        public string $sourceId,
    ) {
        self::assertValidSourceKey($sourceKey);

        if (RecordKind::tryFrom($entityType) === null) {
            throw new \InvalidArgumentException('Entity type must be a canonical record kind.');
        }

        if (preg_match(self::SOURCE_ID_PATTERN, $sourceId) !== 1) {
            throw new \InvalidArgumentException('Source ID must be a positive decimal ID with optional lowercase compound segments.');
        }
    }

    public static function assertValidSourceKey(string $sourceKey): void
    {
        if ($sourceKey === 'local' || preg_match(self::SOURCE_KEY_PATTERN, $sourceKey) !== 1) {
            throw new \InvalidArgumentException('Source key is invalid or uses the retired implicit local namespace.');
        }
    }

    public function kind(): RecordKind
    {
        return RecordKind::from($this->entityType);
    }

    public function canonical(): string
    {
        return $this->sourceKey . ':' . $this->entityType . ':' . $this->sourceId;
    }

    public static function fromCanonical(string $canonical): self
    {
        $parts = explode(':', $canonical, 3);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Canonical source identity is invalid.');
        }

        return new self($parts[0], $parts[1], $parts[2]);
    }
}
