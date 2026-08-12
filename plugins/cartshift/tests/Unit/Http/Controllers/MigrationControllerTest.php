<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Http\Controllers\MigrationController;
use CartShift\State\MigrationState;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';

/** The v1 REST writer is a tombstone with directions, not a compatibility path. */
final class MigrationControllerTest extends PluginTestCase
{
    private MigrationState $state;
    private MigrationController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new MigrationState();
        $container = new Container();
        $container->instance(MigrationState::class, $this->state);
        $this->controller = new MigrationController($container);
    }

    /** @return list<array{string, string, string}> */
    public static function mutationRoutes(): array
    {
        return [
            ['migrate', 'legacy_generic_migration_closed', 'wp cartshift transfer prepare'],
            ['retry', 'legacy_generic_migration_closed', 'wp cartshift transfer stage'],
            ['batch', 'legacy_generic_migration_closed', 'wp cartshift transfer stage'],
            ['cancel', 'legacy_generic_migration_closed', 'wp cartshift transfer status'],
            ['reset', 'legacy_generic_migration_closed', 'wp cartshift transfer status'],
        ];
    }

    #[DataProvider('mutationRoutes')]
    public function testEveryLegacyMutationRouteRefusesBeforeReadingOrWriting(
        string $method,
        string $code,
        string $nextCommand,
    ): void {
        $this->state->start(['product']);
        $migrationId = $this->state->getMigrationId();
        $GLOBALS['_cartshift_test_as_pending'] = [[
            'hook' => 'cartshift/migration/process_batch',
            'args' => [$migrationId],
            'group' => 'cartshift',
        ]];

        $response = $this->controller->{$method}($this->request([
            'migration_id' => "  ../wrong\0id  ",
            'entity_types' => ['product', 'wp_users'],
            'dry_run' => true,
            'background' => true,
            'force' => true,
        ]));

        self::assertSame(410, $response->get_status());
        self::assertSame($code, $response->get_data()['data']['code']);
        self::assertSame($nextCommand, $response->get_data()['data']['next_command']);
        self::assertSame(['nothing' => true], $response->get_data()['data']['writes']);
        self::assertSame($migrationId, (new MigrationState())->getMigrationId());
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
        self::assertSame([], $GLOBALS['_cartshift_test_as_scheduled']);
        self::assertSame([], $GLOBALS['_cartshift_test_as_unscheduled']);
        self::assertSame([], $GLOBALS['_cartshift_test_deleted_posts']);
    }

    public function testProgressRemainsReadOnlyLegacyEvidence(): void
    {
        $response = $this->controller->progress(new WP_REST_Request());

        self::assertSame(200, $response->get_status());
        self::assertSame('idle', $response->get_data()['data']['status']);
        self::assertSame([], $GLOBALS['_cartshift_test_queries']);
    }

    /** @param array<string, mixed> $params */
    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }
}
