<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class CustomerAddressRecord
{
    public string $fingerprint;

    public function __construct(
        public SourceIdentity $identity,
        public string $type,
        public bool $primaryIntent,
        public string $status,
        public string $label,
        public string $name,
        public string $company,
        public string $address1,
        public string $address2,
        public string $city,
        public string $state,
        public string $postcode,
        public string $country,
        public string $phone,
        public string $email,
    ) {
        if ($identity->entityType !== 'customer' || !in_array($type, ['billing', 'shipping'], true) || !in_array($status, ['active', 'inactive'], true)) {
            throw new \InvalidArgumentException('Customer address identity, type or status is invalid.');
        }
        $this->fingerprint = CanonicalJson::fingerprint($this->toArray(includeFingerprint: false));
    }

    /** @return array<string, mixed> */
    public function toArray(bool $includeFingerprint = true): array
    {
        $data = [
            'identity' => $this->identity->canonical(), 'type' => $this->type, 'primary_intent' => $this->primaryIntent,
            'status' => $this->status, 'label' => $this->label, 'name' => $this->name, 'company' => $this->company,
            'address_1' => $this->address1, 'address_2' => $this->address2, 'city' => $this->city, 'state' => $this->state,
            'postcode' => $this->postcode, 'country' => $this->country, 'phone' => $this->phone, 'email' => $this->email,
        ];
        if ($includeFingerprint) $data['fingerprint'] = $this->fingerprint;
        return $data;
    }
}
