<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * What `DatasetClosureValidator` decided, and the only thing allowed to say a
 * dataset is ready.
 *
 * `isComplete()` is that one thing. There is no "mostly complete", no warning
 * tier that lets a run continue, and no free-form string that controls cutover:
 * every failure carries a code from the plan's section 9.4 table, the source
 * reference it is about, and enough context to act on. Commands, receipts and
 * retry logic read the codes; the UI may be friendlier about it.
 *
 * The failure list is sorted so two runs over the same dataset produce the same
 * report byte for byte — which is what makes `fingerprint()` worth having, and
 * what stops a package that passed in the source runtime failing in the target
 * for no reason but iteration order.
 */
final readonly class ClosureReport
{
    // Section 9.4, Dataset row.
    public const string CODE_INVALID_SOURCE_RECORD = 'invalid_source_record';
    public const string CODE_MISSING_CUSTOMER = 'dataset_missing_customer';
    public const string CODE_MISSING_PRODUCT = 'dataset_missing_product';
    public const string CODE_MISSING_PARENT_ORDER = 'dataset_missing_parent_order';
    public const string CODE_MISSING_RELATED_ORDER = 'dataset_missing_related_order';
    public const string CODE_MISSING_TRANSACTION = 'dataset_missing_transaction';
    public const string CODE_AMBIGUOUS_ORDER_RELATIONSHIP = 'dataset_ambiguous_order_relationship';
    public const string CODE_DUPLICATE_REFERENCE = 'dataset_duplicate_reference';
    public const string CODE_COUNT_MISMATCH = 'dataset_count_mismatch';
    public const string CODE_CHECKSUM_MISMATCH = 'dataset_checksum_mismatch';

    // Section 6.2 requires these two by name from the closure check, and they
    // live in neighbouring rows of the same table rather than the Dataset one.
    public const string CODE_SHARED_PARENT_ORDER = 'shared_parent_order_requires_projection';
    public const string CODE_HISTORY_COUNT_MISMATCH = 'history_count_mismatch';

    /**
     * Two codes the Dataset row does not yet name, both blocking.
     *
     * Section 9.4 sanctions adding a reason — "an enum case, operator copy, one
     * focused test, and a documented ready/confirmation/block severity" — and
     * both of these are block severity with a focused test each. They exist
     * because the alternatives were worse than a new code:
     *
     * `source_encoding_invalid`: a source string that is not valid UTF-8 cannot
     * be canonicalised, cannot be written to a utf8mb4 column, and previously
     * collapsed every affected record onto one fingerprint. It is not a missing
     * reference and not a checksum disagreement, so no existing code describes
     * it without lying to whoever reads the receipt.
     *
     * `dataset_foreign_source_key`: a record whose source key disagrees with the
     * manifest's. The dataset is then not the dataset it claims to be, which is
     * a coherence failure rather than any of the per-record ones.
     *
     * Both want ratifying into the plan's table.
     */
    public const string CODE_SOURCE_ENCODING_INVALID = 'source_encoding_invalid';
    public const string CODE_FOREIGN_SOURCE_KEY = 'dataset_foreign_source_key';

    /**
     * Every reason code this layer may emit, and the allow-list a package
     * payload is filtered against.
     *
     * Section 9.4: "Free-form strings do not control cutover." An
     * `InvalidSourceRecord` arriving from a package file carries reason codes
     * chosen by whoever wrote the file, and those codes drive retry logic and
     * operator copy — so they are checked against this list rather than
     * believed. The decode-time codes from `SubscriptionRecordFactory` are here
     * too, because that is what an invalid record actually carries.
     *
     * @var list<string>
     */
    public const array REASON_CODES = [
        self::CODE_AMBIGUOUS_ORDER_RELATIONSHIP,
        self::CODE_CHECKSUM_MISMATCH,
        self::CODE_COUNT_MISMATCH,
        self::CODE_DUPLICATE_REFERENCE,
        self::CODE_FOREIGN_SOURCE_KEY,
        self::CODE_HISTORY_COUNT_MISMATCH,
        self::CODE_INVALID_SOURCE_RECORD,
        self::CODE_MISSING_CUSTOMER,
        self::CODE_MISSING_PARENT_ORDER,
        self::CODE_MISSING_PRODUCT,
        self::CODE_MISSING_RELATED_ORDER,
        self::CODE_MISSING_TRANSACTION,
        self::CODE_SHARED_PARENT_ORDER,
        self::CODE_SOURCE_ENCODING_INVALID,
        SubscriptionRecordFactory::REASON_CUSTOMER_EMAIL_MISSING,
        SubscriptionRecordFactory::REASON_REQUIRED_REFERENCE_MISSING,
        SubscriptionRecordFactory::REASON_UNSUPPORTED_CADENCE,
    ];

    /** @var list<array{code: string, source_key: string, kind: string, source_ref: string, context: array<string, mixed>}> */
    public array $failures;

    /** @var array<string, int> Decoded records per entity kind, invalid ones included. */
    public array $counts;

    /**
     * @param list<array{code: string, source_key: string, kind: string, source_ref: string, context: array<string, mixed>}> $failures
     * @param array<string, int> $counts
     */
    public function __construct(array $failures, array $counts)
    {
        usort($failures, static function (array $left, array $right): int {
            return [$left['code'], $left['kind'], $left['source_ref'], SubscriptionRecordFactory::canonicalJson($left['context'])]
                <=> [$right['code'], $right['kind'], $right['source_ref'], SubscriptionRecordFactory::canonicalJson($right['context'])];
        });

        ksort($counts);

        $this->failures = array_values($failures);
        $this->counts   = $counts;
    }

    /**
     * The faults that are about the SET, not about any one record in it.
     *
     * THE DISTINCTION EXISTS BECAUSE THE TWO KINDS NEED OPPOSITE ANSWERS.
     *
     * Section 6.2 says an invalid record forces the affected ENTITY to blocked,
     * not the package — the reference cohort contains exactly one malformed
     * subscription and is expected to migrate the other 563. So an entity-level
     * failure must not stop the run; it blocks its own entry and is reported.
     *
     * These five cannot be answered that way, because no per-record gate can see
     * them:
     *
     * `shared_parent_order_requires_projection` — two subscriptions on one parent
     * order. Assessed one at a time they are both perfectly ready: the importer
     * adopts the already-mapped FluentCart order for the second and two
     * `fct_subscriptions` rows land with the same `parent_order_id`, which
     * FluentCart's renewal service assumes never happens.
     *
     * `dataset_duplicate_reference` — one reference, two different payloads.
     * Last write wins, silently, and the target gets whichever arrived first.
     *
     * `dataset_count_mismatch` and `dataset_checksum_mismatch` — the manifest
     * disagrees with what was decoded. That is a statement about the whole file.
     *
     * `dataset_foreign_source_key` — the dataset is not the dataset it claims to
     * be, which is coherence rather than one bad row.
     *
     * @var list<string>
     */
    public const array SET_LEVEL_CODES = [
        self::CODE_CHECKSUM_MISMATCH,
        self::CODE_COUNT_MISMATCH,
        self::CODE_DUPLICATE_REFERENCE,
        self::CODE_FOREIGN_SOURCE_KEY,
        self::CODE_SHARED_PARENT_ORDER,
    ];

    /**
     * Whether the dataset is clean in every respect, entity-level included.
     *
     * This is the export/audit bar, and it stays as strict as it was. It is NOT
     * the bar `stage` uses — see `hasSetLevelFault()`.
     */
    public function isComplete(): bool
    {
        return $this->failures === [];
    }

    /**
     * Whether anything is wrong with the SET, as distinct from with its members.
     *
     * The gate `stage` refuses on. A dataset with one malformed subscription and
     * nothing else wrong answers false here and true to `isComplete()`, which is
     * the whole point: that cohort migrates 563 of 564.
     */
    public function hasSetLevelFault(): bool
    {
        return $this->setLevelFailures() !== [];
    }

    /**
     * @return list<array{code: string, source_key: string, kind: string, source_ref: string, context: array<string, mixed>}>
     */
    public function setLevelFailures(): array
    {
        return array_values(array_filter(
            $this->failures,
            static fn (array $failure): bool => in_array($failure['code'], self::SET_LEVEL_CODES, true),
        ));
    }

    /**
     * @return list<string> Sorted, unique.
     */
    public function reasonCodes(): array
    {
        $codes = array_values(array_unique(array_column($this->failures, 'code')));
        sort($codes);

        return $codes;
    }

    /**
     * @return list<array{code: string, source_key: string, kind: string, source_ref: string, context: array<string, mixed>}>
     */
    public function failuresFor(string $code): array
    {
        return array_values(array_filter(
            $this->failures,
            static fn (array $failure): bool => $failure['code'] === $code,
        ));
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
        return [
            // BOTH, because they answer different questions and only one of
            // them decides whether a cohort may be staged. `complete` is
            // informational: the reference dataset carries one malformed
            // subscription and is expected to migrate the other 563, so it is
            // permanently false and always was. `set_level` is the verdict —
            // see `hasSetLevelFault()` — and it is what the audit's outcome and
            // `SubscriptionCutover::stage()` both key off, so the two commands
            // give one answer about one dataset.
            'complete'          => $this->isComplete(),
            'counts'            => $this->counts,
            'failures'          => $this->failures,
            'reason_codes'      => $this->reasonCodes(),
            'set_level'         => $this->hasSetLevelFault(),
            'set_level_codes'   => self::codesOf($this->setLevelFailures()),
        ];
    }

    /**
     * @param list<array{code: string, source_key: string, kind: string, source_ref: string, context: array<string, mixed>}> $failures
     * @return list<string> Sorted, unique.
     */
    private static function codesOf(array $failures): array
    {
        $codes = array_values(array_unique(array_column($failures, 'code')));
        sort($codes);

        return $codes;
    }

    public function fingerprint(): string
    {
        return SubscriptionRecordFactory::digest($this->toArray());
    }
}
