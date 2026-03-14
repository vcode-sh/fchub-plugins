<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Support;

use FChubMemberships\Support\PlanStatus;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class PlanStatusTest extends PluginTestCase
{
    public function test_normalize_maps_legacy_draft_to_inactive(): void
    {
        self::assertSame(PlanStatus::INACTIVE, PlanStatus::normalize('draft'));
        self::assertSame(PlanStatus::INACTIVE, PlanStatus::normalizeNullable('draft'));
    }

    public function test_normalize_rejects_invalid_values_and_uses_fallback(): void
    {
        self::assertSame(PlanStatus::ACTIVE, PlanStatus::normalize('bogus'));
        self::assertNull(PlanStatus::normalizeNullable('bogus'));
        self::assertSame(PlanStatus::ARCHIVED, PlanStatus::normalize('bogus', PlanStatus::ARCHIVED));
    }

    public function test_is_valid_accepts_current_and_legacy_aliases_only(): void
    {
        self::assertTrue(PlanStatus::isValid('active'));
        self::assertTrue(PlanStatus::isValid('inactive'));
        self::assertTrue(PlanStatus::isValid('archived'));
        self::assertTrue(PlanStatus::isValid('draft'));
        self::assertFalse(PlanStatus::isValid(''));
        self::assertFalse(PlanStatus::isValid('deleted'));
    }
}
