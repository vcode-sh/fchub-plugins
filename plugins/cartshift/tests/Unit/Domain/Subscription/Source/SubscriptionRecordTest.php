<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription\Source;

use CartShift\Domain\Subscription\Source\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionContract;
use CartShift\Domain\Subscription\SubscriptionDates;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord as V1SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionRecordTest extends PluginTestCase
{
    public function testV1ContractBecomesOneCanonicalV2RecordWithoutInventingAbsentDates(): void
    {
        $record = SubscriptionRecord::fromV1($this->v1(), new SourceIdentity('shop-alpha', 'customer', '9'));
        $payload = $record->toArray();

        self::assertSame('shop-alpha:subscription:77', $record->identity->canonical());
        self::assertSame('shop-alpha:customer:9', $payload['customer_identity']);
        self::assertSame('shop-alpha:product:12', $payload['items'][0]['product_identity']);
        self::assertSame('shop-alpha:product:12:variation:13', $payload['items'][0]['variation_identity']);
        self::assertSame([
            'shop-alpha:customer:9',
            'shop-alpha:order:41',
            'shop-alpha:order:42',
            'shop-alpha:product:12',
        ], $payload['dependencies']);
        self::assertSame([
            'cancelled_utc' => null,
            'end_utc' => null,
            'next_payment_utc' => null,
            'start_utc' => '2026-01-02T03:04:05Z',
            'trial_end_utc' => null,
        ], $payload['schedule']);
        self::assertSame(2400, $payload['contract']['recurring_total']);
        self::assertSame('unassessed', $payload['payment_ownership']['ownership_state']);
        self::assertSame(['stripe_source_id' => 'pm_private_fixture'], $payload['payment_ownership']['payment_references']);
        self::assertStringNotContainsString('private@example.test', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testPrivatePaymentEvidenceChangesTheDigestButNeverTheStructuralIdentity(): void
    {
        $first = SubscriptionRecord::fromV1($this->v1(), new SourceIdentity('shop-alpha', 'customer', '9'))->envelope();
        $second = SubscriptionRecord::fromV1(
            $this->v1(['stripe_source_id' => 'pm_rotated_fixture']),
            new SourceIdentity('shop-alpha', 'customer', '9'),
        )->envelope();

        self::assertSame($first->structuralFingerprint, $second->structuralFingerprint);
        self::assertNotSame($first->privateContentDigest, $second->privateContentDigest);
    }

    public function testMissingTypedParentRelationshipIsBlockedInsteadOfInferredFromTheParentField(): void
    {
        $this->expectExceptionMessage('subscription_parent_relationship_missing');
        SubscriptionRecord::fromV1(
            $this->v1(relatedOrders: [new SubscriptionOrderReference(42, SubscriptionOrderReference::RENEWAL)]),
            new SourceIdentity('shop-alpha', 'customer', '9'),
        );
    }

    public function testCustomerIdentityMustStayInsideTheSameSourceNamespace(): void
    {
        $this->expectExceptionMessage('subscription_customer_identity_invalid');
        SubscriptionRecord::fromV1($this->v1(), new SourceIdentity('another-shop', 'customer', '9'));
    }

    /** @param array<string, string> $paymentReferences @param list<SubscriptionOrderReference>|null $relatedOrders */
    private function v1(
        array $paymentReferences = ['stripe_source_id' => 'pm_private_fixture'],
        ?array $relatedOrders = null,
    ): V1SubscriptionRecord {
        $contract = new SubscriptionContract(
            'month',
            1,
            'monthly',
            2000,
            400,
            2400,
            12,
            0,
            'day',
            0,
            ['finite_cycles_source' => 'declared'],
        );
        $record = new V1SubscriptionRecord(
            'shop-alpha',
            'subscription:77',
            77,
            'active',
            'PLN',
            'customer:9',
            9,
            'private@example.test',
            [],
            41,
            [[
                'source_item_id' => 501,
                'source_product_id' => 12,
                'source_variation_id' => 13,
                'pseudo_variation_key' => '13',
                'name' => 'Membership',
                'quantity' => 1,
                'line_total' => 2000,
                'line_tax' => 400,
            ]],
            $contract,
            'stripe',
            false,
            $paymentReferences,
            new SubscriptionDates('2026-01-02 03:04:05', null, null, null, null),
            $relatedOrders ?? [
                new SubscriptionOrderReference(41, SubscriptionOrderReference::PARENT),
                new SubscriptionOrderReference(42, SubscriptionOrderReference::RENEWAL),
            ],
            3,
            '',
        );
        return $record->withFingerprint(SubscriptionRecordFactory::digest($record->fingerprintPayload()));
    }
}
