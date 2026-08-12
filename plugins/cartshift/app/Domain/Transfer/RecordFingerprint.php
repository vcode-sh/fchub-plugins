<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final class RecordFingerprint
{
    public static function structural(int $schemaVersion, SourceIdentity $identity): string
    {
        self::assertSchemaVersion($schemaVersion);

        return CanonicalJson::fingerprint([
            'schema_version' => $schemaVersion,
            'identity' => $identity->canonical(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    public static function privateContent(int $schemaVersion, SourceIdentity $identity, array $payload): string
    {
        self::assertSchemaVersion($schemaVersion);

        return CanonicalJson::fingerprint([
            'schema_version' => $schemaVersion,
            'identity' => $identity->canonical(),
            'payload' => $payload,
        ]);
    }

    public static function publicEvidenceIdentifier(RecordEnvelope $record, string $runSecret): string
    {
        if (strlen($runSecret) < 32) {
            throw new \InvalidArgumentException('Public evidence identifiers require a per-run secret of at least 32 bytes.');
        }

        return hash_hmac(
            'sha256',
            CanonicalJson::encode([
                'schema_version' => $record->schemaVersion,
                'identity' => $record->identity->canonical(),
            ]),
            $runSecret,
        );
    }

    public static function assertSchemaVersion(int $schemaVersion): void
    {
        if ($schemaVersion <= 0) {
            throw new \InvalidArgumentException('Record schema version must be positive.');
        }
    }
}
