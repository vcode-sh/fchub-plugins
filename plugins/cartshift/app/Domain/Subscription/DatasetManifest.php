<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * The package header, and what a live source claims it is about to hand over.
 *
 * The counts are the load-bearing part. They are per entity kind, and an
 * `InvalidSourceRecord` counts under the kind it failed to become — a malformed
 * subscription is still one of the 564 selected subscriptions, and the moment
 * it stops being counted as one the arithmetic starts agreeing with a run that
 * quietly skipped it. `invalidCount` reports how many of the total are in that
 * state without adding a fifth bucket to the sum.
 *
 * `recordsChecksum` is taken over the canonical record lines and never over a
 * header containing itself, which is the sort of thing that looks obvious right
 * up until somebody writes the recursive version.
 */
final readonly class DatasetManifest
{
    /** The package format version. Not the plugin's, and not the database's. */
    public const int SCHEMA_VERSION = 1;

    /** @var list<string> */
    public const array KINDS = [
        CustomerRecord::KIND,
        OrderRecord::KIND,
        ProductRecord::KIND,
        SubscriptionRecord::KIND,
    ];

    /** @var array<string, int> Every kind present, sorted by key. */
    public array $counts;

    /** @var list<string> */
    public array $currencies;

    /**
     * @param list<string>        $currencies
     * @param array<string, mixed> $versions
     * @param array<string, int>  $counts
     */
    public function __construct(
        public int $schemaVersion,
        public string $sourceKey,
        public string $storageAuthority,
        array $currencies,
        public string $exportedAtUtc,
        public array $versions,
        public string $selectionFingerprint,
        array $counts,
        public int $invalidCount,
        public int $totalRecords,
        public string $recordsChecksum,
        /**
         * What the SOURCE found when it compared its two storage backends.
         *
         * Summary only — per-field value counts, per-field discrepancy counts,
         * and the fields nothing could be learned about. Never the per-subscriber
         * rows: those are customer-adjacent, and section 6.5 keeps the package
         * free of anything it does not need. A non-zero discrepancy count sends
         * the operator back to the source audit for detail; this field's job is
         * to stop them proceeding in ignorance, not to reproduce the report.
         *
         * It is here because the comparison can only be computed where
         * WooCommerce is booted — the source — while in cross-runtime mode the
         * operator decides whether to proceed on the TARGET. That is not a
         * hypothetical mode; it is the only route Lapka uses. Without this the
         * four discrepancies section 4.9 found are invisible at exactly the
         * moment the decision is made.
         *
         * Trailing and defaulted, so a package written before this field existed
         * decodes as "the source did not report one" rather than failing, and
         * `schema_version` stays 1.
         *
         * @var array<string, mixed>
         */
        public array $storageMirror = [],
        /**
         * The complete, non-sensitive cohort definition whose fingerprint is
         * recorded above. Empty means a package written before definitions
         * travelled with the header, and therefore the legacy `all` selection.
         *
         * @var array<string, mixed>
         */
        public array $selection = [],
    ) {
        $normalisedCounts = [];

        foreach (self::KINDS as $kind) {
            $normalisedCounts[$kind] = (int) ($counts[$kind] ?? 0);
        }

        ksort($normalisedCounts);

        $normalisedCurrencies = array_values(array_unique(array_filter(array_map(
            static fn (mixed $currency): string => strtoupper(trim((string) $currency)),
            $currencies,
        ))));
        sort($normalisedCurrencies);

        $this->counts     = $normalisedCounts;
        $this->currencies = $normalisedCurrencies;
    }

    /**
     * @param array<string, mixed> $header
     */
    public static function fromArray(array $header): self
    {
        return new self(
            (int) ($header['schema_version'] ?? self::SCHEMA_VERSION),
            (string) ($header['source_key'] ?? ''),
            (string) ($header['storage_authority'] ?? ''),
            (array) ($header['currencies'] ?? []),
            (string) ($header['exported_at_utc'] ?? ''),
            (array) ($header['versions'] ?? []),
            (string) ($header['selection_fingerprint'] ?? ''),
            (array) ($header['counts'] ?? []),
            (int) ($header['invalid_count'] ?? 0),
            (int) ($header['total_records'] ?? 0),
            (string) ($header['records_checksum'] ?? ''),
            (array) ($header['storage_mirror'] ?? []),
            (array) ($header['selection'] ?? []),
        );
    }

    public function countFor(string $kind): int
    {
        return $this->counts[$kind] ?? 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $header = [
            'counts'                => $this->counts,
            'currencies'            => $this->currencies,
            'exported_at_utc'       => $this->exportedAtUtc,
            'invalid_count'         => $this->invalidCount,
            'records_checksum'      => $this->recordsChecksum,
            'schema_version'        => $this->schemaVersion,
            'selection_fingerprint' => $this->selectionFingerprint,
            'source_key'            => $this->sourceKey,
            'storage_authority'     => $this->storageAuthority,
            'storage_mirror'        => $this->storageMirror,
            'total_records'         => $this->totalRecords,
            'versions'              => $this->versions,
        ];

        if ($this->selection !== []) {
            $header['selection'] = $this->selection;
        }

        return $header;
    }
}
