<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer;

defined('ABSPATH') || exit;

final readonly class TransferSelection
{
    /** @var list<string> */
    public array $reverseDependencies;

    /**
     * @param list<string> $reverseDependencies
     */
    public function __construct(
        public string $sourceKey,
        public SelectionClause $products,
        public SelectionClause $customers,
        public SelectionClause $orders,
        public SelectionClause $subscriptions,
        array $reverseDependencies = [],
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);

        if (!array_is_list($reverseDependencies)) {
            throw new \InvalidArgumentException('Reverse dependencies must be a list.');
        }

        foreach ($reverseDependencies as $kind) {
            if (!is_string($kind) || !in_array($kind, [RecordKind::Order->value, RecordKind::Subscription->value], true)) {
                throw new \InvalidArgumentException('Reverse dependencies may contain only order or subscription.');
            }
        }

        if (count($reverseDependencies) !== count(array_unique($reverseDependencies))) {
            throw new \InvalidArgumentException('Reverse dependency kinds must be unique.');
        }

        sort($reverseDependencies);
        $this->reverseDependencies = $reverseDependencies;
    }

    /** @return array<string, mixed> */
    public function canonical(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'products' => $this->products->canonical(),
            'customers' => $this->customers->canonical(),
            'orders' => $this->orders->canonical(),
            'subscriptions' => $this->subscriptions->canonical(),
            'include_reverse_dependencies' => $this->reverseDependencies,
        ];
    }

    public function fingerprint(): string
    {
        return SelectionFingerprint::fromCanonical($this->canonical());
    }
}
