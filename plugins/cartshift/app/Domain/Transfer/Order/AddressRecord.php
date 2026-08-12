<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class AddressRecord
{
    public function __construct(
        public SourceIdentity $identity,
        public string $type,
        public string $firstName,
        public string $lastName,
        public string $company,
        public string $address1,
        public string $address2,
        public string $city,
        public string $state,
        public string $postcode,
        public string $country,
        public string $email,
        public string $phone,
        public string $businessTaxId,
    ) {
        if (!in_array($type, ['billing', 'shipping'], true)) {
            throw new \InvalidArgumentException('Order address type is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['identity' => $this->identity->canonical(), 'type' => $this->type,
            'first_name' => $this->firstName, 'last_name' => $this->lastName, 'company' => $this->company,
            'address_1' => $this->address1, 'address_2' => $this->address2, 'city' => $this->city,
            'state' => $this->state, 'postcode' => $this->postcode, 'country' => $this->country,
            'email' => $this->email, 'phone' => $this->phone, 'business_tax_id' => $this->businessTaxId];
    }
}
