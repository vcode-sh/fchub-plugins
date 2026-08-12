<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final readonly class PaymentGraphProjector
{
    public function __construct(private HistoricalPaymentPolicy $paymentPolicy)
    {
    }

    /**
     * @param array<string, int> $targetChargeIds Canonical source charge identity to newly inserted target ID.
     */
    public function project(
        PaymentGraph $graph,
        array $targetChargeIds,
        string $orderType,
        ?string $sourceMode,
        ?string $selectionFingerprint = null,
    ): PaymentGraphProjection {
        if (trim($orderType) === '') {
            throw new \InvalidArgumentException('Target order type is required for transaction projection.');
        }

        $charges = [];
        $refunds = [];
        foreach ($graph->charges as $charge) {
            $identity = $charge->identity->canonical();
            $chargeRow = $this->paymentPolicy
                ->project($charge, $sourceMode, $selectionFingerprint)
                ->toTargetTransaction();
            $chargeRow['order_type'] = $orderType;
            $chargeRow['meta']['refunded_total'] = $this->successfulRefundTotal(
                $graph->refundsByChargeSourceId[$identity] ?? [],
            );
            $charges[] = $chargeRow;

            foreach ($graph->refundsByChargeSourceId[$identity] ?? [] as $refund) {
                $parentId = $targetChargeIds[$identity] ?? null;
                if (!is_int($parentId) || $parentId <= 0) {
                    throw new SourceRecordException(
                        'refund_parent_ambiguous',
                        'refund_parent_ambiguous: refund has no newly inserted target charge ID.',
                    );
                }
                $refundRow = $this->paymentPolicy
                    ->project($refund, $sourceMode, $selectionFingerprint)
                    ->toTargetTransaction();
                $refundRow['order_type'] = $orderType;
                $refundRow['currency'] = $chargeRow['currency'];
                $refundRow['payment_method'] = $chargeRow['payment_method'];
                $refundRow['payment_method_type'] = $chargeRow['payment_method_type'];
                $refundRow['payment_mode'] = $chargeRow['payment_mode'];
                $refundRow['status'] = $refund->status === 'succeeded' ? 'refunded' : $refund->status;
                $refundRow['meta']['parent_id'] = $parentId;
                $refunds[] = $refundRow;
            }
        }

        return new PaymentGraphProjection(
            $charges,
            $refunds,
            $graph->grossPaid,
            $graph->totalRefunded,
            $this->paymentStatus($graph),
        );
    }

    /** @param list<PaymentEventRecord> $refunds */
    private function successfulRefundTotal(array $refunds): int
    {
        return array_sum(array_map(
            static fn (PaymentEventRecord $refund): int => $refund->status === 'succeeded' ? $refund->amount : 0,
            $refunds,
        ));
    }

    private function paymentStatus(PaymentGraph $graph): string
    {
        if ($graph->grossPaid === 0) {
            return 'pending';
        }
        if ($graph->totalRefunded === 0) {
            return 'paid';
        }
        return $graph->totalRefunded === $graph->grossPaid ? 'refunded' : 'partially_refunded';
    }
}
