<?php

namespace WcFc\Migrator;

defined('ABSPATH') or die;

use WcFc\Mapper\SubscriptionMapper;
use FluentCart\App\Models\Subscription;

class SubscriptionMigrator extends AbstractMigrator
{
    protected string $entityType = 'subscriptions';

    protected function countTotal(): int
    {
        if (!function_exists('wcs_get_subscriptions')) {
            return 0;
        }

        $subs = wcs_get_subscriptions([
            'subscriptions_per_page' => -1,
        ]);

        return count($subs);
    }

    protected function fetchBatch(int $page): array
    {
        if (!function_exists('wcs_get_subscriptions')) {
            return [];
        }

        $offset = ($page - 1) * $this->batchSize;

        $subs = wcs_get_subscriptions([
            'subscriptions_per_page' => $this->batchSize,
            'offset'                 => $offset,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
        ]);

        return array_values($subs);
    }

    /**
     * @param \WC_Subscription $subscription
     */
    protected function processRecord($subscription)
    {
        $wcId = $subscription->get_id();

        // Skip if already migrated.
        if ($this->idMap->getFcId('subscription', $wcId)) {
            $this->log($wcId, 'skipped', 'Already migrated.');
            return false;
        }

        $mapped = SubscriptionMapper::map($subscription, $this->idMap);

        if ($this->dryRun) {
            $this->log($wcId, 'success', sprintf(
                '[DRY RUN] Would migrate subscription #%d - Status: %s, Interval: %s.',
                $wcId,
                $mapped['status'],
                $mapped['billing_interval']
            ));
            return 0;
        }

        // Create FC subscription.
        $fcSubscription = Subscription::query()->create($mapped);
        $this->idMap->store('subscription', $wcId, $fcSubscription->id);

        $this->log($wcId, 'success', sprintf(
            'Migrated subscription #%d (FC ID: %d) - Status: %s, Interval: %s.',
            $wcId,
            $fcSubscription->id,
            $mapped['status'],
            $mapped['billing_interval']
        ));

        return $fcSubscription->id;
    }
}
