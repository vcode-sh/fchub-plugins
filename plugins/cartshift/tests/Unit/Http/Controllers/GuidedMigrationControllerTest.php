<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Transfer\Execution\ConfiguredTransferEvidence;
use CartShift\Domain\Transfer\Execution\RollbackPlan;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeSymbols;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedCustomerDecisionBuilder;
use CartShift\Domain\Transfer\SameSite\GuidedRunState;
use CartShift\Domain\Transfer\SameSite\GuidedRunFailure;
use CartShift\Domain\Transfer\SameSite\GuidedRollback;
use CartShift\Domain\Transfer\SameSite\GuidedStep;
use CartShift\Domain\Transfer\SameSite\SiteSourceIdentity;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Http\Controllers\GuidedMigrationController;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/PreflightStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/ZeroWriteGuard.php';

/**
 * The guided screen's only endpoint, and the promise it makes.
 *
 * It reads. A screen that polled a route which quietly wrote would be the
 * rehearsal-versus-read-only confusion the subscription audit was built to
 * correct, arriving on a different screen — so the same guard watches this one,
 * and the guard is proved able to catch a real write before it is trusted to
 * report the absence of one.
 */
final class GuidedMigrationControllerTest extends PluginTestCase
{
    private ?string $privateWorkspace = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): string => 'exists';
        $GLOBALS['_cartshift_test_hpos_enabled'] = true;
    }

    #[\Override]
    protected function tearDown(): void
    {
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID);

        unset($GLOBALS['_cartshift_test_get_var_callback'], $GLOBALS['_cartshift_test_current_user_id']);
        unset($GLOBALS['_cartshift_test_post_status_counts']);

        if ($this->privateWorkspace !== null) {
            foreach (glob($this->privateWorkspace . '/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->privateWorkspace);
        }

        parent::tearDown();
    }

    /**
     * THE GUARD ALREADY EARNED ITS KEEP HERE.
     *
     * The first version of this endpoint called `SiteSourceIdentity::ensure()`,
     * which mints and stores the site's key — a status poll performing a
     * configuration write. The guard caught it. Naming the site now lives behind
     * an explicit POST, and this asserts the read stays a read whether or not
     * the site has been named.
     */
    public function testTheStatusReadWritesNothingAtAll(): void
    {
        $unnamed = \CartShiftZeroWriteGuard::watch(fn (): array => $this->guidedStatus());

        self::assertSame([], $unnamed['violations'], 'The guided status endpoint attempted a write.');
        self::assertFalse($unnamed['result']['initialised']);

        $this->nameTheSite();

        $named = \CartShiftZeroWriteGuard::watch(fn (): array => $this->guidedStatus());

        self::assertSame([], $named['violations'], 'The guided status endpoint wrote once the site was named.');
        self::assertTrue($named['result']['initialised']);
    }

    public function testStatusWithUnsupportedProductsReadsTheirImpactWithoutCachingIt(): void
    {
        $this->nameTheSite();
        $GLOBALS['_cartshift_test_transients'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (string $query): array =>
            str_contains($query, 'product_type')
                ? [(object) ['slug' => 'course', 'count' => 2]]
                : [];
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string {
            if (str_contains($query, 'SHOW TABLES LIKE')) return 'exists';
            if (str_contains($query, 'woocommerce_order_items')) return '41';
            if (str_contains($query, 'wc_orders')) return '699';
            return '0';
        };

        try {
            $watched = \CartShiftZeroWriteGuard::watch(fn (): array => $this->guidedStatus());
        } finally {
            unset($GLOBALS['_cartshift_test_get_results_callback']);
        }

        self::assertSame([], $watched['violations']);
        self::assertSame([], $GLOBALS['_cartshift_test_transients']);
        $checks = array_column($watched['result']['preflight']['checks'], null, 'label');
        self::assertSame('warn', $checks['Product types']['severity']);
        self::assertStringContainsString('41', $checks['Product types']['message']);
    }

    public function testStatusReplacesStorageAndApiInternalsWithPlainEnglish(): void
    {
        $this->nameTheSite();
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string {
            if (str_contains($query, 'SHOW TABLES LIKE')) return '';
            return '0';
        };

        $encoded = json_encode($this->guidedStatus(), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('CartShift storage is not ready', $encoded);
        self::assertStringNotContainsString('wp_cartshift', $encoded);
        self::assertStringNotContainsString('max_execution_time', $encoded);
        self::assertStringNotContainsString('wc_get_', $encoded);
    }

    public function testNamingTheSiteIsAnExplicitActionAndIsIdempotent(): void
    {
        $first = $this->nameTheSite();

        self::assertMatchesRegularExpression('/\Asite-[a-f0-9]{16}\z/D', $first);
        self::assertSame($first, $this->nameTheSite());
    }

    /**
     * Not vacuous: the guard catches a real write, so the assertion above means
     * the endpoint did not make one rather than that nothing was watched.
     */
    public function testTheGuardWouldCatchARealWrite(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static function (): void {
            update_option('cartshift_guided_probe', 'written');
        });

        self::assertContains('option state changed', $watched['violations']);
    }

    public function testTheShopIsToldWhatIsMissingRatherThanJustThatItIsNotReady(): void
    {
        $this->nameTheSite();

        $setup = $this->guidedStatus()['setup'];

        self::assertFalse($setup['complete']);
        self::assertSame(
            [ConfiguredTransferEvidence::PRIVATE_DIRECTORY, ConfiguredTransferEvidence::OPERATOR_ID],
            array_column($setup['missing'], 'constant'),
        );
        self::assertTrue($setup['can_copy_lines']);
        self::assertArrayNotHasKey('snippet', $setup);
        self::assertArrayNotHasKey('suggested', $setup['missing'][0]);

        $response = $this->controller()->setupLines(new WP_REST_Request());
        self::assertSame(200, $response->get_status());
        self::assertStringContainsString('define(', $response->get_data()['data']['lines']);
    }

    /**
     * The whole reason this workstream exists: a shop without the optional
     * add-on is a shop that migrates everything else.
     */
    public function testAShopWithoutSubscriptionsIsUsableAndSaysSoWithoutClaimingZero(): void
    {
        $this->nameTheSite();

        $data = $this->guidedStatus();

        self::assertTrue($data['guided_available']);
        self::assertFalse($data['subscriptions']['available']);
        self::assertSame(['available'], array_keys($data['subscriptions']));

        // Twelve steps, all of them present. Nothing about the missing add-on
        // shortens the plan for products, customers and orders.
        self::assertCount(12, $data['plan']);
        self::assertNull($data['plan_blocked']);
    }

    public function testStandaloneCouponsAreReportedWithoutBlockingTheRestOfTheShop(): void
    {
        $this->nameTheSite();
        $GLOBALS['_cartshift_test_post_status_counts']['shop_coupon'] = (object) [
            'publish' => 1,
            'draft' => 0,
            'private' => 0,
        ];

        $data = $this->guidedStatus();

        self::assertTrue($data['preflight']['ready']);
        $checks = array_column($data['preflight']['checks'], null, 'label');
        self::assertSame('warn', $checks['Standalone coupons']['severity']);
        self::assertStringContainsString('1 standalone WooCommerce coupon', $checks['Standalone coupons']['message']);
        self::assertStringContainsString('will not be migrated', $checks['Standalone coupons']['message']);
    }

    public function testExistingFluentCartRecordsBlockStatusAndDirectStart(): void
    {
        $this->nameTheSite();
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): string {
            if (str_contains($query, 'SHOW TABLES LIKE')) return 'exists';
            if (str_contains($query, "post_type = 'fluent-products'")) return '1';
            return '0';
        };

        $status = $this->guidedStatus();
        $checks = array_column($status['preflight']['checks'], null, 'label');
        $response = $this->controller()->start(new WP_REST_Request());

        self::assertFalse($status['preflight']['ready']);
        self::assertSame('fail', $checks['Existing FluentCart records']['severity']);
        self::assertStringContainsString('could create duplicates', $checks['Existing FluentCart records']['message']);
        self::assertStringContainsString('remove them in FluentCart', $checks['Existing FluentCart records']['message']);
        self::assertStringContainsString('CartShift will not overwrite them', $checks['Existing FluentCart records']['message']);
        self::assertStringNotContainsString('target matching', $checks['Existing FluentCart records']['message']);
        self::assertSame(409, $response->get_status());
    }

    public function testThePlanProjectionContainsFriendlyProgressWithoutCommandsOrPaths(): void
    {
        $this->nameTheSite();

        $plan = $this->guidedStatus()['plan'];

        self::assertSame('Check compatibility', $plan[0]['label']);
        self::assertFalse($plan[0]['completed']);
        foreach ($plan as $step) {
            self::assertSame(['label', 'completed'], array_keys($step));
        }
    }

    /**
     * The audit walks every product, customer and order in the shop. A screen
     * that triggered it on each poll would put the most expensive read in the
     * plugin behind a page load.
     */
    public function testStatusExposesOnlyTheFriendlyReadinessProjection(): void
    {
        $this->nameTheSite();

        $data = $this->guidedStatus();

        self::assertSame(['ready', 'checks'], array_keys($data['preflight']));
        self::assertTrue(array_is_list($data['preflight']['checks']));
        foreach ($data['preflight']['checks'] as $check) {
            self::assertSame(['label', 'severity', 'message'], array_keys($check));
        }
        self::assertArrayNotHasKey('audit', $data);
    }

    /**
     * THE REVIEW STEP WRITES ONLY WHEN ASKED.
     *
     * `propose-decisions` returns `owner_review_required` and writes nothing;
     * the decision set exists because somebody accepted it. A status poll that
     * produced one would be the machine taking the one judgement it must not.
     */
    public function testTheDecisionSetDoesNotExistUntilTheOwnerAcceptsIt(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();

        self::assertFileDoesNotExist($this->privateWorkspace . '/decisions.json');
    }

    public function testAcceptingWithoutNamingTheSiteIsRefused(): void
    {
        $response = $this->controller()->acceptDecisions(new WP_REST_Request());

        self::assertSame(409, $response->get_status());
    }

    public function testCutoverIsReportedUnavailableBeforeAnybodyStarts(): void
    {
        $this->nameTheSite();

        $cutover = $this->guidedStatus()['setup']['cutover'];

        self::assertFalse($cutover['available']);
        self::assertArrayNotHasKey('reason', $cutover);
        self::assertStringContainsString('roll back a completed rehearsal', $cutover['message']);
    }

    public function testStartPersistsEveryCompletedStepAndResumesPastPrepare(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $calls = [];
        $workspace = $this->privateWorkspace;
        $proposal = $this->proposal();
        $controller = $this->controller(static function (GuidedStep $step) use (&$calls, $workspace, $proposal): array {
            $calls[] = $step->verb;

            return match ($step->verb) {
                'compatibility' => ['ready' => true],
                'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
                'propose-decisions' => $proposal,
                'export' => ['path' => $workspace . '/package'],
                'validate-package' => ['status' => 'validated'],
                'prepare' => ['descriptor' => 'tr-491f7178d619ae327139ae2e'],
                default => ['state' => $step->verb],
            };
        });

        $first = $controller->start(new WP_REST_Request());
        self::assertSame(1, $first->get_data()['data']['completed_steps']);
        $review = $this->driveToReview($controller);
        self::assertSame(200, $review->get_status());
        self::assertSame(GuidedRunState::AWAITING_DECISIONS, $review->get_data()['data']['phase']);

        $accepted = $controller->acceptDecisions($this->approvalRequest($review));
        self::assertSame(200, $accepted->get_status());

        $finished = $this->driveToTerminal($controller);
        self::assertSame('unsafe_completion', $finished->get_data()['data']['phase']);
        self::assertSame(1, count(array_filter($calls, static fn (string $verb): bool => $verb === 'prepare')));

        $status = $controller->status(new WP_REST_Request())->get_data()['data'];
        self::assertSame('unsafe_completion', $status['run']['phase']);
        self::assertFalse($status['run']['failure']['can_restart']);
        self::assertSame(12, $status['run']['completed_steps']);
    }

    public function testStartRefusesABlockingPreflightBeforeCreatingRunState(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $GLOBALS['_cartshift_test_hpos_enabled'] = false;

        $response = $this->controller(static fn (): array => throw new \LogicException('must not run'))
            ->start(new WP_REST_Request());

        self::assertSame(409, $response->get_status());
        self::assertSame(
            ['message' => 'Resolve the blocking shop checks before starting.'],
            $response->get_data()['data'],
        );
        self::assertSame([], glob($this->privateWorkspace . '/guided-run-*.json') ?: []);
    }

    public function testAnOlderPausedReviewRemainsReadableAndCanBeCancelled(): void
    {
        $this->configurePrivateWorkspace();
        $sourceKey = $this->nameTheSite();
        $repository = new \CartShift\Domain\Transfer\SameSite\GuidedRunStateRepository(
            $this->privateWorkspace,
            $sourceKey,
        );
        $legacy = GuidedRunState::start($sourceKey, 'wp-user:1', '2026-08-12T12:00:00Z')
            ->afterStep('compatibility', ['ready' => true], 12)
            ->afterStep('compatibility', ['ready' => true], 12)
            ->afterStep('audit', ['selection_fingerprint' => str_repeat('a', 64)], 12)
            ->afterStep('propose-decisions', [
                'status' => 'owner_review_required',
                'blockers' => [],
                'decision_set' => ['decisions' => []],
            ], 12);
        $repository->transaction(static fn (): GuidedRunState => $legacy);

        $run = $this->controller()->status(new WP_REST_Request())->get_data()['data']['run'];

        self::assertSame(GuidedRunState::AWAITING_DECISIONS, $run['phase']);
        self::assertSame([], $run['review']['items']);
        self::assertStringContainsString('older CartShift version', $run['review']['blockers'][0]);
        self::assertSame(GuidedRunState::CANCELLED, $this->controller()->cancel(new WP_REST_Request())->get_data()['data']['phase']);
    }

    public function testAChangedSubscriptionModeKeepsTheRunVisibleAndRecordsNoStaleApproval(): void
    {
        $this->configurePrivateWorkspace();
        $sourceKey = $this->nameTheSite();
        $proposal = $this->proposal();
        $state = GuidedRunState::start($sourceKey, 'wp-user:1', '2026-08-12T12:00:00Z', true)
            ->afterStep('compatibility', ['ready' => true], 12)
            ->afterStep('compatibility', ['ready' => true], 12)
            ->afterStep('audit', ['selection_fingerprint' => str_repeat('a', 64)], 12)
            ->afterStep('propose-decisions', $proposal, 12);
        (new \CartShift\Domain\Transfer\SameSite\GuidedRunStateRepository(
            $this->privateWorkspace,
            $sourceKey,
        ))->transaction(static fn (): GuidedRunState => $state);
        $controller = $this->controller(static fn (): array => $proposal);

        $status = $controller->status(new WP_REST_Request())->get_data()['data'];

        self::assertNotNull($status['run']);
        self::assertSame(GuidedRunState::AWAITING_DECISIONS, $status['run']['phase']);
        self::assertStringContainsString('Subscription availability changed', $status['run']['mode_changed']);

        $request = new WP_REST_Request();
        $request->set_param('approved_reviews', array_column($status['run']['review']['items'], 'review_id'));
        $accepted = $controller->acceptDecisions($request)->get_data()['data'];

        self::assertSame(GuidedRunState::FAILED, $accepted['run']['phase']);
        self::assertStringContainsString('Subscription availability changed', $accepted['run']['failure']['message']);
        self::assertFalse($accepted['run']['failure']['can_restart']);
        self::assertFileDoesNotExist($this->privateWorkspace . '/decisions.json');
    }

    public function testCancelStopsAnAwaitingRunWithoutTouchingTheTarget(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $proposal = $this->proposal();
        $controller = $this->controller(static fn (GuidedStep $step): array => match ($step->verb) {
            'compatibility' => ['ready' => true],
            'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
            default => $proposal,
        });
        $this->driveToReview($controller);

        $cancelled = $controller->cancel(new WP_REST_Request());

        self::assertSame(GuidedRunState::CANCELLED, $cancelled->get_data()['data']['phase']);
    }

    public function testCustomerOwnershipIsAskedPerCustomerAndMergedOnlyAfterApproval(): void
    {
        $this->configurePrivateWorkspace();
        $sourceKey = $this->nameTheSite();
        $record = RecordEnvelope::forPayload(
            2,
            new SourceIdentity($sourceKey, 'customer', '7'),
            [
                'classification' => 'registered',
                'source_user_id' => 7,
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'email' => 'ada@example.test',
            ],
        );
        $builder = new GuidedCustomerDecisionBuilder(static fn (): RecordEnvelope => $record);
        $proposal = $this->proposal([[
            'code' => 'customer_ownership_decision_requires_owner',
            'identity' => $sourceKey . ':customer:7',
        ]]);
        $controller = $this->controller(static fn (GuidedStep $step): array => match ($step->verb) {
            'compatibility' => ['ready' => true],
            'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
            default => $proposal,
        }, $builder);

        $reviewResponse = $this->driveToReview($controller);
        $review = $reviewResponse->get_data()['data']['review'];
        self::assertSame('Ada Lovelace', $review['items'][0]['title']);
        self::assertStringNotContainsString($sourceKey, json_encode($review, JSON_THROW_ON_ERROR));

        $accepted = $controller->acceptDecisions($this->approvalRequest($reviewResponse));

        self::assertSame(200, $accepted->get_status());
        self::assertSame(1, $accepted->get_data()['data']['accepted']);
    }

    public function testSourceChangeDuringReviewReplacesTheReviewWithoutWritingTheOldDecision(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $old = $this->proposal();
        $current = $old;
        $current['proposal_decisions'][0]['source_fingerprint'] = str_repeat('b', 64);
        $current['decision_set']['decisions'][0]['source_fingerprint'] = str_repeat('b', 64);
        $proposals = 0;
        $controller = $this->controller(static function (GuidedStep $step) use (
            $old,
            $current,
            &$proposals,
        ): array {
            return match ($step->verb) {
                'compatibility' => ['ready' => true],
                'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
                'propose-decisions' => ++$proposals === 1 ? $old : $current,
                default => throw new \LogicException('unexpected_step'),
            };
        });
        $oldReview = $this->driveToReview($controller);
        $oldId = $oldReview->get_data()['data']['review']['items'][0]['review_id'];

        $response = $controller->acceptDecisions($this->approvalRequest($oldReview));
        $data = $response->get_data()['data'];

        self::assertSame(200, $response->get_status());
        self::assertTrue($data['review_changed']);
        self::assertSame(GuidedRunState::AWAITING_DECISIONS, $data['run']['phase']);
        self::assertNotSame($oldId, $data['run']['review']['items'][0]['review_id']);
        self::assertFileDoesNotExist($this->privateWorkspace . '/decisions.json');
    }

    public function testGuestDownloadChangeDuringReviewRequiresFreshApproval(): void
    {
        $this->configurePrivateWorkspace();
        $sourceKey = $this->nameTheSite();
        $record = RecordEnvelope::forPayload(
            2,
            new SourceIdentity($sourceKey, 'customer', '91:guest'),
            [
                'classification' => 'guest',
                'source_user_id' => null,
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'email' => 'grace@example.test',
                'provenance' => ['source_order_id' => 91],
            ],
        );
        $downloadable = true;
        $builder = new GuidedCustomerDecisionBuilder(
            static fn (): RecordEnvelope => $record,
            static function () use (&$downloadable): bool {
                return $downloadable;
            },
        );
        $proposal = $this->proposal([[
            'code' => 'customer_ownership_decision_requires_owner',
            'identity' => $record->identity->canonical(),
        ]]);
        $controller = $this->controller(static fn (GuidedStep $step): array => match ($step->verb) {
            'compatibility' => ['ready' => true],
            'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
            default => $proposal,
        }, $builder);
        $oldReview = $this->driveToReview($controller);
        $oldId = $oldReview->get_data()['data']['review']['items'][0]['review_id'];
        $downloadable = false;

        $response = $controller->acceptDecisions($this->approvalRequest($oldReview));
        $run = $response->get_data()['data']['run'];

        self::assertTrue($response->get_data()['data']['review_changed']);
        self::assertSame(GuidedRunState::AWAITING_DECISIONS, $run['phase']);
        self::assertNotSame($oldId, $run['review']['items'][0]['review_id']);
        self::assertStringContainsString('unlinked from a WordPress account', $run['review']['items'][0]['summary']);
        self::assertFileDoesNotExist($this->privateWorkspace . '/decisions.json');
    }

    public function testApprovalRecordsTheCurrentOwnerRatherThanTheRunStarter(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $proposal = $this->proposal();
        $controller = $this->controller(static fn (GuidedStep $step): array => match ($step->verb) {
            'compatibility' => ['ready' => true],
            'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
            default => $proposal,
        });
        $review = $this->driveToReview($controller);
        $GLOBALS['_cartshift_test_current_user_id'] = 9;

        $response = $controller->acceptDecisions($this->approvalRequest($review));
        $rows = TransferDecisionSet::fromFile($this->privateWorkspace . '/decisions.json')->rows();

        self::assertSame(200, $response->get_status());
        self::assertSame('wp-user:9', $rows[0]['operator']);
    }

    public function testAFailedRunExposesAnExactRollbackPreviewAndRequiresItsConfirmation(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $workspace = $this->privateWorkspace;
        $plan = new RollbackPlan('tr-491f7178d619ae327139ae2e', 1, [], [], true);
        $rollbackFactory = static fn (GuidedRunState $state): GuidedRollback => new GuidedRollback(
            $workspace,
            $state,
            static fn (): RollbackPlan => $plan,
            static fn (): array => ['state' => 'rolled_back'],
        );
        $proposal = $this->proposal();
        $controller = $this->controller(static fn (GuidedStep $step): array => match ($step->verb) {
            'compatibility' => ['ready' => true],
            'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
            'propose-decisions' => $proposal,
            'export' => ['path' => $workspace . '/package'],
            'validate-package' => ['status' => 'validated'],
            'prepare' => ['descriptor' => 'tr-491f7178d619ae327139ae2e'],
            default => throw new \RuntimeException('stage_failed'),
        }, rollbackFactory: $rollbackFactory);

        $review = $this->driveToReview($controller);
        $controller->acceptDecisions($this->approvalRequest($review));
        $failed = $this->driveToTerminal($controller)->get_data()['data'];

        self::assertSame(GuidedRunState::FAILED, $failed['phase']);
        self::assertTrue($failed['rollback']['safe']);
        self::assertSame(0, $failed['rollback']['deletion_count']);

        $request = new WP_REST_Request();
        $request->set_param('review_id', $failed['rollback']['review_id']);
        $rolledBack = $controller->rollback($request);

        self::assertSame(200, $rolledBack->get_status(), json_encode($rolledBack->get_data(), JSON_THROW_ON_ERROR));
        self::assertSame(GuidedRunState::ROLLED_BACK, $rolledBack->get_data()['data']['phase']);
    }

    public function testKnownCapabilityStopIsFriendlyAndDoesNotOfferAPointlessRetry(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $controller = $this->controller(
            static fn (): array => throw new \RuntimeException(
                'guided_completed_rehearsal_rollback_unavailable',
            ),
        );

        $failed = $controller->start(new WP_REST_Request())->get_data()['data'];

        self::assertFalse($failed['failure']['can_restart']);
        self::assertStringContainsString('cannot yet roll back', $failed['failure']['message']);
        self::assertStringNotContainsString('guided_completed', json_encode($failed, JSON_THROW_ON_ERROR));
    }

    public function testStockCompromiseIsPresentedAsFriendlyActionableGuiReport(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $controller = $this->controller(static fn (): array => throw new GuidedRunFailure(
            'guided_completed_rehearsal_rollback_unavailable',
            ['migration_exceptions' => [[
                'kind' => 'shared_parent_stock',
                'product_name' => 'Trail harness',
                'variation_name' => 'Harness size: Large',
                'sku' => 'HARNESS-L',
                'source_owner' => 'site-secret:product:42',
                'source_quantity' => 11,
                'source_status' => 'instock',
                'source_backorders' => 'yes',
                'source_variation' => 'site-secret:product:42:variation:101',
            ], [
                'kind' => 'shared_parent_stock',
                'product_name' => 'Trail harness',
                'variation_name' => 'Harness size: Small',
                'sku' => 'HARNESS-S',
                'source_owner' => 'site-secret:product:42',
                'source_quantity' => 11,
                'source_status' => 'instock',
                'source_backorders' => 'yes',
            ]]],
        ));

        $failed = $controller->start(new WP_REST_Request())->get_data()['data'];
        $report = $failed['migration_exceptions'][0];

        self::assertSame('Trail harness', $report['title']);
        self::assertSame([
            ['title' => 'Harness size: Large', 'sku' => 'HARNESS-L'],
            ['title' => 'Harness size: Small', 'sku' => 'HARNESS-S'],
        ], $report['variations']);
        self::assertSame(11, $report['source_quantity']);
        self::assertCount(3, $report['suggestions']);
        self::assertStringContainsString('prevent overselling', $report['message']);
        self::assertStringContainsString('backorders disabled', $report['message']);
        self::assertCount(1, $failed['migration_exceptions']);
        self::assertStringNotContainsString('site-secret', json_encode($failed, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('shared_parent_stock', json_encode($failed, JSON_THROW_ON_ERROR));
    }

    public function testUnknownOrNegativeSharedStockNeverGetsAllocationAdvice(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $controller = $this->controller(static fn (): array => throw new GuidedRunFailure(
            'guided_completed_rehearsal_rollback_unavailable',
            ['migration_exceptions' => [[
                'kind' => 'shared_parent_stock',
                'product_name' => 'Unknown stock',
                'variation_name' => 'One',
                'source_owner' => 'secret:product:1',
                'source_quantity' => null,
            ], [
                'kind' => 'shared_parent_stock',
                'product_name' => 'Negative stock',
                'variation_name' => 'One',
                'source_owner' => 'secret:product:2',
                'source_quantity' => -1,
            ]]],
        ));

        $reports = $controller->start(new WP_REST_Request())->get_data()['data']['migration_exceptions'];
        $byTitle = array_column($reports, null, 'title');

        self::assertSame('unknown', $byTitle['Unknown stock']['source_quantity_state']);
        self::assertSame('below_zero', $byTitle['Negative stock']['source_quantity_state']);
        self::assertStringContainsString('Count the available stock', $byTitle['Unknown stock']['suggestions'][0]);
        self::assertStringNotContainsString('Allocate', json_encode($reports, JSON_THROW_ON_ERROR));
    }

    public function testNonReversibleCompletedRehearsalStopsBeforeTargetPreparation(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $controller = $this->controller(
            static fn (): array => throw new \RuntimeException(
                'guided_completed_rehearsal_rollback_unavailable',
            ),
        );

        $failed = $controller->start(new WP_REST_Request())->get_data()['data'];

        self::assertFalse($failed['failure']['can_restart']);
        self::assertStringContainsString('cannot yet roll back a completed rehearsal', $failed['failure']['message']);
        self::assertSame(0, $failed['completed_steps']);
    }

    public function testDependencyBoundReadinessFailureSaysOnlyTargetWritesWerePrevented(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $controller = $this->controller(static fn (): array => throw new \RuntimeException(
            'guided_dependency_bound_target_readiness_unavailable',
        ));

        $failed = $controller->start(new WP_REST_Request())->get_data()['data'];

        self::assertStringContainsString('before writing target records', $failed['failure']['message']);
        self::assertStringNotContainsString('before writing anything', $failed['failure']['message']);
    }

    public function testInterruptedRollbackResumesFromPersistedIntentWithoutLosingTheRun(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();
        $workspace = $this->privateWorkspace;
        $attempts = 0;
        $plan = new RollbackPlan('tr-491f7178d619ae327139ae2e', 1, [], [], true);
        $rollbackFactory = static function (GuidedRunState $state) use (
            $workspace,
            $plan,
            &$attempts,
        ): GuidedRollback {
            return new GuidedRollback(
                $workspace,
                $state,
                static fn (): RollbackPlan => $plan,
                static function () use (&$attempts): array {
                    ++$attempts;
                    if ($attempts === 1) {
                        throw new \RuntimeException('response_lost_after_core_rollback');
                    }

                    return ['state' => 'rolled_back'];
                },
            );
        };
        $proposal = $this->proposal();
        $controller = $this->controller(static fn (GuidedStep $step): array => match ($step->verb) {
            'compatibility' => ['ready' => true],
            'audit' => ['selection_fingerprint' => str_repeat('a', 64)],
            'propose-decisions' => $proposal,
            'export' => ['path' => $workspace . '/package'],
            'validate-package' => ['status' => 'validated'],
            'prepare' => ['descriptor' => 'tr-491f7178d619ae327139ae2e'],
            default => throw new \RuntimeException('stage_failed'),
        }, rollbackFactory: $rollbackFactory);
        $review = $this->driveToReview($controller);
        $controller->acceptDecisions($this->approvalRequest($review));
        $failed = $this->driveToTerminal($controller)->get_data()['data'];
        $request = new WP_REST_Request();
        $request->set_param('review_id', $failed['rollback']['review_id']);

        self::assertSame(422, $controller->rollback($request)->get_status());
        $interrupted = $controller->status(new WP_REST_Request())->get_data()['data']['run'];
        self::assertSame(GuidedRunState::ROLLING_BACK, $interrupted['phase']);
        self::assertSame($failed['rollback']['review_id'], $interrupted['rollback']['review_id']);

        $resumed = $controller->rollback($request);

        self::assertSame(200, $resumed->get_status(), json_encode($resumed->get_data(), JSON_THROW_ON_ERROR));
        self::assertSame(GuidedRunState::ROLLED_BACK, $resumed->get_data()['data']['phase']);
        self::assertSame(2, $attempts);
    }

    public function testStatusPresentationOmitsSourceKeysWorkspacesCommandsFingerprintsAndPaths(): void
    {
        $this->configurePrivateWorkspace();
        $this->nameTheSite();

        $data = $this->guidedStatus();
        $forbidden = [
            'source_key', 'workspace', 'command', 'fingerprint', 'path', 'confirm', 'pending',
            'reason', 'skipped_reason', 'code', 'counts', 'errors', 'warnings',
        ];
        $this->assertKeysAbsentRecursively($data, $forbidden);
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->privateWorkspace, $encoded);
        self::assertStringNotContainsString('wp cartshift', $encoded);
    }

    public function testUnconfiguredStatusDoesNotExposeOrCreateTheSuggestedPrivateDirectory(): void
    {
        $sourceKey = $this->nameTheSite();
        $expected = sys_get_temp_dir() . '/cartshift-private/' . $sourceKey;
        $before = is_dir($expected) ? (glob($expected . '/*') ?: []) : null;

        $data = $this->guidedStatus();

        self::assertStringNotContainsString($expected, json_encode($data, JSON_THROW_ON_ERROR));
        self::assertSame($before, is_dir($expected) ? (glob($expected . '/*') ?: []) : null);
    }

    private function nameTheSite(): string
    {
        $response = $this->controller()->initialise(new WP_REST_Request());

        self::assertSame(200, $response->get_status());

        return (new SiteSourceIdentity())->current()
            ?? throw new \LogicException('The site identity was not persisted.');
    }

    private function controller(
        ?\Closure $runStep = null,
        ?GuidedCustomerDecisionBuilder $customerDecisions = null,
        ?\Closure $rollbackFactory = null,
    ): GuidedMigrationController
    {
        return new GuidedMigrationController(
            new Container(),
            self::sameSiteProbe(),
            $runStep,
            $customerDecisions,
            $rollbackFactory,
        );
    }

    private function configurePrivateWorkspace(): void
    {
        $this->privateWorkspace = sys_get_temp_dir() . '/cartshift-guided-controller-' . bin2hex(random_bytes(8));
        mkdir($this->privateWorkspace, 0700);
        putenv(ConfiguredTransferEvidence::PRIVATE_DIRECTORY . '=' . $this->privateWorkspace);
        putenv(ConfiguredTransferEvidence::OPERATOR_ID . '=wp-user:1');
    }

    private function driveToReview(GuidedMigrationController $controller): \WP_REST_Response
    {
        do {
            $response = $controller->start(new WP_REST_Request());
        } while ($response->get_data()['data']['phase'] === GuidedRunState::RUNNING);

        return $response;
    }

    private function driveToTerminal(GuidedMigrationController $controller): \WP_REST_Response
    {
        do {
            $response = $controller->start(new WP_REST_Request());
        } while ($response->get_data()['data']['phase'] === GuidedRunState::RUNNING);

        return $response;
    }

    private function approvalRequest(\WP_REST_Response $review): WP_REST_Request
    {
        $request = new WP_REST_Request();
        $request->set_param(
            'approved_reviews',
            array_column($review->get_data()['data']['review']['items'], 'review_id'),
        );

        return $request;
    }

    /** @param array<string,mixed> $value @param list<string> $forbidden */
    private function assertKeysAbsentRecursively(array $value, array $forbidden): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                self::assertNotContains($key, $forbidden);
            }
            if (is_array($item)) {
                $this->assertKeysAbsentRecursively($item, $forbidden);
            }
        }
    }

    /** @param list<array{code:string,identity:string}> $blockers @return array<string,mixed> */
    private function proposal(array $blockers = []): array
    {
        $sourceKey = (new SiteSourceIdentity())->current() ?? 'site-0123456789abcdef';
        $row = [
            'identity' => $sourceKey . ':product:10',
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => str_repeat('a', 64),
            'operator' => 'wp-user:1',
            'reason' => 'Owner reviewed the product.',
            'decided_at' => '2026-08-12T12:00:00Z',
        ];
        $rows = $blockers === [] ? [$row] : [];

        return [
            'status' => $blockers === [] ? 'owner_review_required' : 'blocked',
            'blockers' => $blockers,
            'base_decision_fingerprint' => TransferDecisionSet::empty()->fingerprint(),
            'renewed_audit_decisions' => [],
            'proposal_decisions' => $rows,
            'proposal_counts' => ['audit_findings' => 0, 'records' => 0, 'retained' => 0, 'total' => 0],
            'decision_set' => ['decisions' => $rows],
        ];
    }

    /**
     * A runtime that has both plugins loaded.
     *
     * FluentCart's model classes are not present in a unit run, so the real
     * probe classifies this process `cross_runtime` and the guided branch would
     * be unreachable — the same shared-process problem `RuntimeSymbols` exists
     * for, one layer up.
     */
    private static function sameSiteProbe(): TransferRuntimeProbe
    {
        return new TransferRuntimeProbe(new class implements TransferRuntimeSymbols {
            public function functionExists(string $function): bool { return true; }
            public function classExists(string $class): bool { return true; }
            public function methodExists(string $class, string $method): bool { return true; }
            public function constantValue(string $constant): ?string { return null; }
            public function modelFillable(string $class): array { return []; }
            public function modelCasts(string $class): array { return []; }
            public function runtimeVersion(string $component): ?string { return null; }
            public function runtimeDigest(string $component): ?string { return null; }
        });
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function guidedStatus(array $params = []): array
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = $this->controller()->status($request);

        self::assertSame(200, $response->get_status());

        return $response->get_data()['data'];
    }
}
