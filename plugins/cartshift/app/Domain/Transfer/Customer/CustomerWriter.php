<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final readonly class CustomerWriter
{
    public function __construct(private CustomerTargetGateway $gateway, private CheckedMappingStore $maps, private CustomerReconciler $reconciler) {}

    public function stage(CustomerRecord $record, CustomerAssessment $assessment, StageContext $context): CustomerStageResult
    {
        if (!in_array($assessment->action, ['create_target_customer_unlinked', 'attach_exact_same_site_user', 'reuse_exact_customer_map', 'reuse_explicit_target_customer'], true)) throw new \RuntimeException('customer_stage_action_not_writable');
        $existing = $this->maps->get($record->identity);
        DatabaseTransaction::begin();
        try {
            if ($assessment->action === 'reuse_explicit_target_customer') {
                if ($existing !== null) throw new \RuntimeException('customer_explicit_reuse_conflicts_with_map');
                $targetId = (int) ($assessment->evidence['target_id'] ?? 0);
                $approvedFingerprint = (string) ($assessment->evidence['target_fingerprint'] ?? '');
                if ($targetId <= 0 || preg_match('/\A[a-f0-9]{64}\z/D', $approvedFingerprint) !== 1 || !$this->gateway->exists($targetId)) throw new \RuntimeException('customer_explicit_target_invalid');
                $actualFingerprint = \CartShift\Support\CanonicalJson::fingerprint($this->gateway->snapshot($targetId));
                if (!hash_equals($approvedFingerprint, $actualFingerprint)) throw new \RuntimeException('customer_explicit_target_fingerprint_changed');
                $this->maps->storeOrThrow($record->identity, $targetId, $context->migrationId, $record->envelope()->privateContentDigest, $actualFingerprint, MapState::Reconciled, false, $context->generation);
                DatabaseTransaction::commit();
                return new CustomerStageResult($targetId, [], $actualFingerprint, true);
            }
            if ($existing !== null) {
                if (!$existing->isActive() || !$this->gateway->exists($existing->targetId)) throw new \RuntimeException('customer_target_missing');
                $addressMap = $this->addressMap($record);
                if ($assessment->action === 'reuse_exact_customer_map') {
                    $sourceFingerprint = $record->envelope()->privateContentDigest;
                    $actualFingerprint = \CartShift\Support\CanonicalJson::fingerprint([
                        'customer' => $this->gateway->snapshot($existing->targetId)['customer'],
                        'addresses' => $this->gateway->snapshot($existing->targetId)['addresses'],
                        'address_map' => $addressMap,
                    ]);
                    if (!hash_equals((string) $existing->sourceFingerprint, $sourceFingerprint)
                        || !hash_equals((string) $existing->targetFingerprint, $actualFingerprint)) {
                        throw new \RuntimeException('customer_checked_mapping_fingerprint_changed');
                    }
                    DatabaseTransaction::commit();
                    return new CustomerStageResult($existing->targetId, array_values($addressMap), $actualFingerprint, true);
                }
                $result = $this->reconciler->reconcile($record, $assessment, $this->gateway->snapshot($existing->targetId), ['customer_id' => $existing->targetId] + $addressMap);
                if (!$result->matches) throw new \RuntimeException('customer_target_reconciliation_failed');
                DatabaseTransaction::commit();
                return new CustomerStageResult($existing->targetId, array_values($addressMap), $result->actualFingerprint, true);
            }

            $customerId = $this->gateway->createCustomer(CustomerReconciler::customerFields($record, $assessment));
            if ($customerId <= 0) throw new \RuntimeException('customer_target_write_failed');
            $addressMap = [];
            foreach ($record->addresses as $address) {
                $addressId = $this->gateway->createAddress($customerId, CustomerReconciler::addressFields($address));
                if ($addressId <= 0) throw new \RuntimeException('customer_address_write_failed');
                $addressMap[$address->identity->canonical()] = $addressId;
            }
            $snapshot = $this->gateway->snapshot($customerId);
            $fingerprint = \CartShift\Support\CanonicalJson::fingerprint(['customer' => $snapshot['customer'], 'addresses' => $snapshot['addresses'], 'address_map' => $addressMap]);
            foreach ($record->addresses as $address) $this->maps->storeOrThrow($address->identity, $addressMap[$address->identity->canonical()], $context->migrationId, $address->fingerprint, $fingerprint, MapState::Staged, true, $context->generation);
            $this->maps->storeOrThrow($record->identity, $customerId, $context->migrationId, $record->envelope()->privateContentDigest, $fingerprint, MapState::Staged, true, $context->generation);
            $reconciliation = $this->reconciler->reconcile($record, $assessment, $snapshot, ['customer_id' => $customerId] + $addressMap);
            if (!$reconciliation->matches) throw new \RuntimeException('customer_target_reconciliation_failed');
            foreach (array_merge(array_map(static fn (CustomerAddressRecord $a) => $a->identity, $record->addresses), [$record->identity]) as $identity) $this->maps->transitionOrThrow($identity, MapState::Staged, MapState::Reconciled, $fingerprint, $reconciliation->actualFingerprint);
            DatabaseTransaction::commit();
            return new CustomerStageResult($customerId, array_values($addressMap), $reconciliation->actualFingerprint, false);
        } catch (\Throwable $exception) {
            DatabaseTransaction::rollback($exception);
            throw $exception;
        }
    }

    /** @return array<string,int> */
    private function addressMap(CustomerRecord $record): array
    {
        $map = [];
        foreach ($record->addresses as $address) {
            $mapping = $this->maps->get($address->identity);
            if ($mapping === null || !$mapping->isActive()) throw new \RuntimeException('customer_address_map_missing');
            $map[$address->identity->canonical()] = $mapping->targetId;
        }
        return $map;
    }
}
