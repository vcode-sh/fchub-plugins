<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TransferManifest
{
    public const string FORMAT = 'cartshift-transfer';
    public const int FORMAT_VERSION = 2;

    /**
     * @param array<string, int> $recordCounts
     * @param array<string, string> $recordSha256
     * @param array<string, string> $assetSha256
     */
    public function __construct(
        public string $sourceKey,
        public string $sourceInstanceFingerprint,
        public string $sourceUrlHash,
        public string $sourceRuntimeFingerprint,
        public string $sourceSettingsFingerprint,
        public string $sourceCapabilityFingerprint,
        public string $selectionFingerprint,
        public string $cartShiftVersion,
        public string $woocommerceVersion,
        public ?string $wcsVersion,
        public string $createdAtUtc,
        public array $recordCounts,
        public array $recordSha256,
        public int $assetCount,
        public int $recordsBytes,
        public int $assetsBytes,
        public string $recordsSha256,
        public array $assetSha256,
    ) {
        \CartShift\Domain\Transfer\SourceIdentity::assertValidSourceKey($sourceKey);
        foreach ([$sourceInstanceFingerprint, $sourceUrlHash, $sourceRuntimeFingerprint, $sourceSettingsFingerprint, $sourceCapabilityFingerprint, $selectionFingerprint, $recordsSha256] as $hash) {
            self::assertHash($hash);
        }
        self::assertUtc($createdAtUtc);
        self::assertSortedMap($recordCounts, 'Record count map');
        self::assertSortedMap($recordSha256, 'Record hash map');
        self::assertSortedMap($assetSha256, 'Asset hash map');
        foreach ($recordCounts as $kind => $count) {
            if (\CartShift\Domain\Transfer\RecordKind::tryFrom($kind) === null || !is_int($count) || $count < 0) {
                throw new \InvalidArgumentException('Record count map is invalid.');
            }
        }
        foreach ([$recordSha256, $assetSha256] as $map) {
            foreach ($map as $hash) {
                if (!is_string($hash)) {
                    throw new \InvalidArgumentException('Manifest hash map is invalid.');
                }
                self::assertHash($hash);
            }
        }
        if ($assetCount < 0 || $recordsBytes < 0 || $assetsBytes < 0 || $assetCount !== count($assetSha256)) {
            throw new \InvalidArgumentException('Manifest byte or asset counts are invalid.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'source_key' => $this->sourceKey,
            'source_instance_fingerprint' => $this->sourceInstanceFingerprint,
            'source_url_hash' => $this->sourceUrlHash,
            'source_runtime_fingerprint' => $this->sourceRuntimeFingerprint,
            'source_settings_fingerprint' => $this->sourceSettingsFingerprint,
            'source_capability_fingerprint' => $this->sourceCapabilityFingerprint,
            'selection_fingerprint' => $this->selectionFingerprint,
            'cartshift_version' => $this->cartShiftVersion,
            'woocommerce_version' => $this->woocommerceVersion,
            'wcs_version' => $this->wcsVersion,
            'created_at_utc' => $this->createdAtUtc,
            'record_counts' => $this->recordCounts,
            'record_sha256' => $this->recordSha256,
            'asset_count' => $this->assetCount,
            'records_bytes' => $this->recordsBytes,
            'assets_bytes' => $this->assetsBytes,
            'records_sha256' => $this->recordsSha256,
            'asset_sha256' => $this->assetSha256,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $expected = array_keys((new self(
            'abc', str_repeat('0', 64), str_repeat('0', 64), str_repeat('0', 64), str_repeat('0', 64), str_repeat('0', 64), str_repeat('0', 64),
            '0', '0', null, '2000-01-01T00:00:00Z', [], [], 0, 0, 0, str_repeat('0', 64), [],
        ))->toArray());
        $actual = array_keys($data);
        sort($expected);
        sort($actual);
        if ($actual !== $expected || ($data['format'] ?? null) !== self::FORMAT || ($data['format_version'] ?? null) !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException('Transfer manifest shape or version is invalid.');
        }

        return new self(
            self::string($data, 'source_key'),
            self::string($data, 'source_instance_fingerprint'),
            self::string($data, 'source_url_hash'),
            self::string($data, 'source_runtime_fingerprint'),
            self::string($data, 'source_settings_fingerprint'),
            self::string($data, 'source_capability_fingerprint'),
            self::string($data, 'selection_fingerprint'),
            self::string($data, 'cartshift_version'),
            self::string($data, 'woocommerce_version'),
            $data['wcs_version'] === null ? null : self::string($data, 'wcs_version'),
            self::string($data, 'created_at_utc'),
            self::map($data, 'record_counts'),
            self::map($data, 'record_sha256'),
            self::integer($data, 'asset_count'),
            self::integer($data, 'records_bytes'),
            self::integer($data, 'assets_bytes'),
            self::string($data, 'records_sha256'),
            self::map($data, 'asset_sha256'),
        );
    }

    /** @return array<string, mixed> */
    public function semanticArray(): array
    {
        $data = $this->toArray();
        unset($data['created_at_utc']);
        return $data;
    }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode($this->toArray()) . "\n";
    }

    private static function assertHash(string $hash): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1) {
            throw new \InvalidArgumentException('Manifest fingerprint is not lowercase SHA-256.');
        }
    }

    private static function assertUtc(string $value): void
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value || \DateTimeImmutable::getLastErrors() !== false) {
            throw new \InvalidArgumentException('Manifest creation time must be canonical UTC.');
        }
    }

    /** @param array<mixed> $map */
    private static function assertSortedMap(array $map, string $label): void
    {
        if (array_is_list($map) && $map !== []) {
            throw new \InvalidArgumentException($label . ' must be a map.');
        }
        $keys = array_keys($map);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        if ($keys !== $sorted) {
            throw new \InvalidArgumentException($label . ' must be sorted.');
        }
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
            throw new \InvalidArgumentException('Manifest scalar field is invalid.');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function integer(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) {
            throw new \InvalidArgumentException('Manifest integer field is invalid.');
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private static function map(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key])) {
            throw new \InvalidArgumentException('Manifest map field is invalid.');
        }
        return $data[$key];
    }
}
