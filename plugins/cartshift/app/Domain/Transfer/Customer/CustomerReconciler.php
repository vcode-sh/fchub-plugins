<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\ReconciliationResult;
use CartShift\Support\CanonicalJson;
use CartShift\Support\UtcDateTime;

defined('ABSPATH') || exit;

final class CustomerReconciler
{
    /** @param array<string, mixed> $snapshot @param array<string,int> $addressMap */
    public function reconcile(CustomerRecord $record, CustomerAssessment $assessment, array $snapshot, array $addressMap): ReconciliationResult
    {
        $expected = $this->expected($record, $assessment, $addressMap);
        $actual = [
            'customer' => $snapshot['customer'] ?? null,
            'addresses' => $snapshot['addresses'] ?? [],
            'address_map' => array_diff_key($addressMap, ['customer_id' => true]),
        ];
        $expectedFingerprint = CanonicalJson::fingerprint($expected);
        $actualFingerprint = CanonicalJson::fingerprint($actual);
        $failures = hash_equals($expectedFingerprint, $actualFingerprint) ? [] : ['customer_target_graph_mismatch'];
        return new ReconciliationResult($failures === [], $actualFingerprint, $failures);
    }

    /** @param array<string,int> $addressMap @return array<string,mixed> */
    public function expected(CustomerRecord $record, CustomerAssessment $assessment, array $addressMap): array
    {
        $customer = self::customerFields($record, $assessment);
        $addresses = [];
        foreach ($record->addresses as $address) $addresses[] = ['customer_id' => $addressMap['customer_id']] + self::addressFields($address);
        return ['customer' => $customer, 'addresses' => $addresses, 'address_map' => array_diff_key($addressMap, ['customer_id' => true])];
    }

    /** @return array<string,mixed> */
    public static function customerFields(CustomerRecord $record, CustomerAssessment $assessment): array
    {
        $digest = hash('sha256', $record->identity->canonical());
        $linkedUserId = in_array($assessment->action, ['attach_exact_same_site_user', 'reuse_exact_customer_map'], true)
            && is_int($assessment->evidence['user_id'] ?? null)
            && $assessment->evidence['user_id'] > 0
            ? $assessment->evidence['user_id']
            : null;
        return ['user_id' => $linkedUserId,
            'email' => $record->email, 'first_name' => $record->firstName, 'last_name' => $record->lastName, 'status' => $record->statusIntent,
            'uuid' => substr($digest, 0, 8) . '-' . substr($digest, 8, 4) . '-' . substr($digest, 12, 4) . '-' . substr($digest, 16, 4) . '-' . substr($digest, 20, 12),
            'created_at' => $record->createdUtc === null ? null : UtcDateTime::targetFromCanonical($record->createdUtc),
            'updated_at' => $record->updatedUtc === null ? null : UtcDateTime::targetFromCanonical($record->updatedUtc)];
    }

    /** @return array<string,mixed> */
    public static function addressFields(CustomerAddressRecord $address): array
    {
        return ['is_primary' => $address->primaryIntent ? 1 : 0, 'type' => $address->type, 'status' => $address->status, 'label' => $address->label,
            'name' => $address->name, 'address_1' => $address->address1, 'address_2' => $address->address2, 'city' => $address->city,
            'state' => $address->state, 'phone' => $address->phone, 'email' => $address->email, 'postcode' => $address->postcode,
            'country' => $address->country, 'meta' => $address->company === '' ? null : ['other_data' => ['company_name' => $address->company]]];
    }
}
