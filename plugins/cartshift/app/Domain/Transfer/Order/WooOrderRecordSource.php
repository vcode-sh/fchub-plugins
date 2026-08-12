<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferRecordSource;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Deterministic public-CRUD order source for package export. */
final class WooOrderRecordSource implements TransferRecordSource
{
    /** @var (\Closure(SelectionClause): iterable<object>)|null */
    private readonly ?\Closure $reader;

    /** @param (callable(SelectionClause): iterable<object>)|null $reader */
    public function __construct(private readonly OrderRecordFactory $factory, ?callable $reader = null)
    {
        $this->reader = $reader === null ? null : $reader(...);
    }

    /** @return iterable<RecordEnvelope> */
    public function records(TransferSelection $selection): iterable
    {
        if ($selection->orders->mode === SelectionMode::None) {
            return;
        }
        $orders = $this->reader !== null
            ? ($this->reader)($selection->orders)
            : $this->loadedOrders($selection->orders);
        $records = [];
        foreach ($orders as $order) {
            if (!is_object($order) || !is_callable([$order, 'get_id'])) {
                throw new SourceRecordException('order_hydration_failed', 'Selected order did not hydrate through WooCommerce CRUD.');
            }
            $id = (int) $order->get_id();
            if ($id <= 0 || isset($records[$id])) {
                throw new SourceRecordException('order_census_duplicate', 'Order source returned an invalid or duplicate identity.');
            }
            $records[$id] = $this->factory->fromWooOrder($order, $selection->sourceKey)->envelope();
        }
        ksort($records, SORT_NUMERIC);
        if ($selection->orders->mode === SelectionMode::Ids
            && array_keys($records) !== $selection->orders->ids) {
            throw new SourceRecordException('selection_identity_missing', 'Explicit order selection did not hydrate exactly once.');
        }
        foreach ($records as $record) {
            yield $record;
        }
    }

    /** @return iterable<object> */
    private function loadedOrders(SelectionClause $clause): iterable
    {
        if (!function_exists('wc_get_orders')) {
            throw new SourceRecordException('order_hydration_failed', 'WooCommerce order CRUD API is unavailable.');
        }
        $base = ['status' => 'any', 'type' => 'shop_order', 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'objects'];
        if ($clause->mode === SelectionMode::Ids) {
            foreach ((array) wc_get_orders($base + ['include' => $clause->ids, 'limit' => count($clause->ids)]) as $order) {
                yield $order;
            }
            return;
        }
        $page = 1;
        do {
            $query = $base + ['limit' => 100, 'page' => $page];
            if ($clause->mode === SelectionMode::Since) {
                $query['date_modified'] = '>=' . $clause->since;
            }
            $orders = wc_get_orders($query);
            if (!is_array($orders)) {
                throw new SourceRecordException('order_hydration_failed', 'WooCommerce order CRUD returned an invalid page.');
            }
            foreach ($orders as $order) {
                yield $order;
            }
            ++$page;
        } while ($orders !== []);
    }
}
