<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferRecordSource;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

final class WooProductRecordSource implements TransferRecordSource
{
    /** @var (\Closure(SelectionClause): iterable<object>)|null */
    private readonly ?\Closure $reader;

    /** @param (callable(SelectionClause): iterable<object>)|null $reader */
    public function __construct(
        private readonly ProductRecordFactory $factory,
        ?callable $reader = null,
    ) {
        $this->reader = $reader !== null ? $reader(...) : null;
    }

    /** @return iterable<RecordEnvelope> */
    public function records(TransferSelection $selection): iterable
    {
        if ($selection->products->mode === SelectionMode::None) {
            return;
        }

        $products = $this->reader !== null
            ? ($this->reader)($selection->products)
            : $this->loadedProducts($selection->products);
        $records = [];

        foreach ($products as $product) {
            if (!is_object($product) || !is_callable([$product, 'get_id'])) {
                throw new SourceRecordException('product_hydration_failed', 'Selected product did not hydrate through WooCommerce.');
            }

            $id = (int) $product->get_id();
            $parentId = is_callable([$product, 'get_parent_id']) ? (int) $product->get_parent_id() : 0;

            if ($id <= 0 || isset($records[$id])) {
                throw new SourceRecordException('product_source_identity_duplicate', 'Product source returned an invalid or duplicate identity.');
            }
            if ($parentId > 0) {
                throw new SourceRecordException('product_root_expected', 'A selected variation cannot masquerade as a root product.');
            }

            $records[$id] = $this->factory->fromWooProduct($product, $selection->sourceKey)->envelope();
        }

        ksort($records, SORT_NUMERIC);
        if ($selection->products->mode === SelectionMode::Ids
            && array_keys($records) !== $selection->products->ids) {
            throw new SourceRecordException('selection_identity_missing', 'Explicit product selection did not hydrate exactly once.');
        }

        foreach ($records as $record) {
            yield $record;
        }
    }

    /** @return iterable<object> */
    private function loadedProducts(SelectionClause $clause): iterable
    {
        if (!function_exists('get_posts') || !function_exists('get_post_stati') || !function_exists('wc_get_product')) {
            throw new SourceRecordException('woocommerce_product_api_unavailable', 'WooCommerce product API is unavailable.');
        }
        $query = [
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
        ];
        if ($clause->mode === SelectionMode::Ids) {
            $query['post__in'] = $clause->ids;
        }
        if ($clause->mode === SelectionMode::Since) {
            $query['date_query'] = [[
                'column' => 'post_modified_gmt',
                'after' => $clause->since,
                'inclusive' => true,
            ]];
        }
        $ids = array_values(array_unique(array_map('intval', (array) get_posts($query))));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!is_object($product)) {
                throw new SourceRecordException('product_hydration_failed', 'Selected product did not hydrate through WooCommerce.');
            }
            yield $product;
        }
    }
}
