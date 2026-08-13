<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final readonly class FulfilmentPolicy
{
    public function __construct(
        private ?string $physicalDecision = null,
        private ?string $approvalFingerprint = null,
    ) {
        if (($physicalDecision === null) !== ($approvalFingerprint === null)) {
            throw new \InvalidArgumentException('Physical fulfilment decision and approval fingerprint travel together.');
        }
        if ($physicalDecision !== null && (!in_array($physicalDecision, ['unshipped', 'historical_complete'], true)
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $approvalFingerprint) !== 1)) {
            throw new \InvalidArgumentException('Physical fulfilment approval is invalid.');
        }
    }

    public function project(OrderRecord $record): FulfilmentProjection
    {
        if ($record->productLines === []) {
            $this->block('Order has no product line from which fulfilment can be proved.');
        }
        $types = [];
        foreach ($record->productLines as $line) {
            $type = (string) ($line->otherInfo['source_fulfilment_type'] ?? '');
            if (!in_array($type, ['physical', 'digital', 'service'], true)) {
                $this->block('Order line has no proved source fulfilment type.');
            }
            $types[$type] = true;
        }
        $hasPhysical = isset($types['physical']);
        $type = $hasPhysical ? 'physical' : (isset($types['digital']) ? 'digital' : 'service');
        $fulfilled = [];
        if (!$hasPhysical) {
            foreach ($record->productLines as $line) {
                $fulfilled[$line->identity->canonical()] = $line->quantity;
            }
            return new FulfilmentProjection($type, '', $fulfilled);
        }

        $decision = $this->physicalDecision ?? $this->historicalDecision($record);
        $complete = $decision === 'historical_complete';
        foreach ($record->productLines as $line) {
            $lineType = (string) $line->otherInfo['source_fulfilment_type'];
            $fulfilled[$line->identity->canonical()] = $lineType === 'physical' && !$complete
                ? 0
                : $line->quantity;
        }
        return new FulfilmentProjection('physical', $complete ? 'delivered' : 'unshipped', $fulfilled);
    }

    private function historicalDecision(OrderRecord $record): string
    {
        $status = strtolower(trim($record->sourceStatus));
        if (str_starts_with($status, 'wc-')) {
            $status = substr($status, 3);
        }

        return $status === 'completed' && $record->completedUtc !== null
            ? 'historical_complete'
            : 'unshipped';
    }

    private function block(string $message): never
    {
        throw new SourceRecordException('target_schema_unrepresentable', $message);
    }
}
