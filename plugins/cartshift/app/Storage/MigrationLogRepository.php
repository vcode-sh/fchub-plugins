<?php

declare(strict_types=1);

namespace CartShift\Storage;

use CartShift\Support\Enums\MigrationErrorCode;

defined('ABSPATH') || exit;

final class MigrationLogRepository
{
    /**
     * Key the machine-readable reason is stored under inside the `details` JSON.
     *
     * Deliberately `error_code` rather than `code`: `details` is a free-for-all
     * that callers fill with whatever the record needed, and `code` is exactly
     * the sort of generic word a caller would use for a coupon code, an HTTP
     * status or a currency. A collision there would be silent and would corrupt
     * the grouping, so the key is the specific one.
     *
     * The JSON copy keeps a raw row self-describing, but it is not what anything
     * reads — see CODE_COLUMN.
     */
    public const DETAILS_CODE_KEY = 'error_code';

    /**
     * The one column a code is ever read from.
     *
     * Schema v3 added `error_code VARCHAR(64)` with a (migration_id, error_code)
     * index and v4 backfilled the rows that predated it, so the column is
     * complete. Every read in this class — the `code` filter on getPaginated(),
     * getCodeCounts(), the breakdown inside getStats(), getRetryableIds() and
     * hydrate() — resolves through it and through nothing else.
     *
     * That exclusivity is the whole point. With two plausible places to look, a
     * reader that picks the other one makes rows disappear from a filtered view
     * while they still count towards the summary card above it: twelve rows
     * listed under a heading that says 2,481, no error, no clue. One source, one
     * answer, and the question cannot arise.
     */
    public const CODE_COLUMN = 'error_code';

    /**
     * Every status the plugin writes, in the order a human reads them.
     *
     * Exposed so the UI's status filter and the CLI offer exactly what the log
     * can contain instead of a hand-copied subset. 'warning' is the one that
     * kept being left out — SubscriptionMigrator writes it, nothing zero-filled
     * it and no filter offered it, so warnings were real and unfindable.
     *
     * Not a whitelist on the way in: write() takes any status, because
     * third-party code hooking the migration can log whatever it likes and
     * getStats() reports whatever it finds.
     *
     * @var list<string>
     */
    public const KNOWN_STATUSES = [
        'success',
        'skipped',
        'warning',
        'error',
        'dry-run',
        'rollback',
    ];

    /**
     * Statuses a retry run may re-attempt.
     *
     * 'success' is absent because re-running a record that worked is how you get
     * duplicates. 'dry-run' and 'rollback' describe runs that wrote nothing
     * there is anything to retry.
     *
     * @var list<string>
     */
    public const RETRYABLE_STATUSES = ['error', 'warning', 'skipped'];

    private readonly string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'cartshift_migration_log';
    }

    /**
     * Write a log entry.
     *
     * `$code` is additive metadata, appended after the existing parameters so
     * every current caller keeps working untouched. It does not replace
     * `$message`: the code says what class of thing went wrong so the UI can
     * group and count, the message keeps the specifics — which SKU, which coupon
     * code, which ID — that a fixed vocabulary cannot carry.
     *
     * The code is written twice, on purpose: to the indexed `error_code` column,
     * which is what filtering and grouping read, and into the `details` JSON
     * under self::DETAILS_CODE_KEY, which keeps a raw row self-describing
     * without a join to the schema. This is not a split-brain risk — write() is
     * the only writer, both come from the same resolved enum in the same
     * statement, and no update path exists that could move one without the other.
     *
     * An explicit `$code` wins over a matching key already present in
     * `$details`; an unknown string is discarded rather than written, so the
     * column only ever holds values the enum can explain.
     *
     * @param array<string, mixed>|null $details
     */
    public function write(
        string $migrationId,
        string $entityType,
        string|int $wcId,
        string $status,
        string $message,
        array|null $details = null,
        MigrationErrorCode|string|null $code = null,
    ): void {
        global $wpdb;

        // One resolution, feeding both destinations in one statement. An explicit
        // $code is the caller's stated intent and wins; failing that, a code the
        // caller tucked into $details is lifted into the column rather than left
        // sitting in JSON that nothing reads.
        $resolved = MigrationErrorCode::coerce($code)
            ?? MigrationErrorCode::coerce(
                is_string($details[self::DETAILS_CODE_KEY] ?? null)
                    ? $details[self::DETAILS_CODE_KEY]
                    : null,
            );

        if ($resolved !== null) {
            $details ??= [];
            $details[self::DETAILS_CODE_KEY] = $resolved->value;
        }

        $wpdb->insert(
            $this->table,
            [
                'migration_id' => $migrationId,
                'entity_type'  => $entityType,
                'wc_id'        => (string) $wcId,
                'status'       => $status,
                'error_code'   => $resolved?->value,
                'message'      => $message,
                'details'      => $details !== null ? wp_json_encode($details) : null,
                'created_at'   => gmdate('Y-m-d H:i:s'),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
        );
    }

    /**
     * Turn a `$wpdb` write that MySQL rejected into a log row. Call it straight
     * after the write, before any other query runs.
     *
     * FluentCart's models throw and are caught per record by
     * MigrationOrchestrator::processBatch(). `$wpdb` does neither: it stores the
     * failure in `$wpdb->last_error`, returns false, and carries on. Every place
     * CartShift writes through `$wpdb` directly was therefore free to fail in
     * total silence — a real run emitted ten `Unknown column 'item_count'` lines
     * to the PHP error log and finished with "Success: Migration complete. 25
     * migrated, 2 skipped", zero errors.
     *
     * Immediacy is the whole contract. `wpdb::query()` calls `flush()`, which
     * blanks `last_error` (wp-includes/class-wpdb.php), so the value belongs to
     * the last statement and nothing else — read it one query too late and it
     * says the wrong thing. That is also why this reads before it writes: the
     * insert below would clear the error it is reporting.
     *
     * The MySQL error goes into the message verbatim. It is the only part that
     * names the column or the constraint, and a shop owner forwarding it is
     * worth more than any sentence this plugin could compose about it.
     *
     * @param  string $operation What was being written, in the owner's terms —
     *                           "order payment status", not "UPDATE fct_orders".
     * @return bool              True when a failure was found and recorded.
     */
    public function recordWriteFailure(
        string $migrationId,
        string $entityType,
        string|int $wcId,
        string $operation,
    ): bool {
        global $wpdb;

        $error = trim((string) ($wpdb->last_error ?? ''));

        if ($error === '') {
            return false;
        }

        $this->write(
            $migrationId,
            $entityType,
            $wcId,
            'error',
            sprintf('Could not write %s: %s', $operation, $error),
            null,
            MigrationErrorCode::DatabaseWriteFailed,
        );

        return true;
    }

    /**
     * Get paginated log entries with optional filters, newest first.
     *
     * Ordered by `id`, not `created_at`. created_at is a DATETIME with one-second
     * resolution and the migration writes thousands of rows a second, so ordering by
     * it leaves MySQL free to break the ties however it likes — different orders on
     * different pages, which makes rows appear twice and others vanish entirely as
     * the user pages through. `id` is the autoincrement, so it is unique, monotonic
     * with insertion, and gives the same newest-first sequence with no ties to break.
     *
     * `$code` filters on the machine-readable reason, and is appended last so
     * existing positional callers are unaffected. It is an indexed equality on
     * the same column getCodeCounts() groups by, which is what makes every line
     * of the breakdown clickable: whatever the count says is there, filtering
     * for it finds exactly that many rows.
     *
     * A code this build's enum has never heard of is passed through rather than
     * rejected, because the column can legitimately hold one — a code retired
     * from the vocabulary in a later release, or lifted out of an old row's JSON
     * by the v4 backfill. Equality on a value no row carries returns nothing,
     * which is the honest answer; returning every row because the filter was not
     * understood would not be.
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function getPaginated(
        string|null $migrationId = null,
        int $page = 1,
        int $perPage = 50,
        string|null $status = null,
        MigrationErrorCode|string|null $code = null,
    ): array {
        global $wpdb;

        $where = [];
        $params = [];

        if ($migrationId !== null) {
            $where[] = 'migration_id = %s';
            $params[] = $migrationId;
        }

        if ($status !== null) {
            $where[] = 'status = %s';
            $params[] = $status;
        }

        $codeValue = $this->codeValue($code);

        if ($codeValue !== null) {
            // Column, not a LIKE over the details LONGTEXT: (migration_id,
            // error_code) is indexed, so this is a range read rather than a scan.
            $where[] = self::CODE_COLUMN . ' = %s';
            $params[] = $codeValue;
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $countSql = "SELECT COUNT(*) FROM {$this->table} {$whereClause}";
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($countSql, ...$params))
            : (int) $wpdb->get_var($countSql);

        $offset = ($page - 1) * $perPage;
        $dataSql = "SELECT * FROM {$this->table} {$whereClause} ORDER BY id DESC LIMIT %d OFFSET %d";
        $dataParams = [...$params, $perPage, $offset];

        $rows = $wpdb->get_results($wpdb->prepare($dataSql, ...$dataParams), ARRAY_A);

        return [
            'data'     => array_map([$this, 'hydrate'], $rows ?: []),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Get counts grouped by status for a migration.
     *
     * success, skipped, warning, error and total are always present, zero-filled,
     * because the UI reads them unconditionally. 'warning' joined that list late:
     * SubscriptionMigrator has always written it, but nothing zero-filled it and no
     * filter offered it, so a run with warnings and no errors looked clean. They are
     * not the only keys: whatever statuses the run actually wrote come back too.
     * write() is called with 'dry-run' and 'rollback' elsewhere in the plugin, and
     * third-party code hooking the migration can write anything it likes. `total` is
     * the sum across every status, not just the named ones.
     *
     * Two extra keys carry the machine-readable breakdown, added without
     * disturbing any existing one: `codes` is a code => count map ordered most
     * frequent first, and `code_breakdown` is the same data as a list of
     * descriptors with label, hint, severity and category attached, so the UI can
     * render "4,000 x Customer not migrated — migrate customers before orders"
     * without shipping its own copy of the vocabulary. Both are empty when the
     * run wrote no codes, which is what an older log looks like.
     *
     * @return array<string, mixed> Status => count, always including success,
     *                              skipped, warning, error and total, plus
     *                              `codes` and `code_breakdown`.
     */
    public function getStats(string $migrationId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) as count FROM {$this->table}
             WHERE migration_id = %s
             GROUP BY status",
            $migrationId,
        ), ARRAY_A);

        $stats = ['success' => 0, 'skipped' => 0, 'warning' => 0, 'error' => 0];
        $total = 0;

        foreach ($rows ?: [] as $row) {
            $count = (int) $row['count'];
            $status = (string) $row['status'];

            // 'total' is a reserved key here, so a status literally called "total"
            // does not quietly overwrite the sum.
            if ($status !== '' && $status !== 'total') {
                $stats[$status] = $count;
            }

            $total += $count;
        }

        $stats['total'] = $total;

        $codes = $this->getCodeCounts($migrationId);

        $stats['codes'] = $codes;
        $stats['code_breakdown'] = $this->describeCodeCounts($codes);

        return $stats;
    }

    /**
     * Counts per machine-readable reason for a migration, most frequent first.
     *
     * Zero counts are dropped: the caller wants what went wrong, not a roll call
     * of everything that could have. Codes the current enum cannot explain are
     * reported under their own value rather than swept into an `other` bucket.
     * `other` was reachable — a code retired from the vocabulary, or one lifted
     * out of an old row's JSON by the v4 backfill — and it was a dead end: the
     * breakdown offered a count that filtering could never reproduce, because
     * there is no single code to filter by. Naming the real value keeps every
     * line of the breakdown clickable, and describeCodeCounts() still labels the
     * unrecognised ones honestly.
     *
     * @return array<string, int> Code => count.
     */
    public function getCodeCounts(string $migrationId): array
    {
        global $wpdb;

        // A plain GROUP BY on the indexed column. This used to be one conditional
        // SUM per enum case plus a total — two dozen LIKE patterns evaluated
        // against a LONGTEXT on every row — because the code only existed inside
        // the details JSON and there was nothing to index into. Since the v3
        // schema added a real error_code column the database can do this properly:
        // (migration_id, error_code) covers both the filter and the grouping.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT error_code, COUNT(*) AS count
             FROM {$this->table}
             WHERE migration_id = %s
               AND error_code IS NOT NULL
               AND error_code <> ''
             GROUP BY error_code",
            $migrationId,
        ), ARRAY_A);

        $counts = [];

        foreach ($rows ?: [] as $row) {
            $count = (int) ($row['count'] ?? 0);
            $code = (string) ($row[self::CODE_COLUMN] ?? '');

            if ($count <= 0 || $code === '') {
                continue;
            }

            // Reported under its real value whether or not this build's enum
            // recognises it. Anything here can be handed straight back to
            // getPaginated()'s `code` filter and will return exactly $count rows.
            $counts[$code] = $count;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Source ids a retry run should re-attempt, ascending.
     *
     * "Retryable" is not simply "was logged with a bad status". One record can be
     * logged as an error on one pass and succeed on a later one — an order whose
     * customer was missing, then migrated, then the order re-run by hand. Its
     * error row is still sitting in the log, and re-running it would create a
     * second copy of something that already migrated. So any wc_id with a
     * `success` row anywhere in the same run is excluded, however many error rows
     * sit beside it.
     *
     * On indexing: the WHERE clause is two equalities, migration_id and
     * entity_type, which is exactly the `migration_entity` index — the planner
     * takes it and reads only that run's rows for that entity.
     * `status_lookup` (migration_id, status) can contribute only its first column
     * here, because the success veto is a per-wc_id aggregate rather than a row
     * predicate, so migration_entity is the tighter range and the one to expect
     * in EXPLAIN. The status list is still in the WHERE clause, but as a filter
     * inside that range rather than as the index.
     *
     * Ordering is a plain ascending sort on wc_id, which is VARCHAR, so it is
     * lexicographic rather than numeric. Deliberately: the column can hold
     * non-numeric keys, and CAST-ing to sort numerically would both defeat the
     * index and raise conversion warnings on the ones that are not numbers. The
     * caller treats the result as a set; the ordering is there so two calls agree
     * with each other, not because the sequence means anything.
     *
     * @param list<string>                   $statuses Statuses to re-attempt.
     * @param MigrationErrorCode|string|null $code     Optional: only ids logged
     *                                                 under this reason, resolved
     *                                                 against the same column
     *                                                 every other read uses.
     *
     * @return list<string> Distinct wc_id values, ascending.
     */
    public function getRetryableIds(
        string $migrationId,
        string $entityType,
        array $statuses = ['error'],
        MigrationErrorCode|string|null $code = null,
    ): array {
        global $wpdb;

        // 'success' in the retry set contradicts the veto below, so it could only
        // ever return nothing. Drop it rather than leave the caller wondering.
        $statuses = array_values(array_unique(array_filter(
            array_map(static fn (mixed $status): string => (string) $status, $statuses),
            static fn (string $status): bool => $status !== '' && $status !== 'success',
        )));

        if ($statuses === [] || $migrationId === '' || $entityType === '') {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        $column = self::CODE_COLUMN;
        $codeValue = $this->codeValue($code);

        $conditions = ['migration_id = %s', 'entity_type = %s'];
        $params = [$migrationId, $entityType];

        // Narrow to the rows the aggregate actually needs: the candidates, plus
        // the successes that veto them.
        $conditions[] = "(status IN ({$placeholders}) OR status = 'success')";
        $params = [...$params, ...$statuses];

        if ($codeValue !== null) {
            // A success row carries no reason, so the code filter must not reach
            // it — otherwise scoping a retry by reason quietly loses the veto and
            // hands back ids that already migrated.
            $conditions[] = "({$column} = %s OR status = 'success')";
            $params[] = $codeValue;
        }

        $where = implode(' AND ', $conditions);

        $sql = "SELECT wc_id
                FROM {$this->table}
                WHERE {$where}
                GROUP BY wc_id
                HAVING SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) = 0
                   AND SUM(CASE WHEN status IN ({$placeholders}) THEN 1 ELSE 0 END) > 0
                ORDER BY wc_id ASC";

        $params = [...$params, ...$statuses];

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

        $ids = [];

        foreach ($rows ?: [] as $row) {
            // ARRAY_A is requested, but $wpdb is replaceable — a site running a
            // drop-in or a query filter can hand back objects, and a fatal here
            // would take the whole retry down over a shape mismatch.
            $row = is_object($row) ? get_object_vars($row) : $row;
            $id = is_array($row) ? (string) ($row['wc_id'] ?? '') : '';

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Whether a migration id appears in the log at all.
     *
     * The cheap existence check the retry endpoint needs. A typo'd id should be
     * refused up front, not accepted into a run that migrates nothing and then
     * reports success.
     */
    public function hasEntries(string $migrationId): bool
    {
        global $wpdb;

        if ($migrationId === '') {
            return false;
        }

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE migration_id = %s LIMIT 1",
            $migrationId,
        ));

        return $found !== null && (int) $found > 0;
    }

    /**
     * Whether a row already exists for this exact (migration_id, entity_type,
     * wc_id, error_code) combination.
     *
     * Built for a caller that runs more than once per migration and must log
     * a given fact only the first time it observes it — MappingPromoter's
     * dead-link check is the first such caller: it re-enters on every batch
     * tick of a resumed run and keeps reporting the same dead ids each time,
     * on purpose (promotion itself has nothing to compare against). Without
     * this check the caller would write a fresh warning row per tick, which
     * inflates getStats()'s warning count and the code breakdown for what is
     * really a handful of distinct problems — the list-disagrees-with-summary
     * failure CODE_COLUMN exists to prevent.
     *
     * The code is required, not optional: `wc_id` on this table sometimes
     * holds an id from a different universe than a WooCommerce object id
     * (MappedFcProductMissing stores a FluentCart post id there), so a bare
     * (migration_id, entity_type, wc_id) match could collide with an
     * unrelated row that happens to share the same numeric value under the
     * same entity type. Filtering on the indexed error_code column too keeps
     * the check specific to the one fact being deduplicated.
     */
    public function hasEntryFor(
        string $migrationId,
        string $entityType,
        string $wcId,
        MigrationErrorCode|string $code,
    ): bool {
        global $wpdb;

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE migration_id = %s AND entity_type = %s AND wc_id = %s AND " . self::CODE_COLUMN . ' = %s
             LIMIT 1',
            $migrationId,
            $entityType,
            $wcId,
            $this->codeValue($code),
        ));

        return $found !== null && (int) $found > 0;
    }

    /**
     * How many error rows this run has written for one entity type.
     *
     * The number a reader would give if you sat them in front of the log and
     * asked how many things went wrong, which is the whole reason it exists.
     * MigrationOrchestrator::processBatch() counts an error when a record
     * *throws*, and for a long time that was the only kind there was. It is not:
     * a `$wpdb` write MySQL refuses does not throw, so a run could write ten
     * error rows and still report "0 errors — Success". That mismatch is what
     * hid `fct_orders.item_count` for the life of the feature.
     *
     * Exactly `status = 'error'`, and deliberately without a `$status`
     * parameter. Warnings are a separate, softer thing — a subscription paused
     * because its product is missing is not a failure — and a method that can be
     * asked for either is one that will eventually be asked for both and quietly
     * reclassify half the log. RetryPanel already reads the two apart via
     * getStats(); nothing needs this one to blur them.
     *
     * Served by the `migration_entity (migration_id, entity_type)` index, and
     * called once per batch rather than once per record.
     */
    public function countErrors(string $migrationId, string $entityType): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE migration_id = %s AND entity_type = %s AND status = 'error'",
            $migrationId,
            $entityType,
        ));
    }

    /**
     * The value to compare the code column against, or null for "do not filter".
     *
     * The single resolution point every reader goes through, so the `code` filter,
     * the retry scope and the breakdown can never disagree about what a code is.
     * Unrecognised strings pass through instead of being rejected — see
     * getPaginated() for why that is the honest behaviour rather than the lax one.
     */
    private function codeValue(MigrationErrorCode|string|null $code): string|null
    {
        if ($code instanceof MigrationErrorCode) {
            return $code->value;
        }

        return $code === null || $code === '' ? null : $code;
    }

    /**
     * Turn a code => count map into UI-ready descriptors, most frequent first.
     *
     * @param array<string, int> $counts
     *
     * @return list<array{code: string, count: int, label: string, hint: string, severity: string, category: string}>
     */
    private function describeCodeCounts(array $counts): array
    {
        $out = [];

        foreach ($counts as $code => $count) {
            $case = MigrationErrorCode::tryFrom((string) $code);

            $out[] = $case !== null
                ? ['count' => $count] + $case->toArray()
                : [
                    'code'     => (string) $code,
                    'count'    => $count,
                    'label'    => __('Unrecognised reason', 'cartshift'),
                    'hint'     => __('Read the log message; this reason has no built-in explanation.', 'cartshift'),
                    'severity' => 'error',
                    'category' => 'system',
                ];
        }

        return $out;
    }

    /**
     * Delete all log entries for a migration.
     */
    public function deleteByMigration(string $migrationId): void
    {
        global $wpdb;

        $wpdb->delete(
            $this->table,
            ['migration_id' => $migrationId],
            ['%s'],
        );
    }

    private function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['details'] = isset($row['details']) ? json_decode($row['details'], true) : null;

        // The column, and deliberately not the details JSON as a fallback. v4
        // backfilled every well-formed code, so a JSON fallback would only ever
        // fire for a row v4 rejected as malformed — and it would fire only here.
        // That row would then show a reason in the list while being absent from
        // the filter and the counts, which both read the column: the exact
        // list-disagrees-with-summary failure CODE_COLUMN exists to prevent.
        // Better to show no reason than a reason nothing else can corroborate.
        //
        // The key is always present and is null when there is no code, because
        // the UI reads it unconditionally.
        $code = $row[self::CODE_COLUMN] ?? null;

        $row[self::CODE_COLUMN] = is_string($code) && $code !== '' ? $code : null;

        return $row;
    }
}
