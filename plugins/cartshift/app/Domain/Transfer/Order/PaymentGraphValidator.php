<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final class PaymentGraphValidator
{
    public function validate(PaymentGraph $graph): void
    {
        $chargeByIdentity = [];
        $grossPaid = 0;
        foreach ($graph->charges as $charge) {
            if (!$charge instanceof PaymentEventRecord || $charge->type !== 'charge') {
                $this->block('source_identity_conflict', 'Payment graph contains a non-charge in its charge set.');
            }
            $identity = $charge->identity->canonical();
            if (isset($chargeByIdentity[$identity])) {
                $this->block('source_identity_conflict', 'Payment graph contains a duplicate charge identity.');
            }
            $chargeByIdentity[$identity] = $charge;
            if ($charge->status === 'succeeded') {
                $grossPaid += $charge->amount;
            }
        }

        $refundIdentities = [];
        $totalRefunded = 0;
        foreach ($graph->refundsByChargeSourceId as $chargeIdentity => $refunds) {
            $charge = $chargeByIdentity[$chargeIdentity] ?? null;
            if (!$charge instanceof PaymentEventRecord) {
                $this->block('refund_parent_ambiguous', 'refund_parent_ambiguous: refund bucket has no exact charge parent.');
            }

            $attachedSuccessful = 0;
            foreach ($refunds as $refund) {
                if (!$refund instanceof PaymentEventRecord || $refund->type !== 'refund') {
                    $this->block('refund_parent_ambiguous', 'refund_parent_ambiguous: charge bucket contains a non-refund event.');
                }
                $identity = $refund->identity->canonical();
                if (isset($refundIdentities[$identity]) || isset($chargeByIdentity[$identity])) {
                    $this->block('source_identity_conflict', 'Payment graph contains a duplicate immutable event identity.');
                }
                $refundIdentities[$identity] = true;
                if ($refund->currency !== $charge->currency) {
                    $this->block('refund_parent_ambiguous', 'refund_parent_ambiguous: refund currency differs from its charge parent.');
                }
                if ($refund->status === 'succeeded') {
                    $attachedSuccessful += $refund->amount;
                    $totalRefunded += $refund->amount;
                }
            }
            if ($attachedSuccessful > $charge->amount) {
                $this->block('order_money_mismatch', 'Successful refunds exceed their exact parent charge.');
            }
        }

        if ($grossPaid !== $graph->grossPaid || $totalRefunded !== $graph->totalRefunded) {
            $this->block('order_money_mismatch', 'Payment graph totals do not equal their successful events.');
        }
        if ($totalRefunded > $grossPaid) {
            $this->block('order_money_mismatch', 'Successful refunds exceed successful gross charges.');
        }
    }

    private function block(string $reasonCode, string $message): never
    {
        throw new SourceRecordException($reasonCode, $message);
    }
}
