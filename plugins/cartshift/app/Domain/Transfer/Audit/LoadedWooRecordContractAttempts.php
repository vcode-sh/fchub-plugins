<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Domain\Subscription\Source\WooDatasetRecordFactory;
use CartShift\Domain\Subscription\Source\WooSubscriptionRecordSource;
use CartShift\Domain\Transfer\Order\OrderRecordFactory;
use CartShift\Domain\Transfer\Package\LoadedWooRootCensus;
use CartShift\Domain\Transfer\Package\SourceRootCensus;
use CartShift\Domain\Transfer\Product\HistoricalProductPlaceholder;
use CartShift\Domain\Transfer\Product\ProductRecordFactory;
use CartShift\Domain\Transfer\Customer\WooCustomerRecordSource;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Read-only loaded-runtime attempts for every immutable export record contract. */
final class LoadedWooRecordContractAttempts
{
    private readonly SourceRootCensus $census;

    public function __construct(
        private readonly WooSourceApi $source = new LoadedWooSourceApi(),
        ?SourceRootCensus $census = null,
    ) {
        $this->census = $census ?? new LoadedWooRootCensus($source);
    }

    /** @return iterable<array{identity: SourceIdentity, assert: callable(): void}> */
    public function attempts(TransferSelection $selection): iterable
    {
        if ($selection->products->mode !== SelectionMode::None) {
            $productFactory = ProductRecordFactory::forLoadedWoo();
            foreach ($this->rootIds(RecordKind::Product, $selection) as $id) {
                $identity = new SourceIdentity($selection->sourceKey, RecordKind::Product->value, (string) $id);
                yield [
                    'identity' => $identity,
                    'assert' => static function () use ($productFactory, $selection, $id): void {
                        if (!function_exists('wc_get_product')) {
                            throw new SourceRecordException('woocommerce_product_api_unavailable', 'WooCommerce product API is unavailable.');
                        }
                        $product = wc_get_product($id);
                        if (!is_object($product)) {
                            throw new SourceRecordException('product_hydration_failed', 'Selected product did not hydrate through WooCommerce.');
                        }
                        $productFactory->fromWooProduct($product, $selection->sourceKey)->envelope();
                    },
                ];
            }
        }

        if ($selection->orders->mode !== SelectionMode::None) {
            $relationshipIndex = $this->relationshipIndex();
            $orderFactory = new OrderRecordFactory(
                $this->storeCurrency(),
                $this->storeCurrency(),
                $selection->fingerprint(),
                relationshipResolver: static fn (int $orderId): array => $relationshipIndex[$orderId] ?? [],
                missingProductResolver: static function (object $order, object $item) use ($selection): array {
                    $lineId = is_callable([$item, 'get_id']) ? (int) $item->get_id() : 0;
                    $productId = $lineId > 0 && function_exists('wc_get_order_item_meta')
                        ? (int) wc_get_order_item_meta($lineId, '_product_id', true)
                        : 0;
                    if ($productId <= 0) {
                        throw new SourceRecordException('historical_product_missing', 'Historical order line has no immutable product identity.');
                    }
                    return [
                        'identity' => new SourceIdentity($selection->sourceKey, RecordKind::Product->value, (string) $productId),
                        'fulfilment_type' => HistoricalProductPlaceholder::FULFILMENT_TYPE,
                    ];
                },
            );
            foreach ($this->rootIds(RecordKind::Order, $selection) as $id) {
                $identity = new SourceIdentity($selection->sourceKey, RecordKind::Order->value, (string) $id);
                yield [
                    'identity' => $identity,
                    'assert' => static function () use ($orderFactory, $selection, $id): void {
                        if (!function_exists('wc_get_order')) {
                            throw new SourceRecordException('order_hydration_failed', 'WooCommerce order API is unavailable.');
                        }
                        $order = wc_get_order($id);
                        if (!is_object($order)) {
                            throw new SourceRecordException('order_hydration_failed', 'Selected order did not hydrate through WooCommerce CRUD.');
                        }
                        $orderFactory->fromWooOrder($order, $selection->sourceKey)->envelope();
                    },
                ];
            }
        }

        if ($selection->customers->mode !== SelectionMode::None) {
            $customerSource = new WooCustomerRecordSource();
            foreach ($this->rootIds(RecordKind::Customer, $selection) as $id) {
                $identity = new SourceIdentity($selection->sourceKey, RecordKind::Customer->value, (string) $id);
                yield [
                    'identity' => $identity,
                    'assert' => static function () use ($customerSource, $identity): void {
                        try {
                            $customerSource->record($identity);
                        } catch (SourceRecordException $exception) {
                            throw $exception;
                        } catch (\Throwable $exception) {
                            throw new SourceRecordException('customer_hydration_failed', 'Selected customer cannot produce an immutable record.');
                        }
                    },
                ];
            }
        }

        if ($selection->subscriptions->mode !== SelectionMode::None) {
            $subscriptionSource = new WooSubscriptionRecordSource();
            foreach ($this->rootIds(RecordKind::Subscription, $selection) as $id) {
                $identity = new SourceIdentity($selection->sourceKey, RecordKind::Subscription->value, (string) $id);
                yield [
                    'identity' => $identity,
                    'assert' => static function () use ($subscriptionSource, $selection, $id): void {
                        $single = new TransferSelection(
                            $selection->sourceKey,
                            SelectionClause::none(),
                            SelectionClause::none(),
                            SelectionClause::none(),
                            SelectionClause::ids([$id]),
                        );
                        $records = iterator_to_array($subscriptionSource->records($single), false);
                        if (count($records) !== 1) {
                            throw new SourceRecordException('subscription_hydration_failed', 'Selected subscription did not produce exactly one immutable record.');
                        }
                    },
                ];
            }
        }
    }

    /** @return list<int> */
    private function rootIds(RecordKind $kind, TransferSelection $selection): array
    {
        $ids = [];
        foreach ($this->census->identities($selection) as $identity) {
            if ($identity->kind() === $kind) {
                $ids[] = (int) $identity->sourceId;
            }
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return array<int, list<array{type: string, parent_order_id: int}>> */
    private function relationshipIndex(): array
    {
        $index = [];
        $factory = new WooDatasetRecordFactory();
        $allSubscriptions = new TransferSelection(
            'record-contract-index',
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::all(),
        );
        foreach ($this->rootIds(RecordKind::Subscription, $allSubscriptions) as $subscriptionId) {
            $subscription = function_exists('wcs_get_subscription') ? wcs_get_subscription($subscriptionId) : null;
            if (!is_object($subscription)) {
                continue;
            }
            $typed = $factory->relatedOrdersByType($subscription);
            $parents = (array) ($typed['parent'] ?? []);
            $parentId = count($parents) === 1 ? (int) $parents[0] : 0;
            foreach (['parent', 'renewal', 'switch', 'resubscribe'] as $type) {
                foreach ((array) ($typed[$type] ?? []) as $orderId) {
                    $index[(int) $orderId][] = [
                        'type' => $type,
                        'parent_order_id' => $type === 'parent' ? 0 : $parentId,
                    ];
                }
            }
        }
        ksort($index, SORT_NUMERIC);

        return $index;
    }

    private function storeCurrency(): string
    {
        if (!function_exists('get_woocommerce_currency')) {
            throw new SourceRecordException('woocommerce_product_api_unavailable', 'WooCommerce currency API is unavailable.');
        }
        return (string) get_woocommerce_currency();
    }
}
