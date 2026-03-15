<?php

declare(strict_types=1);

namespace FChubMemberships\Tests\Unit\Domain\Import;

use FChubMemberships\Domain\Import\CsvParser;
use FChubMemberships\Domain\Import\ImportService;
use FChubMemberships\Domain\Import\Parsers\PmproCsvParser;
use FChubMemberships\Domain\AccessGrantService;
use FChubMemberships\Domain\Plan\PlanService;
use FChubMemberships\Storage\GrantRepository;
use FChubMemberships\Tests\Unit\PluginTestCase;

final class ImportStackTest extends PluginTestCase
{
    private function inject(object $service, string $property, object $value): void
    {
        $reflection = new \ReflectionProperty($service, $property);
        $reflection->setValue($service, $value);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $alice = new \WP_User();
        $alice->ID = 21;
        $alice->display_name = 'Alice Example';
        $alice->user_email = 'alice@example.com';
        $alice->user_login = 'alice';
        $GLOBALS['_fchub_test_users'][21] = $alice;
        $GLOBALS['_fchub_test_users_by_email']['alice@example.com'] = $alice;
    }

    public function test_csv_parser_detects_generic_and_pmpro_formats_and_handles_invalid_rows(): void
    {
        $genericCsv = "\xEF\xBB\xBFemail,username,level,expires_at\nalice@example.com,alice,Gold,2026-03-20\nbroken-email,user,Silver,\n";
        $pmproCsv = "membership,username,joined,startdate,expires,email\nGold,alice,2026-03-01,2026-03-02,Brak danych,alice@example.com\n";

        $generic = (new CsvParser())->parse($genericCsv);
        $pmpro = (new CsvParser())->parse($pmproCsv);
        $unknown = (new CsvParser())->parse("name,role\nAlice,Gold\n");
        $empty = (new CsvParser())->parse('');

        self::assertSame('Generic', $generic['format']);
        self::assertCount(1, $generic['members']);
        self::assertCount(1, $generic['warnings']);
        self::assertSame('PMPro', $pmpro['format']);
        self::assertTrue($pmpro['members'][0]['is_lifetime']);
        self::assertSame('unknown', $unknown['format']);
        self::assertSame('unknown', $empty['format']);
    }

    public function test_pmpro_parser_covers_date_and_lifetime_normalization(): void
    {
        $parser = new PmproCsvParser();

        self::assertTrue($parser->canParse(['membership', 'username', 'joined', 'startdate', 'expires']));
        self::assertFalse($parser->canParse(['membership', 'username']));

        $rows = $parser->parse([[
            'id' => '10',
            'email' => 'alice@example.com',
            'username' => 'alice',
            'firstname' => 'Alice',
            'lastname' => 'Example',
            'membership' => 'Gold',
            'joined' => '2026-03-01',
            'startdate' => '2026-03-02',
            'expires' => 'Brak danych',
        ], [
            'email' => 'bob@example.com',
            'username' => 'bob',
            'membership' => 'Silver',
            'joined' => 'bad-date',
            'startdate' => '',
            'expires' => '2026-03-20',
        ]]);

        self::assertSame('PMPro', $parser->getSourceName());
        self::assertTrue($rows[0]['is_lifetime']);
        self::assertNull($rows[1]['joined_at']);
        self::assertSame('2026-03-20', $rows[1]['expires_at']);
    }

    public function test_import_service_covers_plan_creation_customer_sync_and_conflict_modes(): void
    {
        $createdPlans = [];
        $granted = [];
        $revoked = [];
        $extended = [];
        $customers = [];

        $service = new ImportService();

        $planService = new class($createdPlans) extends PlanService {
            public function __construct(private array &$createdPlans)
            {
            }

            public function create(array $data): array
            {
                $this->createdPlans[] = $data;
                if (($data['title'] ?? '') === 'Broken') {
                    return ['error' => 'Cannot create'];
                }

                return ['id' => 55] + $data;
            }
        };

        $grantService = new class($granted, $revoked, $extended) extends AccessGrantService {
            public function __construct(private array &$granted, private array &$revoked, private array &$extended)
            {
            }

            public function grantPlan(int $userId, int $planId, array $context = []): array
            {
                $this->granted[] = [$userId, $planId, $context];
                return ['created' => 1, 'updated' => 0, 'total' => 1];
            }

            public function revokePlan(int $userId, int $planId, array $context = []): array
            {
                $this->revoked[] = [$userId, $planId, $context];
                return ['revoked' => 1];
            }

            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = [$userId, $planId, $newExpiresAt];
                return 1;
            }
        };

        $grantRepo = new class extends GrantRepository {
            public array $existing = [];

            public function getByUserId(int $userId, array $filters = []): array
            {
                $status = $filters['status'] ?? '';
                if ($status === 'active' || $status === 'paused') {
                    return $this->existing[$status] ?? [];
                }
                return [];
            }
        };

        $this->inject($service, 'planService', $planService);
        $this->inject($service, 'grantService', $grantService);
        $this->inject($service, 'grantRepo', $grantRepo);

        $mappings = $service->createPlansForLevels([
            ['level_name' => 'Gold', 'action' => 'create_new', 'title' => 'Gold'],
            ['level_name' => 'Silver', 'action' => 'create_new', 'title' => '   '],
            ['level_name' => 'Broken', 'action' => 'create_new', 'title' => 'Broken'],
        ]);

        self::assertSame(55, $mappings[0]['plan_id']);
        self::assertSame('map_existing', $mappings[0]['action']);
        self::assertArrayHasKey('error', $mappings[1]);
        self::assertSame('Cannot create', $mappings[2]['error']);

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|string {
            return match (true) {
                str_contains($query, "SHOW TABLES LIKE 'wp_fct_customers'") => 'wp_fct_customers',
                str_contains($query, 'SELECT id FROM wp_fct_customers WHERE email') => 0,
                default => 0,
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['insert'] = static function (string $table, array $data, \wpdb $wpdb) use (&$customers): int {
            if ($table === 'wp_fct_customers') {
                $customers[] = $data;
                $wpdb->insert_id = 999;
            }
            return 1;
        };

        $customerId = $service->ensureFluentCartCustomer([
            'email' => 'alice@example.com',
            'first_name' => 'Alice',
            'last_name' => 'Example',
        ]);
        self::assertSame(999, $customerId);
        self::assertSame('00000000-0000-4000-8000-000000000000', $customers[0]['uuid']);

        $grantRepo->existing = ['active' => [], 'paused' => []];
        $batch = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Gold', 'expires_at' => '2026-04-01', 'is_lifetime' => false],
            ['email' => 'missing@example.com', 'username' => 'missing', 'level_name' => 'Gold', 'expires_at' => null, 'is_lifetime' => true],
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Skip', 'expires_at' => null, 'is_lifetime' => true],
        ], [
            ['level_name' => 'Gold', 'action' => 'map_existing', 'plan_id' => 55],
            ['level_name' => 'Skip', 'action' => 'skip'],
        ], 'skip', true);

        self::assertSame(1, $batch['summary']['imported']);
        self::assertSame(2, $batch['summary']['skipped']);
        self::assertCount(1, $granted);

        $grantRepo->existing = ['active' => [['id' => 1]], 'paused' => []];
        $extend = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Gold', 'expires_at' => '2026-05-01', 'is_lifetime' => false],
        ], [
            ['level_name' => 'Gold', 'action' => 'map_existing', 'plan_id' => 55],
        ], 'extend', false);
        self::assertSame(1, $extend['summary']['extended']);
        self::assertCount(1, $extended);

        $grantRepo->existing = ['active' => [['id' => 1]], 'paused' => []];
        $overwrite = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Gold', 'expires_at' => null, 'is_lifetime' => true],
        ], [
            ['level_name' => 'Gold', 'action' => 'map_existing', 'plan_id' => 55],
        ], 'overwrite', false);
        self::assertSame(1, $overwrite['summary']['imported']);
        self::assertCount(1, $revoked);
    }

    public function test_import_service_handles_existing_customers_missing_tables_and_member_processing_failures(): void
    {
        $service = new ImportService();

        self::assertSame([[
            'level_name' => 'Existing',
            'action' => 'map_existing',
            'plan_id' => 88,
        ]], $service->createPlansForLevels([[
            'level_name' => 'Existing',
            'action' => 'map_existing',
            'plan_id' => 88,
        ]]));

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static function (string $query): int|string {
            return match (true) {
                str_contains($query, "SHOW TABLES LIKE 'wp_fct_customers'") => 'wp_fct_customers',
                default => 0,
            };
        };
        $GLOBALS['_fchub_test_wpdb_overrides']['get_row'] = static fn(string $query): ?array => str_contains($query, 'SELECT id FROM wp_fct_customers WHERE email')
            ? ['id' => 321]
            : null;

        self::assertSame(321, $service->ensureFluentCartCustomer([
            'email' => 'alice@example.com',
        ]));

        $GLOBALS['_fchub_test_wpdb_overrides']['get_var'] = static fn(string $query): int|string => 0;
        self::assertNull($service->ensureFluentCartCustomer([
            'email' => 'alice@example.com',
        ]));
        self::assertNull($service->ensureFluentCartCustomer([]));

        $grantService = new class extends AccessGrantService {
            public function grantPlan(int $userId, int $planId, array $context = []): array
            {
                throw new \RuntimeException('Grant failed');
            }
        };

        $grantRepo = new class extends GrantRepository {
            public function getByUserId(int $userId, array $filters = []): array
            {
                return [];
            }
        };

        $this->inject($service, 'grantService', $grantService);
        $this->inject($service, 'grantRepo', $grantRepo);

        $result = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Gold', 'expires_at' => null, 'is_lifetime' => true],
        ], [
            ['level_name' => 'Gold', 'action' => 'map_existing', 'plan_id' => 55],
        ]);

        self::assertSame(1, $result['summary']['failed']);
        self::assertSame('Grant failed', $result['results'][0]['message']);
    }

    public function test_import_service_uses_username_fallback_and_handles_missing_plan_ids_and_lifetime_extensions(): void
    {
        $granted = [];
        $extended = [];

        $service = new ImportService();
        $grantService = new class($granted, $extended) extends AccessGrantService {
            public function __construct(private array &$granted, private array &$extended)
            {
            }

            public function grantPlan(int $userId, int $planId, array $context = []): array
            {
                $this->granted[] = [$userId, $planId, $context];
                return ['created' => 1];
            }

            public function extendExpiry(int $userId, int $planId, string $newExpiresAt, ?int $renewalSourceId = null): int
            {
                $this->extended[] = [$userId, $planId, $newExpiresAt];
                return 1;
            }
        };
        $grantRepo = new class extends GrantRepository {
            public array $existing = [];

            public function getByUserId(int $userId, array $filters = []): array
            {
                return $this->existing[$filters['status'] ?? ''] ?? [];
            }
        };

        $this->inject($service, 'grantService', $grantService);
        $this->inject($service, 'grantRepo', $grantRepo);

        unset($GLOBALS['_fchub_test_users_by_email']['alice@example.com']);

        $imported = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Gold', 'expires_at' => null, 'is_lifetime' => true],
        ], [
            ['level_name' => 'Gold', 'action' => 'map_existing', 'plan_id' => 55],
        ]);

        self::assertSame(1, $imported['summary']['imported']);
        self::assertCount(1, $granted);

        $missingPlan = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Silver', 'expires_at' => null, 'is_lifetime' => true],
        ], [
            ['level_name' => 'Silver', 'action' => 'map_existing', 'plan_id' => 0],
        ]);

        self::assertSame('No plan ID for level "Silver".', $missingPlan['results'][0]['message']);

        $missingMapping = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Unmapped', 'expires_at' => null, 'is_lifetime' => true],
        ], []);

        self::assertSame('No level mapping found.', $missingMapping['results'][0]['message']);

        $grantRepo->existing = ['active' => [['id' => 1]], 'paused' => []];
        $lifetimeExtend = $service->processBatch([
            ['email' => 'alice@example.com', 'username' => 'alice', 'level_name' => 'Gold', 'expires_at' => null, 'is_lifetime' => true],
        ], [
            ['level_name' => 'Gold', 'action' => 'map_existing', 'plan_id' => 55],
        ], 'extend');

        self::assertSame('Active grant exists; no expiry to extend (lifetime).', $lifetimeExtend['results'][0]['message']);
        self::assertSame([], $extended);
    }

    public function test_create_plans_for_levels_uses_level_name_and_default_duration_values(): void
    {
        $captured = [];
        $service = new ImportService();
        $planService = new class($captured) extends PlanService {
            public function __construct(private array &$captured)
            {
            }

            public function create(array $data): array
            {
                $this->captured[] = $data;
                return ['id' => 77] + $data;
            }
        };

        $this->inject($service, 'planService', $planService);

        $result = $service->createPlansForLevels([[
            'level_name' => 'Bronze',
            'action' => 'create_new',
        ]]);

        self::assertSame('Bronze', $captured[0]['title']);
        self::assertSame('lifetime', $captured[0]['duration_type']);
        self::assertSame(0, $captured[0]['duration_days']);
        self::assertSame(77, $result[0]['plan_id']);
    }
}
