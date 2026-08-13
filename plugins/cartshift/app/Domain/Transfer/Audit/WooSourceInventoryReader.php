<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Domain\Transfer\Product\HistoricalProductPlaceholder;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final class WooSourceInventoryReader implements SourceInventoryInspector
{
    private const array KNOWN_PRODUCT_TYPES = [
        'simple',
        'variable',
        'variation',
        'grouped',
        'external',
        'subscription',
        'variable-subscription',
        'subscription_variation',
    ];

    public function __construct(
        private readonly WooSourceApi $source,
        private readonly WooStorageIntegrityReader $storageIntegrity,
        private readonly string $runtimeFingerprint,
        private readonly int $pageSize = 100,
    ) {
        if ($runtimeFingerprint === '' || $pageSize <= 0) {
            throw new \InvalidArgumentException('Runtime fingerprint and positive page size are required.');
        }
    }

    public function inspect(TransferSelection $selection): SourceInventoryReport
    {
        [$productIds, $productDuplicates] = $this->census(
            fn (int $page): array => $this->source->productCensusPage($page, $this->pageSize),
        );
        [$orderIds, $orderDuplicates] = $this->census(
            fn (int $page): array => $this->source->orderCensusPage($page, $this->pageSize),
        );
        [$subscriptionIds, $subscriptionDuplicates] = $this->census(
            fn (int $page): array => $this->source->subscriptionCensusPage($page, $this->pageSize),
        );
        $inventory = new SourceInventory($productIds, $orderIds, $subscriptionIds);
        $capabilities = $this->emptyCapabilities();
        $blockers = [];
        $counts = [
            'product_census_ids' => count($inventory->productIds),
            'products_considered' => 0,
            'products_exported' => 0,
            'products_excluded' => 0,
            'products_blocked' => 0,
            'products_blocked_hydration' => 0,
            'products_unaccounted' => 0,
            'product_duplicates' => $productDuplicates,
            'order_census_ids' => count($inventory->orderIds),
            'orders_considered' => 0,
            'orders_exported' => 0,
            'orders_excluded' => 0,
            'orders_blocked' => 0,
            'order_duplicates' => $orderDuplicates,
            'subscription_census_ids' => count($inventory->subscriptionIds),
            'subscriptions_considered' => 0,
            'subscriptions_exported' => 0,
            'subscriptions_excluded' => 0,
            'subscriptions_blocked' => 0,
            'subscription_duplicates' => $subscriptionDuplicates,
        ];

        $this->recordDuplicateBlocker($selection->sourceKey, 'product', $productDuplicates, $blockers);
        $this->recordDuplicateBlocker($selection->sourceKey, 'order', $orderDuplicates, $blockers);
        $this->recordDuplicateBlocker($selection->sourceKey, 'subscription', $subscriptionDuplicates, $blockers);

        $semanticIds = $this->sortedUnique($this->source->semanticProductIds());
        $semanticMissing = array_values(array_diff($inventory->productIds, $semanticIds));
        $semanticStale = array_values(array_diff($semanticIds, $inventory->productIds));
        $capabilities['semantic_enumeration']['missing'] = count($semanticMissing);
        $capabilities['semantic_enumeration']['stale'] = count($semanticStale);

        if ($semanticMissing !== [] || $semanticStale !== []) {
            $blockers[] = $this->blocker(
                'product_semantic_enumeration_mismatch',
                $selection->sourceKey . ':product:1:census',
                ['missing' => count($semanticMissing), 'stale' => count($semanticStale)],
            );
        }

        $lookupIds = $this->sortedUnique($this->source->lookupProductIds());
        $lookupMissing = array_values(array_diff($inventory->productIds, $lookupIds));
        $lookupStale = array_values(array_diff($lookupIds, $inventory->productIds));
        $capabilities['lookup_integrity']['missing'] = count($lookupMissing);
        $capabilities['lookup_integrity']['stale'] = count($lookupStale);

        if ($lookupMissing !== []) {
            $blockers[] = $this->blocker(
                'product_lookup_missing',
                $selection->sourceKey . ':product:1:lookup:missing',
                ['count' => count($lookupMissing)],
            );
        }

        if ($lookupStale !== []) {
            $blockers[] = $this->blocker(
                'product_lookup_stale',
                $selection->sourceKey . ':product:1:lookup:stale',
                ['count' => count($lookupStale)],
            );
        }

        $productFacts = [];
        $unsupportedProducts = [];
        $unrepresentableProducts = [];

        foreach ($inventory->productIds as $id) {
            $facts = null;
            $selected = $this->selectedWithoutFacts($selection->products, $id);

            if ($selection->products->mode === SelectionMode::Since) {
                $facts = $this->source->product($id);
                $selected = $facts === null || $this->selectedWithFacts($selection->products, $id, $facts);
            }

            if (!$selected) {
                $facts = $this->source->product($id);
                $type = is_array($facts) ? (string) ($facts['type'] ?? '') : '';
                if ($type !== '' && !in_array($type, self::KNOWN_PRODUCT_TYPES, true)) {
                    $unsupportedProducts[$id] = $type;
                }
                ++$counts['products_excluded'];
                continue;
            }

            ++$counts['products_considered'];
            $facts ??= $this->source->product($id);

            if ($facts === null) {
                $unrepresentableProducts[$id] = true;
                ++$counts['products_blocked'];
                ++$counts['products_blocked_hydration'];
                $blockers[] = $this->blocker(
                    'product_hydration_failed',
                    sprintf('%s:product:%d', $selection->sourceKey, $id),
                    ['source_id' => $id],
                );
                continue;
            }

            $productFacts[$id] = $facts;
            $type = (string) ($facts['type'] ?? '');
            $this->bump($capabilities['product_types'], $type === '' ? 'unknown' : $type);
            $this->recordProductCapabilities($facts, $capabilities);
            $productBlocked = false;

            if ((int) ($facts['sku_length'] ?? 0) > 30) {
                $productBlocked = true;
                $unrepresentableProducts[$id] = true;
                ++$capabilities['target_schema']['sku_over_limit'];
                $blockers[] = $this->blocker(
                    'target_schema_unrepresentable',
                    sprintf('%s:product:%d', $selection->sourceKey, $id),
                    [
                        'field' => 'sku',
                        'source_sku_fingerprint' => (string) ($facts['sku_fingerprint'] ?? ''),
                        'sku_length' => (int) $facts['sku_length'],
                        'target_limit' => 30,
                    ],
                );
            }

            if (!in_array($type, self::KNOWN_PRODUCT_TYPES, true)) {
                $productBlocked = true;
                $unsupportedProducts[$id] = $type === '' ? 'unknown' : $type;
                $blockers[] = $this->blocker(
                    'unsupported_product_type',
                    sprintf('%s:product:%d', $selection->sourceKey, $id),
                    ['source_id' => $id, 'type' => $type === '' ? 'unknown' : $type],
                );
            }

            $upsells = (int) ($facts['upsell_count'] ?? 0);
            $crossSells = (int) ($facts['cross_sell_count'] ?? 0);
            if ($upsells > 0 || $crossSells > 0) {
                $productBlocked = true;
                $blockers[] = $this->blocker(
                    'product_relation_loss_decision_required',
                    sprintf('%s:product:%d', $selection->sourceKey, $id),
                    ['cross_sell_count' => $crossSells, 'relation_policy' => 'preserve_provenance', 'upsell_count' => $upsells],
                );
            }
            if (($facts['password_protected'] ?? false) === true) {
                $productBlocked = true;
                $blockers[] = $this->blocker(
                    'product_password_protection_unsupported',
                    sprintf('%s:product:%d', $selection->sourceKey, $id),
                    ['password_protection_policy' => 'excluded_by_policy'],
                );
            }

            if ($productBlocked) {
                ++$counts['products_blocked'];
            } else {
                ++$counts['products_exported'];
            }
        }

        $this->recordMissingExplicitIds('product', $selection->products, $inventory->productIds, $selection->sourceKey, $blockers);

        foreach ($inventory->orderIds as $id) {
            $facts = null;
            $selected = $this->selectedWithoutFacts($selection->orders, $id);

            if ($selection->orders->mode === SelectionMode::Since) {
                $facts = $this->source->order($id);
                $selected = $facts === null || $this->selectedWithFacts($selection->orders, $id, $facts);
            }

            if (!$selected) {
                ++$counts['orders_excluded'];
                continue;
            }

            ++$counts['orders_considered'];
            $facts ??= $this->source->order($id);

            if ($facts === null) {
                ++$counts['orders_blocked'];
                $blockers[] = $this->blocker(
                    'order_hydration_failed',
                    sprintf('%s:order:%d', $selection->sourceKey, $id),
                    ['source_id' => $id],
                );
                continue;
            }

            $this->recordOrderCapabilities($facts, $capabilities);
            $orderBlocked = false;
            $dependentUnsupportedTypes = [];
            $dependentUnrepresentableProducts = [];
            $hasDownloadableProduct = false;

            $noteCount = (int) ($facts['note_count'] ?? 0);
            if ($noteCount > 0) {
                $orderBlocked = true;
                $blockers[] = $this->blocker(
                    'order_note_visibility_decision_required',
                    sprintf('%s:order:%d', $selection->sourceKey, $id),
                    [
                        'customer_visible_note_count' => (int) ($facts['customer_visible_note_count'] ?? 0),
                        'note_count' => $noteCount,
                        'note_policy' => 'preserve_history_select_canonical',
                    ],
                );
            }

            foreach ((array) ($facts['product_ids'] ?? []) as $productId) {
                $productId = (int) $productId;

                if ((array) ($productFacts[$productId]['downloads'] ?? []) !== []) {
                    $hasDownloadableProduct = true;
                }

                if (isset($unsupportedProducts[$productId])) {
                    $dependentUnsupportedTypes[$unsupportedProducts[$productId]] = true;
                }
                if (isset($unrepresentableProducts[$productId])) {
                    $dependentUnrepresentableProducts[$productId] = true;
                }

                if (!in_array($productId, $inventory->productIds, true)) {
                    $orderBlocked = true;
                    $blockers[] = $this->blocker(
                        'historical_product_missing',
                        sprintf('%s:order:%d:product:%d', $selection->sourceKey, $id, $productId),
                        ['source_id' => $id, 'product_id' => $productId],
                    );
                }
            }

            if ($hasDownloadableProduct) {
                ++$capabilities['order_shapes']['downloadable_product_orders'];
            }

            foreach ((array) ($facts['missing_product_refs'] ?? []) as $missingProductRef) {
                if (!is_array($missingProductRef)) {
                    continue;
                }
                $lineId = (int) ($missingProductRef['line_id'] ?? 0);
                $productId = (int) ($missingProductRef['product_id'] ?? 0);
                if ($lineId <= 0 || $productId <= 0) {
                    continue;
                }
                $lineShape = $missingProductRef['line_shape'] ?? null;
                $placeholder = new SourceIdentity(
                    $selection->sourceKey,
                    RecordKind::Product->value,
                    (string) $productId,
                );
                $placeholderFingerprint = is_array($lineShape)
                    ? HistoricalProductPlaceholder::approvalFingerprint($placeholder, $lineShape)
                    : null;
                $orderBlocked = true;
                $blockers[] = $this->blocker(
                    'historical_product_missing',
                    sprintf('%s:order:%d:item:%d', $selection->sourceKey, $id, $lineId),
                    [
                        'source_id' => $id,
                        'line_id' => $lineId,
                        'placeholder_fingerprint' => $placeholderFingerprint,
                        'placeholder_identity' => $placeholder->canonical(),
                        'placeholder_ready' => $placeholderFingerprint !== null,
                        'product_id' => $productId,
                    ],
                );
            }

            foreach (array_keys($dependentUnsupportedTypes) as $type) {
                $this->bump($capabilities['unsupported_type_orders'], $type);
                $orderBlocked = true;
            }

            if ($dependentUnsupportedTypes !== []) {
                $types = array_keys($dependentUnsupportedTypes);
                sort($types, SORT_STRING);
                $blockers[] = $this->blocker(
                    'unsupported_product_dependency',
                    sprintf('%s:order:%d', $selection->sourceKey, $id),
                    [
                        'source_id' => $id,
                        'product_type_count' => count($types),
                        'product_types' => implode(',', $types),
                    ],
                );
            }

            if ($dependentUnrepresentableProducts !== []) {
                $orderBlocked = true;
                $blockers[] = $this->blocker(
                    'unrepresentable_product_dependency',
                    sprintf('%s:order:%d', $selection->sourceKey, $id),
                    ['product_count' => count($dependentUnrepresentableProducts)],
                );
            }

            if ($orderBlocked) {
                ++$counts['orders_blocked'];
            } else {
                ++$counts['orders_exported'];
            }
        }

        $this->recordMissingExplicitIds('order', $selection->orders, $inventory->orderIds, $selection->sourceKey, $blockers);

        foreach ($inventory->subscriptionIds as $id) {
            $facts = null;
            $selected = $this->selectedWithoutFacts($selection->subscriptions, $id);

            if ($selection->subscriptions->mode === SelectionMode::Since) {
                $facts = $this->source->subscription($id);
                $selected = $facts === null || $this->selectedWithFacts($selection->subscriptions, $id, $facts);
            }

            if (!$selected) {
                ++$counts['subscriptions_excluded'];
                continue;
            }

            ++$counts['subscriptions_considered'];
            $facts ??= $this->source->subscription($id);

            if ($facts === null) {
                ++$counts['subscriptions_blocked'];
                $blockers[] = $this->blocker(
                    'subscription_hydration_failed',
                    sprintf('%s:subscription:%d', $selection->sourceKey, $id),
                    ['source_id' => $id],
                );
                continue;
            }

            foreach (['parent', 'renewal', 'switch', 'resubscribe'] as $relationship) {
                $capabilities['subscription_relationships'][$relationship] += count(
                    (array) ($facts['related_orders'][$relationship] ?? []),
                );
            }

            $status = (string) ($facts['status'] ?? '');
            if ($status !== '') {
                $this->bump($capabilities['subscription_statuses'], $status);
                foreach (['next_payment', 'end'] as $schedule) {
                    $present = ($facts['has_' . $schedule] ?? null) === true;
                    ++$capabilities['subscription_schedules'][$schedule . '_' . ($present ? 'present' : 'absent')];
                    if ($status === 'active' && !$present) {
                        ++$capabilities['subscription_schedules']['active_missing_' . $schedule];
                    }
                }
                if ($status === 'active'
                    && (($facts['has_next_payment'] ?? null) === false || ($facts['has_end'] ?? null) === false)) {
                    $blockers[] = $this->blocker(
                        'subscription_schedule_absence',
                        sprintf('%s:subscription:%d', $selection->sourceKey, $id),
                        [
                            'end_absent' => ($facts['has_end'] ?? null) === false,
                            'next_payment_absent' => ($facts['has_next_payment'] ?? null) === false,
                            'policy' => 'preserve_absence',
                        ],
                    );
                }
            }

            if (!in_array(strtolower($status), ['cancelled', 'canceled', 'expired', 'switched'], true)
                && ($facts['requires_manual_renewal'] ?? null) === false) {
                $blockers[] = $this->blocker(
                    'subscription_payment_ownership_unassessed',
                    sprintf('%s:subscription:%d', $selection->sourceKey, $id),
                    [
                        'next_action_owner' => 'target_manual',
                        'source_auto_renewal_release_required' => true,
                        'source_gateway' => (string) ($facts['source_gateway'] ?? ''),
                        'target_collection_method' => 'manual',
                    ],
                );
            }

            ++$counts['subscriptions_exported'];
        }

        $this->recordMissingExplicitIds(
            'subscription',
            $selection->subscriptions,
            $inventory->subscriptionIds,
            $selection->sourceKey,
            $blockers,
        );

        foreach ($this->storageIntegrity->inspect($selection->sourceKey) as $finding) {
            $blockers[] = $finding;
            $key = $finding['code'] === 'order_item_parent_missing' ? 'orphan_items' : 'parent_type_mismatches';
            ++$capabilities['storage_integrity'][$key];
        }

        $counts['products_unaccounted'] = max(
            0,
            $counts['product_census_ids']
                - $counts['products_exported']
                - $counts['products_excluded']
                - $counts['products_blocked'],
        );

        if ($counts['products_unaccounted'] > 0) {
            $blockers[] = $this->blocker(
                'source_census_unaccounted',
                $selection->sourceKey . ':product:1:census',
                ['count' => $counts['products_unaccounted']],
            );
        }

        usort(
            $blockers,
            static fn (array $left, array $right): int => [$left['code'], $left['identity']]
                <=> [$right['code'], $right['identity']],
        );

        return SourceInventoryReport::create(
            $selection->sourceKey,
            $selection->fingerprint(),
            $this->runtimeFingerprint,
            $counts,
            $capabilities,
            $blockers,
        );
    }

    /** @return array<string, array<string, int>> */
    private function emptyCapabilities(): array
    {
        return [
            'product_types' => [],
            'product_statuses' => [],
            'attribute_contracts' => ['global' => 0, 'custom' => 0, 'wildcard' => 0],
            'price_contracts' => ['explicit_zero_regular' => 0, 'explicit_zero_sale' => 0, 'scheduled_sale' => 0],
            'tax_classes' => ['standard' => 0],
            'stock_contracts' => [
                'self_owned' => 0,
                'parent_owned' => 0,
                'backorders_no' => 0,
                'backorders_notify' => 0,
                'backorders_yes' => 0,
            ],
            'download_contracts' => ['local' => 0, 'remote' => 0, 'missing' => 0],
            'media_contracts' => ['featured' => 0, 'gallery' => 0, 'variation' => 0],
            'catalogue_contracts' => [
                'visibility_visible' => 0,
                'visibility_catalog' => 0,
                'visibility_search' => 0,
                'visibility_hidden' => 0,
                'featured' => 0,
                'menu_order_nonzero' => 0,
                'purchase_note' => 0,
                'reviews_enabled' => 0,
                'review_count' => 0,
                'rating' => 0,
                'sales_count' => 0,
                'global_unique_id' => 0,
                'extension_metadata' => 0,
            ],
            'relationship_contracts' => ['upsells' => 0, 'cross_sells' => 0, 'grouped_children' => 0, 'external_fields' => 0],
            'order_statuses' => [],
            'order_shapes' => [
                'fee' => 0,
                'coupon' => 0,
                'shipping' => 0,
                'multi_tax' => 0,
                'partial_refund' => 0,
                'full_refund' => 0,
                'refund_records' => 0,
                'downloadable_product_orders' => 0,
                'orders_with_notes' => 0,
            ],
            'subscription_relationships' => ['parent' => 0, 'renewal' => 0, 'switch' => 0, 'resubscribe' => 0],
            'subscription_statuses' => [],
            'subscription_schedules' => [
                'next_payment_present' => 0,
                'next_payment_absent' => 0,
                'end_present' => 0,
                'end_absent' => 0,
                'active_missing_next_payment' => 0,
                'active_missing_end' => 0,
            ],
            'lookup_integrity' => ['missing' => 0, 'stale' => 0],
            'semantic_enumeration' => ['missing' => 0, 'stale' => 0],
            'storage_integrity' => ['orphan_items' => 0, 'parent_type_mismatches' => 0],
            'unsupported_type_orders' => [],
            'target_schema' => ['sku_over_limit' => 0],
        ];
    }

    /** @param array<string, mixed> $facts @param array<string, array<string, int>> $capabilities */
    private function recordProductCapabilities(array $facts, array &$capabilities): void
    {
        $this->bump($capabilities['product_statuses'], (string) ($facts['status'] ?? 'unknown'));

        foreach ((array) ($facts['attribute_contracts'] ?? []) as $contract) {
            $this->bump($capabilities['attribute_contracts'], (string) $contract);
        }

        if (($facts['regular_price'] ?? null) === '0') {
            ++$capabilities['price_contracts']['explicit_zero_regular'];
        }

        if (($facts['sale_price'] ?? null) === '0') {
            ++$capabilities['price_contracts']['explicit_zero_sale'];
        }

        if (($facts['sale_scheduled'] ?? false) === true) {
            ++$capabilities['price_contracts']['scheduled_sale'];
        }

        $this->bump($capabilities['tax_classes'], (string) ($facts['tax_class'] ?? 'standard'));
        $stockOwner = ($facts['stock_owner'] ?? 'self') === 'parent' ? 'parent_owned' : 'self_owned';
        ++$capabilities['stock_contracts'][$stockOwner];
        $backorders = (string) ($facts['backorders'] ?? 'no');
        $this->bump($capabilities['stock_contracts'], 'backorders_' . $backorders);

        foreach ((array) ($facts['downloads'] ?? []) as $contract) {
            $this->bump($capabilities['download_contracts'], (string) $contract);
        }

        foreach ((array) ($facts['media'] ?? []) as $contract) {
            $this->bump($capabilities['media_contracts'], (string) $contract);
        }

        $this->bump(
            $capabilities['catalogue_contracts'],
            'visibility_' . (string) ($facts['catalogue_visibility'] ?? 'unknown'),
        );

        foreach (
            [
                'featured' => 'featured',
                'purchase_note' => 'purchase_note',
                'reviews_enabled' => 'reviews_enabled',
                'has_rating' => 'rating',
                'has_global_unique_id' => 'global_unique_id',
                'has_external_fields' => 'external_fields',
            ] as $fact => $capability
        ) {
            if (($facts[$fact] ?? false) === true) {
                $target = $capability === 'external_fields' ? 'relationship_contracts' : 'catalogue_contracts';
                ++$capabilities[$target][$capability];
            }
        }

        if ((int) ($facts['menu_order'] ?? 0) !== 0) {
            ++$capabilities['catalogue_contracts']['menu_order_nonzero'];
        }

        $capabilities['catalogue_contracts']['review_count'] += (int) ($facts['review_count'] ?? 0);
        $capabilities['catalogue_contracts']['sales_count'] += (int) ($facts['sales_count'] ?? 0);
        $capabilities['catalogue_contracts']['extension_metadata'] += (int) ($facts['extension_metadata_count'] ?? 0);
        $capabilities['relationship_contracts']['upsells'] += (int) ($facts['upsell_count'] ?? 0);
        $capabilities['relationship_contracts']['cross_sells'] += (int) ($facts['cross_sell_count'] ?? 0);
        $capabilities['relationship_contracts']['grouped_children'] += (int) ($facts['grouped_child_count'] ?? 0);
    }

    /** @param array<string, mixed> $facts @param array<string, array<string, int>> $capabilities */
    private function recordOrderCapabilities(array $facts, array &$capabilities): void
    {
        $this->bump($capabilities['order_statuses'], (string) ($facts['status'] ?? 'unknown'));

        foreach (['fee' => 'has_fee', 'coupon' => 'has_coupon', 'shipping' => 'has_shipping'] as $shape => $fact) {
            if (($facts[$fact] ?? false) === true) {
                ++$capabilities['order_shapes'][$shape];
            }
        }

        if ((int) ($facts['tax_rate_count'] ?? 0) > 1) {
            ++$capabilities['order_shapes']['multi_tax'];
        }

        $refundState = (string) ($facts['refund_state'] ?? 'none');

        if ($refundState === 'partial' || $refundState === 'full') {
            ++$capabilities['order_shapes'][$refundState . '_refund'];
        }

        $capabilities['order_shapes']['refund_records'] += (int) ($facts['refund_count'] ?? 0);
        if ((int) ($facts['note_count'] ?? 0) > 0) {
            ++$capabilities['order_shapes']['orders_with_notes'];
        }
    }

    /** @return array{0: list<int>, 1: int} */
    private function census(callable $pageReader): array
    {
        $all = [];

        for ($page = 1; ; ++$page) {
            if ($page > 1000000) {
                throw new \RuntimeException('Source census exceeded the page safety limit.');
            }

            $batch = $pageReader($page);

            if ($batch === []) {
                break;
            }

            foreach ($batch as $id) {
                if (!is_int($id) || $id <= 0) {
                    throw new \UnexpectedValueException('Source census returned a non-positive integer ID.');
                }

                $all[] = $id;
            }
        }

        $unique = $this->sortedUnique($all);

        return [$unique, count($all) - count($unique)];
    }

    /** @param list<int> $ids @return list<int> */
    private function sortedUnique(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function selectedWithoutFacts(SelectionClause $clause, int $id): bool
    {
        return match ($clause->mode) {
            SelectionMode::None => false,
            SelectionMode::All, SelectionMode::Since => true,
            SelectionMode::Ids => in_array($id, $clause->ids, true),
        };
    }

    /** @param array<string, mixed> $facts */
    private function selectedWithFacts(SelectionClause $clause, int $id, array $facts): bool
    {
        if ($clause->mode !== SelectionMode::Since) {
            return $this->selectedWithoutFacts($clause, $id);
        }

        $modified = $facts['modified_gmt'] ?? null;

        return is_string($modified) && $modified >= (string) $clause->since;
    }

    /** @param list<int> $censusIds @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers */
    private function recordMissingExplicitIds(
        string $kind,
        SelectionClause $clause,
        array $censusIds,
        string $sourceKey,
        array &$blockers,
    ): void {
        if ($clause->mode !== SelectionMode::Ids) {
            return;
        }

        foreach (array_diff($clause->ids, $censusIds) as $id) {
            $blockers[] = $this->blocker(
                'selection_identity_missing',
                sprintf('%s:%s:%d', $sourceKey, $kind, $id),
                ['source_id' => $id],
            );
        }
    }

    /** @param list<array{code: string, identity: string, context: array<string, scalar|null>}> $blockers */
    private function recordDuplicateBlocker(string $sourceKey, string $kind, int $duplicates, array &$blockers): void
    {
        if ($duplicates === 0) {
            return;
        }

        $blockers[] = $this->blocker(
            $kind . '_census_duplicate',
            sprintf('%s:%s:1:census', $sourceKey, $kind),
            ['count' => $duplicates],
        );
    }

    /** @param array<string, int> $group */
    private function bump(array &$group, string $key): void
    {
        $group[$key] = ($group[$key] ?? 0) + 1;
    }

    /** @param array<string, scalar|null> $context @return array{code: string, identity: string, context: array<string, scalar|null>} */
    private function blocker(string $code, string $identity, array $context): array
    {
        ksort($context);

        return ['code' => $code, 'identity' => $identity, 'context' => $context];
    }
}
