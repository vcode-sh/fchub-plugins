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
        if (count($types) !== 1) {
            $this->block('Mixed fulfilment has no proved target per-line representation.');
        }

        $type = array_key_first($types);
        $fulfilled = [];
        if ($type !== 'physical') {
            foreach ($record->productLines as $line) {
                $fulfilled[$line->identity->canonical()] = $line->quantity;
            }
            return new FulfilmentProjection($type, '', $fulfilled);
        }
        if ($this->physicalDecision === null) {
            $this->block('Physical fulfilment requires an adapter or fingerprint-bound cohort policy.');
        }
        $complete = $this->physicalDecision === 'historical_complete';
        foreach ($record->productLines as $line) {
            $fulfilled[$line->identity->canonical()] = $complete ? $line->quantity : 0;
        }
        return new FulfilmentProjection('physical', $complete ? 'delivered' : 'unshipped', $fulfilled);
    }

    private function block(string $message): never
    {
        throw new SourceRecordException('target_schema_unrepresentable', $message);
    }
}
