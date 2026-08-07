<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\FcSubscriptionStatus;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Support\WooStorage;
use FluentCart\App\Models\Subscription;

final class SubscriptionMigrator extends AbstractMigrator
{
    private readonly SubscriptionMapper $subscriptionMapper;

    /** @var int Offset the next page starts at — see cursorFor(). */
    private int $nextOffset = 0;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $batchSize);
        $this->subscriptionMapper = new SubscriptionMapper($idMap, get_woocommerce_currency());
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_SUBSCRIPTION;
    }

    /**
     * FIX H2: use COUNT(*) query, not loading all full subscription objects.
     *
     * Scoped to the statuses WooCommerce Subscriptions actually registers, so
     * trashed and draft shop_subscription rows — which wcs_get_subscriptions()
     * never hands back — stop inflating the total. The status set is read from
     * wcs_get_subscription_statuses() when available and falls back to the
     * documented list otherwise; either way the function_exists() guard above
     * means none of this runs without the add-on installed.
     */
    #[\Override]
    protected function countTotal(): int
    {
        // The unit suite stubs wcs_get_subscriptions(), and a PHP function
        // cannot be undeclared, so nothing in the suite exercises this branch
        // any more. Its coverage is Task 15's real-store validation.
        if (!function_exists('wcs_get_subscriptions')) {
            return 0;
        }

        global $wpdb;

        $table     = WooStorage::ordersTable();
        $scope     = WooStorage::subscriptionScopeSql();
        $selection = $this->scopeResolver()->subscriptionPredicate();

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$scope}" . $selection->andSql();

        // subscriptionScopeSql() is already prepared, so only the selection's
        // values are bound — and when it has none there is nothing left to
        // prepare. prepare() with no placeholders and no values is a warning,
        // so the branch is on the values rather than on the clause: '1 = 0' is
        // a clause with no values, and it must go down the same door as none().
        return (int) ($selection->values() === []
            ? $wpdb->get_var($sql)
            : $wpdb->get_var($wpdb->prepare($sql, ...$selection->values())));
    }

    /**
     * Subscriptions keep OFFSET pagination. Deliberately.
     *
     * Every other migrator moved to a keyset cursor because its source is
     * either our own SQL or a query layer whose capabilities can be read off
     * the WooCommerce source in this repository. wcs_get_subscriptions() is not
     * one of those: WooCommerce Subscriptions is a paid add-on, it is not
     * installed here, and its argument vocabulary cannot be verified. Inventing
     * an `id > x` argument that may not exist would either be silently ignored
     * — re-reading the same page for ever — or throw.
     *
     * Building the ID page from {prefix}wc_orders ourselves is expressible, but
     * hydrating those IDs still needs an add-on function, so the same objection
     * applies to the half that matters.
     *
     * The cost is bounded in practice: subscription counts are a small fraction
     * of order counts, so the O(n^2) tail this leaves behind is the cheapest one
     * on the list. Revisit if WooCommerce Subscriptions is ever available to
     * test against.
     */
    #[\Override]
    public function fetchBatch(string|int|null $cursor, int $limit): array
    {
        // The unit suite stubs wcs_get_subscriptions(), and a PHP function
        // cannot be undeclared, so nothing in the suite exercises this branch
        // any more. Its coverage is Task 15's real-store validation.
        if (!function_exists('wcs_get_subscriptions')) {
            return [];
        }

        $offset = max(0, (int) $cursor);

        // Loops only when an entire page filters away to nothing — the same
        // shape OrderMigrator::fetchBatch() and CouponMigrator::fetchBatch() use
        // when an entire page fails to hydrate, and for the same reason.
        //
        // Returning [] here while wcs_get_subscriptions() is still yielding rows
        // would end the entity: MigrationOrchestrator treats an empty batch as
        // the *only* end-of-entity signal. With 400 subscriptions, a batch size
        // of 50 and the one in-scope subscriber at position 300, the first page
        // filters to zero — and a paying subscriber never migrates, with nothing
        // in the counters to show for it.
        while (true) {
            $subs = array_values((array) wcs_get_subscriptions([
                'subscriptions_per_page' => $limit,
                'offset'                 => $offset,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
            ]));

            if ($subs === []) {
                // The source itself is exhausted. This is the only honest way
                // out of this method with an empty array.
                $this->nextOffset = $offset;

                return [];
            }

            // The position advances by what came back, never by what survived
            // the filter. wcs_get_subscriptions() is the one source this
            // migrator cannot express a predicate to — its query vocabulary is
            // not verifiable against source in this repository — so the filter
            // happens after the fetch, and the paging position has to stay a
            // position in the *unfiltered* sequence or a page that kept nothing
            // would ask for the same offset for ever.
            $offset += count($subs);
            $this->nextOffset = $offset;

            $kept = $this->inScope($subs);

            if ($kept !== []) {
                return $kept;
            }
        }
    }

    /**
     * Keep only the subscriptions this scope selects.
     *
     * Returning fewer than were fetched is fine — a short batch is not an
     * end-of-entity signal. Returning *none* is not fine, which is why the only
     * caller loops rather than handing this result straight back.
     *
     * @param list<object> $subs
     *
     * @return list<object>
     */
    private function inScope(array $subs): array
    {
        $resolver = $this->scopeResolver();

        if ($resolver->subscriptionPredicate()->isEmpty()) {
            return $subs;
        }

        $scope = $resolver->scope();

        if ($scope->mode() === MigrationScope::MODE_SINCE) {
            // MigrationScope::since() is GMT. WC_DateTime carries the site
            // timezone, so date('Y-m-d H:i:s') on it renders site-local time and
            // comparing that string against a GMT bound shifts the boundary by
            // the site's offset — silently, and in whichever direction the site
            // happens to be configured. getTimestamp() is the same instant on
            // both sides regardless, so the comparison is on epochs.
            $bound = (int) strtotime((string) $scope->since() . ' UTC');

            return array_values(array_filter($subs, static function (object $sub) use ($bound): bool {
                $created = $sub->get_date_created();

                return $created !== null && $created->getTimestamp() >= $bound;
            }));
        }

        $closed     = $resolver->closedCustomers();
        $registered = array_flip($closed['registered']);
        $guests     = array_flip($closed['guests']);

        // A disjunction, not a branch, because ScopeResolver::
        // seedSubscriptionPredicate() is a disjunction: `customer_id IN (…) OR
        // billing_email IN (…)`, with no `customer_id = 0` guard on the email
        // side. countTotal() counts through that predicate and this filters the
        // fetched page, so anything the two read differently is a subscription
        // counted and never migrated — total stuck above processed, silently
        // and for ever, which is the exact failure countTotal()'s docblock
        // exists to prevent.
        //
        // The gap is real rather than theoretical: closedCustomers() keeps its
        // *derived* sets disjoint, but an owner is free to type the email of a
        // registered account into guest_emails, and nothing filters it out. The
        // resolver's reading is also the one the order side already applies —
        // seedOrderPredicate() ORs the same two sets — so mirroring it here
        // keeps subscriptions consistent with the orders they belong to. If
        // such a subscription's customer was not selected, processRecord()
        // skips it with a logged warning: visible, counted, and nothing like a
        // number that never moves.
        return array_values(array_filter($subs, static function (object $sub) use ($registered, $guests): bool {
            $customerId = (int) $sub->get_customer_id();

            if ($customerId > 0 && isset($registered[$customerId])) {
                return true;
            }

            // Lower-cased on both sides: MigrationScope normalises the picked
            // emails the same way, and a case mismatch here drops a subscriber.
            return isset($guests[strtolower(trim((string) $sub->get_billing_email()))]);
        }));
    }

    /**
     * Hydrate exactly these subscription IDs, for a retry run.
     *
     * This is the one migrator whose retry does not go through the same door as
     * its batch fetch, and it is an improvement rather than a compromise:
     * fetchBatch() has to page blind through wcs_get_subscriptions() because an
     * `id > x` argument cannot be verified against an add-on that is not
     * installed, whereas hydrating a known ID needs only wcs_get_subscription(),
     * whose single-argument signature is stable and documented.
     *
     * Both function_exists() guards stay. Without WooCommerce Subscriptions
     * there is nothing to hydrate, and a retry that quietly returned nothing
     * would look like success — so the reason is written to the log against the
     * retry run rather than left to inference.
     *
     * The offset cursor is deliberately not moved: a retry paginates an ID list.
     *
     * @param array<int, string|int> $wcIds
     *
     * @return list<object>
     */
    #[\Override]
    public function fetchByIds(array $wcIds): array
    {
        $ids = self::normalizeIntIds($wcIds);

        if ($ids === []) {
            return [];
        }

        if (!function_exists('wcs_get_subscriptions') || !function_exists('wcs_get_subscription')) {
            $this->writeLog(
                0,
                'warning',
                sprintf(
                    'Cannot retry %d subscription(s): WooCommerce Subscriptions is not active, '
                    . 'so there is nothing to hydrate them from.',
                    count($ids),
                ),
            );

            return [];
        }

        $subscriptions = [];

        foreach ($ids as $id) {
            $subscription = wcs_get_subscription($id);

            if (is_object($subscription)) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * The "cursor" here is the offset the next page starts at.
     *
     * It is computed in fetchBatch() rather than derived from the record,
     * because an offset is a property of the page, not of any row in it. The
     * orchestrator calls this immediately after fetchBatch() with a record from
     * that same batch, so the pairing holds. It is monotonic whenever a batch
     * is non-empty, which is the only case the orchestrator asks about.
     */
    #[\Override]
    public function cursorFor(mixed $record): string|int
    {
        return $this->nextOffset;
    }

    /**
     * Validate a subscription without creating any FC records.
     * Skips FK validation in dry-run — we validate data mapping quality only.
     *
     * @param \WC_Subscription $subscription
     */
    #[\Override]
    public function validateRecord(mixed $subscription): bool
    {
        $wcId = $subscription->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_SUBSCRIPTION, (string) $wcId)) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: already migrated, would skip.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        $wcCustomerId = $subscription->get_customer_id();
        if ($wcCustomerId <= 0) {
            $this->writeLog($wcId, 'dry-run', 'dry-run: subscription has no customer, would fail.', MigrationErrorCode::CustomerNotFound);
            return false;
        }

        $this->writeLog($wcId, 'dry-run', sprintf(
            'dry-run: would create subscription WC-#%d.',
            $wcId,
        ));

        return true;
    }

    /**
     * @param \WC_Subscription $subscription
     */
    #[\Override]
    public function processRecord(mixed $subscription): int|false
    {
        $wcId = $subscription->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_SUBSCRIPTION, (string) $wcId)) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.', MigrationErrorCode::AlreadyMigrated);
            return false;
        }

        // FIX C4: validate customer_id before creating.
        //
        // The one gap a subscription does not survive. fct_subscriptions.customer_id
        // is NOT NULL, and unlike an order there is nothing on the record to rebuild
        // a buyer from — a subscription carries no billing address of its own. A
        // subscription with nobody to bill is not a compromised record, it is not a
        // record at all, so this stays a skip.
        $wcCustomerId = $subscription->get_customer_id();
        $fcCustomerId = $wcCustomerId > 0
            ? $this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $wcCustomerId)
            : null;

        if (!$fcCustomerId) {
            $this->writeLog(
                $wcId,
                'warning',
                $wcCustomerId > 0
                    ? sprintf(
                        'Customer ID %d was not migrated, and a subscription has no billing details of its own '
                        . 'to rebuild a buyer from. Skipping: FluentCart requires a customer on every subscription.',
                        $wcCustomerId,
                    )
                    : 'The subscription has no customer at all. Skipping: FluentCart requires a customer on '
                        . 'every subscription, and there is nothing here to rebuild one from.',
                MigrationErrorCode::CustomerNotFound,
            );

            return false;
        }

        $missingReference = $this->missingProductReference($subscription);

        $mapped = $this->subscriptionMapper->map($subscription);

        // FIX M1/M2: flush mapper warnings to the migration log.
        foreach ($this->subscriptionMapper->getCodedWarnings() as $warning) {
            $this->writeLog($wcId, 'warning', $warning['message'], $warning['code']);
        }

        if ($missingReference !== null) {
            $mapped = self::pause($mapped);

            $this->writeLog(
                $wcId,
                'warning',
                sprintf(
                    '%s. Migrated as "paused" so nobody is charged for something that is not in the shop; '
                    . 'the original WooCommerce status "%s" is kept in the subscription config. '
                    . 'Migrate the product, point the subscription at it, then resume it.',
                    $missingReference,
                    $subscription->get_status(),
                ),
                MigrationErrorCode::SubscriptionPausedMissingProduct,
            );
        }

        $fcSubscription = Subscription::query()->create($mapped);
        $this->idMap->store(
            Constants::ENTITY_SUBSCRIPTION,
            (string) $wcId,
            $fcSubscription->id,
            $this->migrationId(),
            true,
        );

        $this->writeLog($wcId, 'success', sprintf(
            'Migrated subscription #%d (FC ID: %d) - Status: %s.',
            $wcId,
            $fcSubscription->id,
            $mapped['status'],
        ));

        return $fcSubscription->id;
    }

    /**
     * The first product or variation on the subscription that never made it into
     * FluentCart, described in words, or null when everything resolves.
     *
     * FIX C4 originally used this to skip the subscription. It no longer does.
     * Losing a paying subscriber with no trace is the worst outcome available:
     * the shop owner finds out when the money stops. A subscription is a live
     * instruction rather than a record, so it must not migrate active and
     * pointing at nothing either — paused is the only safe middle, and that is
     * what processRecord() does with this.
     *
     * Every line item is checked. Stopping after the first one let multi-product
     * subscriptions through while still pointing at products that were never
     * migrated.
     */
    private function missingProductReference(mixed $subscription): ?string
    {
        $position = 0;

        foreach ($subscription->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $position++;

            $wcProductId = $item->get_product_id();
            $wcVariationId = $item->get_variation_id();
            $itemLabel = $this->describeItem($item, $position);

            if ($wcProductId > 0 && !$this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcProductId)) {
                return sprintf(
                    'Product ID %d for item %s was not migrated',
                    $wcProductId,
                    $itemLabel,
                );
            }

            if ($wcVariationId > 0) {
                $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcVariationId);
                if (!$fcVariationId) {
                    $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcProductId);
                }
                if (!$fcVariationId) {
                    return sprintf(
                        'Variation ID %d for item %s was not migrated',
                        $wcVariationId,
                        $itemLabel,
                    );
                }
            }
        }

        return null;
    }

    /**
     * Force a mapped subscription to 'paused', keeping the status it would have
     * had where somebody can find it again.
     *
     * fct_subscriptions has no notes column — the schema is
     * database/Migrations/SubscriptionsMigrator.php in FluentCart 1.6.0 — so the
     * audit trail goes in `config`, the JSON column SubscriptionMapper already
     * uses for its own migration bookkeeping (`wc_subscription_id`, `migrated`,
     * `currency`). Restoring the subscription later is then a matter of reading
     * `cartshift_original_status` back out.
     *
     * `config` is defensive about its own type because 'cartshift/mapper/
     * subscription' can rewrite the whole mapped array, and a filter that
     * replaced it with a string must not fatal here.
     *
     * @param array<string, mixed> $mapped
     *
     * @return array<string, mixed>
     */
    private static function pause(array $mapped): array
    {
        $originalStatus = is_string($mapped['status'] ?? null) ? $mapped['status'] : '';

        $config = is_array($mapped['config'] ?? null) ? $mapped['config'] : [];
        $config['cartshift_original_status'] = $originalStatus;
        $config['cartshift_paused_reason']   = 'product_not_migrated';

        $mapped['config'] = $config;
        $mapped['status'] = FcSubscriptionStatus::Paused->value;

        return $mapped;
    }

    /**
     * A human-readable label for a subscription line item, for log messages.
     * $position is 1-based; WC keys get_items() by order-item ID, not by offset.
     */
    private function describeItem(mixed $item, int $position): string
    {
        $name = '';

        if (is_object($item) && method_exists($item, 'get_name')) {
            $name = trim((string) $item->get_name());
        }

        return $name !== ''
            ? sprintf('#%d "%s"', $position, $name)
            : sprintf('#%d', $position);
    }
}
