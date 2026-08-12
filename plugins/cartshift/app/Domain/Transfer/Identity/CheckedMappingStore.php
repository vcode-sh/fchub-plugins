<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Identity;

use CartShift\Domain\Transfer\SourceIdentity;

defined('ABSPATH') || exit;

interface CheckedMappingStore
{
    public function get(SourceIdentity $identity): ?MappingRecord;

    public function storeOrThrow(
        SourceIdentity $identity,
        int $targetId,
        string $migrationId,
        string $sourceFingerprint,
        string $targetFingerprint,
        MapState $state,
        bool $createdByMigration,
        int $generation = 1,
    ): MappingRecord;

    public function transitionOrThrow(
        SourceIdentity $identity,
        MapState $expected,
        MapState $next,
        string $expectedTargetFingerprint,
        string $nextTargetFingerprint,
    ): MappingRecord;
}
