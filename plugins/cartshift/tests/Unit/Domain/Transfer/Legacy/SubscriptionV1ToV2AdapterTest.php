<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Legacy;

use CartShift\Domain\Subscription\Source\SubscriptionRecord;
use CartShift\Domain\Subscription\CustomerRecord as V1CustomerRecord;
use CartShift\Domain\Subscription\OrderRecord as V1OrderRecord;
use CartShift\Domain\Subscription\ProductRecord as V1ProductRecord;
use CartShift\Domain\Subscription\SubscriptionContract;
use CartShift\Domain\Subscription\SubscriptionDates;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionRecord as V1SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Domain\Transfer\Legacy\SubscriptionV1ToV2Adapter;
use CartShift\Domain\Transfer\Customer\CustomerRecord as V2CustomerRecord;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;
use CartShift\Tests\Unit\PluginTestCase;

final class SubscriptionV1ToV2AdapterTest extends PluginTestCase
{
    public function testRegisteredLiveAndV1PathsProduceByteIdenticalCanonicalSubscriptionRecords(): void
    {
        $v1 = $this->record(9);
        $live = SubscriptionRecord::fromV1($v1, new SourceIdentity('shop-alpha', 'customer', '9'))->envelope();

        $result = (new SubscriptionV1ToV2Adapter())->convert([$v1]);

        self::assertTrue($result['ok']);
        self::assertSame([], $result['failures']);
        self::assertCount(1, $result['records']);
        self::assertSame($live->privateContentDigest, $result['records'][0]->privateContentDigest);
        self::assertSame(CanonicalJson::encode($live->payload), CanonicalJson::encode($result['records'][0]->payload));
    }

    public function testGuestCannotBeSilentlyRekeyedFromEmailHashToOrderScope(): void
    {
        $v1 = $this->record(null);
        $adapter = new SubscriptionV1ToV2Adapter();

        $blocked = $adapter->convert([$v1]);

        self::assertFalse($blocked['ok']);
        self::assertSame([[
            'code' => 'blocked_subscription_v1_conversion',
            'identity' => 'shop-alpha:subscription:77',
            'field' => 'customer_identity',
            'reason' => 'guest_identity_rekey_unproven',
        ]], $blocked['failures']);
        self::assertSame([], $blocked['records']);

        $proof = [
            'mode' => 'exact_map',
            'customer_identity' => 'shop-alpha:customer:41:guest',
            'target_customer_id' => 9001,
            'target_fingerprint' => str_repeat('a', 64),
            'dependent_fingerprints' => ['subscription:77' => $v1->fingerprint],
            'decision_fingerprint' => null,
        ];
        $converted = $adapter->convert([$v1], ['subscription:77' => $proof]);

        self::assertTrue($converted['ok']);
        self::assertSame('shop-alpha:customer:41:guest', $converted['records'][0]->payload['customer_identity']);

        $proof['dependent_fingerprints']['subscription:77'] = str_repeat('f', 64);
        self::assertSame('guest_dependent_fingerprint_changed', $adapter->convert([$v1], ['subscription:77' => $proof])['failures'][0]['reason']);
    }

    public function testCompletedV1ReceiptsRemainExternalEvidenceAndAreNeverScheduledAsNewRecords(): void
    {
        $v1 = $this->record(9);
        $evidence = [
            'action' => 'created',
            'source_identity' => 'shop-alpha:subscription:77',
            'source_fingerprint' => $v1->fingerprint,
            'target_id' => 7001,
            'target_fingerprint' => str_repeat('b', 64),
            'state' => 'completed',
        ];
        $evidence['evidence_fingerprint'] = CanonicalJson::fingerprint($evidence);

        $result = (new SubscriptionV1ToV2Adapter())->convert([$v1], [], [$evidence]);

        self::assertTrue($result['ok']);
        self::assertCount(1, $result['records']);
        self::assertSame([$evidence], $result['external_evidence']);
        self::assertArrayNotHasKey('external_evidence', $result['records'][0]->payload);

        $evidence['target_id'] = 7002;
        $blocked = (new SubscriptionV1ToV2Adapter())->convert([$v1], [], [$evidence]);
        self::assertFalse($blocked['ok']);
        self::assertSame('external_evidence_fingerprint_changed', $blocked['failures'][0]['reason']);
    }

    public function testThinV1CustomerCanOnlyAdoptAFullCanonicalRecordWithFingerprintBoundFieldWitnesses(): void
    {
        $v1 = new V1CustomerRecord(
            'shop-alpha',
            'customer:9',
            9,
            'person@example.test',
            ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
            '',
        );
        $v1 = $v1->withFingerprint(CanonicalJson::fingerprint($v1->fingerprintPayload()));
        $v2 = V2CustomerRecord::create(
            new SourceIdentity('shop-alpha', 'customer', '9'),
            9,
            'registered',
            'Ada',
            'Lovelace',
            'person@example.test',
            'active',
            [],
            null,
            null,
            ['source' => 'woocommerce'],
            [],
        )->envelope();
        $proof = [
            'source_identity' => 'shop-alpha:customer:9',
            'v1_fingerprint' => $v1->fingerprint,
            'v2_private_digest' => $v2->privateContentDigest,
            'field_witnesses' => ['billing_identity', 'email', 'identity'],
        ];
        $proof['decision_fingerprint'] = CanonicalJson::fingerprint($proof);

        $result = (new SubscriptionV1ToV2Adapter())->convertDataset(
            [$v1],
            ['shop-alpha:customer:9' => $v2],
            ['shop-alpha:customer:9' => $proof],
        );

        self::assertTrue($result['ok']);
        self::assertSame($v2->privateContentDigest, $result['records'][0]->privateContentDigest);

        $proof['v2_private_digest'] = str_repeat('f', 64);
        self::assertSame(
            'canonical_v2_record_changed',
            (new SubscriptionV1ToV2Adapter())->convertDataset(
                [$v1],
                ['shop-alpha:customer:9' => $v2],
                ['shop-alpha:customer:9' => $proof],
            )['failures'][0]['reason'],
        );
    }

    public function testThinProductAndOrderNeverGrowInventedV2Fields(): void
    {
        $product = new V1ProductRecord('shop-alpha', 'product:12', 12, 'subscription', 'Membership', 'MEM-12', [[
            'pseudo_variation_key' => '13',
        ]], str_repeat('a', 64));
        $order = new V1OrderRecord(
            'shop-alpha', 'order:41', 41, 'completed', 'PLN', 'customer:9', 'person@example.test',
            [], [], [], ['total' => 2400], ['created_utc' => '2026-01-02 03:04:05'], str_repeat('b', 64),
        );

        $result = (new SubscriptionV1ToV2Adapter())->convertDataset([$product, $order]);

        self::assertFalse($result['ok']);
        self::assertSame([], $result['records']);
        self::assertSame([
            ['identity' => 'shop-alpha:product:12', 'reason' => 'canonical_v2_record_missing'],
            ['identity' => 'shop-alpha:order:41', 'reason' => 'canonical_v2_record_missing'],
        ], array_map(
            static fn (array $failure): array => [
                'identity' => $failure['identity'],
                'reason' => $failure['reason'],
            ],
            $result['failures'],
        ));
    }

    private function record(?int $customerId): V1SubscriptionRecord
    {
        $contract = new SubscriptionContract('month', 1, 'monthly', 2000, 400, 2400, 12, 0, 'day', 0, ['finite_cycles_source' => 'declared']);
        $record = new V1SubscriptionRecord(
            'shop-alpha', 'subscription:77', 77, 'active', 'PLN',
            $customerId === null ? SubscriptionRecordFactory::guestRef('private@example.test') : 'customer:' . $customerId,
            $customerId, 'private@example.test', [], 41,
            [[
                'source_item_id' => 501, 'source_product_id' => 12, 'source_variation_id' => 13,
                'pseudo_variation_key' => '13', 'name' => 'Membership', 'quantity' => 1,
                'line_total' => 2000, 'line_tax' => 400,
            ]],
            $contract, 'stripe', false, ['stripe_source_id' => 'pm_private_fixture'],
            new SubscriptionDates('2026-01-02 03:04:05', null, null, null, null),
            [
                new SubscriptionOrderReference(41, SubscriptionOrderReference::PARENT),
                new SubscriptionOrderReference(42, SubscriptionOrderReference::RENEWAL),
            ],
            3,
            '',
        );
        return $record->withFingerprint(SubscriptionRecordFactory::digest($record->fingerprintPayload()));
    }
}
