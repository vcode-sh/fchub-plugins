<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
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
        if (!function_exists('wcs_get_subscriptions')) {
            return 0;
        }

        global $wpdb;

        $table = WooStorage::ordersTable();
        $scope = WooStorage::subscriptionScopeSql();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE {$scope}",
        );
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
        if (!function_exists('wcs_get_subscriptions')) {
            return [];
        }

        $offset = max(0, (int) $cursor);

        $subs = array_values((array) wcs_get_subscriptions([
            'subscriptions_per_page' => $limit,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
        ]));

        $this->nextOffset = $offset + count($subs);

        return $subs;
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
        $wcCustomerId = $subscription->get_customer_id();
        if ($wcCustomerId > 0) {
            $fcCustomerId = $this->idMap->getFcId(Constants::ENTITY_CUSTOMER, (string) $wcCustomerId);
            if (!$fcCustomerId) {
                $this->writeLog(
                    $wcId,
                    'warning',
                    sprintf('Customer ID %d not found in ID map. Skipping subscription.', $wcCustomerId),
                    MigrationErrorCode::CustomerNotFound,
                );
                return false;
            }
        }

        // FIX C4: validate product_id and variation_id before creating.
        $missingRefs = $this->validateProductReferences($subscription, $wcId);
        if ($missingRefs) {
            return false;
        }

        $mapped = $this->subscriptionMapper->map($subscription);

        // FIX M1/M2: flush mapper warnings to the migration log.
        foreach ($this->subscriptionMapper->getCodedWarnings() as $warning) {
            $this->writeLog($wcId, 'warning', $warning['message'], $warning['code']);
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
     * FIX C4: validate that product and variation references exist in the ID map.
     * Returns true if references are missing (should skip), false if all valid.
     *
     * Every line item is checked. Stopping after the first one let multi-product
     * subscriptions through while still pointing at products that were never
     * migrated. Behaviour on a miss is unchanged — log a warning, skip the
     * subscription — except that the warning now names the offending item.
     */
    private function validateProductReferences(mixed $subscription, int $wcId): bool
    {
        $position = 0;

        foreach ($subscription->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $position++;

            $wcProductId = $item->get_product_id();
            $wcVariationId = $item->get_variation_id();
            $itemLabel = $this->describeItem($item, $position);

            if ($wcProductId > 0 && !$this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcProductId)) {
                $this->writeLog(
                    $wcId,
                    'warning',
                    sprintf(
                        'Product ID %d for item %s not found in ID map. Skipping subscription.',
                        $wcProductId,
                        $itemLabel,
                    ),
                    MigrationErrorCode::ProductNotMapped,
                );
                return true;
            }

            if ($wcVariationId > 0) {
                $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcVariationId);
                if (!$fcVariationId) {
                    $fcVariationId = $this->idMap->getFcId(Constants::ENTITY_VARIATION, (string) $wcProductId);
                }
                if (!$fcVariationId) {
                    $this->writeLog(
                        $wcId,
                        'warning',
                        sprintf(
                            'Variation ID %d for item %s not found in ID map. Skipping subscription.',
                            $wcVariationId,
                            $itemLabel,
                        ),
                        MigrationErrorCode::VariationNotMapped,
                    );
                    return true;
                }
            }
        }

        return false;
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
