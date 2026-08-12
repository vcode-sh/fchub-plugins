<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription\Source;

use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferRecordSource;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Support\UtcDateTime;
use CartShift\Support\WooStorage;

defined('ABSPATH') || exit;

/** WCS public-API source for canonical v2 subscription records. */
final class WooSubscriptionRecordSource implements TransferRecordSource
{
    /** @var (\Closure(SelectionClause): iterable<object>)|null */
    private readonly ?\Closure $reader;

    /** @param (callable(SelectionClause): iterable<object>)|null $reader */
    public function __construct(
        ?callable $reader = null,
        private readonly WooDatasetRecordFactory $factory = new WooDatasetRecordFactory(),
    ) {
        $this->reader = $reader === null ? null : $reader(...);
    }

    /** @return iterable<RecordEnvelope> */
    public function records(TransferSelection $selection): iterable
    {
        if ($selection->subscriptions->mode === SelectionMode::None) {
            return;
        }
        $source = $this->reader !== null
            ? ($this->reader)($selection->subscriptions)
            : $this->loadedSubscriptions($selection->subscriptions);
        $records = [];
        foreach ($source as $subscription) {
            if (!is_object($subscription) || !is_callable([$subscription, 'get_id'])) {
                throw new SourceRecordException('subscription_hydration_failed', 'subscription_hydration_failed: selected WCS subscription did not hydrate.');
            }
            $id = (int) $subscription->get_id();
            if ($id <= 0 || isset($records[$id])) {
                throw new SourceRecordException('subscription_source_identity_duplicate', 'subscription_source_identity_duplicate: invalid or duplicate WCS subscription identity.');
            }
            $typedOrders = $this->factory->relatedOrdersByType($subscription);
            $legacy = $this->factory->subscription($selection->sourceKey, $subscription, $typedOrders);
            if ($legacy instanceof InvalidSourceRecord) {
                throw new SourceRecordException(
                    'blocked_subscription_source_record',
                    'blocked_subscription_source_record:' . implode(',', $legacy->reasonCodes),
                );
            }
            $customer = $legacy->sourceCustomerId !== null
                ? new SourceIdentity($selection->sourceKey, RecordKind::Customer->value, (string) $legacy->sourceCustomerId)
                : new SourceIdentity($selection->sourceKey, RecordKind::Customer->value, $legacy->parentOrderId . ':guest');
            $records[$id] = SubscriptionRecord::fromV1($legacy, $customer)->envelope();
        }
        ksort($records, SORT_NUMERIC);
        if ($selection->subscriptions->mode === SelectionMode::Ids
            && array_keys($records) !== $selection->subscriptions->ids) {
            throw new SourceRecordException('selection_identity_missing', 'selection_identity_missing: explicit subscription selection did not hydrate exactly once.');
        }
        foreach ($records as $record) {
            yield $record;
        }
    }

    /** @return iterable<object> */
    private function loadedSubscriptions(SelectionClause $clause): iterable
    {
        global $wpdb;

        if (!function_exists('wcs_get_subscription') || !isset($wpdb)) {
            throw new SourceRecordException('wcs_subscription_api_unavailable', 'wcs_subscription_api_unavailable: WCS public subscription API is unavailable.');
        }
        $ids = $clause->mode === SelectionMode::Ids
            ? $clause->ids
            : $this->authoritativeIds();

        foreach ($ids as $id) {
            $subscription = wcs_get_subscription($id);
            if (!is_object($subscription)) {
                throw new SourceRecordException(
                    'subscription_hydration_failed',
                    'subscription_hydration_failed: authoritative WCS identity did not hydrate.',
                );
            }
            if ($this->included($subscription, $clause)) {
                yield $subscription;
            }
        }
    }

    /** @return list<int> */
    private function authoritativeIds(): array
    {
        global $wpdb;

        $wpdb->last_error = '';
        $query = WooStorage::isHposEnabled()
            ? "SELECT id FROM `{$wpdb->prefix}wc_orders` WHERE type = 'shop_subscription' ORDER BY id ASC"
            : "SELECT ID FROM `{$wpdb->posts}` WHERE post_type = 'shop_subscription' ORDER BY ID ASC";
        $ids = $wpdb->get_col($query);
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new SourceRecordException('subscription_hydration_failed', 'subscription_hydration_failed: authoritative subscription census failed.');
        }
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));
        if (array_filter($ids, static fn (int $id): bool => $id <= 0) !== []) {
            throw new SourceRecordException('subscription_hydration_failed', 'subscription_hydration_failed: authoritative subscription identity is invalid.');
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function included(mixed $subscription, SelectionClause $clause): bool
    {
        if (!is_object($subscription) || !is_callable([$subscription, 'get_id'])) {
            throw new SourceRecordException('subscription_hydration_failed', 'subscription_hydration_failed: WCS returned a non-object subscription.');
        }
        $id = (int) $subscription->get_id();
        if ($clause->mode === SelectionMode::Ids) {
            return in_array($id, $clause->ids, true);
        }
        if ($clause->mode !== SelectionMode::Since) {
            return true;
        }
        if (!is_callable([$subscription, 'get_date_modified'])) {
            throw new SourceRecordException('subscription_modified_date_missing', 'subscription_modified_date_missing: since selection cannot be proven.');
        }
        $modified = UtcDateTime::canonical($subscription->get_date_modified());
        if ($modified === null) {
            throw new SourceRecordException('subscription_modified_date_missing', 'subscription_modified_date_missing: since selection cannot be proven.');
        }
        return strcmp($modified, (string) $clause->since) >= 0;
    }
}
