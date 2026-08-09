<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Package;

defined('ABSPATH') || exit;

use CartShift\Domain\Subscription\ClosureReport;
use CartShift\Domain\Subscription\DatasetClosureValidator;
use CartShift\Domain\Subscription\DatasetManifest;
use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use Generator;
use RuntimeException;

/**
 * The private NDJSON package, read — and disbelieved until it proves itself.
 *
 * Five things are checked, and a package that fails any of them is not a
 * package with a small problem:
 *
 * SCHEMA. Version 1, or nothing. A reader that guesses at a future format is a
 * reader that half-imports one.
 *
 * CANONICAL FORM. Every record line must be exactly what the writer would have
 * produced for that payload. Re-serialising a file through a JSON formatter
 * preserves the data and destroys the bytes, and the checksum is over the
 * bytes — so without this check a tampered file could be made to pass by being
 * reformatted, and a legitimately reformatted one would fail with no
 * explanation of why.
 *
 * CHECKSUM. Over the record lines as they appear on disk, never over a header
 * containing itself.
 *
 * COUNTS. Per kind and in total, against the header. A truncated file is
 * refused rather than partly believed.
 *
 * CLOSURE. Separately, and reported rather than folded into `ok`: a package
 * containing the one malformed Lapka subscription is structurally perfect and
 * still has a record nobody may migrate. Those are different questions and the
 * commands answer them differently — `prepare-package` needs the first,
 * `audit` reports the second.
 *
 * The path is re-resolved immediately before every open. Not once per reader:
 * once per open.
 */
final class SubscriptionPackageReader
{
    public const string REASON_CHECKSUM_MISMATCH = ClosureReport::CODE_CHECKSUM_MISMATCH;
    public const string REASON_COUNT_MISMATCH = ClosureReport::CODE_COUNT_MISMATCH;

    /**
     * Structural refusals, ratified into section 9.4's Dataset row at severity
     * block.
     *
     * They are codes rather than context details, and the reason is the
     * operator action behind each. `dataset_checksum_mismatch` tells somebody
     * the content changed — which is exactly the one thing that has NOT
     * happened in any of these three cases. An unsupported schema version means
     * upgrade CartShift or re-export from a matching version; an unreadable
     * header means repair or re-export the header; a zero-record package means
     * go and look at the selection that produced it. Collapsing three different
     * next steps into one integrity code sends all three operators down the
     * wrong path, and section 9.4 exists precisely because commands, receipts,
     * tests and retry logic key off these strings.
     *
     * Contrast the two below, which stay as context details: a line that is not
     * canonical, or not JSON at all, IS a content-integrity failure, and
     * `dataset_checksum_mismatch` is the right thing to tell somebody about it.
     */
    public const string REASON_SCHEMA_UNSUPPORTED = 'package_schema_unsupported';
    public const string REASON_HEADER_UNREADABLE = 'package_header_unreadable';
    public const string REASON_EMPTY = 'package_empty';

    public const string DETAIL_NON_CANONICAL_LINE = 'non_canonical_line';
    public const string DETAIL_UNREADABLE_JSON = 'unreadable_json';

    /**
     * Every code this layer may put into a `failures[].code`, ratified into
     * section 9.4's Dataset row at severity block.
     *
     * THE POINT OF THE LITERALS. These are spelled out rather than assembled
     * from the constants above, and that duplication is the whole mechanism. A
     * registry derived from the constants would accept a newly minted one
     * automatically, which is precisely the slip this exists to catch: the
     * `package_path_*` family was once argued to sit outside section 9.4 on the
     * grounds that it "fires before anything is read and blocks a command, not
     * a cutover" — and that was false, because `validate()` maps every
     * `PackagePath::resolveForRead()` refusal straight into a failure code, and
     * `ok` drives the audit's `outcome => blocked`. Nine codes were controlling
     * cutovers without being in the table.
     *
     * So minting a code is two deliberate acts: the constant, and the entry
     * here. `PackageFailureCodeRegistryTest` fails if either happens without the
     * other, which is what turns a ratification step into something a suite can
     * enforce rather than something a reviewer has to remember.
     *
     * @var list<string>
     */
    public const array RATIFIED_CODES = [
        'dataset_checksum_mismatch',
        'dataset_count_mismatch',
        'package_empty',
        'package_header_unreadable',
        'package_path_directory_unknown',
        'package_path_missing',
        'package_path_not_a_file',
        'package_path_not_absolute',
        'package_path_public_directory',
        'package_path_symlink',
        'package_path_unreadable',
        'package_path_unwritable',
        'package_path_version_control',
        'package_schema_unsupported',
    ];

    public function __construct(
        private readonly SubscriptionRecordFactory $factory = new SubscriptionRecordFactory(),
    ) {
    }

    /**
     * The header, or an exception. There is no third answer worth having.
     */
    public function manifest(string $path): DatasetManifest
    {
        $handle = $this->open($path);

        try {
            $header = fgets($handle);
        } finally {
            fclose($handle);
        }

        if ($header === false) {
            throw new RuntimeException(sprintf('The package at %s is empty.', $path));
        }

        $decoded = json_decode(trim($header), true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('The package at %s has no readable header.', $path));
        }

        return DatasetManifest::fromArray($decoded);
    }

    /**
     * Every record in the package, streamed.
     *
     * @return Generator<int, object>
     */
    public function records(string $path): Generator
    {
        $handle = $this->open($path);

        try {
            fgets($handle); // The header.

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                yield $this->decode($line);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{ok: bool, path: string|null, manifest: DatasetManifest|null, checksum: string, failures: list<array<string, mixed>>, closure: ClosureReport|null}
     */
    public function validate(string $path): array
    {
        $resolved = PackagePath::resolveForRead($path);

        if ($resolved['path'] === null) {
            return [
                'ok'       => false,
                'path'     => null,
                'manifest' => null,
                'checksum' => '',
                'failures' => array_map(
                    static fn (string $code): array => self::failure($code, '', 'package', $path, []),
                    $resolved['failures'],
                ),
                'closure'  => null,
            ];
        }

        $lines = file($resolved['path'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false || $lines === []) {
            return [
                'ok'       => false,
                'path'     => $resolved['path'],
                'manifest' => null,
                'checksum' => '',
                'failures' => [self::failure(self::REASON_EMPTY, '', 'package', $resolved['path'], [])],
                'closure'  => null,
            ];
        }

        $header = json_decode((string) array_shift($lines), true);

        if (!is_array($header)) {
            return [
                'ok'       => false,
                'path'     => $resolved['path'],
                'manifest' => null,
                'checksum' => '',
                'failures' => [self::failure(self::REASON_HEADER_UNREADABLE, '', 'package', $resolved['path'], [])],
                'closure'  => null,
            ];
        }

        $manifest = DatasetManifest::fromArray($header);
        $failures = [];

        if ($manifest->schemaVersion !== DatasetManifest::SCHEMA_VERSION) {
            $failures[] = self::failure(
                self::REASON_SCHEMA_UNSUPPORTED,
                $manifest->sourceKey,
                'package',
                $resolved['path'],
                ['declared' => $manifest->schemaVersion, 'supported' => DatasetManifest::SCHEMA_VERSION],
            );
        }

        [$records, $counts, $invalidCount, $lineFailures] = $this->decodeLines($manifest, $lines);

        $failures = array_merge($failures, $lineFailures);

        $checksum = SubscriptionPackageWriter::checksum($lines);

        if (!hash_equals($manifest->recordsChecksum, $checksum)) {
            $failures[] = self::failure(
                self::REASON_CHECKSUM_MISMATCH,
                $manifest->sourceKey,
                'package',
                $resolved['path'],
                ['declared' => $manifest->recordsChecksum, 'computed' => $checksum],
            );
        }

        $failures = array_merge(
            $failures,
            $this->countFailures($manifest, $counts, $invalidCount, count($lines)),
        );

        return [
            'ok'       => $failures === [],
            'path'     => $resolved['path'],
            'manifest' => $manifest,
            'checksum' => $checksum,
            'failures' => self::ordered($failures),
            'closure'  => (new DatasetClosureValidator())->validate($manifest, $records),
        ];
    }

    /**
     * @param list<string> $lines
     * @return array{0: list<object>, 1: array<string, int>, 2: int, 3: list<array<string, mixed>>}
     */
    private function decodeLines(DatasetManifest $manifest, array $lines): array
    {
        $records = [];
        $counts = array_fill_keys(DatasetManifest::KINDS, 0);
        $invalidCount = 0;
        $failures = [];

        foreach ($lines as $number => $line) {
            $decodedLine = json_decode($line, true);

            if (!is_array($decodedLine)) {
                $failures[] = self::failure(
                    self::REASON_CHECKSUM_MISMATCH,
                    $manifest->sourceKey,
                    'package',
                    'line:' . ($number + 2),
                    ['reason' => self::DETAIL_UNREADABLE_JSON],
                );

                continue;
            }

            if (!self::isCanonical($line, $decodedLine)) {
                $failures[] = self::failure(
                    self::REASON_CHECKSUM_MISMATCH,
                    $manifest->sourceKey,
                    'package',
                    'line:' . ($number + 2),
                    ['reason' => self::DETAIL_NON_CANONICAL_LINE],
                );
            }

            $record = $this->factory->fromEnvelope($decodedLine);
            $records[] = $record;

            $kind = $record instanceof InvalidSourceRecord ? $record->entityKind : $record->kind();

            if (array_key_exists($kind, $counts)) {
                $counts[$kind]++;
            }

            if ($record instanceof InvalidSourceRecord) {
                $invalidCount++;
            }
        }

        return [$records, $counts, $invalidCount, $failures];
    }

    /**
     * @param array<string, int> $counts
     * @return list<array<string, mixed>>
     */
    private function countFailures(
        DatasetManifest $manifest,
        array $counts,
        int $invalidCount,
        int $total,
    ): array {
        $failures = [];

        foreach ($counts as $kind => $decoded) {
            if ($manifest->countFor($kind) !== $decoded) {
                $failures[] = self::failure(
                    self::REASON_COUNT_MISMATCH,
                    $manifest->sourceKey,
                    $kind,
                    'manifest',
                    ['declared' => $manifest->countFor($kind), 'decoded' => $decoded],
                );
            }
        }

        if ($manifest->totalRecords !== $total) {
            $failures[] = self::failure(
                self::REASON_COUNT_MISMATCH,
                $manifest->sourceKey,
                'package',
                'manifest',
                ['declared_total' => $manifest->totalRecords, 'decoded_total' => $total],
            );
        }

        if ($manifest->invalidCount !== $invalidCount) {
            $failures[] = self::failure(
                self::REASON_COUNT_MISMATCH,
                $manifest->sourceKey,
                InvalidSourceRecord::KIND,
                'manifest',
                ['declared' => $manifest->invalidCount, 'decoded' => $invalidCount],
            );
        }

        return $failures;
    }

    private function decode(string $line): object
    {
        $decoded = json_decode($line, true);

        return $this->factory->fromEnvelope(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<array-key, mixed> $decoded
     */
    private static function isCanonical(string $line, array $decoded): bool
    {
        try {
            return SubscriptionRecordFactory::canonicalJson($decoded) === $line;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        // Resolved here, at the open, and not before. A path validated earlier
        // and opened now is a path that had time to become a symlink.
        $resolved = PackagePath::resolveForRead($path);

        if ($resolved['path'] === null) {
            throw new RuntimeException(sprintf(
                'The package at %s cannot be read: %s.',
                $path,
                implode(', ', $resolved['failures']),
            ));
        }

        $handle = fopen($resolved['path'], 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('The package at %s could not be opened.', $resolved['path']));
        }

        return $handle;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function failure(
        string $code,
        string $sourceKey,
        string $kind,
        string $sourceRef,
        array $context,
    ): array {
        return [
            'code'       => $code,
            'context'    => SubscriptionRecordFactory::sortDeep($context),
            'kind'       => $kind,
            'source_key' => $sourceKey,
            'source_ref' => $sourceRef,
        ];
    }

    /**
     * @param list<array<string, mixed>> $failures
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $failures): array
    {
        usort(
            $failures,
            static fn (array $left, array $right): int =>
                [$left['code'], $left['kind'], $left['source_ref']]
                <=> [$right['code'], $right['kind'], $right['source_ref']],
        );

        return array_values($failures);
    }
}
