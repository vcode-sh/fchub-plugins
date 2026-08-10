<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Source;

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
use CartShift\Support\Constants;
use CartShift\Support\WooStorage;

/**
 * The live source, read through WooCommerce's own public data-store APIs.
 *
 * WooCommerce chooses its storage backend; CartShift does not. That is the
 * whole design. Lapka's authoritative store is legacy CPT, the plan forbids
 * forcing HPOS to make a migration tool convenient, and the reader this
 * replaces hard-coded `{prefix}wc_orders` — so on that store `countTotal()`
 * counted zero while `fetchBatch()` happily hydrated 564 subscriptions through
 * WCS. Nothing here names a table.
 *
 * COUNT AND FETCH SHARE ONE SELECTION. `selectionIndex()` is the single answer
 * to "which subscriptions is this run about", and both `countSelected()` and
 * `page()` read it. The two cannot drift, because there is only one of them.
 *
 * THE DATASET IS DEPENDENCY-COMPLETE. A subscription reference is not an order.
 * Every parent, renewal, switch and resubscribe order is hydrated and emitted
 * as a full `OrderRecord` with its items and its succeeded charge, because
 * FluentCart recomputes `bill_count` from those charges and a bare integer
 * contributes nothing.
 *
 * THE MIRROR IS REPORTED, NEVER ADOPTED. Where a second backend exists, the
 * audit compares the fields that decide when somebody is next charged and says
 * where they disagree — Lapka's HPOS mirror holds two next-payment dates
 * exactly 365 days out and two retry schedules the legacy store has never heard
 * of. Reporting that is useful. Believing it would migrate the wrong dates.
 */
final class WooSubscriptionDatasetSource implements SubscriptionDatasetSource
{
    /** How many subscriptions one `wcs_get_subscriptions()` page asks for. */
    public const int DEFAULT_PAGE_SIZE = 100;

    /**
     * The mirror fields worth comparing, by WCS meta key.
     *
     * Both are schedule dates, and both are named in the plan: the next payment
     * decides when a subscriber is charged, and the retry schedule decides
     * whether a failed charge is coming back. WCS stores its schedule dates as
     * `_schedule_{date type}`, and the values are compared against the public
     * `get_date()` for the same type, so the authority side is never raw-read.
     *
     * @var array<string, string>
     */
    private const array MIRROR_DATE_META = [
        '_schedule_next_payment'  => 'next_payment',
        '_schedule_payment_retry' => 'payment_retry',
    ];

    /**
     * Built datasets, keyed by selection fingerprint.
     *
     * @var array<string, array{manifest: DatasetManifest, records: list<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord>}>
     */
    private array $built = [];

    /**
     * Selection indexes, keyed by selection fingerprint.
     *
     * @var array<string, list<array{id: int, status: string, customer_id: int, billing_email: string, created_ts: int|null}>>
     */
    private array $indexes = [];

    /**
     * Mirror reports, keyed by selection fingerprint.
     *
     * Memoised because the manifest now carries the summary and the audit
     * prints the full report, so an unmemoised comparison would hydrate every
     * selected subscription twice for one command.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $mirrorReports = [];

    public function __construct(
        private readonly string $sourceKey = Constants::DEFAULT_SOURCE_KEY,
        private readonly ?SubscriptionSelection $selection = null,
        private readonly WooDatasetRecordFactory $factory = new WooDatasetRecordFactory(),
        private readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
    ) {
    }

    /**
     * The run's selection, defaulting to every subscription in the source.
     */
    private function selection(): SubscriptionSelection
    {
        return $this->selection ?? SubscriptionSelection::all($this->sourceKey);
    }

    /**
     * Which backend WooCommerce considers authoritative — reported, not chosen.
     */
    public function storageAuthority(): string
    {
        return WooStorage::isHposEnabled() ? 'hpos' : 'posts';
    }

    #[\Override]
    public function manifest(): DatasetManifest
    {
        return $this->build($this->selection())['manifest'];
    }

    /**
     * @return iterable<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord>
     */
    #[\Override]
    public function records(SubscriptionSelection $selection): iterable
    {
        yield from $this->build($selection)['records'];
    }

    // ──────────────────────────────────────────────
    // Selection: one logic, two callers
    // ──────────────────────────────────────────────

    /**
     * Every selected subscription, as the scalars a scope needs to judge it.
     *
     * Scalars rather than objects because this list is walked in full to answer
     * "how many", and holding a hydrated `WC_Subscription` per row to answer a
     * count is how a large store runs out of memory before it runs out of
     * subscriptions. `page()` hydrates only what it is about to hand back.
     *
     * COST, HONESTLY. Both `countSelected()` and `page()` walk this index in
     * full, and it is memoised per selection fingerprint — but only for the
     * lifetime of this object. A batched background run that reconstructs the
     * migrator per request therefore re-pages the entire source once per batch,
     * which is quadratic in the number of subscriptions. At Lapka's 564 that is
     * nothing. On a store with tens of thousands it is not, and the fix when it
     * matters is caching the index across requests or teaching WCS a keyset
     * argument — neither of which can be written against an add-on that is not
     * installed here, which is why the cost is documented rather than guessed at.
     *
     * @return list<array{id: int, status: string, customer_id: int, billing_email: string, created_ts: int|null}>
     */
    public function selectionIndex(SubscriptionSelection $selection): array
    {
        $key = $selection->fingerprint();

        if (isset($this->indexes[$key])) {
            return $this->indexes[$key];
        }

        $index = [];

        if (function_exists('wcs_get_subscriptions')) {
            $offset = 0;

            while (true) {
                $page = array_values((array) wcs_get_subscriptions([
                    'subscriptions_per_page' => $this->pageSize,
                    'offset'                 => $offset,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                ]));

                if ($page === []) {
                    break;
                }

                $offset += count($page);

                foreach ($page as $subscription) {
                    if (!is_object($subscription)) {
                        continue;
                    }

                    $id = (int) $subscription->get_id();
                    $status = (string) $subscription->get_status();

                    if ($id <= 0 || !$selection->includes($id, $status)) {
                        continue;
                    }

                    $created = method_exists($subscription, 'get_date_created')
                        ? $subscription->get_date_created()
                        : null;

                    $index[] = [
                        'id'            => $id,
                        'status'        => $status,
                        'customer_id'   => (int) $subscription->get_customer_id(),
                        'billing_email' => (string) $subscription->get_billing_email(),
                        'created_ts'    => is_object($created) && method_exists($created, 'getTimestamp')
                            ? (int) $created->getTimestamp()
                            : null,
                    ];
                }
            }
        }

        usort($index, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $this->indexes[$key] = $index;
    }

    /**
     * How many subscriptions the selection holds, scope applied.
     *
     * Counts index rows, which includes any row that will later fail to
     * hydrate. That is deliberate — a selected subscription that WooCommerce
     * cannot hand back is still a selected subscription, and hiding it from the
     * total would make a run look complete while a live subscriber sat
     * unmigrated. `page()` reports those rows separately so the gap between
     * total and processed has a name in the log instead of being a mystery.
     *
     * @param null|callable(array{id: int, status: string, customer_id: int, billing_email: string, created_ts: int|null}): bool $filter
     */
    public function countSelected(SubscriptionSelection $selection, ?callable $filter = null): int
    {
        return count($this->filtered($selection, $filter));
    }

    /**
     * One page of hydrated subscriptions, from the same list `countSelected()` counts.
     *
     * Three things come back, and the second two are not decoration.
     *
     * `consumed` is how many INDEX ROWS the page covered, not how many objects
     * survived. The caller's cursor must advance by that, because a page of
     * fifty rows where one fails to hydrate returns forty-nine objects — and a
     * cursor advanced by forty-nine starts the next page one row early and
     * re-processes a subscription it has already handed back.
     *
     * The loop exists for the harder version of the same fault. If an ENTIRE
     * page fails to hydrate, an empty result would reach the orchestrator,
     * which treats an empty batch as the only end-of-entity signal: the
     * migration would end there and every later subscriber would be silently
     * untouched, with a green run to show for it. So this keeps consuming until
     * something survives or the selection is genuinely exhausted, and an empty
     * result therefore means exactly one thing.
     *
     * `unhydratable` names the rows that were skipped, so the caller can say so
     * out loud rather than leaving the total permanently above the processed
     * count with no explanation anywhere.
     *
     * @param null|callable(array{id: int, status: string, customer_id: int, billing_email: string, created_ts: int|null}): bool $filter
     * @return array{records: list<object>, consumed: int, unhydratable: list<int>}
     */
    public function page(
        SubscriptionSelection $selection,
        int $offset,
        int $limit,
        ?callable $filter = null,
    ): array {
        $selected = $this->filtered($selection, $filter);
        $offset = max(0, $offset);
        $limit = max(0, $limit);

        $consumed = 0;
        $records = [];
        $unhydratable = [];

        while ($limit > 0) {
            $slice = array_slice($selected, $offset + $consumed, $limit);

            if ($slice === []) {
                break;
            }

            $consumed += count($slice);

            foreach ($slice as $row) {
                $subscription = $this->hydrate($row['id']);

                if ($subscription === null) {
                    $unhydratable[] = $row['id'];

                    continue;
                }

                $records[] = $subscription;
            }

            if ($records !== []) {
                break;
            }
        }

        return ['records' => $records, 'consumed' => $consumed, 'unhydratable' => $unhydratable];
    }

    public function hydrate(int $subscriptionId): ?object
    {
        if (!function_exists('wcs_get_subscription')) {
            return null;
        }

        $subscription = wcs_get_subscription($subscriptionId);

        return is_object($subscription) ? $subscription : null;
    }

    // ──────────────────────────────────────────────
    // Storage mirror
    // ──────────────────────────────────────────────

    /**
     * Where the non-authoritative backend disagrees with the authoritative one.
     *
     * The authority side is read through the public `get_date()`, so this never
     * pretends to know better than WooCommerce about its own storage; only the
     * mirror is raw-read, and only to be reported.
     *
     * THE HONEST PART. A field that yields nothing on either side is not
     * agreement — it is a question nobody answered. That distinction is the
     * whole value of this report for `payment_retry`: plan section 4.9 records
     * two Stripe retry values existing ONLY in the HPOS mirror, and WooCommerce
     * Subscriptions is not installed on this machine, so `_schedule_payment_retry`
     * is convention rather than verified contract. If that literal is wrong the
     * mirror read returns nothing, the authority returns nothing, and a naive
     * comparison would report a clean bill of health for exactly the finding it
     * was built to surface. So per-field value counts are reported, and a field
     * that produced zero values on both sides is named in `unverified_fields`
     * rather than counted as agreeing.
     *
     * @return array{authority: string, mirror: string, mirror_present: bool, compared_fields: list<string>, compared: int, mirror_values_found: array<string, int>, authority_values_found: array<string, int>, unverified_fields: list<string>, discrepancies: list<array<string, mixed>>}
     */
    public function storageMirrorReport(SubscriptionSelection $selection): array
    {
        return $this->mirrorReports[$selection->fingerprint()] ??= $this->compareMirror($selection);
    }

    /**
     * The same finding, reduced to what a package header may carry.
     *
     * Counts, not rows. The per-subscriber discrepancies name source
     * references and dates; those belong in the source audit, not in a file
     * that crosses a machine boundary. What travels is enough for an operator
     * on the target to know whether to go and look.
     *
     * @return array<string, mixed>
     */
    public function storageMirrorSummary(SubscriptionSelection $selection): array
    {
        $report = $this->storageMirrorReport($selection);

        $discrepancyCounts = array_fill_keys($report['compared_fields'], 0);

        foreach ($report['discrepancies'] as $discrepancy) {
            $discrepancyCounts[$discrepancy['field']]++;
        }

        unset($report['discrepancies']);

        return $report + ['discrepancy_counts' => $discrepancyCounts];
    }

    /**
     * @return array<string, mixed>
     */
    private function compareMirror(SubscriptionSelection $selection): array
    {
        $authority = $this->storageAuthority();
        $mirror = $authority === 'hpos' ? 'posts' : 'hpos';

        $fields = array_values(self::MIRROR_DATE_META);
        sort($fields);

        $ids = array_column($this->selectionIndex($selection), 'id');

        $report = [
            'authority'              => $authority,
            'mirror'                 => $mirror,
            'mirror_present'         => false,
            'compared_fields'        => $fields,
            'compared'               => 0,
            'mirror_values_found'    => array_fill_keys($fields, 0),
            'authority_values_found' => array_fill_keys($fields, 0),
            // Nothing was compared, so nothing was verified. Reported as such
            // rather than as an empty discrepancy list, which reads as "fine".
            'unverified_fields'      => $fields,
            'discrepancies'          => [],
        ];

        if ($ids === []) {
            return $report;
        }

        $mirrorValues = $this->readMirror($mirror, $ids);

        if ($mirrorValues === null) {
            return $report;
        }

        $report['mirror_present'] = true;

        $mirrorFound = array_fill_keys($fields, 0);
        $authorityFound = array_fill_keys($fields, 0);
        $discrepancies = [];

        foreach ($ids as $id) {
            $subscription = $this->hydrate($id);

            if ($subscription === null || !method_exists($subscription, 'get_date')) {
                continue;
            }

            $report['compared']++;

            foreach (self::MIRROR_DATE_META as $metaKey => $field) {
                // `wcsDate()` rather than a bare string cast: WCS answers the
                // integer 0 for an unset date, and `'0'` compared against an
                // absent mirror row would report drift on every subscription
                // that simply never had a trial.
                $authorityValue = SubscriptionRecordFactory::wcsDate($subscription->get_date($field));
                // Both sides through the same normaliser, or a mirror row
                // holding '0' would "differ" from an authority that is absent.
                $mirrorValue = SubscriptionRecordFactory::wcsDate($mirrorValues[$id][$metaKey] ?? null);

                $authorityFound[$field] += $authorityValue === null ? 0 : 1;
                $mirrorFound[$field] += $mirrorValue === null ? 0 : 1;

                if ($authorityValue === $mirrorValue) {
                    continue;
                }

                $discrepancies[] = [
                    'authority'  => $authorityValue,
                    'field'      => $field,
                    'mirror'     => $mirrorValue,
                    'source_ref' => SubscriptionRecordFactory::ref(SubscriptionRecord::KIND, $id),
                ];
            }
        }

        usort(
            $discrepancies,
            static fn (array $left, array $right): int =>
                [$left['source_ref'], $left['field']] <=> [$right['source_ref'], $right['field']],
        );

        // Zero values on both sides across the whole selection. Either the
        // field genuinely is not in use anywhere, or the meta key this reads is
        // not the one the source writes — and from here those are
        // indistinguishable. Neither is evidence that the two backends agree.
        $unverified = array_values(array_filter(
            $fields,
            static fn (string $field): bool => $mirrorFound[$field] === 0 && $authorityFound[$field] === 0,
        ));
        sort($unverified);

        $report['mirror_values_found'] = $mirrorFound;
        $report['authority_values_found'] = $authorityFound;
        $report['unverified_fields'] = $unverified;
        $report['discrepancies'] = $discrepancies;

        return $report;
    }

    // ──────────────────────────────────────────────
    // Building the dataset
    // ──────────────────────────────────────────────

    /**
     * @return array{manifest: DatasetManifest, records: list<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord>}
     */
    private function build(SubscriptionSelection $selection): array
    {
        $key = $selection->fingerprint();

        if (isset($this->built[$key])) {
            return $this->built[$key];
        }

        /** @var array<string, CustomerRecord|InvalidSourceRecord> $customers */
        $customers = [];
        /** @var array<int, ProductRecord|InvalidSourceRecord> $products */
        $products = [];
        /** @var array<int, OrderRecord|InvalidSourceRecord> $orders */
        $orders = [];
        /** @var list<SubscriptionRecord|InvalidSourceRecord> $subscriptions */
        $subscriptions = [];

        $currencies = [];
        $productIds = [];
        $orderIds = [];

        foreach ($this->selectionIndex($selection) as $row) {
            $subscription = $this->hydrate($row['id']);

            if ($subscription === null) {
                // Listed by the source and then unhydratable. Counted rather
                // than skipped: a selected subscription that quietly vanishes
                // makes every downstream total agree with a run that never
                // looked at it.
                $subscriptions[] = $this->factory->invalid(
                    $this->sourceKey,
                    SubscriptionRecord::KIND,
                    SubscriptionRecordFactory::ref(SubscriptionRecord::KIND, $row['id']),
                    [WooDatasetRecordFactory::REASON_INVALID_SOURCE_RECORD],
                    ['source_subscription_id' => $row['id'], 'hydrated' => false],
                );

                continue;
            }

            $relatedByType = $this->factory->relatedOrdersByType($subscription);
            $record = $this->factory->subscription($this->sourceKey, $subscription, $relatedByType);
            $subscriptions[] = $record;

            if ($record instanceof SubscriptionRecord) {
                $customers[$record->sourceCustomerRef] ??= $this->factory->customer($this->sourceKey, $subscription);
                $currencies[] = $record->currency;

                foreach ($record->items as $item) {
                    $productIds[(int) $item['source_product_id']] = true;
                }

                $orderIds[$record->parentOrderId] = true;
            }

            foreach ($relatedByType as $ids) {
                foreach ($ids as $orderId) {
                    $orderIds[$orderId] = true;
                }
            }
        }

        unset($orderIds[0]);

        foreach (array_keys($orderIds) as $orderId) {
            $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;

            if (!is_object($order)) {
                // No payload, so the closure validator will report the missing
                // related order against the subscription that named it. Nothing
                // is invented to fill the gap.
                continue;
            }

            $record = $this->factory->order($this->sourceKey, $order);
            $orders[$orderId] = $record;

            if ($record instanceof OrderRecord) {
                if ($record->currency !== '') {
                    $currencies[] = $record->currency;
                }

                foreach ($record->productClaims() as $claim) {
                    $productIds[$claim['source_product_id']] = true;
                }
            }
        }

        unset($productIds[0]);

        foreach (array_keys($productIds) as $productId) {
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;

            if (is_object($product)) {
                $products[$productId] = $this->factory->product(
                    $this->sourceKey,
                    $product,
                    static fn (int $variationId): ?object => is_object($resolved = wc_get_product($variationId))
                        ? $resolved
                        : null,
                );
            }
        }

        ksort($orders);
        ksort($products);
        ksort($customers);

        $records = [
            ...array_values($customers),
            ...array_values($products),
            ...array_values($orders),
            ...$subscriptions,
        ];

        return $this->built[$key] = [
            'manifest' => $this->manifestFor($selection, $records, $currencies),
            'records'  => $records,
        ];
    }

    /**
     * @param list<CustomerRecord|ProductRecord|OrderRecord|SubscriptionRecord|InvalidSourceRecord> $records
     * @param list<string> $currencies
     */
    private function manifestFor(
        SubscriptionSelection $selection,
        array $records,
        array $currencies,
    ): DatasetManifest {
        $counts = array_fill_keys(DatasetManifest::KINDS, 0);
        $invalid = 0;

        foreach ($records as $record) {
            // An invalid record counts under the kind it failed to become. Drop
            // it from the total and 564 quietly becomes 563.
            $kind = $record instanceof InvalidSourceRecord ? $record->entityKind : $record->kind();

            if (array_key_exists($kind, $counts)) {
                $counts[$kind]++;
            }

            if ($record instanceof InvalidSourceRecord) {
                $invalid++;
            }
        }

        return new DatasetManifest(
            DatasetManifest::SCHEMA_VERSION,
            $this->sourceKey,
            $this->storageAuthority(),
            $currencies,
            gmdate('Y-m-d H:i:s'),
            self::versions(),
            $selection->fingerprint(),
            $counts,
            $invalid,
            count($records),
            // The checksum belongs to a written package, over its canonical
            // record lines. A live source has no lines, and inventing a value
            // here would make a package's most load-bearing field look like
            // something a source could assert about itself.
            '',
            // Computed here because only the source has WooCommerce booted, and
            // carried in the header because in cross-runtime mode the operator
            // decides on the target.
            $this->storageMirrorSummary($selection),
            $selection->toArray(),
        );
    }

    /**
     * @return array<string, string|null>
     */
    private static function versions(): array
    {
        return [
            'cartshift'                => defined('CARTSHIFT_VERSION') ? (string) CARTSHIFT_VERSION : null,
            'woocommerce'              => defined('WC_VERSION') ? (string) WC_VERSION : null,
            'woocommerce_subscriptions' => defined('WCS_VERSION') ? (string) WCS_VERSION : null,
        ];
    }

    /**
     * @param null|callable(array{id: int, status: string, customer_id: int, billing_email: string, created_ts: int|null}): bool $filter
     * @return list<array{id: int, status: string, customer_id: int, billing_email: string, created_ts: int|null}>
     */
    private function filtered(SubscriptionSelection $selection, ?callable $filter): array
    {
        $index = $this->selectionIndex($selection);

        return $filter === null ? $index : array_values(array_filter($index, $filter));
    }

    /**
     * The mirror's copy of the schedule meta, or null when there is no mirror.
     *
     * Null means the mirror does not exist as a place to look — the HPOS meta
     * table is absent. It does NOT mean "the query found nothing", which is a
     * different and much less reassuring finding, and is why the empty case
     * comes back as an empty array in both directions. `postmeta` is WordPress
     * core and is always there, so the posts direction can never be null; a
     * legacy store with no schedule meta reports present-and-empty, and
     * `unverified_fields` is what says nothing was learned from it.
     *
     * @param list<int> $ids
     * @return array<int, array<string, string>>|null
     */
    private function readMirror(string $mirror, array $ids): ?array
    {
        global $wpdb;

        if ($mirror === 'hpos') {
            $table = $wpdb->prefix . 'wc_orders_meta';

            if (!$this->tableExists($table)) {
                return null;
            }

            $idColumn = 'order_id';
        } else {
            $table = $wpdb->postmeta;
            $idColumn = 'post_id';
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));
        $keys = implode(', ', array_fill(0, count(self::MIRROR_DATE_META), '%s'));

        $rows = $this->readQuietly($wpdb->prepare(
            'SELECT ' . $idColumn . ' AS object_id, meta_key, meta_value'
            . ' FROM `' . $table . '`'
            . ' WHERE ' . $idColumn . ' IN (' . $placeholders . ')'
            . ' AND meta_key IN (' . $keys . ')',
            ...[...$ids, ...array_keys(self::MIRROR_DATE_META)],
        ));

        $values = [];

        foreach ($rows as $row) {
            $values[(int) ($row->object_id ?? 0)][(string) ($row->meta_key ?? '')] =
                (string) ($row->meta_value ?? '');
        }

        return $values;
    }

    private function tableExists(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== '';
    }

    /**
     * Run a read with wpdb's own error printing switched off, then put it back.
     *
     * A missing mirror table is an ordinary finding here, and wpdb would
     * otherwise echo the raw MySQL error straight into a `--format=json`
     * stream whenever WP_DEBUG_DISPLAY is on — which breaks both the JSON and
     * the byte-identical-summary promise the audit rests on.
     *
     * @return list<object>
     */
    private function readQuietly(string $sql): array
    {
        global $wpdb;

        $previous = $wpdb->suppress_errors(true);

        try {
            $rows = $wpdb->get_results($sql);

            return is_array($rows) ? array_values(array_filter($rows, is_object(...))) : [];
        } finally {
            $wpdb->suppress_errors($previous);
        }
    }

}
