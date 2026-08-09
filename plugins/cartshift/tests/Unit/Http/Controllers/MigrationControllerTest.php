<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Migration\BatchProcessor;
use CartShift\Domain\Migration\MigrationOrchestrator;
use CartShift\Domain\Migration\MigrationOrchestratorFactory;
use CartShift\Domain\Scope\ScopeResolver;
use CartShift\Http\Controllers\MigrationController;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';
// get_post_status()/get_post_type(), which promotion's dead-link check reads.
require_once dirname(__DIR__, 3) . '/stubs/PostStatusStubs.php';

/**
 * Covers the reset endpoint and the batch concurrency lock.
 */
final class MigrationControllerTest extends PluginTestCase
{
    private const string AS_HOOK = 'cartshift/migration/process_batch';
    private const string AS_GROUP = 'cartshift';

    private MigrationState $state;
    private MigrationController $controller;
    private ?\wpdb $originalWpdb = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_as_pending'] = [];
        $GLOBALS['_cartshift_test_wc_product_batches'] = [];
        $GLOBALS['_cartshift_test_posts'] = [];

        // PluginTestCase does not clear the query stubs, and other suites leave
        // callbacks behind. Start from a known-empty database rather than
        // whatever the previous test class decided the log contains.
        unset(
            $GLOBALS['_cartshift_test_get_results_callback'],
            $GLOBALS['_cartshift_test_get_results_return'],
        );

        $this->state = new MigrationState();
        $this->controller = new MigrationController($this->makeContainer());

        // Default: the lock is free.
        $this->stubLock(true);
    }

    /**
     * Clear the query callbacks.
     *
     * PluginTestCase::setUp() resets the recorded queries and the option store
     * but not these two, so a callback left installed stays live for every test
     * class that runs afterwards. The symptom lands in an unrelated file with
     * nothing pointing back here — a class that stubs only get_var inheriting
     * another file's get_results shape is a fatal, not a failure.
     */
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

    // ── Migrate: scope ─────────────────────────────────────

    public function testMigrateStoresTheScopeItWasGiven(): void
    {
        $response = $this->controller->migrate($this->request([
            'entity_types' => ['order'],
            'scope'        => ['mode' => 'since', 'since' => '2024-03-01'],
        ]));

        $this->assertSame('since', (new MigrationState())->getScope()->mode());
        // The brief lists `data.scope` as part of /migrate's response contract.
        $this->assertSame('since', $response->get_data()['data']['scope']['mode']);
    }

    public function testMigrateWithNoScopeMigratesEverything(): void
    {
        // Every caller that predates this parameter — an old UI bundle, a
        // scripted integration — keeps working, and keeps meaning what it meant.
        $this->controller->migrate($this->request(['entity_types' => ['order']]));

        $this->assertTrue((new MigrationState())->getScope()->isEverything());
    }

    public function testAnOversizedClosureIsRefusedBeforeAnythingIsWritten(): void
    {
        // The scope itself is three keys long; the *closure* is what overflows.
        // Only a scope that accepted the upward offer runs the closure queries
        // at all, so this is the shape that can exceed the limit — and a stubbed
        // wpdb answers the buyers query with one ID more than MAX_CLOSURE_IDS
        // allows.
        $this->stubClosureBuyers(range(1, ScopeResolver::MAX_CLOSURE_IDS + 1));

        $response = $this->controller->migrate($this->request([
            'entity_types' => ['order'],
            'scope'        => [
                'mode'                        => 'explicit',
                'product_ids'                 => [12],
                'include_orders_for_products' => true,
            ],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame('scope_closure_too_large', $response->get_data()['data']['code']);
        $this->assertSame('idle', (new MigrationState())->getProgress()['status']);
    }

    /**
     * Swap $GLOBALS['wpdb'] for one whose DISTINCT customer_id closure query
     * returns this many buyers, and restore the original in tearDown().
     *
     * The same helper PreviewControllerTest declares in Task 9, for the same
     * query and the same reason. Neither file has a base class to hang it on;
     * if you extract it, extract it for both.
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

    // ── Migrate: product mapping ───────────────────────────
    //
    // The test the whole feature turned on. POST /migrate is what the wizard
    // calls, and it is the path that used to build its own migrators and
    // therefore promote nothing: every link the owner drew was ignored, every
    // mapped product duplicated, every skip migrated anyway. Driving the real
    // endpoint end to end — not MappingPromoter in isolation — is the only
    // shape that can see that, which is why it lives here rather than beside
    // the promoter's own tests.

    /**
     * Make a saved `link` decision visible to the run, and capture what lands
     * in the ID map.
     *
     * Real repositories throughout: ProductMapRepository and IdMapRepository
     * are both `final`, so they are driven through the $wpdb stub's global
     * callbacks, the same technique MappingPromoterTest uses. The get_var
     * callback has to serve three unrelated readers at once — the batch lock,
     * IdMapRepository::getFcId() (which must answer "not promoted yet", i.e.
     * null, not the stub's default 0) and everything else — because the stub
     * exposes exactly one of them.
     *
     * @param list<array{0: string, 1: string, 2: int, 3: string, 4: bool}> $stored
     */
    private function stubSavedLink(array &$stored, int $wcId, int $fcPostId, array $variantMap): void
    {
        $GLOBALS['_cartshift_test_posts'][$fcPostId] = ['status' => 'publish', 'type' => 'fluent-products'];

        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$stored): int {
            if (str_contains($table, 'cartshift_id_map')) {
                $stored[] = [
                    $data['entity_type'],
                    $data['wc_id'],
                    (int) $data['fc_id'],
                    $data['migration_id'],
                    (bool) $data['created_by_migration'],
                ];
            }

            return 1;
        };

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($wcId, $fcPostId, $variantMap): array {
            if (!str_contains($query, 'cartshift_product_map') || !str_contains($query, "decision = 'link'")) {
                return [];
            }

            return [(object) [
                'wc_id'       => $wcId,
                'wc_type'     => 'variable',
                'decision'    => 'link',
                'fc_post_id'  => $fcPostId,
                'band'        => 'strong',
                'variant_map' => (string) wp_json_encode(['map' => $variantMap, 'orphans' => []]),
            ]];
        };

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string|null {
            if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) {
                return '1';
            }

            // Nothing has been promoted yet, and the stub's default of 0 would
            // read as "fc_id 0", which promotion treats as already done.
            if (str_contains($query, 'cartshift_id_map')) {
                return null;
            }

            return '0';
        };

        // The linked FluentCart product still owns the variants the decision
        // maps to. Promotion re-reads `fct_product_variations` at run time and
        // drops anything that has moved or been deleted since, so a fixture
        // answering "no variants" would be describing a product the owner had
        // emptied between mapping and running.
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array
            => str_contains($query, 'fct_product_variations')
                ? array_values(array_map(intval(...), $variantMap))
                : [];
    }

    public function testMigratePromotesASavedLinkIntoTheIdMap(): void
    {
        $stored = [];
        $this->stubSavedLink($stored, 42, 900, [11 => 501]);

        $response = $this->controller->migrate($this->request(['entity_types' => ['product']]));

        $this->assertSame(200, $response->get_status());

        $migrationId = (string) $this->state->getMigrationId();

        $this->assertSame(
            [Constants::ENTITY_VARIATION, '11', 501, $migrationId, false],
            $stored[0] ?? null,
            'Order line items resolve through the variation rows, so a promoted link that '
            . 'stops at the product leaves every historical line pointing at nothing.',
        );

        // Product last: it is MappingPromoter's marker for "this decision is
        // finished", and nothing may follow it.
        $this->assertSame(
            [Constants::ENTITY_PRODUCT, '42', 900, $migrationId, false],
            $stored[1] ?? null,
            'POST /migrate must promote the owner\'s link, under this run\'s own migration id, '
            . 'with created_by_migration = 0 so rollback leaves their hand-made product alone.',
        );
    }

    // ── Reset ──────────────────────────────────────────────

    public function testResetOnIdleStateReportsNothingToClear(): void
    {
        $response = $this->controller->reset(new WP_REST_Request());
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($data['cleared']);
        $this->assertSame('idle', $data['previous_status']);
        $this->assertNull($data['cleared_migration_id']);
    }

    public function testResetClearsStaleRunningMigration(): void
    {
        $this->state->start(['product']);
        $migrationId = $this->state->getMigrationId();

        // Nothing queued, lock is free — nobody is driving this run.
        $response = $this->controller->reset(new WP_REST_Request());
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['cleared']);
        $this->assertSame($migrationId, $data['cleared_migration_id']);
        $this->assertSame('running', $data['previous_status']);
        $this->assertSame(['status' => 'idle'], $data['progress']);

        // State option is gone, so a new migration can start.
        $this->assertNull($this->state->getCurrent());
        $this->assertFalse($this->state->isRunning());
    }

    public function testResetExplainsItIsNotARollback(): void
    {
        $this->state->start(['product']);
        $migrationId = (string) $this->state->getMigrationId();

        $data = $this->controller->reset(new WP_REST_Request())->get_data()['data'];

        $this->assertStringContainsString('id-map entries are untouched', $data['message']);
        $this->assertStringContainsString('roll back migration ' . $migrationId, $data['message']);
    }

    public function testResetCancelsPendingBackgroundActions(): void
    {
        $this->state->start(['product']);
        $migrationId = $this->state->getMigrationId();

        $this->controller->reset($this->request(['force' => true]));

        $unscheduled = $GLOBALS['_cartshift_test_as_unscheduled'];

        $this->assertCount(1, $unscheduled);
        $this->assertSame(self::AS_HOOK, $unscheduled[0]['hook']);
        $this->assertSame([$migrationId], $unscheduled[0]['args']);
    }

    public function testResetRefusesWhenBackgroundBatchesAreQueued(): void
    {
        $this->state->start(['product']);
        $migrationId = $this->state->getMigrationId();

        $this->queuePendingAction((string) $migrationId);

        $response = $this->controller->reset(new WP_REST_Request());
        $data = $response->get_data()['data'];

        $this->assertSame(409, $response->get_status());
        $this->assertFalse($data['cleared']);
        $this->assertTrue($data['alive']);
        $this->assertTrue($this->state->isRunning(), 'State must survive a refused reset');
    }

    public function testResetRefusesWhenABatchHoldsTheLock(): void
    {
        $this->state->start(['product']);
        $this->stubLock(false);

        $response = $this->controller->reset(new WP_REST_Request());

        $this->assertSame(409, $response->get_status());
        $this->assertTrue($this->state->isRunning());
    }

    public function testForceResetClearsAnAliveMigration(): void
    {
        $this->state->start(['product']);
        $this->queuePendingAction((string) $this->state->getMigrationId());
        $this->stubLock(false);

        $response = $this->controller->reset($this->request(['force' => true]));
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['cleared']);
        $this->assertNull($this->state->getCurrent());
    }

    public function testResetClearsAFinishedMigrationWithoutForce(): void
    {
        $this->state->start(['product']);
        $this->state->complete();

        // Even a held lock must not block resetting a run that already finished.
        $this->stubLock(false);

        $response = $this->controller->reset(new WP_REST_Request());
        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['cleared']);
        $this->assertSame('completed', $data['previous_status']);
    }

    // ── Batch lock ─────────────────────────────────────────

    public function testBatchRefusesWhenTheLockIsHeld(): void
    {
        $this->state->start(['product'], true);
        $this->stubLock(false);

        $response = $this->controller->batch(new WP_REST_Request());
        $data = $response->get_data()['data'];

        $this->assertSame(409, $response->get_status());
        $this->assertTrue($data['locked']);
        $this->assertTrue($data['continue'], 'A lock clash is a retry, not a failure');

        // Nothing was processed, so the offset must not have moved.
        $this->assertSame(0, $this->state->getCurrentOffset());
        $this->assertSame(0, $this->state->getCurrentEntityIndex());
    }

    public function testBatchTakesAndReleasesTheLock(): void
    {
        $this->state->start(['product'], true);

        $response = $this->controller->batch(new WP_REST_Request());

        $this->assertSame(200, $response->get_status());

        $lockQueries = $this->lockQueries();

        $this->assertNotEmpty(
            array_filter($lockQueries, static fn (string $q): bool => str_contains($q, 'GET_LOCK')),
            'Batch processing must take the lock',
        );
        $this->assertNotEmpty(
            array_filter($lockQueries, static fn (string $q): bool => str_contains($q, 'RELEASE_LOCK')),
            'Batch processing must release the lock',
        );
    }

    public function testBatchReleasesTheLockWhenProcessingThrows(): void
    {
        $this->state->start(['product'], true);

        // Make the orchestrator blow up from inside the locked section.
        add_filter('cartshift/migration/batch_size', static function (): int {
            throw new \RuntimeException('boom');
        }, 99);

        try {
            $this->controller->batch(new WP_REST_Request());
        } catch (\RuntimeException) {
            // Expected — the point is what happened to the lock.
        }

        $released = array_filter(
            $this->lockQueries(),
            static fn (string $q): bool => str_contains($q, 'RELEASE_LOCK'),
        );

        $this->assertNotEmpty($released, 'The lock must be released even when a batch throws');
    }

    public function testBatchRejectsWhenNoMigrationIsRunning(): void
    {
        $response = $this->controller->batch(new WP_REST_Request());

        $this->assertSame(422, $response->get_status());
        $this->assertFalse($response->get_data()['data']['continue']);
    }

    // ── Progress ───────────────────────────────────────────

    public function testProgressReportsBackgroundSignals(): void
    {
        $this->state->start(['product']);
        $this->queuePendingAction((string) $this->state->getMigrationId());

        $data = $this->controller->progress(new WP_REST_Request())->get_data()['data'];

        $this->assertTrue($data['background_available']);
        $this->assertTrue($data['background_pending']);
    }

    public function testProgressReportsNoPendingWorkForAnAbandonedRun(): void
    {
        $this->state->start(['product']);

        $data = $this->controller->progress(new WP_REST_Request())->get_data()['data'];

        $this->assertSame('running', $data['status']);
        $this->assertFalse($data['background_pending']);
    }

    // ── Retry ──────────────────────────────────────────────

    public function testRetryRegistersItsRoute(): void
    {
        $this->controller->registerRoutes();

        $route = $GLOBALS['_cartshift_test_rest_routes']['cartshift/v1/retry'] ?? null;

        $this->assertNotNull($route, 'Retry needs a route or the UI has nothing to call');
        $this->assertSame('POST', $route['methods']);
        $this->assertTrue($route['args']['migration_id']['required']);
        $this->assertSame(['error'], $route['args']['statuses']['default']);
    }

    public function testRetryNeedsAMigrationId(): void
    {
        $response = $this->controller->retry($this->request(['migration_id' => '   ']));

        $this->assertSame(422, $response->get_status());
    }

    /**
     * Same guard as /migrate, same reason: two runs sharing one stored state
     * would overwrite each other's offsets and migrate records twice.
     */
    public function testRetryRefusesWhileAMigrationIsRunning(): void
    {
        $this->state->start(['product']);

        $response = $this->controller->retry($this->request(['migration_id' => 'source-1']));
        $data = $response->get_data()['data'];

        $this->assertSame(409, $response->get_status());
        $this->assertStringContainsString('already in progress', $data['message']);
        $this->assertArrayHasKey('progress', $data);
    }

    /**
     * A typo'd id would otherwise start a run with nothing to do and then report
     * success, which reads exactly like "there was nothing wrong".
     */
    public function testRetryRefusesAMigrationIdThatIsNotInTheLog(): void
    {
        $this->stubLogHasEntries(false);

        $response = $this->controller->retry($this->request(['migration_id' => 'never-happened']));

        $this->assertSame(404, $response->get_status());
        $this->assertStringContainsString('Nothing to retry', $response->get_data()['data']['message']);
    }

    public function testRetryRejectsEntityTypesThatAreNotOnTheWhitelist(): void
    {
        $this->stubLogHasEntries(true);

        $response = $this->controller->retry($this->request([
            'migration_id' => 'source-1',
            'entity_types' => ['wp_users', '../../etc/passwd'],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame('No valid entity types specified.', $response->get_data()['data']['message']);
    }

    public function testRetryRejectsStatusesThatAreNotRetryable(): void
    {
        $this->stubLogHasEntries(true);

        $response = $this->controller->retry($this->request([
            'migration_id' => 'source-1',
            'statuses'     => ['success', 'rollback'],
        ]));

        $this->assertSame(422, $response->get_status());
        $this->assertSame(
            MigrationLogRepository::RETRYABLE_STATUSES,
            $response->get_data()['data']['statuses'],
            'Refusing without saying what would have been accepted is just rude',
        );
    }

    /**
     * The whitelist is shared with /migrate so there is one list to keep honest.
     */
    public function testEntityTypeWhitelistIsTheSameOneMigrateUses(): void
    {
        $this->assertSame(
            ['product', 'order'],
            MigrationController::whitelistEntityTypes(['product', 'wp_users', 'order']),
        );
        $this->assertSame([], MigrationController::whitelistEntityTypes(['nonsense']));
    }

    public function testStatusWhitelistAcceptsArraysAndCommaSeparatedStrings(): void
    {
        $this->assertSame(['error'], MigrationController::whitelistStatuses(null));
        $this->assertSame(['error'], MigrationController::whitelistStatuses([]));
        $this->assertSame(['error', 'warning'], MigrationController::whitelistStatuses('error, warning'));
        $this->assertSame(['warning'], MigrationController::whitelistStatuses(['warning', 'success']));
        $this->assertSame([], MigrationController::whitelistStatuses(['success']));
    }

    /**
     * Retry leans on MigrationOrchestrator::startRetry(), which is owned
     * elsewhere. Until it lands the endpoint must say so plainly rather than
     * fatal on an undefined method; once it lands, the request must go through.
     * Asserting the equivalence rather than one branch keeps this test honest on
     * both sides of that change.
     */
    public function testRetryReportsUnavailableOnlyWhenTheOrchestratorCannotDoIt(): void
    {
        $this->stubLogHasEntries(true);

        $supported = method_exists(MigrationOrchestrator::class, 'startRetry');

        $response = $this->controller->retry($this->request([
            'migration_id' => 'source-1',
            'statuses'     => ['error', 'warning'],
        ]));

        if (!$supported) {
            $this->assertSame(503, $response->get_status());
            $this->assertStringContainsString('not available in this build', $response->get_data()['data']['message']);

            return;
        }

        $data = $response->get_data()['data'];

        $this->assertSame(200, $response->get_status());
        $this->assertSame('source-1', $data['retry_of'], 'The response must say what it is a retry of');
        $this->assertSame(['error', 'warning'], $data['statuses']);
        $this->assertArrayHasKey('background_available', $data, 'Same envelope as /migrate');
    }

    /**
     * `dry_run` on POST /retry now reaches the run.
     *
     * The controller used to check by reflection whether startRetry() had room
     * for the flag and, finding it did not, report `dry_run: false` — honest
     * about the outcome, but the UI still offered a checkbox that changed
     * nothing. The parameter exists, the reflection is gone, and what comes back
     * is what the run is actually doing.
     */
    public function testRetryPassesTheDryRunFlagThroughToTheRun(): void
    {
        $this->stubLogHasEntries(true);

        $response = $this->controller->retry($this->request([
            'migration_id' => 'source-1',
            'dry_run'      => true,
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['data']['dry_run']);
        $this->assertTrue(
            $this->state->isDryRun(),
            'The state the batch loop reads must agree with the response.',
        );
    }

    public function testRetryIsARealRunWhenNoDryRunWasAskedFor(): void
    {
        $this->stubLogHasEntries(true);

        $response = $this->controller->retry($this->request([
            'migration_id' => 'source-1',
        ]));

        $this->assertSame(200, $response->get_status());
        $this->assertFalse($response->get_data()['data']['dry_run']);
        $this->assertFalse($this->state->isDryRun());
    }

    // ── Helpers ────────────────────────────────────────────

    /**
     * Make MigrationLogRepository::hasEntries() answer yes or no.
     *
     * It reads through get_var, which the lock stub also owns, so both live in
     * one callback rather than fighting over the global.
     */
    private function stubLogHasEntries(bool $exists): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] =
            static function (string $query) use ($exists): ?string {
                if (str_contains($query, 'RELEASE_LOCK') || str_contains($query, 'GET_LOCK')) {
                    return '1';
                }

                if (str_contains($query, 'cartshift_migration_log')) {
                    return $exists ? '1' : null;
                }

                return '0';
            };
    }

    private function makeContainer(): Container
    {
        $container = new Container();

        $container->instance(MigrationState::class, $this->state);
        $container->singleton(IdMapRepository::class, static fn (): IdMapRepository => new IdMapRepository());
        $container->singleton(
            MigrationLogRepository::class,
            static fn (): MigrationLogRepository => new MigrationLogRepository(),
        );

        // The real assembler, not a stand-in: buildOrchestrator() delegating to
        // it is exactly the thing under test in testMigratePromotesSavedLinks(),
        // and a container that handed the controller anything simpler would let
        // the defect back in unnoticed.
        $container->singleton(
            MigrationOrchestratorFactory::class,
            static fn (Container $c): MigrationOrchestratorFactory => MigrationOrchestratorFactory::standalone(
                $c->get(IdMapRepository::class),
                $c->get(MigrationLogRepository::class),
                $c->get(MigrationState::class),
            ),
        );

        $container->singleton(BatchProcessor::class, static function (Container $c): BatchProcessor {
            $factory = static fn (): MigrationOrchestrator => new MigrationOrchestrator(
                [],
                $c->get(MigrationState::class),
                $c->get(IdMapRepository::class),
                $c->get(MigrationLogRepository::class),
            );

            return new BatchProcessor($factory, $c->get(MigrationState::class));
        });

        return $container;
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

    private function stubLock(bool $acquired): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use ($acquired): ?string {
            if (str_contains($query, 'RELEASE_LOCK')) {
                return '1';
            }

            if (str_contains($query, 'GET_LOCK')) {
                return $acquired ? '1' : '0';
            }

            return '0';
        };
    }

    private function queuePendingAction(string $migrationId): void
    {
        $GLOBALS['_cartshift_test_as_pending'][] = [
            'hook'  => self::AS_HOOK,
            'args'  => [$migrationId],
            'group' => self::AS_GROUP,
        ];
    }

    /**
     * @return string[]
     */
    private function lockQueries(): array
    {
        $queries = [];

        foreach ($GLOBALS['_cartshift_test_queries'] as $entry) {
            if (($entry[0] ?? '') === 'get_var' && is_string($entry[1] ?? null)) {
                $queries[] = $entry[1];
            }
        }

        return $queries;
    }
}
