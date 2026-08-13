<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

/** Builds the small, private presentation snapshot used by the guided owner review. */
final readonly class GuidedReviewContextBuilder
{
    /** @param array<string,mixed> $proposal @return array<string,mixed> */
    public function enrich(array $proposal, GuidedSourceDependencyIndex $index): array
    {
        $context = [];
        foreach ($index->records(RecordKind::Product) as $record) {
            $context[$record->identity->canonical()] = $this->product($record, $index);
        }
        foreach ($index->records(RecordKind::Customer) as $record) {
            $context[$record->identity->canonical()] = $this->customer($record, $index);
        }
        foreach ($index->records(RecordKind::Order) as $record) {
            $context[$record->identity->canonical()] = $this->order($record, $index);
        }
        foreach ($index->records(RecordKind::Subscription) as $record) {
            $context[$record->identity->canonical()] = $this->subscription($record, $index);
        }

        return array_replace($proposal, ['review_context' => $context]);
    }

    /** @return array<string,mixed> */
    private function product(RecordEnvelope $record, GuidedSourceDependencyIndex $index): array
    {
        $payload = $record->payload;
        $closure = $index->closure($record->identity);

        return [
            'kind' => 'product',
            'name' => trim((string) ($payload['name'] ?? '')),
            'sku' => trim((string) ($payload['sku'] ?? '')),
            'status' => trim((string) ($payload['status'] ?? '')),
            'product_type' => trim((string) ($payload['product_type'] ?? '')),
            'dependent_orders' => $this->count($closure, RecordKind::Order),
            'dependent_subscriptions' => $this->count($closure, RecordKind::Subscription),
        ];
    }

    /** @return array<string,mixed> */
    private function customer(RecordEnvelope $record, GuidedSourceDependencyIndex $index): array
    {
        $payload = $record->payload;
        $closure = $index->closure($record->identity);
        $purchases = [];
        foreach ($closure as $dependent) {
            if ($dependent->identity->kind() !== RecordKind::Order) {
                continue;
            }
            foreach ($this->lines($dependent->payload) as $line) {
                $name = trim((string) ($line['name'] ?? ''));
                if ($name !== '') {
                    $purchases[$name] = true;
                }
            }
        }

        return [
            'kind' => 'customer',
            'name' => $this->customerName($payload),
            'email' => trim((string) ($payload['email'] ?? '')),
            'classification' => trim((string) ($payload['classification'] ?? '')),
            'dependent_orders' => $this->count($closure, RecordKind::Order),
            'dependent_subscriptions' => $this->count($closure, RecordKind::Subscription),
            'purchases' => array_slice(array_keys($purchases), 0, 3),
        ];
    }

    /** @return array<string,mixed> */
    private function order(RecordEnvelope $record, GuidedSourceDependencyIndex $index): array
    {
        $payload = $record->payload;
        $customer = is_string($payload['customer'] ?? null)
            ? $index->record(SourceIdentity::fromCanonical($payload['customer']))->payload
            : [];
        $name = $this->customerName($customer);
        $lines = $this->lines($payload);
        $items = array_map(static fn (mixed $line): array => is_array($line) ? [
            'name' => trim((string) ($line['name'] ?? '')),
            'sku' => trim((string) ($line['sku'] ?? '')),
            'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
        ] : ['name' => '', 'sku' => '', 'quantity' => 0], $lines);

        return [
            'kind' => 'order',
            'customer_name' => $name,
            'customer_email' => trim((string) ($customer['email'] ?? '')),
            'created_utc' => trim((string) ($payload['created_utc'] ?? '')),
            'status' => trim((string) ($payload['source_status'] ?? '')),
            'currency' => trim((string) ($payload['currency'] ?? '')),
            'gross_total' => (int) ($payload['gross_total'] ?? 0),
            'items' => $items,
            'item_count' => count($lines),
        ];
    }

    /** @return array<string,mixed> */
    private function subscription(RecordEnvelope $record, GuidedSourceDependencyIndex $index): array
    {
        $payload = $record->payload;
        $customer = is_string($payload['customer_identity'] ?? null)
            ? $index->record(SourceIdentity::fromCanonical($payload['customer_identity']))->payload
            : [];
        $lines = is_array($payload['items'] ?? null) && array_is_list($payload['items'])
            ? $payload['items']
            : [];
        $items = array_map(static fn (mixed $line): array => is_array($line) ? [
            'name' => trim((string) ($line['name'] ?? '')),
            'quantity' => max(0, (int) ($line['quantity'] ?? 0)),
        ] : ['name' => '', 'quantity' => 0], $lines);

        return [
            'kind' => 'subscription',
            'customer_name' => $this->customerName($customer),
            'customer_email' => trim((string) ($customer['email'] ?? '')),
            'status' => trim((string) ($payload['status'] ?? '')),
            'currency' => trim((string) ($payload['currency'] ?? '')),
            'recurring_total' => (int) (($payload['contract']['recurring_total'] ?? 0)),
            'next_payment_utc' => is_string($payload['schedule']['next_payment_utc'] ?? null)
                ? $payload['schedule']['next_payment_utc']
                : null,
            'items' => $items,
            'item_count' => count($lines),
        ];
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function lines(array $payload): array
    {
        $lines = $payload['product_lines'] ?? [];
        return is_array($lines) && array_is_list($lines)
            ? array_values(array_filter($lines, 'is_array'))
            : [];
    }

    /** @param array<string,mixed> $payload */
    private function customerName(array $payload): string
    {
        return trim(implode(' ', array_filter([
            trim((string) ($payload['first_name'] ?? '')),
            trim((string) ($payload['last_name'] ?? '')),
        ], static fn (string $part): bool => $part !== '')));
    }

    /** @param list<RecordEnvelope> $records */
    private function count(array $records, RecordKind $kind): int
    {
        return count(array_filter(
            $records,
            static fn (RecordEnvelope $record): bool => $record->identity->kind() === $kind,
        ));
    }
}
