<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Audit\LoadedWooSourceApi;
use CartShift\Domain\Transfer\Audit\WooSourceApi;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Authoritative root-only census. Variations are deliberately embedded in their product parent. */
final class LoadedWooRootCensus implements SourceRootCensus
{
    /** @var \Closure(SelectionClause): iterable<int> */
    private readonly \Closure $productReader;

    /** @var \Closure(SelectionClause): iterable<int> */
    private readonly \Closure $customerReader;

    /**
     * @param (callable(SelectionClause): iterable<int>)|null $productReader
     * @param (callable(SelectionClause): iterable<int>)|null $customerReader
     */
    public function __construct(
        private readonly WooSourceApi $source = new LoadedWooSourceApi(),
        ?callable $productReader = null,
        ?callable $customerReader = null,
    ) {
        $this->productReader = $productReader === null ? $this->loadedProductIds(...) : $productReader(...);
        $this->customerReader = $customerReader === null ? $this->loadedCustomerIds(...) : $customerReader(...);
    }

    public function identities(TransferSelection $selection): iterable
    {
        foreach ([
            RecordKind::Product->value => $this->ids('product', $selection->products),
            RecordKind::Customer->value => $this->ids('customer', $selection->customers),
            RecordKind::Order->value => $this->ids('order', $selection->orders),
            RecordKind::Subscription->value => $this->ids('subscription', $selection->subscriptions),
        ] as $kind => $ids) {
            foreach ($ids as $id) {
                yield new SourceIdentity($selection->sourceKey, $kind, (string) $id);
            }
        }
    }

    /** @return list<int> */
    private function ids(string $kind, SelectionClause $clause): array
    {
        if ($clause->mode === SelectionMode::None) {
            return [];
        }
        if ($clause->mode === SelectionMode::Ids) {
            return $clause->ids;
        }

        $ids = match ($kind) {
            'product' => iterator_to_array(($this->productReader)($clause), false),
            'customer' => iterator_to_array(($this->customerReader)($clause), false),
            'order', 'subscription' => $this->pagedIds($kind),
            default => throw new \LogicException('Unsupported source root kind.'),
        };
        $ids = array_map('intval', $ids);
        if (array_filter($ids, static fn (int $id): bool => $id <= 0) !== []) {
            throw new SourceRecordException($kind . '_source_identity_duplicate', 'Source root census returned an invalid identity.');
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        if ($clause->mode === SelectionMode::Since) {
            $ids = array_values(array_filter(
                $ids,
                fn (int $id): bool => $this->selectedSince($kind, $id, (string) $clause->since),
            ));
        }

        return $ids;
    }

    /** @return list<int> */
    private function pagedIds(string $kind): array
    {
        $ids = [];
        $page = 1;
        do {
            $batch = $kind === 'order'
                ? $this->source->orderCensusPage($page, 100)
                : $this->source->subscriptionCensusPage($page, 100);
            array_push($ids, ...$batch);
            ++$page;
        } while ($batch !== []);

        return $ids;
    }

    private function selectedSince(string $kind, int $id, string $since): bool
    {
        $facts = match ($kind) {
            'product' => $this->source->product($id),
            'order' => $this->source->order($id),
            'subscription' => $this->source->subscription($id),
            'customer' => null,
            default => null,
        };
        if ($kind === 'customer') {
            return true; // WordPress already applied the date query in loadedCustomerIds().
        }

        return $facts === null
            || !is_string($facts['modified_gmt'] ?? null)
            || strcmp((string) $facts['modified_gmt'], $since) >= 0;
    }

    /** @return iterable<int> */
    private function loadedProductIds(SelectionClause $clause): iterable
    {
        if (!function_exists('get_posts') || !function_exists('get_post_stati')) {
            throw new SourceRecordException('woocommerce_product_api_unavailable', 'WordPress product root census is unavailable.');
        }
        yield from array_map('intval', (array) get_posts([
            'post_type' => 'product',
            'post_status' => array_values(get_post_stati()),
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'cache_results' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ]));
    }

    /** @return iterable<int> */
    private function loadedCustomerIds(SelectionClause $clause): iterable
    {
        if (!function_exists('get_users')) {
            throw new SourceRecordException('wordpress_user_api_unavailable', 'WordPress customer census is unavailable.');
        }
        $arguments = ['orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID'];
        if ($clause->mode === SelectionMode::Since) {
            $arguments['date_query'] = [['after' => $clause->since, 'inclusive' => true]];
        }
        yield from array_map('intval', (array) get_users($arguments));
    }
}
