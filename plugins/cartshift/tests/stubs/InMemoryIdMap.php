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

    /** @var array<string, array<string, int>> Simulated realm, kept apart from the real one. */
    private array $simulatedMap = [];

    private bool $simulating = false;

    public function setSimulating(bool $simulating): void
    {
        $this->simulating = $simulating;
    }

    public function isSimulating(): bool
    {
        return $this->simulating;
    }

    public function purgeSimulated(): void
    {
        $this->simulatedMap = [];
    }

    public function store(
        string $entityType,
        string $wcId,
        int $fcId,
        string $migrationId = '',
        bool $createdByMigration = true,
    ): void {
        if ($this->simulating) {
            $this->simulatedMap[$entityType][$wcId] = $fcId;

            return;
        }

        $this->map[$entityType][$wcId] = $fcId;
    }

    public function getFcId(string $entityType, string $wcId): int|null
    {
        $real = $this->map[$entityType][$wcId] ?? null;

        if ($real !== null || !$this->simulating) {
            return $real;
        }

        return $this->simulatedMap[$entityType][$wcId] ?? null;
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

    /**
     * @return array<string, int> wc_id => fc_id
     */
    public function getMapForEntityType(string $entityType): array
    {
        $source = $this->simulating
            ? ($this->simulatedMap[$entityType] ?? []) + ($this->map[$entityType] ?? [])
            : ($this->map[$entityType] ?? []);

        $map = [];
        foreach ($source as $wcId => $fcId) {
            $map[(string) $wcId] = $fcId;
        }
        return $map;
    }

    public function deleteByMigration(string $migrationId, bool|null $simulated = null): void
    {
        if ($simulated !== true) {
            $this->map = [];
        }

        if ($simulated !== false) {
            $this->simulatedMap = [];
        }
    }

    public function deleteCreatedByMigration(string $migrationId): void
    {
        $this->map = [];
    }

    public function truncate(): void
    {
        $this->map = [];
        $this->simulatedMap = [];
    }

    public function flushMemo(): void
    {
        // No memo layer — every read is already in memory.
    }
}
