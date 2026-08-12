<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Customer;

use CartShift\Domain\Transfer\Customer\WooCustomerRecordSource;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class WooCustomerRecordSourceTest extends PluginTestCase
{
    public function testRegisteredCustomersAreCanonicalSortedAndNeverExportAccountSecrets(): void
    {
        $reader = static fn (SelectionClause $clause): array => [
            self::registered(9, 'same@example.test', ['password_hash' => 'forbidden']),
            self::registered(2, 'SAME@example.test', ['capabilities' => ['administrator']]),
        ];
        $source = new WooCustomerRecordSource($reader, static fn (): null => null);
        $records = iterator_to_array($source->records($this->selection([2, 9])));

        self::assertSame(['shop-alpha:customer:2', 'shop-alpha:customer:9'], array_map(static fn ($r): string => $r->identity->canonical(), $records));
        self::assertSame($records[0]->payload['normalized_email_digest'], $records[1]->payload['normalized_email_digest']);
        $json = json_encode($records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('forbidden', $json);
        self::assertStringNotContainsString('administrator', $json);
    }

    public function testGuestIdentityIsOrderScopedAndBillingShippingSnapshotsRemainDistinct(): void
    {
        $source = new WooCustomerRecordSource(static fn (): array => [], static fn (int $orderId): array => [
            'order_id' => $orderId, 'email' => 'guest@example.test', 'first_name' => 'Guest', 'last_name' => 'Buyer',
            'created_utc' => '2024-01-01T00:00:00Z',
            'billing' => self::address('billing', 'Billing City'),
            'shipping' => self::address('shipping', 'Shipping City'),
        ]);
        $record = $source->record(new SourceIdentity('shop-alpha', 'customer', '41:guest'));

        self::assertSame('guest', $record->payload['classification']);
        self::assertSame(['Billing City', 'Shipping City'], array_column($record->payload['addresses'], 'city'));
        self::assertSame(['shop-alpha:customer:41:guest:billing', 'shop-alpha:customer:41:guest:shipping'], array_column($record->payload['addresses'], 'identity'));
    }

    public function testExplicitRegisteredSelectionMissingOneUserBlocksRatherThanShrinking(): void
    {
        $source = new WooCustomerRecordSource(static fn (): array => [self::registered(2, 'a@example.test')], static fn (): null => null);
        $this->expectException(SourceRecordException::class);
        iterator_to_array($source->records($this->selection([2, 9])));
    }

    /** @param list<int> $ids */
    private function selection(array $ids): TransferSelection
    {
        return new TransferSelection('shop-alpha', SelectionClause::none(), SelectionClause::ids($ids), SelectionClause::none(), SelectionClause::none());
    }

    /** @param array<string, mixed> $extra */
    private static function registered(int $id, string $email, array $extra = []): array
    {
        return $extra + ['user_id' => $id, 'email' => $email, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'active', 'created_utc' => '2020-01-01T00:00:00Z', 'updated_utc' => null, 'billing' => self::address('billing', 'London'), 'shipping' => []];
    }

    /** @return array<string, string> */
    private static function address(string $type, string $city): array
    {
        return ['type' => $type, 'name' => 'Ada Lovelace', 'company' => '', 'address_1' => '1 Logic Lane', 'address_2' => '', 'city' => $city, 'state' => '', 'postcode' => 'N1', 'country' => 'GB', 'phone' => '+44', 'email' => 'ada@example.test'];
    }
}
