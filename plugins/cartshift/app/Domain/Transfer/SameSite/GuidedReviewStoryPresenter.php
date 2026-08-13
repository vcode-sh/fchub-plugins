<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

/** Keeps source and target review stories small, human-readable and free of internal evidence. */
final readonly class GuidedReviewStoryPresenter
{
    public function fallbackTitle(SourceIdentity $identity): string
    {
        $kind = match ($identity->entityType) {
            'product' => 'Product',
            'order' => 'Order',
            'subscription' => 'Subscription',
            'customer' => 'Customer',
            default => throw new \RuntimeException('guided_decision_review_unsupported'),
        };

        if ($identity->entityType === 'order'
            && preg_match('/\A([^:]+):item:([^:]+)\z/D', $identity->sourceId, $parts) === 1) {
            return sprintf('Order %s, item %s', $parts[1], $parts[2]);
        }
        if ($identity->entityType === 'product'
            && preg_match('/\A([^:]+):/D', $identity->sourceId, $parts) === 1) {
            return 'Product ' . $parts[1];
        }

        return $kind . ' ' . $identity->sourceId;
    }

    /** @param array<string,mixed> $proposal @return ?array<string,mixed> */
    public function source(array $proposal, SourceIdentity $identity): ?array
    {
        $context = $proposal['review_context'] ?? null;
        if (!is_array($context) || array_is_list($context)) {
            return null;
        }
        $candidate = $context[$identity->canonical()] ?? null;
        if (!is_array($candidate)) {
            $root = $this->rootIdentity($identity);
            $candidate = $root === null ? null : ($context[$root->canonical()] ?? null);
        }
        if (!is_array($candidate) || !is_string($candidate['kind'] ?? null)) {
            return null;
        }

        return match ($candidate['kind']) {
            'product' => $this->product($candidate),
            'customer' => $this->customer($candidate),
            'order' => $this->order($candidate),
            'subscription' => $this->subscription($candidate),
            default => null,
        };
    }

    /** @return ?array<string,mixed> */
    public function target(mixed $story, string $kind): ?array
    {
        if (!is_array($story) || !is_string($story['kind'] ?? null) || $story['kind'] !== $kind) {
            return null;
        }

        if ($kind === 'order') {
            $items = $this->items($story);
            return [
                'kind' => 'order',
                'customer_name' => trim((string) ($story['customer_name'] ?? '')),
                'created_utc' => trim((string) ($story['created_utc'] ?? '')),
                'status' => trim((string) ($story['status'] ?? '')),
                'currency' => trim((string) ($story['currency'] ?? '')),
                'gross_total' => (int) ($story['gross_total'] ?? 0),
                'items' => array_map(static fn (mixed $item): array => is_array($item) ? [
                    'name' => trim((string) ($item['name'] ?? '')),
                    'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
                ] : ['name' => '', 'quantity' => 0], $items),
                'item_count' => max(0, (int) ($story['item_count'] ?? count($items))),
            ];
        }

        if ($kind === 'subscription') {
            return [
                'kind' => 'subscription',
                'status' => trim((string) ($story['status'] ?? '')),
                'recurring_total' => (int) ($story['recurring_total'] ?? 0),
                'next_payment_utc' => is_string($story['next_payment_utc'] ?? null)
                    ? $story['next_payment_utc']
                    : null,
                'item_name' => trim((string) ($story['item_name'] ?? '')),
                'quantity' => max(0, (int) ($story['quantity'] ?? 0)),
            ];
        }

        return null;
    }

    /** @param ?array<string,mixed> $story */
    public function title(?array $story): ?string
    {
        if ($story === null) {
            return null;
        }
        foreach (['name', 'customer_name', 'customer_email'] as $key) {
            if (is_string($story[$key] ?? null) && trim($story[$key]) !== '') {
                return trim($story[$key]);
            }
        }
        return null;
    }

    private function rootIdentity(SourceIdentity $identity): ?SourceIdentity
    {
        if ($identity->entityType === 'order'
            && preg_match('/\A([^:]+):item:/D', $identity->sourceId, $parts) === 1) {
            return new SourceIdentity($identity->sourceKey, 'order', $parts[1]);
        }
        if ($identity->entityType === 'product'
            && preg_match('/\A([^:]+):/D', $identity->sourceId, $parts) === 1) {
            return new SourceIdentity($identity->sourceKey, 'product', $parts[1]);
        }
        return null;
    }

    /** @param array<string,mixed> $story @return array<string,mixed> */
    private function product(array $story): array
    {
        return [
            'kind' => 'product',
            'name' => trim((string) ($story['name'] ?? '')),
            'sku' => trim((string) ($story['sku'] ?? '')),
            'status' => trim((string) ($story['status'] ?? '')),
            'product_type' => trim((string) ($story['product_type'] ?? '')),
            'dependent_orders' => max(0, (int) ($story['dependent_orders'] ?? 0)),
            'dependent_subscriptions' => max(0, (int) ($story['dependent_subscriptions'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $story @return array<string,mixed> */
    private function customer(array $story): array
    {
        $purchases = is_array($story['purchases'] ?? null) && array_is_list($story['purchases'])
            ? array_values(array_filter($story['purchases'], 'is_string'))
            : [];
        return [
            'kind' => 'customer',
            'name' => trim((string) ($story['name'] ?? '')),
            'email' => trim((string) ($story['email'] ?? '')),
            'classification' => trim((string) ($story['classification'] ?? '')),
            'dependent_orders' => max(0, (int) ($story['dependent_orders'] ?? 0)),
            'dependent_subscriptions' => max(0, (int) ($story['dependent_subscriptions'] ?? 0)),
            'purchases' => array_slice($purchases, 0, 3),
        ];
    }

    /** @param array<string,mixed> $story @return array<string,mixed> */
    private function order(array $story): array
    {
        $items = $this->items($story);
        return [
            'kind' => 'order',
            'customer_name' => trim((string) ($story['customer_name'] ?? '')),
            'customer_email' => trim((string) ($story['customer_email'] ?? '')),
            'created_utc' => trim((string) ($story['created_utc'] ?? '')),
            'status' => trim((string) ($story['status'] ?? '')),
            'currency' => trim((string) ($story['currency'] ?? '')),
            'gross_total' => (int) ($story['gross_total'] ?? 0),
            'items' => array_map(static fn (mixed $item): array => is_array($item) ? [
                'name' => trim((string) ($item['name'] ?? '')),
                'sku' => trim((string) ($item['sku'] ?? '')),
                'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
            ] : ['name' => '', 'sku' => '', 'quantity' => 0], $items),
            'item_count' => max(0, (int) ($story['item_count'] ?? count($items))),
        ];
    }

    /** @param array<string,mixed> $story @return array<string,mixed> */
    private function subscription(array $story): array
    {
        $items = $this->items($story);
        return [
            'kind' => 'subscription',
            'customer_name' => trim((string) ($story['customer_name'] ?? '')),
            'customer_email' => trim((string) ($story['customer_email'] ?? '')),
            'status' => trim((string) ($story['status'] ?? '')),
            'currency' => trim((string) ($story['currency'] ?? '')),
            'recurring_total' => (int) ($story['recurring_total'] ?? 0),
            'next_payment_utc' => is_string($story['next_payment_utc'] ?? null)
                ? $story['next_payment_utc']
                : null,
            'items' => array_map(static fn (mixed $item): array => is_array($item) ? [
                'name' => trim((string) ($item['name'] ?? '')),
                'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
            ] : ['name' => '', 'quantity' => 0], $items),
            'item_count' => max(0, (int) ($story['item_count'] ?? count($items))),
        ];
    }

    /** @param array<string,mixed> $story @return list<mixed> */
    private function items(array $story): array
    {
        return is_array($story['items'] ?? null) && array_is_list($story['items']) ? $story['items'] : [];
    }
}
