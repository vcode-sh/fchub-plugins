<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordFingerprint;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SelectionMode;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferReasonCode;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferContractTest extends PluginTestCase
{
    public function testIdentityRejectsImplicitLocalAndUnsafeKeys(): void
    {
        foreach (['local', 'ab', 'Lapka-web', '-lapka', 'lapka web', str_repeat('a', 65)] as $sourceKey) {
            try {
                new SourceIdentity($sourceKey, 'order', '42');
                self::fail(sprintf('Source key %s was accepted.', $sourceKey));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testIdentityAcceptsOnlyKnownKindsAndStablePositiveIds(): void
    {
        self::assertSame(
            'lapka-web:customer:42:billing',
            (new SourceIdentity('lapka-web', 'customer', '42:billing'))->canonical(),
        );

        foreach (['', '0', '-1', '01', ' 42', '42 ', '42:', '42:Billing', 'uuid'] as $sourceId) {
            try {
                new SourceIdentity('lapka-web', 'order', $sourceId);
                self::fail(sprintf('Source ID %s was accepted.', $sourceId));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new SourceIdentity('lapka-web', 'invoice', '42');
    }

    public function testSelectionFingerprintIgnoresInputOrderButNotMembership(): void
    {
        $left = new TransferSelection(
            'lapka-web',
            SelectionClause::ids([9, 2]),
            SelectionClause::none(),
            SelectionClause::ids([8, 1]),
            SelectionClause::none(),
        );
        $right = new TransferSelection(
            'lapka-web',
            SelectionClause::ids([2, 9]),
            SelectionClause::none(),
            SelectionClause::ids([1, 8]),
            SelectionClause::none(),
        );
        $different = new TransferSelection(
            'lapka-web',
            SelectionClause::ids([2]),
            SelectionClause::none(),
            SelectionClause::ids([1, 8]),
            SelectionClause::none(),
        );

        self::assertSame([2, 9], $left->products->ids);
        self::assertSame($left->fingerprint(), $right->fingerprint());
        self::assertNotSame($left->fingerprint(), $different->fingerprint());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $left->fingerprint());
    }

    public function testEverySelectionKindAndModeIsExplicitInCanonicalForm(): void
    {
        $selection = new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::since('2026-08-10T08:09:10Z'),
            SelectionClause::ids([7]),
        );

        self::assertSame(
            [
                'source_key' => 'lapka-web',
                'products' => ['mode' => 'all'],
                'customers' => ['mode' => 'none'],
                'orders' => ['mode' => 'since', 'since' => '2026-08-10T08:09:10Z'],
                'subscriptions' => ['mode' => 'ids', 'ids' => [7]],
                'include_reverse_dependencies' => [],
            ],
            $selection->canonical(),
        );
    }

    public function testReverseDependenciesAreCanonicalAndChangeTheRootFingerprint(): void
    {
        $plain = new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        );
        $reverse = new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            ['subscription', 'order'],
        );

        self::assertSame(['order', 'subscription'], $reverse->reverseDependencies);
        self::assertNotSame($plain->fingerprint(), $reverse->fingerprint());

        $this->expectException(\InvalidArgumentException::class);
        new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
            ['product'],
        );
    }

    public function testSelectionClauseRejectsAmbiguousEmptyDuplicateAndInvalidValues(): void
    {
        $invalid = [
            static fn (): SelectionClause => new SelectionClause(SelectionMode::Ids, [], null),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::Ids, [1, 1], null),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::Ids, [1, 0], null),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::Ids, [1], '2026-01-01T00:00:00Z'),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::Since, [], null),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::Since, [1], '2026-01-01T00:00:00Z'),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::Since, [], '2026-02-30T00:00:00Z'),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::All, [1], null),
            static fn (): SelectionClause => new SelectionClause(SelectionMode::None, [], '2026-01-01T00:00:00Z'),
        ];

        foreach ($invalid as $factory) {
            try {
                $factory();
                self::fail('An ambiguous or invalid selection clause was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRecordEnvelopeSeparatesStructureFromPrivateContent(): void
    {
        $identity = new SourceIdentity('lapka-web', 'order', '42');
        $left = RecordEnvelope::forPayload(2, $identity, ['email' => 'first@example.test', 'total' => 1000]);
        $reordered = RecordEnvelope::forPayload(2, $identity, ['total' => 1000, 'email' => 'first@example.test']);
        $changed = RecordEnvelope::forPayload(2, $identity, ['email' => 'second@example.test', 'total' => 1000]);

        self::assertSame($left->structuralFingerprint, $changed->structuralFingerprint);
        self::assertSame($left->privateContentDigest, $reordered->privateContentDigest);
        self::assertNotSame($left->privateContentDigest, $changed->privateContentDigest);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $left->privateContentDigest);
    }

    public function testEnvelopeRejectsForgedFingerprints(): void
    {
        $identity = new SourceIdentity('lapka-web', 'product', '9');
        $valid = RecordEnvelope::forPayload(2, $identity, ['name' => 'Harness product']);

        $this->expectException(\InvalidArgumentException::class);
        new RecordEnvelope(
            2,
            $identity,
            $valid->structuralFingerprint,
            str_repeat('0', 64),
            $valid->payload,
        );
    }

    public function testEnvelopeRejectsNonPositiveSchemaVersionsBeforeHashing(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordEnvelope::forPayload(
            0,
            new SourceIdentity('lapka-web', 'product', '9'),
            ['name' => 'Harness product'],
        );
    }

    public function testEnvelopeRejectsListPayloadsThatCannotBeCanonicalRecords(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RecordEnvelope::forPayload(
            2,
            new SourceIdentity('lapka-web', 'product', '9'),
            ['not', 'a', 'record'],
        );
    }

    public function testPublicEvidenceIdentifierExcludesPrivatePayloadAndRequiresASecret(): void
    {
        $identity = new SourceIdentity('lapka-web', 'customer', '7');
        $left = RecordEnvelope::forPayload(2, $identity, ['email' => 'one@example.test']);
        $right = RecordEnvelope::forPayload(2, $identity, ['email' => 'two@example.test']);
        $secret = str_repeat('s', 32);

        self::assertSame(
            RecordFingerprint::publicEvidenceIdentifier($left, $secret),
            RecordFingerprint::publicEvidenceIdentifier($right, $secret),
        );
        self::assertNotSame(
            RecordFingerprint::publicEvidenceIdentifier($left, $secret),
            RecordFingerprint::publicEvidenceIdentifier($left, str_repeat('t', 32)),
        );

        $this->expectException(\InvalidArgumentException::class);
        RecordFingerprint::publicEvidenceIdentifier($left, 'short');
    }

    public function testCanonicalRecordKindOrderIsPinned(): void
    {
        self::assertSame(
            [
                'taxonomy_group',
                'taxonomy_term',
                'media_asset',
                'download_asset',
                'product',
                'customer',
                'order',
                'subscription',
            ],
            array_map(static fn (RecordKind $kind): string => $kind->value, RecordKind::cases()),
        );
    }

    public function testTransferReasonCodesAreUniqueStableAndExhaustive(): void
    {
        $expected = [
            'runtime_contract_mismatch',
            'source_key_invalid',
            'source_identity_conflict',
            'selection_drift',
            'selection_identity_missing',
            'product_census_duplicate',
            'order_census_duplicate',
            'subscription_census_duplicate',
            'source_census_unaccounted',
            'product_hydration_failed',
            'order_hydration_failed',
            'subscription_hydration_failed',
            'product_semantic_enumeration_mismatch',
            'product_lookup_missing',
            'product_lookup_stale',
            'order_item_parent_missing',
            'order_item_parent_type_mismatch',
            'record_fingerprint_mismatch',
            'unsupported_product_type',
            'unsupported_product_dependency',
            'subscription_schedule_absence',
            'unsupported_product_status',
            'unsupported_attribute_contract',
            'unsupported_tax_class',
            'unsupported_sale_schedule',
            'unsupported_stock_contract',
            'asset_missing',
            'asset_hash_mismatch',
            'unsupported_download_policy',
            'historical_product_missing',
            'order_money_mismatch',
            'order_tax_mismatch',
            'order_fee_mismatch',
            'charge_parent_missing',
            'refund_parent_ambiguous',
            'executable_payment_reference',
            'target_schema_unrepresentable',
            'target_write_failed',
            'target_reconciliation_failed',
        ];
        $actual = array_map(static fn (TransferReasonCode $code): string => $code->value, TransferReasonCode::cases());

        self::assertSame($expected, $actual);
        self::assertSame($actual, array_values(array_unique($actual)));

        foreach ($actual as $code) {
            self::assertMatchesRegularExpression('/\A[a-z][a-z0-9_]*\z/', $code);
        }
    }
}
