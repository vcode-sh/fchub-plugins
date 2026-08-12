<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final readonly class PaymentGraphBuilder
{
    public function __construct(private PaymentGraphValidator $validator = new PaymentGraphValidator())
    {
    }

    /** @param list<PaymentEventRecord> $events */
    public function build(array $events): PaymentGraph
    {
        $byIdentity = [];
        foreach ($events as $event) {
            if (!$event instanceof PaymentEventRecord) {
                throw new \InvalidArgumentException('Payment graph input must contain payment event records.');
            }
            $identity = $event->identity->canonical();
            if (isset($byIdentity[$identity])) {
                throw new SourceRecordException(
                    'source_identity_conflict',
                    'Payment graph contains a duplicate immutable event identity.',
                );
            }
            $byIdentity[$identity] = $event;
        }

        usort($events, static fn (PaymentEventRecord $left, PaymentEventRecord $right): int => [
            $left->occurredUtc ?? '',
            $left->identity->canonical(),
        ] <=> [
            $right->occurredUtc ?? '',
            $right->identity->canonical(),
        ]);

        $charges = array_values(array_filter(
            $events,
            static fn (PaymentEventRecord $event): bool => $event->type === 'charge',
        ));
        $refunds = array_values(array_filter(
            $events,
            static fn (PaymentEventRecord $event): bool => $event->type === 'refund',
        ));

        $chargeByIdentity = [];
        $successfulCharges = [];
        $refundsByCharge = [];
        foreach ($charges as $charge) {
            $identity = $charge->identity->canonical();
            $chargeByIdentity[$identity] = $charge;
            $refundsByCharge[$identity] = [];
            if ($charge->status === 'succeeded') {
                $successfulCharges[$identity] = $charge;
            }
        }

        foreach ($refunds as $refund) {
            $parentIdentity = $refund->parentEvent?->canonical();
            if ($parentIdentity === null) {
                if (count($successfulCharges) !== 1) {
                    throw new SourceRecordException(
                        'refund_parent_ambiguous',
                        'refund_parent_ambiguous: refund has no unique successful charge parent.',
                    );
                }
                $parentIdentity = array_key_first($successfulCharges);
            }

            $parent = $successfulCharges[$parentIdentity] ?? null;
            if (!$parent instanceof PaymentEventRecord || $refund->currency !== $parent->currency) {
                throw new SourceRecordException(
                    'refund_parent_ambiguous',
                    'refund_parent_ambiguous: explicit refund parent is missing, unsuccessful or currency-incompatible.',
                );
            }
            $refundsByCharge[$parentIdentity][] = $refund;
        }

        $grossPaid = array_sum(array_map(
            static fn (PaymentEventRecord $charge): int => $charge->status === 'succeeded' ? $charge->amount : 0,
            $charges,
        ));
        $totalRefunded = array_sum(array_map(
            static fn (PaymentEventRecord $refund): int => $refund->status === 'succeeded' ? $refund->amount : 0,
            $refunds,
        ));
        $graph = new PaymentGraph($charges, $refundsByCharge, $grossPaid, $totalRefunded);
        $this->validator->validate($graph);

        return $graph;
    }
}
