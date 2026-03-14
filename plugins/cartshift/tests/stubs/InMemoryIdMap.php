<?php

declare(strict_types=1);

namespace CartShift\Tests\Stubs;

/**
 * In-memory test double for IdMapRepository.
 * The real class is final and can't be mocked by PHPUnit.
 */
final class InMemoryIdMap
{
    /** @var array<string, array<string, int>> */
    private array $map = [];

    public function store(
        string $entityType,
        string $wcId,
        int $fcId,
        string $migrationId = '',
        bool $createdByMigration = true,
    ): void {
        $this->map[$entityType][$wcId] = $fcId;
    }

    public function getFcId(string $entityType, string $wcId): int|null
    {
        return $this->map[$entityType][$wcId] ?? null;
    }

    public function getAllByEntityType(string $entityType, string|null $migrationId = null): array
    {
        $results = [];
        foreach ($this->map[$entityType] ?? [] as $wcId => $fcId) {
            $results[] = (object) ['wc_id' => $wcId, 'fc_id' => $fcId];
        }
        return $results;
    }

    public function getCreatedByMigration(string $entityType, string $migrationId): array
    {
        return $this->getAllByEntityType($entityType);
    }

    public function deleteByMigration(string $migrationId): void
    {
        $this->map = [];
    }

    public function deleteCreatedByMigration(string $migrationId): void
    {
        $this->map = [];
    }

    public function truncate(): void
    {
        $this->map = [];
    }
}
