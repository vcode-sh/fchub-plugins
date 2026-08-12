<?php

declare(strict_types=1);

/** A source ledger with tax, fee, shipping, coupon, partial refund, addresses and note history. */
function cartshift_contract_historical_order_record(
    int $sourceOrderId,
    int $sourceCustomerId,
    int $sourceProductId,
    string $relationshipType = 'checkout',
    ?int $parentSourceOrderId = null,
): \CartShift\Domain\Transfer\Order\OrderRecord {
    $identity = static fn (string $sourceId, string $kind = 'order'):
        \CartShift\Domain\Transfer\SourceIdentity => new \CartShift\Domain\Transfer\SourceIdentity(
            'installed-order',
            $kind,
            $sourceId,
        );
    $order = $identity((string) $sourceOrderId);
    $product = $identity((string) $sourceProductId, 'product');
    $variation = $identity($sourceProductId . ':variation:' . $sourceProductId, 'product');
    $chargeIdentity = $identity($sourceOrderId . ':charge:' . $sourceOrderId);
    $line = new \CartShift\Domain\Transfer\Order\OrderLineRecord(
        $identity($sourceOrderId . ':item:11'), 11, $product, $variation, 'Historical course', 'HIST-COURSE', [],
        1, 0, 10000, 10000, 2300, 1000, 230, 2070, 9000, 2000,
        'not_available_from_woo_core', 1, '1', '2026-08-01T10:00:00Z', [19 => 2070],
        ['source_fulfilment_type' => 'digital'], [],
    );
    $fee = new \CartShift\Domain\Transfer\Order\FeeLineRecord(
        $identity($sourceOrderId . ':fee:21'), 21, 'Handling', 500, 115, [19 => 115], [],
    );
    $shipping = new \CartShift\Domain\Transfer\Order\ShippingLineRecord(
        $identity($sourceOrderId . ':shipping:31'), 31, 'flat_rate', 2, 'Courier', 2000, 460, [19 => 460], [],
    );
    $coupon = new \CartShift\Domain\Transfer\Order\CouponLineRecord(
        $identity($sourceOrderId . ':coupon:41'), 41, 'HIST10', 1000, 230,
    );
    $tax = new \CartShift\Domain\Transfer\Order\TaxRateRecord(
        $identity($sourceOrderId . ':tax:51'), 51, 19, 'PL-VAT-23', 'VAT', '23.0000', false,
        2185, 460, 11370, false,
    );
    $billing = new \CartShift\Domain\Transfer\Order\AddressRecord(
        $identity($sourceOrderId . ':address:1'), 'billing', 'Admin', 'Owner', 'Example Ltd', 'Main 1', '',
        'Warsaw', '', '00-001', 'PL', 'admin@example.com', '+48 500 000 000', '5291831115',
    );
    $shippingAddress = new \CartShift\Domain\Transfer\Order\AddressRecord(
        $identity($sourceOrderId . ':address:2'), 'shipping', 'Admin', 'Owner', '', 'Second 2', '',
        'Krakow', '', '30-001', 'PL', '', '', '',
    );
    $charge = new \CartShift\Domain\Transfer\Order\PaymentEventRecord(
        $chargeIdentity, 'charge', 14145, 'PLN', 'succeeded',
        \CartShift\Domain\Transfer\Order\PaymentEvidenceKind::ProviderReference,
        'stripe', 'Card', 'ch_installed_private', null, '2026-08-01T10:00:00Z', [],
    );
    $refund = new \CartShift\Domain\Transfer\Order\PaymentEventRecord(
        $identity($sourceOrderId . ':refund:61'), 'refund', 2000, 'PLN', 'succeeded',
        \CartShift\Domain\Transfer\Order\PaymentEvidenceKind::ProviderReference,
        'stripe', 'Card', 're_installed_private', $chargeIdentity, '2026-08-02T10:00:00Z', [],
    );
    $customerNote = new \CartShift\Domain\Transfer\Order\OrderNoteRecord(
        $identity($sourceOrderId . ':note:71'), 71, 'Customer-visible canonical note',
        '2026-08-01T11:00:00Z', true, 'customer', hash('sha256', 'public-note-71'),
    );
    $privateNote = new \CartShift\Domain\Transfer\Order\OrderNoteRecord(
        $identity($sourceOrderId . ':note:72'), 72, 'Private provenance note',
        '2026-08-01T12:00:00Z', false, 'system', hash('sha256', 'public-note-72'),
    );

    return new \CartShift\Domain\Transfer\Order\OrderRecord(
        $order,
        $identity((string) $sourceCustomerId, 'customer'),
        $parentSourceOrderId === null ? null : $identity((string) $parentSourceOrderId),
        $relationshipType,
        'completed',
        'PLN', 'PLN', 'PLN', '1', 'same_currency:PLN', false,
        10000, 1000, 0, 230, 2000, 460, 500, 115, 2185, 14145, 2000,
        '2026-08-01T10:00:00Z', '2026-08-02T11:00:00Z', '2026-08-01T10:00:00Z',
        '2026-08-01T10:00:00Z', '2026-08-02T10:00:00Z',
        [$line], [$fee], [$shipping], [$coupon], [$tax], [$billing, $shippingAddress],
        [$charge, $refund], [$customerNote, $privateNote], ['campaign' => 'installed-contract'],
    );
}
