<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class RecordEnvelope
{
    public string $sourceContentDigest;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $schemaVersion,
        public SourceIdentity $identity,
        public string $structuralFingerprint,
        public string $privateContentDigest,
        public array $payload,
        ?string $sourceContentDigest = null,
    ) {
        RecordFingerprint::assertSchemaVersion($schemaVersion);

        foreach (array_keys($payload) as $key) {
            if (!is_string($key)) {
                throw new \InvalidArgumentException('Record payload must use string keys.');
            }
        }

        $expectedStructural = RecordFingerprint::structural($schemaVersion, $identity);
        $expectedPrivate = RecordFingerprint::privateContent($schemaVersion, $identity, $payload);

        if (!hash_equals($expectedStructural, $structuralFingerprint)) {
            throw new \InvalidArgumentException('Record structural fingerprint does not match its identity.');
        }

        if (!hash_equals($expectedPrivate, $privateContentDigest)) {
            throw new \InvalidArgumentException('Record private content digest does not match its payload.');
        }

        $sourceContentDigest ??= $privateContentDigest;
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sourceContentDigest) !== 1) {
            throw new \InvalidArgumentException('Record source content digest is invalid.');
        }
        $this->sourceContentDigest = $sourceContentDigest;
    }

    /** @param array<string, mixed> $payload */
    public static function forPayload(int $schemaVersion, SourceIdentity $identity, array $payload): self
    {
        return new self(
            $schemaVersion,
            $identity,
            RecordFingerprint::structural($schemaVersion, $identity),
            RecordFingerprint::privateContent($schemaVersion, $identity, $payload),
            $payload,
        );
    }

    /** @param array<string, mixed> $payload */
    public static function forPackagedPayload(self $source, array $payload): self
    {
        $packaged = self::forPayload($source->schemaVersion, $source->identity, $payload);
        return new self(
            $packaged->schemaVersion,
            $packaged->identity,
            $packaged->structuralFingerprint,
            $packaged->privateContentDigest,
            $packaged->payload,
            $source->sourceContentDigest,
        );
    }
}
