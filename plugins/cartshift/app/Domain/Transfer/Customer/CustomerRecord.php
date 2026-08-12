<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

final readonly class CustomerRecord
{
    /**
     * @param list<CustomerAddressRecord> $addresses
     * @param array<string, scalar|null> $provenance
     * @param list<SourceIdentity> $dependencies
     */
    private function __construct(
        public SourceIdentity $identity,
        public ?int $sourceUserId,
        public string $classification,
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $normalizedEmailDigest,
        public string $statusIntent,
        public string $accountIntent,
        public array $addresses,
        public ?string $createdUtc,
        public ?string $updatedUtc,
        public array $provenance,
        public array $dependencies,
    ) {}

    /** @param list<CustomerAddressRecord> $addresses @param array<string, scalar|null> $provenance @param list<SourceIdentity> $dependencies */
    public static function create(
        SourceIdentity $identity, ?int $sourceUserId, string $classification, string $firstName, string $lastName,
        string $email, string $statusIntent, array $addresses, ?string $createdUtc, ?string $updatedUtc,
        array $provenance, array $dependencies,
    ): self {
        if ($identity->entityType !== 'customer' || !in_array($classification, ['registered', 'guest'], true)
            || ($classification === 'registered' && ($sourceUserId ?? 0) <= 0)
            || ($classification === 'guest' && $sourceUserId !== null)
            || !in_array($statusIntent, ['active', 'inactive'], true)) {
            throw new \InvalidArgumentException('Customer record identity or lifecycle intent is invalid.');
        }
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Customer record requires a valid email address.');
        }
        if (!array_is_list($addresses) || !array_is_list($dependencies)) throw new \InvalidArgumentException('Customer record collections must be lists.');
        foreach ($addresses as $address) if (!$address instanceof CustomerAddressRecord || $address->identity->sourceKey !== $identity->sourceKey || !str_starts_with($address->identity->sourceId, $identity->sourceId . ':')) throw new \InvalidArgumentException('Customer address does not belong to its customer identity.');
        foreach ($dependencies as $dependency) if (!$dependency instanceof SourceIdentity) throw new \InvalidArgumentException('Customer dependency is invalid.');
        foreach ($provenance as $key => $value) if (!is_string($key) || (!is_scalar($value) && $value !== null)) throw new \InvalidArgumentException('Customer provenance must be a scalar map.');
        $normalised = function_exists('mb_strtolower') ? mb_strtolower($email, 'UTF-8') : strtolower($email);
        return new self($identity, $sourceUserId, $classification, $firstName, $lastName, $email, hash('sha256', $normalised), $statusIntent, 'create_no_account', $addresses, $createdUtc, $updatedUtc, $provenance, $dependencies);
    }

    public function envelope(int $schemaVersion = 2): RecordEnvelope
    {
        return RecordEnvelope::forPayload($schemaVersion, $this->identity, $this->toArray());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identity' => $this->identity->canonical(), 'source_user_id' => $this->sourceUserId, 'classification' => $this->classification,
            'first_name' => $this->firstName, 'last_name' => $this->lastName, 'email' => $this->email,
            'normalized_email_digest' => $this->normalizedEmailDigest, 'status_intent' => $this->statusIntent,
            'account_intent' => $this->accountIntent, 'addresses' => array_map(static fn (CustomerAddressRecord $a): array => $a->toArray(), $this->addresses),
            'created_utc' => $this->createdUtc, 'updated_utc' => $this->updatedUtc, 'provenance' => $this->provenance,
            'dependencies' => array_map(static fn (SourceIdentity $i): string => $i->canonical(), $this->dependencies),
        ];
    }
}
