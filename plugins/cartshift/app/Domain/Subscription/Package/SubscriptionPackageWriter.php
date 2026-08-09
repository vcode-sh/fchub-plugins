<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Package;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\CustomerRecord;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\OrderRecord;
use CartShift\Domain\Subscription\ProductRecord;
use CartShift\Domain\Subscription\SubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Subscription\SubscriptionSelection;

/**
 * The private NDJSON package, written.
 *
 * One header line, then one canonical envelope per record, sorted so two
 * exports of an unchanged source are byte-identical below the header. That
 * determinism is not tidiness: the cutover exports, freezes the source
 * workers, exports again, and aborts if anything moved. A file whose byte
 * order depended on the order a database happened to return rows in would
 * abort every time.
 *
 * The checksum is taken over the record lines and never over a header that
 * contains it — which reads as obvious right up until somebody writes the
 * recursive version.
 *
 * Counts and currencies come from what was actually written, not from what the
 * source said it was about to write. A header that describes a different file
 * from the one underneath it is the one lie this format cannot survive.
 */
final class SubscriptionPackageWriter
{
    /** Section 9.4's code for a record that could not be serialised at all. */
    public const string REASON_UNENCODABLE_RECORD = 'invalid_source_record';

    public const string REASON_WRITE_FAILED = 'package_write_failed';

    /** The order kinds appear in, so a file is diffable by eye. */
    private const array KIND_ORDER = [
        CustomerRecord::KIND     => 0,
        ProductRecord::KIND      => 1,
        OrderRecord::KIND        => 2,
        SubscriptionRecord::KIND => 3,
        InvalidSourceRecord::KIND => 4,
    ];

    /**
     * @return array{path: string|null, checksum: string, manifest: DatasetManifest|null, failures: list<string>}
     */
    public function write(
        string $path,
        SubscriptionDatasetSource $source,
        SubscriptionSelection $selection,
    ): array {
        $resolved = PackagePath::resolveForWrite($path);

        if ($resolved['path'] === null) {
            return ['path' => null, 'checksum' => '', 'manifest' => null, 'failures' => $resolved['failures']];
        }

        $sourceManifest = $source->manifest();
        $records = self::sorted(iterator_to_array($source->records($selection), false));

        [$lines, $counts, $invalidCount, $currencies] = $this->encode($records);

        $checksum = self::checksum($lines);

        $manifest = new DatasetManifest(
            DatasetManifest::SCHEMA_VERSION,
            $sourceManifest->sourceKey,
            $sourceManifest->storageAuthority,
            $currencies,
            gmdate('Y-m-d H:i:s'),
            $sourceManifest->versions,
            $selection->fingerprint(),
            $counts,
            $invalidCount,
            count($lines),
            $checksum,
            // Copied through from the source, exactly as the storage authority
            // and the versions are. The writer cannot compute it — only a
            // runtime with WooCommerce booted can — and it travels because the
            // operator who has to act on it is on the other machine.
            //
            // It is a header field, so it does not touch `records_checksum`,
            // which is taken over the record lines and never over a header
            // containing it.
            $sourceManifest->storageMirror,
        );

        // Resolved again, immediately before the open. The first resolution
        // happened before the whole dataset was read, and a directory can be
        // replaced with a symlink in the meantime.
        $reresolved = PackagePath::resolveForWrite($path);

        if ($reresolved['path'] === null) {
            return ['path' => null, 'checksum' => '', 'manifest' => null, 'failures' => $reresolved['failures']];
        }

        $failures = $this->put($reresolved['path'], $manifest, $lines);

        return [
            'path'     => $failures === [] ? $reresolved['path'] : null,
            'checksum' => $failures === [] ? $checksum : '',
            'manifest' => $failures === [] ? $manifest : null,
            'failures' => $failures,
        ];
    }

    /**
     * The checksum contract, in one place so the reader cannot disagree with it.
     *
     * @param list<string> $lines
     */
    public static function checksum(array $lines): string
    {
        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Kind, then source ID, then reference.
     *
     * Numeric where the reference is numeric, because `order:88050` sorts
     * before `order:880501` as a string and after it as an order ID, and a
     * package that reorders itself when the shop crosses a power of ten is not
     * deterministic in any useful sense.
     *
     * @param list<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord> $records
     * @return list<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord>
     */
    public static function sorted(array $records): array
    {
        usort($records, static function (object $left, object $right): int {
            return [
                self::KIND_ORDER[$left->kind()] ?? 9,
                self::numericPart($left->sourceRef),
                $left->sourceRef,
            ] <=> [
                self::KIND_ORDER[$right->kind()] ?? 9,
                self::numericPart($right->sourceRef),
                $right->sourceRef,
            ];
        });

        return array_values($records);
    }

    /**
     * @param list<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord> $records
     * @return array{0: list<string>, 1: array<string, int>, 2: int, 3: list<string>}
     */
    private function encode(array $records): array
    {
        $lines = [];
        $counts = array_fill_keys(DatasetManifest::KINDS, 0);
        $invalidCount = 0;
        $currencies = [];

        foreach ($records as $record) {
            $line = self::canonicalLine($record);

            if ($line === null) {
                // Canonicalisation is strict, and a row carrying invalid UTF-8
                // — which a restored Polish WooCommerce database produces —
                // cannot be encoded. It becomes a counted invalid record rather
                // than aborting an export of 564 subscriptions over one mangled
                // byte in a street name.
                $record = (new SubscriptionRecordFactory())->invalid(
                    $record->sourceKey,
                    $record instanceof InvalidSourceRecord ? $record->entityKind : $record->kind(),
                    $record->sourceRef,
                    [self::REASON_UNENCODABLE_RECORD],
                    ['unencodable' => true],
                );

                $line = self::canonicalLine($record);

                if ($line === null) {
                    // The replacement carries only values this class chose, so
                    // this is unreachable short of a broken JSON extension.
                    continue;
                }
            }

            $lines[] = $line;

            $kind = $record instanceof InvalidSourceRecord ? $record->entityKind : $record->kind();

            if (array_key_exists($kind, $counts)) {
                $counts[$kind]++;
            }

            if ($record instanceof InvalidSourceRecord) {
                $invalidCount++;
            }

            if ($record instanceof OrderRecord || $record instanceof SubscriptionRecord) {
                if ($record->currency !== '') {
                    $currencies[] = $record->currency;
                }
            }
        }

        return [$lines, $counts, $invalidCount, $currencies];
    }

    private static function canonicalLine(object $record): ?string
    {
        try {
            return SubscriptionRecordFactory::canonicalJson(SubscriptionRecordFactory::envelope($record));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function put(string $path, DatasetManifest $manifest, array $lines): array
    {
        $header = self::canonicalHeader($manifest);

        if ($header === null) {
            return [self::REASON_WRITE_FAILED];
        }

        // Created empty and locked down BEFORE any content arrives. Writing
        // first and chmod-ing after leaves a window in which the file is
        // world-readable, which on a shared host is the whole exposure.
        if (@file_put_contents($path, '') === false) {
            return [self::REASON_WRITE_FAILED];
        }

        @chmod($path, PackagePath::PRIVATE_MODE);

        $body = $header . "\n" . implode("\n", $lines) . "\n";

        return @file_put_contents($path, $body) === false ? [self::REASON_WRITE_FAILED] : [];
    }

    private static function canonicalHeader(DatasetManifest $manifest): ?string
    {
        try {
            return SubscriptionRecordFactory::canonicalJson($manifest->toArray());
        } catch (\Throwable) {
            return null;
        }
    }

    private static function numericPart(string $sourceRef): int
    {
        $suffix = substr($sourceRef, (int) strpos($sourceRef, ':') + 1);

        return ctype_digit($suffix) ? (int) $suffix : 0;
    }
}
