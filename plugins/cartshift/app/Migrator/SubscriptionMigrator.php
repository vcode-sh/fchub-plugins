<?php

declare(strict_types=1);

namespace CartShift\Migrator;

defined('ABSPATH') || exit;

use CartShift\Domain\Mapping\SubscriptionMapper;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use FluentCart\App\Models\Subscription;

final class SubscriptionMigrator extends AbstractMigrator
{
    private readonly SubscriptionMapper $subscriptionMapper;

    public function __construct(
        IdMapRepository $idMap,
        MigrationLogRepository $log,
        MigrationState $migrationState,
        string $migrationId,
        int $batchSize = Constants::DEFAULT_BATCH_SIZE,
    ) {
        parent::__construct($idMap, $log, $migrationState, $migrationId, $batchSize);
        $this->subscriptionMapper = new SubscriptionMapper($idMap, get_woocommerce_currency());
    }

    #[\Override]
    protected function getEntityType(): string
    {
        return Constants::ENTITY_SUBSCRIPTION;
    }

    /**
     * FIX H2: use COUNT(*) query, not loading all full subscription objects.
     */
    #[\Override]
    protected function countTotal(): int
    {
        if (!function_exists('wcs_get_subscriptions')) {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}wc_orders
             WHERE type = 'shop_subscription'",
        );
    }

    #[\Override]
    protected function fetchBatch(int $offset, int $limit): array
    {
        if (!function_exists('wcs_get_subscriptions')) {
            return [];
        }

        $subs = wcs_get_subscriptions([
            'subscriptions_per_page' => $limit,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
        ]);

        return array_values($subs);
    }

    /**
     * @param \WC_Subscription $subscription
     */
    #[\Override]
    protected function processRecord(mixed $subscription): int|false
    {
        $wcId = $subscription->get_id();

        if ($this->idMap->getFcId(Constants::ENTITY_SUBSCRIPTION, (string) $wcId)) {
            $this->writeLog($wcId, 'skipped', 'Already migrated.');
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

        $fcSubscription = Subscription::query()->create($mapped);
        $this->idMap->store(
            Constants::ENTITY_SUBSCRIPTION,
            (string) $wcId,
            $fcSubscription->id,
            $this->migrationId,
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
     */
    private function validateProductReferences(mixed $subscription, int $wcId): bool
    {
        foreach ($subscription->get_items() as $item) {
            /** @var \WC_Order_Item_Product $item */
            $wcProductId = $item->get_product_id();
            $wcVariationId = $item->get_variation_id();

            if ($wcProductId > 0 && !$this->idMap->getFcId(Constants::ENTITY_PRODUCT, (string) $wcProductId)) {
                $this->writeLog(
                    $wcId,
                    'warning',
                    sprintf('Product ID %d not found in ID map. Skipping subscription.', $wcProductId),
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
                        sprintf('Variation ID %d not found in ID map. Skipping subscription.', $wcVariationId),
                    );
                    return true;
                }
            }

            break; // Only check the first item.
        }

        return false;
    }
}
