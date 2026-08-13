<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedReviewContextBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedSourceDependencyIndex;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedReviewContextBuilderTest extends PluginTestCase
{
    public function testOrderContextConnectsTheCustomerAndProductsWithoutCopyingPrivatePayload(): void
    {
        $product = $this->record('product', '10', [
            'name' => 'Store membership',
            'sku' => 'MEMBERSHIP',
            'status' => 'publish',
            'product_type' => 'simple',
        ]);
        $customer = $this->record('customer', '7', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'classification' => 'registered',
            'phone' => '+48 private',
        ]);
        $order = $this->record('order', '42', [
            'customer' => $customer->identity->canonical(),
            'created_utc' => '2025-01-20T11:12:13Z',
            'source_status' => 'completed',
            'currency' => 'PLN',
            'gross_total' => 2400,
            'product_lines' => [[
                'product' => $product->identity->canonical(),
                'name' => 'Store membership',
                'sku' => 'MEMBERSHIP',
                'quantity' => 1,
            ]],
            'addresses' => [['address_1' => 'Private street 1']],
            'notes' => [['content' => 'Private order note']],
        ]);
        $index = new GuidedSourceDependencyIndex([$product, $customer, $order]);

        $enriched = (new GuidedReviewContextBuilder())->enrich([], $index);

        self::assertSame([
            'kind' => 'order',
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'created_utc' => '2025-01-20T11:12:13Z',
            'status' => 'completed',
            'currency' => 'PLN',
            'gross_total' => 2400,
            'items' => [[
                'name' => 'Store membership',
                'sku' => 'MEMBERSHIP',
                'quantity' => 1,
            ]],
            'item_count' => 1,
        ], $enriched['review_context'][$order->identity->canonical()]);

        $encoded = json_encode($enriched['review_context'], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Private street', $encoded);
        self::assertStringNotContainsString('Private order note', $encoded);
        self::assertStringNotContainsString('+48 private', $encoded);
        self::assertStringNotContainsString($order->sourceContentDigest, $encoded);
    }

    public function testProductCustomerAndSubscriptionContextsShowOnlyUsefulRelationshipFacts(): void
    {
        $product = $this->record('product', '10', [
            'name' => 'Store membership',
            'sku' => 'MEMBERSHIP',
            'status' => 'publish',
            'product_type' => 'simple',
        ]);
        $customer = $this->record('customer', '7', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
            'classification' => 'registered',
        ]);
        $order = $this->record('order', '42', [
            'customer' => $customer->identity->canonical(),
            'created_utc' => '2025-01-20T11:12:13Z',
            'source_status' => 'completed',
            'currency' => 'PLN',
            'gross_total' => 2400,
            'product_lines' => [[
                'product' => $product->identity->canonical(),
                'name' => 'Store membership',
                'sku' => 'MEMBERSHIP',
                'quantity' => 1,
            ]],
        ]);
        $subscription = $this->record('subscription', '77', [
            'customer_identity' => $customer->identity->canonical(),
            'status' => 'active',
            'currency' => 'PLN',
            'contract' => ['recurring_total' => 2400],
            'schedule' => ['next_payment_utc' => '2026-09-01T10:00:00Z'],
            'items' => [[
                'product_identity' => $product->identity->canonical(),
                'name' => 'Store membership',
                'quantity' => 1,
            ]],
            'related_orders' => [['identity' => $order->identity->canonical()]],
        ]);

        $context = (new GuidedReviewContextBuilder())
            ->enrich([], new GuidedSourceDependencyIndex([$product, $customer, $order, $subscription]))['review_context'];

        self::assertSame([
            'kind' => 'product',
            'name' => 'Store membership',
            'sku' => 'MEMBERSHIP',
            'status' => 'publish',
            'product_type' => 'simple',
            'dependent_orders' => 1,
            'dependent_subscriptions' => 1,
        ], $context[$product->identity->canonical()]);
        self::assertSame([
            'kind' => 'customer',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'classification' => 'registered',
            'dependent_orders' => 1,
            'dependent_subscriptions' => 1,
            'purchases' => ['Store membership'],
        ], $context[$customer->identity->canonical()]);
        self::assertSame([
            'kind' => 'subscription',
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'status' => 'active',
            'currency' => 'PLN',
            'recurring_total' => 2400,
            'next_payment_utc' => '2026-09-01T10:00:00Z',
            'items' => [['name' => 'Store membership', 'quantity' => 1]],
            'item_count' => 1,
        ], $context[$subscription->identity->canonical()]);
    }

    /** @param array<string,mixed> $payload */
    private function record(string $kind, string $id, array $payload): RecordEnvelope
    {
        return RecordEnvelope::forPayload(
            2,
            new SourceIdentity('site-alpha', $kind, $id),
            ['dependencies' => [], ...$payload],
        );
    }
}
