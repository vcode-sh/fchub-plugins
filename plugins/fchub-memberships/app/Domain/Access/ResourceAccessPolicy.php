<?php

namespace FChubMemberships\Domain\Access;

defined('ABSPATH') || exit;

final class ResourceAccessPolicy
{
    /** @var array<int, list<array<string, mixed>>> */
    private array $planPaths = [];

    private bool $anyActivePlan = false;

    public function __construct(
        private string $provider,
        private string $resourceType,
        private string $resourceId
    ) {
    }

    public function provider(): string
    {
        return $this->provider;
    }

    public function resourceType(): string
    {
        return $this->resourceType;
    }

    public function resourceId(): string
    {
        return $this->resourceId;
    }

    public function allowAnyActivePlan(): void
    {
        $this->anyActivePlan = true;
    }

    public function allowsAnyActivePlan(): bool
    {
        return $this->anyActivePlan;
    }

    /**
     * @param array{provider: string, resource_type: string, resource_id: string}|null $qualifier
     */
    public function addPlanPath(
        int $planId,
        ?array $dripRule,
        string $basis = 'membership',
        ?array $qualifier = null
    ): void
    {
        if (!in_array($basis, ['membership', 'resource'], true)) {
            throw new \InvalidArgumentException('Resource access path basis is invalid.');
        }
        if ($basis === 'resource' && $qualifier === null) {
            throw new \InvalidArgumentException('Resource access paths require a qualifying resource.');
        }

        $path = array_merge($this->normalisePath($dripRule), [
            'basis' => $basis,
            'qualifier' => $qualifier === null ? null : [
                'provider' => (string) $qualifier['provider'],
                'resource_type' => (string) $qualifier['resource_type'],
                'resource_id' => (string) $qualifier['resource_id'],
            ],
        ]);
        $key = wp_json_encode($path);
        foreach ($this->planPaths[$planId] ?? [] as $existing) {
            if (wp_json_encode($existing) === $key) {
                return;
            }
        }
        $this->planPaths[$planId][] = $path;
        ksort($this->planPaths, SORT_NUMERIC);
    }

    public function allowsPlan(int $planId): bool
    {
        return $this->anyActivePlan || isset($this->planPaths[$planId]);
    }

    /** @return list<array<string, mixed>> */
    public function pathsForPlan(int $planId): array
    {
        $paths = $this->anyActivePlan ? [array_merge($this->normalisePath(null), [
            'basis' => 'membership',
            'qualifier' => null,
        ])] : [];
        foreach ($this->planPaths[$planId] ?? [] as $path) {
            $encoded = wp_json_encode($path);
            if (!in_array($encoded, array_map('wp_json_encode', $paths), true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /** @return list<int> */
    public function eligiblePlanIds(): array
    {
        return array_map('intval', array_keys($this->planPaths));
    }

    public function hasPlanAccess(): bool
    {
        return $this->anyActivePlan || $this->planPaths !== [];
    }

    /** @return array<string, mixed> */
    private function normalisePath(?array $dripRule): array
    {
        if (!$dripRule || ($dripRule['drip_type'] ?? 'immediate') === 'immediate') {
            return [
                'drip_type' => 'immediate',
                'drip_delay_days' => 0,
                'drip_date' => null,
            ];
        }

        return [
            'drip_type' => (string) $dripRule['drip_type'],
            'drip_delay_days' => max(0, (int) ($dripRule['drip_delay_days'] ?? 0)),
            'drip_date' => isset($dripRule['drip_date']) ? (string) $dripRule['drip_date'] : null,
        ];
    }
}
