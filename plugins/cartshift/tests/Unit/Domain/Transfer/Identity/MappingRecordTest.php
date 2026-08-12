<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class MappingRecordTest extends PluginTestCase
{
    public function testLegacyMappingPermitsNullFingerprints(): void
    {
        $record = new MappingRecord($this->identity(), 88, null, null, MapState::Legacy);

        self::assertTrue($record->isActive());
        self::assertSame(88, $record->targetId);
    }

    public function testV2MappingRequiresBothFingerprints(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MappingRecord($this->identity(), 88, '', str_repeat('b', 64), MapState::Staged);
    }

    public function testV2MappingRejectsUppercaseOrMalformedDigests(): void
    {
        foreach ([str_repeat('A', 64), str_repeat('a', 63), str_repeat('g', 64)] as $fingerprint) {
            try {
                new MappingRecord($this->identity(), 88, $fingerprint, str_repeat('b', 64), MapState::Claimed);
                self::fail('Malformed fingerprint was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNonLegacyMappingRequiresAPositiveTargetId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MappingRecord(
            $this->identity(),
            0,
            str_repeat('a', 64),
            str_repeat('b', 64),
            MapState::Reconciled,
        );
    }

    public function testRolledBackMappingIsInactiveAndAllOthersAreActive(): void
    {
        foreach (MapState::cases() as $state) {
            $fingerprint = $state === MapState::Legacy ? null : str_repeat('a', 64);
            $record = new MappingRecord($this->identity(), 88, $fingerprint, $fingerprint, $state);

            self::assertSame($state !== MapState::RolledBack, $record->isActive(), $state->value);
        }
    }

    private function identity(): SourceIdentity
    {
        return new SourceIdentity('lapka-web', 'product', '42');
    }
}
