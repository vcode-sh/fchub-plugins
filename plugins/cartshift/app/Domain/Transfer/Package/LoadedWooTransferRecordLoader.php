<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Subscription\Source\WooDatasetRecordFactory;
use CartShift\Domain\Subscription\Source\WooSubscriptionRecordSource;
use CartShift\Domain\Transfer\Customer\WooCustomerRecordSource;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Order\OrderRecordFactory;
use CartShift\Domain\Transfer\Product\HistoricalProductDecisionResolver;
use CartShift\Domain\Transfer\Product\ProductRecordFactory;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Strict one-identity-at-a-time loader used after the audit has approved the same selection. */
final class LoadedWooTransferRecordLoader
{
    /** @var \Closure(int): mixed */
    private readonly \Closure $productReader;

    /** @var \Closure(int): mixed */
    private readonly \Closure $orderReader;

    /**
     * @param callable(int): mixed $productReader
     * @param callable(int): mixed $orderReader
     */
    public function __construct(
        private readonly string $sourceKey,
        private readonly ProductRecordFactory $products,
        private readonly OrderRecordFactory $orders,
        private readonly WooCustomerRecordSource $customers,
        private readonly WooSubscriptionRecordSource $subscriptions,
        private readonly HistoricalProductDecisionResolver $historicalProducts,
        callable $productReader,
        callable $orderReader,
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $this->productReader = $productReader(...);
        $this->orderReader = $orderReader(...);
    }

    public static function fromLoadedRuntime(
        TransferSelection $selection,
        TransferDecisionSet $decisions,
        SourceRootCensus $census,
    ): self {
        $historical = new HistoricalProductDecisionResolver($selection->sourceKey, $decisions);
        $relationships = self::relationshipIndex($selection->sourceKey, $census);
        if (!function_exists('get_woocommerce_currency')) {
            throw new SourceRecordException('woocommerce_product_api_unavailable', 'WooCommerce currency API is unavailable.');
        }
        $currency = strtoupper(trim((string) get_woocommerce_currency()));
        $orders = new OrderRecordFactory(
            $currency,
            $currency,
            $selection->fingerprint(),
            relationshipResolver: static fn (int $orderId): array => $relationships[$orderId] ?? [],
            missingProductResolver: $historical->resolveLine(...),
        );

        return new self(
            $selection->sourceKey,
            ProductRecordFactory::forLoadedWoo(),
            $orders,
            new WooCustomerRecordSource(),
            new WooSubscriptionRecordSource(),
            $historical,
            static fn (int $id): mixed => function_exists('wc_get_product') ? wc_get_product($id) : null,
            static fn (int $id): mixed => function_exists('wc_get_order') ? wc_get_order($id) : null,
        );
    }

    public function load(SourceIdentity $identity): ?RecordEnvelope
    {
        if ($identity->sourceKey !== $this->sourceKey) {
            throw new SourceRecordException('dependency_source_mismatch', 'Record loader cannot cross source namespaces.');
        }

        return match ($identity->kind()) {
            RecordKind::Product => $this->product($identity),
            RecordKind::Customer => $this->customers->record($identity),
            RecordKind::Order => $this->order($identity),
            RecordKind::Subscription => $this->subscription($identity),
            default => null,
        };
    }

    private function product(SourceIdentity $identity): ?RecordEnvelope
    {
        $historical = $this->historicalProducts->record($identity);
        if ($historical instanceof RecordEnvelope) {
            return $historical;
        }
        if (preg_match('/\A[1-9][0-9]*\z/D', $identity->sourceId) !== 1) {
            return null;
        }
        $product = ($this->productReader)((int) $identity->sourceId);
        if (!is_object($product)) {
            return null;
        }
        $actualId = is_callable([$product, 'get_id']) ? (int) $product->get_id() : 0;
        $parentId = is_callable([$product, 'get_parent_id']) ? (int) $product->get_parent_id() : 0;
        if ($actualId !== (int) $identity->sourceId) {
            throw new SourceRecordException('dependency_ambiguous', 'WooCommerce product API returned another identity.');
        }
        if ($parentId > 0) {
            throw new SourceRecordException('product_root_expected', 'A selected variation cannot masquerade as a root product.');
        }

        return $this->products->fromWooProduct($product, $this->sourceKey)->envelope();
    }

    private function order(SourceIdentity $identity): ?RecordEnvelope
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $identity->sourceId) !== 1) {
            return null;
        }
        $order = ($this->orderReader)((int) $identity->sourceId);
        if (!is_object($order)) {
            return null;
        }
        if (!is_callable([$order, 'get_id']) || (int) $order->get_id() !== (int) $identity->sourceId) {
            throw new SourceRecordException('dependency_ambiguous', 'WooCommerce order API returned another identity.');
        }

        return $this->orders->fromWooOrder($order, $this->sourceKey)->envelope();
    }

    private function subscription(SourceIdentity $identity): ?RecordEnvelope
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $identity->sourceId) !== 1) {
            return null;
        }
        $selection = new TransferSelection(
            $this->sourceKey,
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::ids([(int) $identity->sourceId]),
        );
        $records = iterator_to_array($this->subscriptions->records($selection), false);
        if (count($records) !== 1) {
            return null;
        }

        return $records[0];
    }

    /** @return array<int, list<array{type:string,parent_order_id:int}>> */
    private static function relationshipIndex(string $sourceKey, SourceRootCensus $census): array
    {
        $selection = new TransferSelection(
            $sourceKey,
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::all(),
        );
        $index = [];
        $factory = new WooDatasetRecordFactory();
        foreach ($census->identities($selection) as $identity) {
            if ($identity->kind() !== RecordKind::Subscription) {
                continue;
            }
            $subscription = function_exists('wcs_get_subscription')
                ? wcs_get_subscription((int) $identity->sourceId)
                : null;
            if (!is_object($subscription)) {
                throw new SourceRecordException('subscription_hydration_failed', 'Authoritative WCS relationship source did not hydrate.');
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
}
