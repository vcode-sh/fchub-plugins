<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\Customer\CustomerAddressRecord;
use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class CustomerRecordTest extends PluginTestCase
{
    public function testRegisteredAndGuestIdentityNeverUsesEmailAsPrimaryIdentity(): void
    {
        $registered = $this->record(new SourceIdentity('shop-alpha', 'customer', '7'), 7, 'registered');
        $guest = $this->record(new SourceIdentity('shop-alpha', 'customer', '41:guest'), null, 'guest');

        self::assertSame('shop-alpha:customer:7', $registered->identity->canonical());
        self::assertSame('shop-alpha:customer:41:guest', $guest->identity->canonical());
        self::assertSame(hash('sha256', 'ada@example.test'), $registered->normalizedEmailDigest);
        self::assertStringNotContainsString('ada@example.test', $registered->identity->canonical());
    }

    public function testEnvelopeCarriesRequiredFieldsButNoAccountSecretsOrCapabilities(): void
    {
        $record = $this->record(new SourceIdentity('shop-alpha', 'customer', '7'), 7, 'registered');
        $json = json_encode($record->envelope()->payload, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('create_no_account', $json);
        foreach (['password', 'capabilities', 'session', 'api_key', 'recovery'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($json));
        }
        self::assertNotSame($record->addresses[0]->identity, $record->identity);
    }

    private function record(SourceIdentity $identity, ?int $sourceUserId, string $classification): CustomerRecord
    {
        $address = new CustomerAddressRecord(
            new SourceIdentity('shop-alpha', 'customer', $identity->sourceId . ':billing'), 'billing', true, 'active', 'Billing',
            'Ada Lovelace', 'Analytical Engines', '1 Logic Lane', '', 'London', '', 'N1', 'GB', '+44', 'ada@example.test',
        );
        return CustomerRecord::create(
            $identity, $sourceUserId, $classification, 'Ada', 'Lovelace', 'ADA@Example.Test ', 'active',
            [$address], '2020-01-01T00:00:00Z', '2024-01-01T00:00:00Z',
            ['origin' => $classification === 'guest' ? 'order_snapshot' : 'source_user'], [],
        );
    }
}
