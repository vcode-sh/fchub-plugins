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

namespace FChubMemberships\Tests\Unit\Cli {

    use FChubMemberships\CLI\GrantCommand;
    use FChubMemberships\Storage\GrantRepository;
    use FChubMemberships\Storage\PlanRepository;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class GrantCommandExportTest extends PluginTestCase
    {
        public function test_csv_neutralises_every_cell_while_json_keeps_raw_values(): void
        {
            $grants = new class extends GrantRepository {
                public function getByPlanId(int $planId, array $filters = []): array
                {
                    return [
                        [
                            'user_id' => 21,
                            'source_type' => '@source',
                            'created_at' => "\tcreated",
                            'expires_at' => "\rexpires",
                        ],
                        [
                            'user_id' => 22,
                            'source_type' => 'manual',
                            'created_at' => '2026-03-01 10:00:00',
                            'expires_at' => '',
                        ],
                    ];
                }
            };
            $plans = new class extends PlanRepository {
                public function findBySlug(string $slug): ?array
                {
                    return ['id' => 5, 'slug' => $slug, 'title' => '-plan'];
                }
            };
            $firstUser = new \WP_User();
            $firstUser->ID = 21;
            $firstUser->user_email = '=email';
            $firstUser->display_name = '+display';
            $secondUser = new \WP_User();
            $secondUser->ID = 22;
            $secondUser->user_email = "\nemail";
            $secondUser->display_name = 'Ordinary';
            $GLOBALS['_fchub_test_users'][21] = $firstUser;
            $GLOBALS['_fchub_test_users'][22] = $secondUser;
            $command = new GrantCommand($grants, $plans);
            $csvPath = tempnam(sys_get_temp_dir(), 'fchub-members-csv-');
            $jsonPath = tempnam(sys_get_temp_dir(), 'fchub-members-json-');
            self::assertNotFalse($csvPath);
            self::assertNotFalse($jsonPath);

            try {
                $command->export_members([], ['plan' => 'gold', 'format' => 'csv', 'output' => $csvPath]);
                $handle = fopen($csvPath, 'r');
                self::assertNotFalse($handle);
                $header = fgetcsv($handle, null, ',', '"', '');
                $firstRow = fgetcsv($handle, null, ',', '"', '');
                $secondRow = fgetcsv($handle, null, ',', '"', '');
                fclose($handle);

                self::assertSame(
                    ['user_id', 'email', 'display_name', 'plan', 'status', 'source_type', 'grants_count', 'created_at', 'expires_at'],
                    $header
                );
                self::assertSame(
                    ['21', "'=email", "'+display", "'-plan", 'active', "'@source", '1', "'\tcreated", "'\rexpires"],
                    $firstRow
                );
                self::assertSame("'\nemail", $secondRow[1]);

                $command->export_members([], ['plan' => 'gold', 'format' => 'json', 'output' => $jsonPath]);
                $json = json_decode((string) file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR);

                self::assertSame('=email', $json[0]['email']);
                self::assertSame('+display', $json[0]['display_name']);
                self::assertSame('-plan', $json[0]['plan']);
                self::assertSame("\nemail", $json[1]['email']);
            } finally {
                @unlink($csvPath);
                @unlink($jsonPath);
            }
        }
    }
}
