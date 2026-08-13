<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Reconciliation;

use FChubMemberships\Domain\Reconciliation\ProviderReconciliationService;
use FChubMemberships\Storage\EntitlementEdgeRepository;
use FChubMemberships\Storage\ProviderOperationRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

/**
 * The member profile reports provider state per resource. It has to say what is
 * actually knowable: WordPress access is local, LearnDash is uncertified, and
 * only a certified provider can report drift.
 */
final class ProviderStateForUserTest extends PluginTestCase
{
    public function test_wordpress_access_is_reported_as_local_rather_than_healthy(): void
    {
        $items = $this->classify([self::edge('wordpress_core', 'post', '55')]);

        self::assertSame('local_only', $items[0]['classification']);
        self::assertNull($items[0]['repair_action']);
    }

    public function test_learndash_is_reported_as_uncertified_rather_than_dressed_up(): void
    {
        $items = $this->classify([self::edge('learndash', 'course', '9')]);

        self::assertSame('provider_uncertified', $items[0]['classification']);
        self::assertNull($items[0]['repair_action']);
    }

    public function test_an_absent_community_install_is_uncertified_rather_than_assumed_healthy(): void
    {
        $items = $this->classify([self::edge('fluent_community', 'fc_space', '2')]);

        self::assertSame('provider_uncertified', $items[0]['classification']);
        self::assertNull($items[0]['repair_action']);
    }

    public function test_edges_of_one_resource_collapse_into_one_row(): void
    {
        $items = $this->classify([
            self::edge('wordpress_core', 'post', '55', edgeId: 1),
            self::edge('wordpress_core', 'post', '55', edgeId: 2),
        ]);

        self::assertCount(1, $items);
        self::assertSame(2, $items[0]['edge_count']);
    }

    public function test_each_row_carries_the_identity_the_repair_route_expects(): void
    {
        $items = $this->classify([self::edge('wordpress_core', 'post', '55')]);

        self::assertSame(
            ['user_id', 'provider', 'resource_type', 'resource_id'],
            array_slice(array_keys($items[0]), 0, 4)
        );
        self::assertSame(21, $items[0]['user_id']);
        self::assertSame(7, $items[0]['plan_id']);
    }

    public function test_a_member_without_edges_returns_nothing_rather_than_failing(): void
    {
        self::assertSame([], $this->classify([]));
    }

    /**
     * @param list<array<string, mixed>> $edges
     * @return list<array<string, mixed>>
     */
    private function classify(array $edges): array
    {
        $repository = new class($edges) extends EntitlementEdgeRepository {
            /** @param list<array<string, mixed>> $edges */
            public function __construct(private array $edges)
            {
            }

            public function getActiveByUser(int $userId): array
            {
                return $this->edges;
            }
        };

        $operations = new class extends ProviderOperationRepository {
            public function __construct()
            {
            }

            public function findLatestForResource(array $resource): ?array
            {
                return null;
            }
        };

        return (new ProviderReconciliationService($repository, $operations))->classifyForUser(21);
    }

    /** @return array<string, mixed> */
    private static function edge(string $provider, string $resourceType, string $resourceId, int $edgeId = 1): array
    {
        return [
            'id' => $edgeId,
            'user_id' => 21,
            'provider' => $provider,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'plan_id' => 7,
            'owner' => 'fchub',
            'assignment_provenance' => 'fchub_created',
            'lifecycle' => 'active',
        ];
    }
}
