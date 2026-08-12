<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Support\MoneyHelper;

defined('ABSPATH') || exit;

/** Resolves deleted Woo product references only from the exact reviewed order-item witness. */
final class HistoricalProductDecisionResolver
{
    /** @var array<string, RecordEnvelope> */
    private array $records = [];

    public function __construct(
        private readonly string $sourceKey,
        private readonly TransferDecisionSet $decisions,
        private readonly HistoricalProductPlaceholder $placeholder = new HistoricalProductPlaceholder(),
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
        $decisions->assertSourceKey($sourceKey);
    }

    public function resolve(object $order, object $item): SourceIdentity
    {
        $orderId = $this->positiveMethod($order, 'get_id');
        $lineId = $this->positiveMethod($item, 'get_id');
        $rawProductId = function_exists('wc_get_order_item_meta')
            ? (int) wc_get_order_item_meta($lineId, '_product_id', true)
            : 0;
        if ($rawProductId <= 0) {
            throw $this->missing();
        }
        $finding = new SourceIdentity(
            $this->sourceKey,
            RecordKind::Order->value,
            $orderId . ':item:' . $lineId,
        );
        $decision = $this->decisions->forAuditFinding(
            $finding->canonical(),
            'historical_product_missing',
        );
        $identity = new SourceIdentity(
            $this->sourceKey,
            RecordKind::Product->value,
            (string) $rawProductId,
        );
        $shape = $this->lineShape($order, $item);
        $approval = HistoricalProductPlaceholder::approvalFingerprint($identity, $shape);
        if ($decision === null
            || ($decision['action'] ?? null) !== 'approve_mapping'
            || ($decision['placeholder_identity'] ?? null) !== $identity->canonical()
            || !hash_equals((string) ($decision['placeholder_fingerprint'] ?? ''), $approval)) {
            throw $this->missing();
        }

        $record = $this->placeholder->record($identity, $shape, $approval)->envelope();
        $canonical = $identity->canonical();
        if (isset($this->records[$canonical])
            && !hash_equals($this->records[$canonical]->privateContentDigest, $record->privateContentDigest)) {
            throw new SourceRecordException(
                'dependency_ambiguous',
                'One deleted product identity has conflicting immutable order-line witnesses.',
            );
        }
        $this->records[$canonical] = $record;

        return $identity;
    }

    /** @return array{identity: SourceIdentity, fulfilment_type: string} */
    public function resolveLine(object $order, object $item): array
    {
        $identity = $this->resolve($order, $item);
        $record = $this->record($identity);
        $fulfilmentType = $record?->payload['fulfilment_type'] ?? null;
        if (!is_string($fulfilmentType)
            || !in_array($fulfilmentType, ['physical', 'digital', 'service'], true)) {
            throw $this->missing();
        }

        return ['identity' => $identity, 'fulfilment_type' => $fulfilmentType];
    }

    /** @return list<RecordEnvelope> */
    public function records(): array
    {
        $records = $this->records;
        ksort($records, SORT_NATURAL);
        return array_values($records);
    }

    public function record(SourceIdentity $identity): ?RecordEnvelope
    {
        return $this->records[$identity->canonical()] ?? null;
    }

    /** @return array{name:string,sku:string,unit_total:int,currency:string,source_created_utc?:string} */
    private function lineShape(object $order, object $item): array
    {
        foreach (['get_name', 'get_subtotal', 'get_quantity'] as $method) {
            if (!is_callable([$item, $method])) {
                throw $this->missing();
            }
        }
        if (!is_callable([$order, 'get_currency'])) {
            throw $this->missing();
        }
        $quantity = (int) $item->get_quantity();
        try {
            $subtotal = MoneyHelper::decimalToCents((string) $item->get_subtotal());
        } catch (\Throwable) {
            throw $this->missing();
        }
        $name = trim((string) $item->get_name());
        $currency = strtoupper(trim((string) $order->get_currency()));
        if ($quantity <= 0 || $subtotal < 0 || $subtotal % $quantity !== 0
            || $name === '' || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1) {
            throw $this->missing();
        }
        $shape = [
            'name' => $name,
            'sku' => is_callable([$item, 'get_meta']) ? trim((string) $item->get_meta('_sku', true)) : '',
            'unit_total' => intdiv($subtotal, $quantity),
            'currency' => $currency,
        ];
        $created = is_callable([$order, 'get_date_created']) ? $order->get_date_created() : null;
        if ($created instanceof \DateTimeInterface) {
            $shape['source_created_utc'] = \DateTimeImmutable::createFromInterface($created)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        }

        return $shape;
    }

    private function positiveMethod(object $object, string $method): int
    {
        $value = is_callable([$object, $method]) ? (int) $object->{$method}() : 0;
        if ($value <= 0) {
            throw $this->missing();
        }
        return $value;
    }

    private function missing(): SourceRecordException
    {
        return new SourceRecordException(
            'historical_product_missing',
            'Historical order line lacks an exact fingerprint-bound placeholder decision.',
        );
    }
}
