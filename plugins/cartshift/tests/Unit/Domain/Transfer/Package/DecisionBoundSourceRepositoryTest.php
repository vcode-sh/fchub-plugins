<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Package\DecisionBoundSourceRepository;
use CartShift\Domain\Transfer\Package\SourceRootCensus;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class DecisionBoundSourceRepositoryTest extends PluginTestCase
{
    public function testOnlyRecordLevelAuditExclusionsRemoveRootsAndSkippedRecordsNeverHydrate(): void
    {
        $identities = [
            new SourceIdentity('shop-alpha', 'product', '9'),
            new SourceIdentity('shop-alpha', 'order', '41'),
            new SourceIdentity('shop-alpha', 'order', '42'),
            new SourceIdentity('shop-alpha', 'customer', '7'),
        ];
        $loads = [];
        $repository = new DecisionBoundSourceRepository(
            $this->selection(),
            TransferDecisionSet::fromArray([
                $this->finding('shop-alpha:product:9', 'unsupported_product_type'),
                $this->finding('shop-alpha:order:41', 'order_money_mismatch'),
                $this->finding('shop-alpha:order:42:item:99', 'order_item_parent_missing'),
            ]),
            $this->census($identities),
            $this->loaders($loads),
        );

        $roots = iterator_to_array($repository->roots(), false);

        self::assertSame(
            ['shop-alpha:customer:7', 'shop-alpha:order:42'],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), $roots),
        );
        self::assertSame(['shop-alpha:customer:7', 'shop-alpha:order:42'], $loads);
        self::assertNull($repository->lookup(new SourceIdentity('shop-alpha', 'order', '41')));
        self::assertSame(['shop-alpha:customer:7', 'shop-alpha:order:42'], $loads);
    }

    public function testDependencyLoaderCannotReturnARecordForAnotherIdentityOrNamespace(): void
    {
        $requested = new SourceIdentity('shop-alpha', 'product', '9');
        $repository = new DecisionBoundSourceRepository(
            $this->selection(),
            TransferDecisionSet::empty(),
            $this->census([]),
            ['product' => static fn (): RecordEnvelope => self::record('shop-alpha', 'product', '10')],
        );

        try {
            $repository->lookup($requested);
            self::fail('A dependency loader returned a different source identity.');
        } catch (SourceRecordException $exception) {
            self::assertSame('dependency_ambiguous', $exception->reasonCode);
        }
    }

    public function testDuplicateRootIdentityIsAStopInsteadOfAConvenientDeduplication(): void
    {
        $identity = new SourceIdentity('shop-alpha', 'product', '9');
        $repository = new DecisionBoundSourceRepository(
            $this->selection(),
            TransferDecisionSet::empty(),
            $this->census([$identity, $identity]),
            ['product' => static fn (): RecordEnvelope => self::record('shop-alpha', 'product', '9')],
        );

        $this->expectExceptionObject(new SourceRecordException(
            'product_source_identity_duplicate',
            'Source root census returned a duplicate identity.',
        ));
        iterator_to_array($repository->roots(), false);
    }

    /** @param list<SourceIdentity> $identities */
    private function census(array $identities): SourceRootCensus
    {
        return new class($identities) implements SourceRootCensus {
            /** @param list<SourceIdentity> $identities */
            public function __construct(private readonly array $identities) {}
            public function identities(TransferSelection $selection): iterable { yield from $this->identities; }
        };
    }

    /** @param list<string> $loads @return array<string, callable(SourceIdentity): ?RecordEnvelope> */
    private function loaders(array &$loads): array
    {
        $loader = static function (SourceIdentity $identity) use (&$loads): RecordEnvelope {
            $loads[] = $identity->canonical();
            return self::record($identity->sourceKey, $identity->entityType, $identity->sourceId);
        };

        return ['product' => $loader, 'customer' => $loader, 'order' => $loader, 'subscription' => $loader];
    }

    /** @return array<string, mixed> */
    private function finding(string $identity, string $code): array
    {
        return [
            'scope' => 'audit_finding',
            'identity' => $identity,
            'finding_code' => $code,
            'action' => 'excluded_by_policy',
            'source_fingerprint' => hash('sha256', $identity . '|' . $code),
            'operator' => 'owner',
            'reason' => 'Exact source anomaly excluded.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ];
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection(
            'shop-alpha',
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
            SelectionClause::all(),
        );
    }

    private static function record(string $sourceKey, string $kind, string $sourceId): RecordEnvelope
    {
        $identity = new SourceIdentity($sourceKey, $kind, $sourceId);
        return RecordEnvelope::forPayload(2, $identity, [
            'identity' => $identity->canonical(),
            'dependencies' => [],
        ]);
    }
}
