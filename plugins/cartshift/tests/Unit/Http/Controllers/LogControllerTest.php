<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Http\Controllers\LogController;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';

/**
 * Covers the `code` filter on GET /log.
 *
 * The REST layer drops query args that are not registered, so the arg
 * declaration and the pass-through are equally load-bearing — either one
 * missing and `?code=` silently returns the whole unfiltered run.
 */
final class LogControllerTest extends PluginTestCase
{
    private LogController $controller;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->singleton(
            MigrationLogRepository::class,
            static fn (): MigrationLogRepository => new MigrationLogRepository(),
        );

        $this->controller = new LogController($container);
    }

    public function testIndexRegistersTheCodeArg(): void
    {
        $this->controller->registerRoutes();

        $args = $GLOBALS['_cartshift_test_rest_routes']['cartshift/v1/log']['args'];

        $this->assertArrayHasKey('code', $args, 'Unregistered args are dropped by the REST layer');
        $this->assertSame('string', $args['code']['type']);
        $this->assertSame('sanitize_text_field', $args['code']['sanitize_callback']);
    }

    public function testCodeFilterReachesTheQuery(): void
    {
        $this->controller->index($this->request(['code' => MigrationErrorCode::ProductNotMapped->value]));

        $this->assertTrue(
            $this->anyQueryContains('product_not_mapped'),
            'The code must be filtered server-side, not left to the client',
        );
    }

    public function testUnknownCodeMatchesNothing(): void
    {
        $response = $this->controller->index($this->request(['code' => 'not_a_real_code']));
        $data = $response->get_data()['data'];

        $this->assertSame([], $data['data']);
        $this->assertSame(0, $data['total']);
    }

    public function testOmittingTheCodeLeavesTheQueryUnfiltered(): void
    {
        $this->controller->index($this->request([]));

        // Pinned on code values rather than on SQL syntax. The repository may
        // filter through an `error_code` column or through a LIKE on the details
        // JSON; either way, asking for no code must put no code in the query.
        foreach (MigrationErrorCode::cases() as $case) {
            $this->assertFalse(
                $this->anyQueryContains($case->value),
                sprintf('No code was requested, so %s must not reach the query', $case->value),
            );
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    /**
     * LIKE patterns arrive with their underscores backslash-escaped, so compare
     * against the unescaped text rather than encoding that detail in every test.
     */
    private function anyQueryContains(string $needle): bool
    {
        foreach ($GLOBALS['_cartshift_test_queries'] as $entry) {
            foreach ($entry as $part) {
                if (is_string($part) && str_contains(str_replace('\\', '', $part), $needle)) {
                    return true;
                }
            }
        }

        return false;
    }
}
