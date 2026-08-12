<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Order;

use CartShift\Domain\Transfer\SourceRecordException;

defined('ABSPATH') || exit;

final readonly class AddressProjection
{
    /** @param array<string, mixed> $row @param array<string, scalar> $businessInfo @param list<string> $sourceFieldPresence */
    private function __construct(
        public array $row,
        public array $businessInfo,
        public array $sourceFieldPresence,
    ) {
    }

    public static function project(AddressRecord $source): ?self
    {
        $values = [
            'first_name' => trim($source->firstName),
            'last_name' => trim($source->lastName),
            'company' => trim($source->company),
            'address_1' => trim($source->address1),
            'address_2' => trim($source->address2),
            'city' => trim($source->city),
            'state' => trim($source->state),
            'postcode' => trim($source->postcode),
            'country' => strtoupper(trim($source->country)),
            'email' => trim($source->email),
            'phone' => trim($source->phone),
            'business_tax_id' => trim($source->businessTaxId),
        ];
        $presence = array_keys(array_filter($values, static fn (string $value): bool => $value !== ''));
        if ($presence === []) {
            return null;
        }

        $otherData = [];
        if ($values['phone'] !== '') {
            $otherData['phone'] = $values['phone'];
        }
        if ($values['company'] !== '') {
            $otherData['company_name'] = $values['company'];
        }
        $businessInfo = [];
        if ($values['business_tax_id'] !== '') {
            if ($source->type !== 'billing' || $values['country'] !== 'PL') {
                throw new SourceRecordException(
                    'target_schema_unrepresentable',
                    'Business tax ID has no installed validation contract for this address country/type.',
                );
            }
            $taxId = self::polishNip($values['business_tax_id']);
            $otherData['vat_number'] = $taxId;
            $otherData['nip'] = $taxId;
            $businessInfo = [
                'tax_number' => $taxId,
                'tax_number_validated' => true,
                'tax_number_name' => $values['company'],
            ];
        }

        return new self([
            'type' => $source->type,
            'name' => trim($values['first_name'] . ' ' . $values['last_name']),
            'address_1' => $values['address_1'],
            'address_2' => $values['address_2'],
            'city' => $values['city'],
            'state' => $values['state'],
            'postcode' => $values['postcode'],
            'country' => $values['country'],
            'meta' => $otherData === [] ? [] : ['other_data' => $otherData],
            'source_identity' => $source->identity->canonical(),
        ], $businessInfo, $presence);
    }

    public function reconcilesBusinessTaxId(): bool
    {
        if ($this->businessInfo === []) {
            return !isset($this->row['meta']['other_data']['vat_number'])
                && !isset($this->row['meta']['other_data']['nip']);
        }
        $tax = (string) $this->businessInfo['tax_number'];
        return ($this->row['meta']['other_data']['vat_number'] ?? null) === $tax
            && ($this->row['meta']['other_data']['nip'] ?? null) === $tax;
    }

    private static function polishNip(string $value): string
    {
        $value = strtoupper(preg_replace('/[\s-]+/', '', $value) ?? '');
        if (str_starts_with($value, 'PL')) {
            $value = substr($value, 2);
        }
        if (preg_match('/\A\d{10}\z/D', $value) !== 1) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Polish NIP has an invalid shape.');
        }
        $weights = [6, 5, 7, 2, 3, 4, 5, 6, 7];
        $sum = 0;
        foreach ($weights as $index => $weight) {
            $sum += ((int) $value[$index]) * $weight;
        }
        if ($sum % 11 === 10 || $sum % 11 !== (int) $value[9]) {
            throw new SourceRecordException('target_schema_unrepresentable', 'Polish NIP checksum is invalid.');
        }
        return $value;
    }
}
