<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Grant;

use FChubMemberships\Domain\Grant\GrantMaintenanceService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Storage\GrantSourceRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * Extending access used to leave no trace, which made the audit trail claim
 * nothing had happened between a grant and its new expiry.
 */
final class GrantExtensionAuditTest extends PluginTestCase
{
    public function test_an_extension_records_the_old_and_the_new_expiry(): void
    {
        $service = new GrantMaintenanceService($this->grants(), new GrantSourceRepository());

        $extended = $service->extendExpiry(9, 5, '2026-12-31 00:00:00');

        self::assertSame(2, $extended);
        $records = $this->auditRecords();
        self::assertCount(2, $records);
        self::assertSame('extended', $records[0]['action']);
        self::assertSame('{"expires_at":"2026-09-12 00:00:00"}', $records[0]['old_value']);
        self::assertSame('{"expires_at":"2026-12-31 00:00:00"}', $records[0]['new_value']);
    }

    /** @return list<array<string, mixed>> */
    private function auditRecords(): array
    {
        return array_values(array_map(
            static fn(array $entry): array => $entry[2],
            array_filter(
                $GLOBALS['_fchub_test_queries'],
                static fn(array $entry): bool => $entry[0] === 'insert'
                    && str_contains((string) $entry[1], 'audit_log')
            )
        ));
    }

    private function grants(): GrantRepository
    {
        return new class extends GrantRepository {
            public function __construct()
            {
            }

            public function getByUserId(int $userId, array $filters = []): array
            {
                return [
                    ['id' => 10, 'expires_at' => '2026-09-12 00:00:00', 'source_ids' => [], 'source_type' => 'order'],
                    ['id' => 11, 'expires_at' => '2026-09-12 00:00:00', 'source_ids' => [], 'source_type' => 'order'],
                ];
            }

            public function update(int $id, array $data): bool
            {
                return true;
            }
        };
    }
}
