<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Source;

use CartShift\Domain\Subscription\Source\WooSubscriptionRecordSource;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 4) . '/stubs/EntityMigratorStubs.php';

final class WooSubscriptionRecordSourceTest extends PluginTestCase
{
    public function testSourceReadsEveryWcsRelationshipTypeSeparatelyAndSortsCanonicalRecords(): void
    {
        $second = new V2FakeWcsSubscription(2, 102, 9);
        $first = new V2FakeWcsSubscription(1, 101, 0);
        $source = new WooSubscriptionRecordSource(static fn (): iterable => [$second, $first]);
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::ids([1, 2]),
        );

        $records = iterator_to_array($source->records($selection), false);

        self::assertSame(['shop-alpha:subscription:1', 'shop-alpha:subscription:2'], array_map(
            static fn ($record): string => $record->identity->canonical(),
            $records,
        ));
        self::assertSame('shop-alpha:customer:101:guest', $records[0]->payload['customer_identity']);
        self::assertSame('shop-alpha:customer:9', $records[1]->payload['customer_identity']);
        self::assertSame(['parent', 'renewal', 'switch', 'resubscribe'], $first->relationshipCalls);
        self::assertSame(['parent', 'renewal', 'switch', 'resubscribe'], $second->relationshipCalls);
        self::assertNull($records[0]->payload['schedule']['next_payment_utc']);
    }

    public function testExplicitSelectionMustHydrateEverySubscriptionExactlyOnce(): void
    {
        $source = new WooSubscriptionRecordSource(static fn (): iterable => [new V2FakeWcsSubscription(1, 101, 9)]);
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::ids([1, 2]),
        );

        $this->expectExceptionMessage('selection_identity_missing');
        iterator_to_array($source->records($selection), false);
    }

    public function testLoadedSourceEnumeratesAuthoritativeHposIdsWithoutPluralApiFiltering(): void
    {
        $first = new V2FakeWcsSubscription(1, 101, 9);
        $second = new V2FakeWcsSubscription(2, 102, 9);
        $GLOBALS['_cartshift_test_wcs_pages'] = [$first, $second];
        $GLOBALS['_cartshift_test_options']['woocommerce_custom_orders_table_enabled'] = 'yes';
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array =>
            str_contains($query, "type = 'shop_subscription'") ? [2, 1] : [];
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::all(),
        );

        $records = iterator_to_array((new WooSubscriptionRecordSource())->records($selection), false);

        self::assertSame(
            ['shop-alpha:subscription:1', 'shop-alpha:subscription:2'],
            array_map(static fn ($record): string => $record->identity->canonical(), $records),
        );
        self::assertSame(0, $GLOBALS['_cartshift_test_wcs_query_count'] ?? 0);
    }
}

final class V2FakeWcsSubscription
{
    /** @var list<string> */
    public array $relationshipCalls = [];

    public function __construct(
        private readonly int $id,
        private readonly int $parentId,
        private readonly int $customerId,
    ) {}

    public function get_id(): int { return $this->id; }
    public function get_status(): string { return 'active'; }
    public function get_currency(): string { return 'PLN'; }
    public function get_customer_id(): int { return $this->customerId; }
    public function get_billing_email(): string { return 'private-' . $this->id . '@example.test'; }
    public function get_parent_id(): int { return $this->parentId; }
    public function get_items(): array { return [500 + $this->id => new V2FakeWcsItem()]; }
    public function get_total(): string { return '24.00'; }
    public function get_total_tax(): string { return '4.00'; }
    public function get_billing_period(): string { return 'month'; }
    public function get_billing_interval(): int { return 1; }
    public function get_payment_method(): string { return 'stripe'; }
    public function get_requires_manual_renewal(): bool { return false; }
    public function get_payment_count(): int { return 3; }
    public function get_date(string $type): string|int { return $type === 'start' ? '2026-01-02 03:04:05' : 0; }
    public function get_meta(string $key): string
    {
        return match ($key) {
            '_subscription_length' => '12',
            '_stripe_customer_id' => 'cus_private_fixture',
            '_stripe_source_id' => 'pm_private_fixture',
            default => '',
        };
    }
    public function get_related_orders(string $return, string $relationship): array
    {
        $this->relationshipCalls[] = $relationship;
        return match ($relationship) {
            'parent' => [$this->parentId],
            'renewal' => [$this->parentId + 1000],
            default => [],
        };
    }
    public function get_billing_first_name(): string { return 'Private'; }
    public function get_billing_last_name(): string { return 'Fixture'; }
    public function get_billing_company(): string { return ''; }
    public function get_billing_address_1(): string { return ''; }
    public function get_billing_address_2(): string { return ''; }
    public function get_billing_city(): string { return ''; }
    public function get_billing_state(): string { return ''; }
    public function get_billing_postcode(): string { return ''; }
    public function get_billing_country(): string { return 'PL'; }
    public function get_billing_phone(): string { return ''; }
}

final class V2FakeWcsItem
{
    public function get_product_id(): int { return 12; }
    public function get_variation_id(): int { return 13; }
    public function get_name(): string { return 'Membership'; }
    public function get_quantity(): int { return 1; }
    public function get_total(): string { return '20.00'; }
    public function get_total_tax(): string { return '4.00'; }
}
