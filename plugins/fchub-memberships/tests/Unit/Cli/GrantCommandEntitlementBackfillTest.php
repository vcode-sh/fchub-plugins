<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static array $successes = [];
            public static array $lines = [];

            public static function success(string $message): void
            {
                self::$successes[] = $message;
            }

            public static function error(string $message): never
            {
                throw new \RuntimeException($message);
            }

            public static function line(string $message): void
            {
                self::$lines[] = $message;
            }

            public static function warning(string $message): void
            {
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (!function_exists(__NAMESPACE__ . '\\get_flag_value')) {
        function get_flag_value(array $assocArgs, string $key, mixed $default = false): mixed
        {
            return array_key_exists($key, $assocArgs) ? (bool) $assocArgs[$key] : $default;
        }
    }
}

namespace FChubMemberships\Tests\Unit\Cli {

    use FChubMemberships\CLI\GrantCommand;
    use FChubMemberships\Domain\Entitlement\EntitlementBackfillService;
    use FChubMemberships\Domain\Entitlement\EntitlementService;
    use FChubMemberships\Storage\EntitlementEdgeRepository;
    use FChubMemberships\Storage\GrantRepository;
    use FChubMemberships\Storage\GrantSourceRepository;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class GrantCommandEntitlementBackfillTest extends PluginTestCase
    {
        public function test_entitlement_backfill_is_dry_run_by_default_and_requires_apply_flag_to_write(): void
        {
            [$backfill, $edges] = $this->backfill();
            $command = new GrantCommand(null, null, null, null, null, $backfill);
            \WP_CLI::$lines = [];
            \WP_CLI::$successes = [];

            $command->entitlement_backfill([], ['through' => 1]);

            self::assertSame([], $edges->rows);
            $dryRun = json_decode(\WP_CLI::$lines[0], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('dry-run', $dryRun['mode']);
            self::assertSame(1, $dryRun['items'][0]['grant_id']);
            self::assertSame('deterministic', $dryRun['items'][0]['classification']);
            self::assertNotEmpty($dryRun['items'][0]['proposed_edges']);
            self::assertContains('typed_sources_authoritative', $dryRun['items'][0]['reason_codes']);
            self::assertArrayNotHasKey('user_id', $dryRun['items'][0]['proposed_edges'][0]);
            self::assertStringNotContainsString('user_id', \WP_CLI::$lines[0]);
            self::assertStringNotContainsString('provider_access_owner', \WP_CLI::$lines[0]);

            $command->entitlement_backfill([], ['through' => 1, 'apply' => true]);

            self::assertCount(1, $edges->rows);
            $apply = json_decode(\WP_CLI::$lines[1], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('apply', $apply['mode']);
            self::assertSame(1, $apply['items'][0]['grant_id']);
            self::assertSame('deterministic', $apply['items'][0]['classification']);
            self::assertSame(['Entitlement backfill applied.'], \WP_CLI::$successes);
        }

        public function test_apply_output_identifies_a_refused_grant_without_exposing_private_data(): void
        {
            [$backfill] = $this->backfill(true);
            $command = new GrantCommand(null, null, null, null, null, $backfill);
            \WP_CLI::$lines = [];
            \WP_CLI::$successes = [];

            $command->entitlement_backfill([], ['through' => 1, 'apply' => true]);

            $output = json_decode(\WP_CLI::$lines[0], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(1, $output['items'][0]['grant_id']);
            self::assertSame('refused', $output['items'][0]['classification']);
            self::assertSame(['typed_source_malformed'], $output['items'][0]['reason_codes']);
            self::assertSame([], $output['items'][0]['proposed_edges']);
            self::assertStringNotContainsString('user_id', \WP_CLI::$lines[0]);
            self::assertStringNotContainsString('provider_access_owner', \WP_CLI::$lines[0]);
            self::assertSame([], \WP_CLI::$successes);
        }

        /** @return array{EntitlementBackfillService, object} */
        private function backfill(bool $malformedSource = false): array
        {
            $grant = [
                'id' => 1,
                'user_id' => 81,
                'plan_id' => 7,
                'provider' => 'wordpress_core',
                'resource_type' => 'post',
                'resource_id' => '42',
                'feed_id' => 50,
                'status' => 'active',
                'starts_at' => null,
                'expires_at' => null,
                'drip_available_at' => null,
                'meta' => ['provider_access_owner' => 'fchub'],
                'created_at' => '2026-06-01 10:00:00',
                'updated_at' => '2026-07-22 10:00:00',
            ];
            $grants = new class($grant) extends GrantRepository {
                public function __construct(private array $grant)
                {
                }
                public function getEntitlementBackfillWatermark(): int
                {
                    return 1;
                }
                public function getEntitlementBackfillBatch(int $after, int $through, int $limit): array
                {
                    return $after < 1 && $through >= 1 ? [$this->grant] : [];
                }
                public function findByGrantKey(string $grantKey): ?array
                {
                    return null;
                }
            };
            $sources = new class($malformedSource) extends GrantSourceRepository {
                public function __construct(private bool $malformed)
                {
                }
                public function getSourcesByGrant(int $grantId): array
                {
                    return [[
                        'source_type' => $this->malformed ? '' : 'order',
                        'source_id' => 123,
                    ]];
                }
            };
            $edges = new class extends EntitlementEdgeRepository {
                public array $rows = [];
                public function findByIdentity(array $identity): ?array
                {
                    return $this->rows['edge'] ?? null;
                }
                public function createOrReplay(array $data, ?array $comparisonFields = null): array
                {
                    $data['id'] = 1;
                    $this->rows['edge'] = $data;
                    return ['action' => 'created', 'edge' => $data];
                }
                public function resourceTransaction(array $resource, callable $callback): mixed
                {
                    return $callback();
                }
            };
            $entitlements = new EntitlementService($edges, $grants);
            $service = new EntitlementBackfillService(
                $grants,
                $sources,
                $edges,
                $entitlements,
                static fn(): array => ['scope' => 'product', 'plan_id' => 7],
                static function (): void {
                }
            );

            return [$service, $edges];
        }
    }
}
