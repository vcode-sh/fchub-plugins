<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Storage;

use FChubMemberships\Storage\FluentCrmProjectionStateRepository;
use PHPUnit\Framework\TestCase;

final class FluentCrmProjectionStateRepositoryTest extends TestCase
{
    public function test_missing_state_returns_the_empty_ownership_contract(): void
    {
        $repository = new FluentCrmProjectionStateRepository(
            static fn(int $userId, string $key): mixed => '',
            static fn(int $userId, string $key, array $state): bool => true
        );

        self::assertSame([
            'contact_id' => 0,
            'tag_ids' => [],
            'list_ids' => [],
        ], $repository->get(21));
    }

    public function test_get_normalises_persisted_ids_without_claiming_invalid_values(): void
    {
        $repository = new FluentCrmProjectionStateRepository(
            static fn(int $userId, string $key): mixed => [
                'contact_id' => '44',
                'tag_ids' => ['9', 3, 9, 0, -2, 'nope'],
                'list_ids' => [8, '4', 8, null],
            ],
            static fn(int $userId, string $key, array $state): bool => true
        );

        self::assertSame([
            'contact_id' => 44,
            'tag_ids' => [3, 9],
            'list_ids' => [4, 8],
        ], $repository->get(21));
    }

    public function test_save_persists_only_the_normalised_ownership_shape(): void
    {
        $written = null;
        $repository = new FluentCrmProjectionStateRepository(
            static fn(int $userId, string $key): mixed => '',
            static function (int $userId, string $key, array $state) use (&$written): bool {
                $written = [$userId, $key, $state];
                return true;
            }
        );

        self::assertTrue($repository->save(21, [
            'contact_id' => 44,
            'tag_ids' => [9, 3, 9],
            'list_ids' => [8, 4, 8],
            'untrusted' => ['anything'],
        ]));
        self::assertSame([
            21,
            '_fchub_memberships_fluentcrm_projection',
            ['contact_id' => 44, 'tag_ids' => [3, 9], 'list_ids' => [4, 8]],
        ], $written);
    }

    public function test_save_rejects_invalid_user_ids_without_writing_meta(): void
    {
        $writes = 0;
        $repository = new FluentCrmProjectionStateRepository(
            static fn(int $userId, string $key): mixed => '',
            static function () use (&$writes): bool {
                $writes++;
                return true;
            }
        );

        self::assertFalse($repository->save(0, ['contact_id' => 44]));
        self::assertSame(0, $writes);
    }
}
