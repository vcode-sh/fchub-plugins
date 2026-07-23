<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_CLI')) {
        class WP_CLI
        {
            public static array $lines = [];

            public static function line(string $message): void
            {
                self::$lines[] = $message;
            }

            public static function error(string $message): never
            {
                throw new \RuntimeException($message);
            }
        }
    }
}

namespace FChubMemberships\Tests\Unit\Cli {
    use FChubMemberships\CLI\ProviderReconcileCommand;
    use FChubMemberships\Tests\Unit\PluginTestCase;

    final class ProviderReconcileCommandTest extends PluginTestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            \WP_CLI::$lines = [];
        }

        public function test_command_is_dry_run_by_default(): void
        {
            $scans = [];
            $repairs = [];
            $command = new ProviderReconcileCommand(
                static function (?string $cursor, int $limit) use (&$scans): array {
                    $scans[] = [$cursor, $limit];
                    return ['items' => [['classification' => 'healthy']], 'next_cursor' => null];
                },
                static function () use (&$repairs): array {
                    $repairs[] = true;
                    return [];
                }
            );

            $command([], ['limit' => 25]);

            self::assertSame([[null, 25]], $scans);
            self::assertSame([], $repairs);
            self::assertSame('dry-run', json_decode(\WP_CLI::$lines[0], true)['mode']);
        }

        public function test_repair_is_explicit_and_requires_exact_resource_and_request_id(): void
        {
            $repairs = [];
            $command = new ProviderReconcileCommand(
                static fn(): never => throw new \LogicException('Repair must not scan.'),
                static function (array $resource, string $requestId, string $classification) use (&$repairs): array {
                    $repairs[] = [$resource, $requestId, $classification];
                    return ['success' => true, 'status' => 'scheduled', 'operation_id' => 91];
                }
            );
            $resource = [
                'user-id' => 17,
                'provider' => 'fluentcrm',
                'resource-type' => 'fluentcrm_tag',
                'resource-id' => '41',
                'request-id' => 'repair-cli-001',
                'expected-classification' => 'internal_active_provider_absent',
                'repair' => true,
            ];

            $command([], $resource);

            self::assertSame([[
                ['user_id' => 17, 'provider' => 'fluentcrm', 'resource_type' => 'fluentcrm_tag', 'resource_id' => '41'],
                'repair-cli-001',
                'internal_active_provider_absent',
            ]], $repairs);
            self::assertSame('repair', json_decode(\WP_CLI::$lines[0], true)['mode']);
        }
    }
}
