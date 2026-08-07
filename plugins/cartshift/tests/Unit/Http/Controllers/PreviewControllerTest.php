<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Http\Controllers\PreviewController;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';

/**
 * Covers the two hard requirements on POST /preview: it never starts a
 * migration and it never returns a record, only counts and consequences.
 */
final class PreviewControllerTest extends PluginTestCase
{
    private ?\wpdb $originalWpdb = null;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        unset(
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
        );

        parent::tearDown();
    }

    public function testPreviewReturnsCountsWithoutStartingAnything(): void
    {
        $response = $this->controller()->preview($this->request(['scope' => ['mode' => 'everything']]));
        $data = $response->get_data()['data'];

        $this->assertArrayHasKey('counts', $data);
        $this->assertArrayHasKey('consequences', $data);
        $this->assertSame('everything', $data['scope']['mode']);
        $this->assertFalse($data['too_large']);

        // The one thing preview must never do.
        $this->assertSame('idle', (new MigrationState())->getProgress()['status']);
    }

    public function testAnInvalidScopeIsNormalisedRatherThanRejected(): void
    {
        $response = $this->controller()->preview($this->request(['scope' => ['mode' => 'nonsense']]));

        $this->assertSame(200, $response->get_status());
        $this->assertSame('everything', $response->get_data()['data']['scope']['mode']);
    }

    public function testAnOversizedClosureIsReportedNotTruncated(): void
    {
        // too_large is a flag on a 200, not an error: the owner is still
        // choosing, and a 500 in the middle of a selection screen tells them
        // nothing about what to change.
        $this->stubClosureBuyers(range(1, ScopeResolver::MAX_CLOSURE_IDS + 1));

        $data = $this->controller()->preview($this->request([
            'scope' => [
                'mode'                        => 'explicit',
                'product_ids'                 => [12],
                'include_orders_for_products' => true,
            ],
        ]))->get_data()['data'];

        $this->assertTrue($data['too_large']);
        $this->assertSame('idle', (new MigrationState())->getProgress()['status']);
    }

    public function testPreviewCountsOnlyTheRequestedEntityTypes(): void
    {
        $response = $this->controller()->preview($this->request([
            'scope'        => ['mode' => 'everything'],
            'entity_types' => ['product', 'coupon'],
        ]));
        $counts = $response->get_data()['data']['counts'];

        $this->assertArrayHasKey('product', $counts);
        $this->assertArrayHasKey('coupon', $counts);
        $this->assertArrayNotHasKey('customer', $counts);
        $this->assertArrayNotHasKey('order', $counts);
        $this->assertArrayNotHasKey('subscription', $counts);
    }

    public function testPreviewReportsTheAddedClosureNotTheRawClosureSize(): void
    {
        // A picked product with no closure hit at all: closedProductIds()
        // returns exactly what was picked, so the added count is zero rather
        // than the size of the pick itself.
        $data = $this->controller()->preview($this->request([
            'scope' => [
                'mode'        => 'explicit',
                'product_ids' => [12, 13],
            ],
        ]))->get_data()['data'];

        $this->assertSame(0, $data['closure']['products']);
        $this->assertSame(0, $data['closure']['customers']);
    }

    /**
     * Swap $GLOBALS['wpdb'] for one whose DISTINCT customer_id closure query
     * returns this many buyers, and restore the original in tearDown().
     *
     * @param list<int> $buyers
     */
    private function stubClosureBuyers(array $buyers): void
    {
        $this->originalWpdb ??= $GLOBALS['wpdb'];

        $GLOBALS['wpdb'] = new class ($buyers) extends \wpdb {
            /** @param list<int> $buyers */
            public function __construct(private readonly array $buyers)
            {
            }

            #[\Override]
            public function get_col(string $query): array
            {
                return str_contains($query, 'DISTINCT customer_id')
                    ? array_map(strval(...), $this->buyers)
                    : [];
            }
        };
    }

    private function controller(): PreviewController
    {
        $container = new Container();
        $container->singleton(IdMapRepository::class, static fn (): IdMapRepository => new IdMapRepository());
        $container->singleton(
            MigrationLogRepository::class,
            static fn (): MigrationLogRepository => new MigrationLogRepository(),
        );
        $container->singleton(MigrationState::class, static fn (): MigrationState => new MigrationState());

        return new PreviewController($container);
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
}
