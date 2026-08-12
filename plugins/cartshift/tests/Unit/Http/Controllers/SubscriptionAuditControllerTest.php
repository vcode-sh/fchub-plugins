<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Http\Controllers;

use CartShift\Core\Container;
use CartShift\Domain\Mapping\ProductMapDecision;
use CartShift\Domain\Subscription\ClosureReport;
use CartShift\Domain\Subscription\Package\PackageContextRepository;
use CartShift\Domain\Subscription\Package\SubscriptionPackageWriter;
use CartShift\Domain\Subscription\Source\WooSubscriptionDatasetSource;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Domain\Subscription\SubscriptionOrderReference;
use CartShift\Domain\Subscription\SubscriptionSelection;
use CartShift\Http\Controllers\SubscriptionAuditController;
use CartShift\Tests\Unit\PluginTestCase;
use WP_REST_Request;

require_once dirname(__DIR__, 3) . '/stubs/PreflightStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/ZeroWriteGuard.php';

/**
 * The read-only subscription audit, over HTTP.
 *
 * The plan's first P0 is that CartShift's dry run writes simulated ID-map rows
 * while calling itself a rehearsal, and section 11 Phase A answers it with an
 * assessment that writes nothing at all. An assertion of the form "the
 * controller did not call the repository" is not that answer — it passes for
 * code that reaches around the repository, for code that writes an option, and
 * for code nobody has written yet. So every GET here runs under a `$wpdb` that
 * refuses to write and a snapshot of the option/transient/post-meta/scheduled-
 * action globals, and `testTheZeroWriteGuardWouldCatchARealWrite` makes a write
 * on purpose so the guard cannot pass merely because nothing tried.
 *
 * The other half of the file is about what the screen is allowed to claim.
 * Totals that do not reconcile to the selected subscription count are worse
 * than no totals; a reason code that only exists nested inside another failure's
 * context is invisible unless something goes looking; and 564 expected §9.2
 * warnings must not read as 564 problems.
 */
final class SubscriptionAuditControllerTest extends PluginTestCase
{
    private const string SOURCE_KEY = 'lapka-club';

    /**
     * The one email every record in the cohort shares.
     *
     * `$base()` in the fixture file derives it from customer 660001; the guest
     * record is given it explicitly. Seven subscriptions, one person — which is
     * what makes `unique_identities` a real assertion rather than a restatement
     * of the record count.
     */
    private const string SHARED_EMAIL = 'subscriber-660001@example.invalid';

    /** A target WordPress user holding that email, for §4.4's matched-user figure. */
    private const int TARGET_USER_ID = 4242;

    /** @var array<string, callable> */
    private array $shapes;

    private string $workspace;

    private ?\wpdb $originalWpdb = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;

        $this->shapes = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';

        $GLOBALS['_cartshift_test_hpos_enabled'] = false;
        $GLOBALS['_cartshift_test_id_map'] = [];
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (): array => [];
        $GLOBALS['_cartshift_test_get_var_callback'] = cartshift_test_id_map_reader();

        $GLOBALS['_cartshift_test_fc_gateways'] = [];
        $GLOBALS['_cartshift_test_options']['fluent_cart_store_settings'] = [
            'subscription_management_mode' => 'gateway_managed',
            'subscription_system_charge'   => 'no',
        ];

        $this->workspace = realpath(sys_get_temp_dir()) . '/cartshift-audit-' . bin2hex(random_bytes(6));
        mkdir($this->workspace, 0700, true);

        $this->seedSource($this->cohort());
        $this->seedIdMap($this->cohort());
        $this->seedTargetIdentity();
    }

    /**
     * A target WordPress user carrying the cohort's email, and no FluentCart
     * customer for it.
     *
     * That is §4.4's shape — 43 of the 215 distinct subscription emails match a
     * target user — and it puts `CustomerResolver::preview()` on step 3's
     * would-create arm, which is the arm `resolve()` would write on and the one
     * an audit must forecast rather than take.
     */
    private function seedTargetIdentity(): void
    {
        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query): array {
            if (str_contains($query, 'fct_customers')) {
                return [];
            }

            return str_contains($query, self::SHARED_EMAIL) ? [self::TARGET_USER_ID] : [];
        };
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTree($this->workspace);

        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
            $this->originalWpdb = null;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // The guard itself, proved against a real write
    // ──────────────────────────────────────────────

    public function testTheZeroWriteGuardWouldCatchARealWrite(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static function (): void {
            global $wpdb;

            $wpdb->insert('wp_cartshift_id_map', ['entity_type' => 'subscription']);
        });

        $this->assertNotSame(
            [],
            $watched['violations'],
            'A guard that cannot catch an INSERT proves nothing about the endpoints it watches.',
        );
    }

    public function testTheZeroWriteGuardAlsoCatchesAnOptionWrite(): void
    {
        $watched = \CartShiftZeroWriteGuard::watch(static function (): void {
            update_option('cartshift_something', 'written');
        });

        $this->assertContains('option state changed', $watched['violations']);
    }

    // ──────────────────────────────────────────────
    // Zero write, on every GET path
    // ──────────────────────────────────────────────

    public function testAuditingTheLiveSourceWritesNothingAtAll(): void
    {
        $GLOBALS['_cartshift_test_wc_order_lookups'] = 0;

        $document = $this->watch(fn (): array => $this->audit());

        // Not a vacuous pass: the audit really did read the source under the
        // guard. An endpoint that bailed out before touching anything would
        // satisfy the zero-write assertion and prove nothing.
        $this->assertGreaterThan(0, $GLOBALS['_cartshift_test_wc_order_lookups']);
        $this->assertGreaterThan(0, $document['totals']['selected']);
    }

    public function testAuditingAPackageWritesNothingAtAll(): void
    {
        $path = $this->exportPackage();

        $document = $this->watch(fn (): array => $this->audit(['source' => 'package', 'file' => $path]));

        $this->assertSame('package', $document['source']['mode']);
    }

    public function testTheRecordsEndpointWritesNothingAtAll(): void
    {
        $this->assertNotSame([], $this->watch(fn (): array => $this->records())['records']);
    }

    /**
     * §9.1's forecast runs on this path too, and it must not write either.
     *
     * `CustomerResolver::resolve()` creates a row on two of its four arms, and
     * the audit calls `preview()` instead. The distinction is one method name
     * wide, so it is proved rather than trusted: the cohort's identity is on
     * step 3's would-create arm, which is precisely the arm `resolve()` would
     * have written on.
     */
    public function testTheCustomerForecastWritesNothingOnTheArmResolveWouldWriteOn(): void
    {
        $document = $this->watch(fn (): array => $this->audit());

        $this->assertSame(1, $document['customers']['resolution']['would_create']);
        $this->assertSame(1, $document['customers']['resolution']['attached_target_user']);
    }

    public function testAuditingDoesNotPrepareOrPersistAPackageDescriptor(): void
    {
        $this->audit(['source' => 'package', 'file' => $this->exportPackage()]);

        $this->assertSame([], (new PackageContextRepository())->all());
    }

    // ──────────────────────────────────────────────
    // What the screen is allowed to claim
    // ──────────────────────────────────────────────

    public function testTheDocumentStatesUnambiguouslyThatNothingIsWritten(): void
    {
        $document = $this->audit();

        $this->assertTrue($document['writes']['nothing']);
        $this->assertStringContainsString('writes nothing', strtolower($document['writes']['statement']));

        $this->assertSame([], $document['writes']['configuration_writes']);
        $this->assertSame([
            'prepare-package' => 'legacy_subscription_v1_package_write_closed',
            'mapping-decisions' => 'legacy_mapping_write_closed',
            'manual-fallback-confirmation' => 'legacy_subscription_v1_write_closed',
        ], $document['writes']['retired_write_routes']);
    }

    public function testTheSourceModeAndSourceKeyAreReported(): void
    {
        $document = $this->audit();

        $this->assertSame('live', $document['source']['mode']);
        $this->assertSame(self::SOURCE_KEY, $document['source']['source_key']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $document['source']['source_fingerprint']);
        $this->assertNotSame('', $document['source']['selection_fingerprint']);
    }

    /**
     * The screen reports both verdicts, and only one of them decides anything.
     *
     * `closure.complete` is `failures === []`, and this cohort carries a
     * malformed record on purpose — so exposing only `complete` showed a
     * permanent red for a dataset `SubscriptionCutover::stage()` migrates all
     * but that one record of, and the operator learned that the red is
     * advisory. `set_level` is the question `stage` asks.
     */
    public function testTheClosurePanelReportsSetLevelAlongsideComplete(): void
    {
        $closure = $this->audit()['closure'];

        $this->assertFalse($closure['complete'], 'The cohort carries one malformed record on purpose.');
        $this->assertFalse($closure['set_level'], 'A malformed record is not a fault of the set.');
        $this->assertSame([], $closure['set_level_codes']);
        $this->assertContains(ClosureReport::CODE_INVALID_SOURCE_RECORD, $closure['reason_codes']);
    }

    /**
     * Asserted slot by slot, by value.
     *
     * A sum-only assertion passes with `ready` and `confirmation_required`
     * swapped, which is the difference between "one subscription migrates" and
     * "none do". The cohort is small enough to name every number, so it does.
     */
    public function testTotalsReconcileToTheSelectedSubscriptionCountSlotBySlot(): void
    {
        $totals = $this->audit()['totals'];

        $this->assertSame(9, $totals['selected'], 'Nine source rows went in.');
        $this->assertSame(7, $totals['assessed']);
        $this->assertSame(2, $totals['invalid'], 'The malformed record and the mangled one.');
        // blankGateway: requires_manual_renewal, so the behaviour change is
        // already the source's own and needs no acceptance.
        $this->assertSame(1, $totals['ready']);
        // Four previously-automatic live records, held until the manual
        // fallback is accepted.
        $this->assertSame(4, $totals['confirmation_required']);
        // activePastDate (next payment already passed) and the guest, whose
        // customer this migration has not recorded.
        $this->assertSame(2, $totals['blocked']);

        $this->assertSame(
            $totals['selected'],
            $totals['ready'] + $totals['confirmation_required'] + $totals['blocked'] + $totals['invalid'],
        );
        $this->assertTrue($totals['reconciles']);
        $this->assertSame($totals['selected'], count($this->cohort()));
    }

    public function testEveryBreakdownReconcilesToTheAssessedCount(): void
    {
        $document = $this->audit();
        $assessed = $document['totals']['assessed'];

        foreach (['by_status', 'by_cadence', 'by_strategy', 'by_collection_method'] as $key) {
            $this->assertSame(
                $assessed,
                array_sum($document['breakdown'][$key]),
                sprintf('%s must account for every assessed record, and only those.', $key),
            );
        }
    }

    public function testTheStripeSplitSeparatesModernLegacyAndMissingTokens(): void
    {
        $stripe = $this->audit()['stripe'];

        $this->assertSame(5, $stripe['total']);
        $this->assertSame(4, $stripe['modern']);
        $this->assertSame(1, $stripe['legacy']);
        $this->assertSame(0, $stripe['missing']);
        $this->assertSame(0, $stripe['unrecognised']);
        // §4.3: none of the reference cohort's Stripe records has one.
        $this->assertSame(0, $stripe['remote_schedule']);
        $this->assertSame(
            $stripe['total'],
            $stripe['modern'] + $stripe['legacy'] + $stripe['missing'] + $stripe['unrecognised'],
        );
    }

    public function testThePayPalSplitSeparatesSystemAutomaticAndManualConfirmation(): void
    {
        $paypal = $this->audit()['paypal'];

        $this->assertSame(1, $paypal['total']);
        // No credentials, no approved settings hash: the safe route, and the one
        // the restored Lapka snapshot actually takes.
        $this->assertSame(1, $paypal['manual_confirmation']);
        $this->assertSame(0, $paypal['system']);
        $this->assertSame(0, $paypal['automatic']);
        $this->assertSame(
            $paypal['total'],
            $paypal['system'] + $paypal['automatic'] + $paypal['manual_confirmation']
            + $paypal['manual_accepted'] + $paypal['blocked'],
        );
    }

    /**
     * `source_encoding_invalid` has no first-class failure of its own.
     *
     * It arrives only inside `context.reason_codes` of an
     * `invalid_source_record` failure — unlike `dataset_foreign_source_key`,
     * which is a failure code in its own right. A reason-code list that reads
     * only the outer `code` therefore shows a mangled source row as the generic
     * "invalid record" and hides the one thing that says how to repair it, on
     * the single screen built to reveal exactly that.
     */
    public function testAContextNestedReasonCodeIsSurfacedWithItsAffectedSourceRefs(): void
    {
        $document = $this->audit();
        $reasons  = $this->reasonsByCode($document);

        $this->assertArrayHasKey(ClosureReport::CODE_SOURCE_ENCODING_INVALID, $reasons);

        $nested = $reasons[ClosureReport::CODE_SOURCE_ENCODING_INVALID];

        $this->assertSame(ClosureReport::CODE_INVALID_SOURCE_RECORD, $nested['nested_in']);
        $this->assertSame('blocking', $nested['severity']);
        $this->assertNotSame([], $nested['source_refs']);

        // And the outer code is still there in its own right — surfacing the
        // nested one must not replace the failure it was nested in.
        $this->assertArrayHasKey(ClosureReport::CODE_INVALID_SOURCE_RECORD, $reasons);
    }

    /**
     * `nested_in` means "this code has no reporting of its own", so one
     * standalone sighting disproves it — whichever order the sightings arrive
     * in. The merge used to be `??=` over the whole entry, which meant the
     * label was decided by whichever occurrence happened to be iterated first.
     *
     * Driven through the private merge directly: producing one code both ways
     * from a fixture would need a source row engineered for it, and the rule
     * under test is arithmetic on three fields rather than anything about
     * datasets.
     */
    public function testAStandaloneSightingClearsTheNestedLabelInEitherOrder(): void
    {
        $merge = new \ReflectionMethod(SubscriptionAuditController::class, 'recordReason');
        $controller = new SubscriptionAuditController(new Container());

        foreach (
            [
                'nested first'     => [['outer', null], [null, 'outer']],
                'standalone first' => [[null, 'outer'], ['outer', null]],
            ] as $label => $orders
        ) {
            foreach ($orders as $index => [$first, $second]) {
                $reasons = [];

                $merge->invokeArgs(
                    $controller,
                    [&$reasons, 'a_code', 'blocking', 'closure', 'subscription:1', $first],
                );
                $merge->invokeArgs(
                    $controller,
                    [&$reasons, 'a_code', 'warning', 'assessment', 'subscription:2', $second],
                );

                $this->assertNull(
                    $reasons['a_code']['nested_in'],
                    sprintf('%s (%d): a standalone sighting must clear the label.', $label, $index),
                );
                $this->assertSame(
                    'blocking',
                    $reasons['a_code']['severity'],
                    'Severity still merges to the most severe.',
                );
                $this->assertSame(
                    ['closure' => true, 'assessment' => true],
                    $reasons['a_code']['origins'],
                    'Both origins are kept rather than one silently winning.',
                );
            }
        }
    }

    public function testEveryReasonCodeCarriesTheSourceRefsItAffects(): void
    {
        foreach ($this->audit()['reasons'] as $reason) {
            $this->assertNotSame('', $reason['code']);
            $this->assertGreaterThan(0, $reason['count']);
            $this->assertNotSame(
                [],
                $reason['source_refs'],
                sprintf('%s named no affected record.', $reason['code']),
            );
        }
    }

    /**
     * Every one of the 564 reference records carries this warning, because
     * §9.2's product fallback is what the Lapka source needs. A screen that
     * presents it beside the blockers reports 564 problems where there are none.
     */
    public function testTheExpectedFiniteTermWarningIsMarkedExpectedAndNotBlocking(): void
    {
        $reasons = $this->reasonsByCode($this->audit());

        $this->assertArrayHasKey(SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT, $reasons);

        $reason = $reasons[SubscriptionAssessment::REASON_FINITE_TERM_FROM_PRODUCT];

        $this->assertTrue($reason['expected']);
        $this->assertSame('warning', $reason['severity']);
    }

    /**
     * Seven subscriptions, one person.
     *
     * The number this pins is the one §4.4 measures — distinct subscription
     * emails — and it is exactly the number the previous implementation got
     * wrong. Counting distinct `sourceCustomerRef` mixed two namespaces:
     * six of these records key as `customer:660001` and the seventh, the same
     * human buying as a guest, keys as `guest:<sha256>`. That answer was 2.
     */
    public function testDistinctIdentitiesAreCountedByEmailNotBySourceCustomerRef(): void
    {
        $customers = $this->audit()['customers'];

        $this->assertSame(1, $customers['unique_identities']);
        $this->assertSame(0, $customers['blank_email']);
        $this->assertSame(7, $customers['assessed']);
        $this->assertSame(1, $customers['guests_at_source']);
        $this->assertSame(6, $customers['registered_at_source']);

        // Two different questions, both reported, neither standing in for the
        // other: six subscriptions resolve a customer through the ID map, the
        // guest does not.
        $this->assertSame(6, $customers['resolved_in_id_map']);
        $this->assertSame(1, $customers['unresolved_in_id_map']);
        $this->assertSame(
            $customers['assessed'],
            $customers['resolved_in_id_map'] + $customers['unresolved_in_id_map'],
        );
    }

    /**
     * The other direction, and the one the cohort alone cannot prove.
     *
     * Every record in the shared cohort carries one email, so an implementation
     * that collapsed everything to a single identity would pass the test above.
     * Two distinct emails must read as two.
     */
    public function testTwoDistinctEmailsAreTwoIdentities(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['monthlyPln24'](['payment_count' => 1, 'billing_email' => 'someone-else@example.invalid']),
        ]);
        $this->seedIdMap($this->cohort());

        $customers = $this->audit()['customers'];

        $this->assertSame(2, $customers['assessed']);
        $this->assertSame(2, $customers['unique_identities']);
    }

    /**
     * A subscription with no email is refused before the forecast ever sees it,
     * and its code still reaches the operator.
     *
     * `SubscriptionRecordFactory` blocks a blank email at decode time with
     * §9.4's `customer_email_missing`, so the record arrives as an
     * `InvalidSourceRecord` rather than as an assessed row — which is why
     * `blank_email` reads 0 on a healthy dataset. That is a routing fact, not
     * an absence of checking, and both halves of it are asserted here: nothing
     * lands in the forecast, and the code is reported all the same.
     */
    public function testASubscriptionWithNoEmailIsRefusedAtDecodeAndStillReported(): void
    {
        $this->seedSource([
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['monthlyPln24'](['id' => 910_404, 'payment_count' => 1, 'billing_email' => '']),
        ]);
        $this->seedIdMap($this->cohort());

        $document = $this->audit();

        $this->assertSame(1, $document['totals']['invalid']);
        $this->assertSame(1, $document['totals']['assessed']);

        // Not silently absorbed into the identity count.
        $this->assertSame(0, $document['customers']['blank_email']);
        $this->assertSame(1, $document['customers']['unique_identities']);

        // And the code an operator has to act on is on the screen.
        $reasons = $this->reasonsByCode($document);

        $this->assertArrayHasKey('customer_email_missing', $reasons);
        $this->assertSame('blocking', $reasons['customer_email_missing']['severity']);
        $this->assertContains('subscription:910404', $reasons['customer_email_missing']['source_refs']);
    }

    /**
     * And if one ever does get through, it is blocked rather than dropped.
     *
     * `SubscriptionRecord`'s constructor is public and takes any string, so the
     * branch is reachable by something other than the factory. Driven through
     * the private builder with a hand-made row, because the factory — correctly
     * — will not produce the input.
     */
    public function testABlankEmailRowThatReachesTheForecastIsCountedAsBlocked(): void
    {
        $customers = (new \ReflectionMethod(SubscriptionAuditController::class, 'customers'))
            ->invoke(new SubscriptionAuditController(new Container()), [
                [
                    'outcome'  => 'blocked',
                    'customer' => [
                        'resolved_in_id_map' => false,
                        'guest'              => true,
                        'source_ref'         => 'customer:0',
                        'identity_hash'      => '',
                        'resolution'         => [
                            'status'              => 'blocked',
                            'outcome'             => null,
                            'reason_code'         => 'customer_email_missing',
                            'would_create'        => false,
                            'matched_target_user' => false,
                        ],
                    ],
                ],
            ]);

        $this->assertSame(1, $customers['blank_email']);
        $this->assertSame(0, $customers['unique_identities'], 'Two blank emails are not two people.');
        $this->assertSame(1, $customers['resolution']['blocked']);
        $this->assertSame(
            ['customer_email_missing' => 1],
            $customers['resolution']['blocked_reason_codes'],
        );
    }

    /**
     * §4.4's load-bearing figure, reported honestly instead of proxied.
     *
     * The cohort's single email matches one target WordPress user with no
     * FluentCart customer attached, so §9.1 step 3 would create one. The audit
     * says "would create" and creates nothing — which the zero-write guard
     * proves on the same endpoint.
     */
    public function testTheCustomerSummaryForecastsSectionNineOneWithoutTakingIt(): void
    {
        $resolution = $this->audit()['customers']['resolution'];

        $this->assertSame(1, $resolution['matched_target_user']);
        $this->assertSame(1, $resolution['attached_target_user']);
        $this->assertSame(1, $resolution['would_create']);
        $this->assertSame(0, $resolution['reused_customer']);
        $this->assertSame(0, $resolution['would_create_guest']);
        $this->assertSame(0, $resolution['blocked']);
        $this->assertSame([], $resolution['blocked_reason_codes']);
    }

    public function testTheMappingSummaryNamesEverySourceProductAndItsTargetIds(): void
    {
        $mapping = $this->audit()['mapping'];

        $productIds = array_column($mapping['source_products'], 'source_product_id');

        $this->assertContains(CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID, $productIds);
        $this->assertArrayHasKey('fingerprint', $mapping);
        $this->assertArrayHasKey('shared_target_variations', $mapping);
    }

    /**
     * The mapping panel is about THIS cohort's source, not about `local`'s.
     *
     * `MigrationModule` binds one `ProductMapRepository`, pinned to
     * `Constants::DEFAULT_SOURCE_KEY`. Every package audit has a source key that
     * is not `local`, so `mapping.decided`, `mapping.mapped`,
     * `mapping.undecided`, `shared_target_variations` and `mapping.fingerprint`
     * all described a different namespace — while `SubscriptionCutover` reads
     * the scoped one and the panel's own note says the fingerprint is what stage
     * revalidates against.
     */
    public function testTheMappingPanelReadsThisSourcesDecisionsRatherThanLocals(): void
    {
        $this->seedProductMap();

        $mapping = $this->audit()['mapping'];

        $this->assertSame(1, $mapping['decided'], 'Three of these decisions belong to another source.');
        $this->assertSame(
            (new \CartShift\Domain\Mapping\MappingSetValidator())
                ->validate((new \CartShift\Storage\ProductMapRepository(self::SOURCE_KEY))->all())
                ->fingerprint(),
            $mapping['fingerprint'],
            'The fingerprint the note promises stage revalidates against.',
        );
    }

    /**
     * The container-bound arm, which is the one that was wrong.
     *
     * A binding that speaks for this source is used; a binding pinned to another
     * one is not, and the audit falls back to a repository scoped to the cohort.
     */
    public function testAContainerBoundRepositoryIsUsedOnlyWhenItSpeaksForThisSource(): void
    {
        $this->seedProductMap();

        $forThisSource = new Container();
        $forThisSource->instance(
            \CartShift\Storage\ProductMapRepository::class,
            new \CartShift\Storage\ProductMapRepository(self::SOURCE_KEY),
        );

        $this->assertSame(1, $this->audit([], $forThisSource)['mapping']['decided']);

        $forLocal = new Container();
        $forLocal->instance(
            \CartShift\Storage\ProductMapRepository::class,
            new \CartShift\Storage\ProductMapRepository(\CartShift\Support\Constants::DEFAULT_SOURCE_KEY),
        );

        $this->assertSame(
            1,
            $this->audit([], $forLocal)['mapping']['decided'],
            'A binding pinned to another source must not answer for this cohort.',
        );
    }

    /**
     * Four mapping decisions: three under `local`, one under this cohort's key.
     */
    private function seedProductMap(): void
    {
        $row = static fn (int $wcId, int $variationId): object => (object) [
            'wc_id'       => $wcId,
            'wc_type'     => 'simple',
            'decision'    => 'link',
            'fc_post_id'  => 88,
            'band'        => 'none',
            'variant_map' => json_encode([(string) $wcId => $variationId]),
        ];

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($row): array {
            if (!str_contains($query, 'cartshift_product_map')) {
                return [];
            }

            if (str_contains($query, "'" . self::SOURCE_KEY . "'")) {
                return [$row(770_001, 4101)];
            }

            return str_contains($query, "'local'")
                ? [$row(770_001, 5101), $row(770_002, 5102), $row(770_003, 5103)]
                : [];
        };
    }

    /**
     * The claim index, with the flag that tells a deliberate share from a
     * collision.
     *
     * Driven through the private builder because seeding the mapping staging
     * table is a different test file's machinery, and the thing under test is
     * whether the claimants and their opt-in survive into the payload — which
     * is what the screen needs to distinguish §7.3's monthly/yearly case from
     * two equivalent legacy products converging on purpose.
     */
    public function testTheClaimIndexNamesEveryClaimantAndWhetherItOptedIn(): void
    {
        $shared = (new \ReflectionMethod(SubscriptionAuditController::class, 'sharedTargets'))
            ->invoke(new SubscriptionAuditController(new Container()), [
                // Two sources on variation 4101, neither opted in.
                ProductMapDecision::link(770_001, 'simple', 88, 'none', [770_001 => 4101], []),
                ProductMapDecision::link(770_002, 'simple', 88, 'none', [770_002 => 4101], []),
                // Two more on 4103, both opted in.
                ProductMapDecision::link(770_003, 'simple', 88, 'none', [770_003 => 4103], [], true),
                ProductMapDecision::link(770_004, 'simple', 88, 'none', [770_004 => 4103], [], true),
                // Uncontested: must not appear at all.
                ProductMapDecision::link(770_005, 'simple', 88, 'none', [770_005 => 4105], []),
            ]);

        $this->assertCount(2, $shared, 'Only contested variations are claims worth reporting.');
        $this->assertSame([4101, 4103], array_column($shared, 'target_variation_id'));

        $this->assertSame(
            [
                ['wc_id' => 770_001, 'source_variation_id' => 770_001, 'allow_shared_target' => false],
                ['wc_id' => 770_002, 'source_variation_id' => 770_002, 'allow_shared_target' => false],
            ],
            $shared[0]['claimants'],
        );

        $this->assertTrue($shared[1]['claimants'][0]['allow_shared_target']);
        $this->assertTrue($shared[1]['claimants'][1]['allow_shared_target']);
    }

    public function testTheTargetSectionCarriesTheSettingsCensusCapabilitiesAndApprovalFingerprint(): void
    {
        $target = $this->audit()['target'];

        $this->assertArrayHasKey('subscription_settings', $target);
        $this->assertArrayHasKey('subscription_census', $target);
        $this->assertArrayHasKey('stripe', $target['capabilities']);
        $this->assertArrayHasKey('paypal', $target['capabilities']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $target['approval_fingerprint']);
        $this->assertFalse(
            $target['approved'],
            'An audit approves nothing. The operator binds the hash at stage.',
        );
    }

    public function testScheduleAnomaliesAreCountedAndNamed(): void
    {
        $schedule = $this->audit()['schedule'];

        $this->assertSame(1, $schedule['active_next_date_past']);
        $this->assertGreaterThan(0, $schedule['next_payment_future']);
        $this->assertContains('subscription:910021', $schedule['active_next_date_past_refs']);
    }

    public function testHistoryCountMismatchesAreReportedWithBothCounts(): void
    {
        $history = $this->audit()['history'];

        $this->assertSame(1, $history['mismatches']);
        $this->assertSame(7, $history['records'][0]['source_payment_count']);
        $this->assertSame(1, $history['records'][0]['included_paid_orders']);
    }

    /**
     * The expected first-run result, and the one the screen must not present as
     * a failure: with the manual fallback unaccepted, every live record that WCS
     * was charging silently sits at `confirmation_required` and migrates nothing.
     */
    public function testTheUnacceptedManualFallbackIsReportedWithItsRemedy(): void
    {
        $document = $this->audit();

        $this->assertFalse($document['confirmation']['manual_fallback_confirmed']);
        $this->assertGreaterThan(0, $document['confirmation']['awaiting']);
        $this->assertNotSame('', $document['confirmation']['remedy']);
        $this->assertSame(
            $document['totals']['confirmation_required'],
            $document['confirmation']['awaiting'],
        );
    }

    // ──────────────────────────────────────────────
    // The paginated record list
    // ──────────────────────────────────────────────

    public function testTheRecordsEndpointPaginates(): void
    {
        $first = $this->records(['page' => 1, 'per_page' => 2]);

        $this->assertCount(2, $first['records']);
        $this->assertSame(count($this->cohort()), $first['total']);
        $this->assertSame(1, $first['page']);

        $second = $this->records(['page' => 2, 'per_page' => 2]);

        $this->assertNotSame(
            array_column($first['records'], 'source_ref'),
            array_column($second['records'], 'source_ref'),
        );
    }

    public function testTheRecordsEndpointFiltersByReasonCode(): void
    {
        $filtered = $this->records([
            'code'     => SubscriptionAssessment::REASON_ACTIVE_NEXT_DATE_PAST,
            'per_page' => 100,
        ]);

        $this->assertSame(1, $filtered['filtered']);
        $this->assertSame('subscription:910021', $filtered['records'][0]['source_ref']);
    }

    /**
     * The summary caps how many refs it carries per code, so every code it
     * shows has to be clickable through to the records that carry it — the
     * nested one included, or surfacing it in the summary would be a dead end.
     */
    public function testTheRecordsEndpointFindsRecordsByAContextNestedReasonCode(): void
    {
        $filtered = $this->records([
            'code'     => ClosureReport::CODE_SOURCE_ENCODING_INVALID,
            'per_page' => 100,
        ]);

        $this->assertSame(1, $filtered['filtered']);
        $this->assertSame('subscription:910777', $filtered['records'][0]['source_ref']);
        $this->assertSame('invalid', $filtered['records'][0]['kind']);
    }

    public function testTheRecordsEndpointFiltersByOutcome(): void
    {
        $blocked = $this->records(['outcome' => 'blocked', 'per_page' => 100]);

        foreach ($blocked['records'] as $record) {
            $this->assertSame('blocked', $record['outcome']);
        }

        $this->assertSame(
            $this->audit()['totals']['blocked'] + $this->audit()['totals']['invalid'],
            $blocked['filtered'],
        );
    }

    /**
     * A blocked mapping row has to say which product to go and re-decide.
     * "Blocked" with no way back to the screen that fixes it is a dead end.
     */
    public function testABlockedRecordCarriesTheMappingRowItNeeds(): void
    {
        $records = $this->records(['per_page' => 100])['records'];

        $rows = array_values(array_filter(
            $records,
            static fn (array $record): bool => ($record['mapping']['source_product_id'] ?? 0) > 0,
        ));

        $this->assertNotSame([], $rows);
        $this->assertArrayHasKey('target_product_id', $rows[0]['mapping']);
        $this->assertArrayHasKey('needs_mapping', $rows[0]['mapping']);
    }

    // ──────────────────────────────────────────────
    // Refusals
    // ──────────────────────────────────────────────

    public function testAnUnknownSourceModeIsRefused(): void
    {
        $response = $this->call('audit', ['source' => 'telepathy']);

        $this->assertSame(400, $response->get_status());
    }

    public function testAPackageAuditWithoutAFileIsRefused(): void
    {
        $response = $this->call('audit', ['source' => 'package']);

        $this->assertSame(400, $response->get_status());
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Run something under the write-refusing `$wpdb` and hand back its result.
     *
     * The violations are asserted HERE, before the caller can touch the result.
     * Done the other way round, a violation makes `watch()` return
     * `result => null` and the caller dies on a null array access — a fatal
     * naming the wrong line instead of a readable failure naming the write.
     *
     * @template T
     * @param callable(): T $run
     * @return T
     */
    private function watch(callable $run): mixed
    {
        $watched = \CartShiftZeroWriteGuard::watch($run);

        $this->assertSame(
            [],
            $watched['violations'],
            'A read-only endpoint attempted a write.',
        );

        return $watched['result'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function audit(array $params = [], ?Container $container = null): array
    {
        $response = $this->call('audit', $params + ['source_key' => self::SOURCE_KEY], $container);

        $this->assertSame(200, $response->get_status());

        return $response->get_data()['data'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function records(array $params = []): array
    {
        $response = $this->call('records', $params + ['source_key' => self::SOURCE_KEY]);

        $this->assertSame(200, $response->get_status());

        return $response->get_data()['data'];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function call(string $method, array $params, ?Container $container = null): \WP_REST_Response
    {
        $request = new WP_REST_Request();

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return (new SubscriptionAuditController($container ?? new Container()))->{$method}($request);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, array<string, mixed>>
     */
    private function reasonsByCode(array $document): array
    {
        $byCode = [];

        foreach ($document['reasons'] as $reason) {
            $byCode[$reason['code']] = $reason;
        }

        return $byCode;
    }

    /**
     * The cohort every assertion in this file is about.
     *
     * Small, and every member is there for a reason: a clean record with typed
     * relationships, a legacy `src_` Stripe token, a PPCP record, a manual one,
     * an active record whose next payment has already passed, a record whose
     * WCS payment count disagrees with the paid orders included, a record with
     * no line item and no parent order, and one whose item name is not valid
     * UTF-8.
     *
     * @return list<object>
     */
    private function cohort(): array
    {
        return [
            $this->shapes['typedRelatedOrders'](),
            $this->shapes['stripeLegacySource'](['payment_count' => 1]),
            $this->shapes['paypalGateway'](['payment_count' => 1]),
            $this->shapes['blankGateway'](['payment_count' => 1]),
            $this->shapes['activePastDate'](['payment_count' => 1]),
            // payment_count 7 against one paid parent order: history_count_mismatch.
            $this->shapes['monthlyPln24'](),
            // The same person as the six above, buying as a guest. Its whole
            // job is to prove identities are counted by email rather than by
            // source customer ref: keyed on the ref this is `guest:<hash>` and
            // the others are `customer:660001`, so a ref-based count says two
            // people where there is one.
            $this->shapes['guestCustomer']([
                'id'            => 910_066,
                'billing_email' => self::SHARED_EMAIL,
                'payment_count' => 1,
            ]),
            $this->shapes['malformedNoItemNoParent'](),
            $this->mangledItemName(),
        ];
    }

    /**
     * A subscription whose line-item name is not valid UTF-8.
     *
     * `\xC3\x28` is a truncated two-byte sequence — the classic mojibake shape a
     * latin1 column produces when it is read as utf8mb4. It cannot be
     * canonicalised, so the record decodes to an `InvalidSourceRecord` carrying
     * `source_encoding_invalid` inside its reason codes.
     */
    private function mangledItemName(): object
    {
        return $this->shapes['monthlyPln29']([
            'id'            => 910_777,
            'payment_count' => 1,
            'items'         => [
                new \CartShiftLapkaItem(
                    CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                    0,
                    "Monthly \xC3\x28 membership",
                    1,
                    '29.00',
                    cartshift_lapka_subscription_product(
                        CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID,
                        'Monthly subscription (fixture)',
                    ),
                ),
            ],
        ]);
    }

    private function exportPackage(string $name = 'package.ndjson'): string
    {
        $path      = $this->workspace . '/' . $name;
        $selection = SubscriptionSelection::all(self::SOURCE_KEY);

        (new SubscriptionPackageWriter())->write(
            $path,
            new WooSubscriptionDatasetSource(self::SOURCE_KEY, $selection),
            $selection,
        );

        $this->assertFileExists($path);

        return $path;
    }

    /**
     * @param list<object> $subscriptions
     */
    private function seedSource(array $subscriptions): void
    {
        $GLOBALS['_cartshift_test_wcs_pages'] = $subscriptions;
        $GLOBALS['_cartshift_test_wc_orders'] = [];
        $GLOBALS['_cartshift_test_wc_products'] = [];

        foreach ($subscriptions as $subscription) {
            foreach (SubscriptionOrderReference::RELATIONSHIPS as $relationship) {
                foreach ($subscription->get_related_orders('all', $relationship) as $order) {
                    $GLOBALS['_cartshift_test_wc_orders'][$order->get_id()] = $order;
                }
            }

            foreach ($subscription->get_items() as $item) {
                $productId = $item->get_product_id();

                // `??=` rather than `=`: one fixture in this cohort carries a
                // deliberately mangled item name, and letting it overwrite the
                // shared product would make the PRODUCT record undecodable —
                // which would block every subscription in the cohort under
                // `dataset_missing_product` and hide what the fixture is for.
                if ($productId > 0) {
                    $GLOBALS['_cartshift_test_wc_products'][$productId] ??=
                        new \CartShiftLapkaProduct($productId, $item->get_name());
                }
            }
        }
    }

    /**
     * Customers, orders, products and variants already migrated.
     *
     * Section 6.2 fixes the import order — customers, products, orders, then
     * subscriptions — so a subscription audit worth reading is one run against
     * a target where the first three have landed. Without this every record
     * blocks on `customer_not_found` and the file would only ever prove that
     * an empty ID map blocks everything, which nobody doubted.
     *
     * @param list<object> $subscriptions
     */
    private function seedIdMap(array $subscriptions): void
    {
        foreach ($subscriptions as $subscription) {
            $this->mapEntity('customer', $subscription->get_customer_id());
            $this->mapEntity('order', $subscription->get_parent_id());

            foreach ($subscription->get_items() as $item) {
                $productId = (int) $item->get_product_id();

                if ($productId <= 0) {
                    continue;
                }

                $this->mapEntity('product', $productId);
                $this->mapEntity('variation', $productId);

                cartshift_test_own_variation($productId + 10_000, $productId + 10_000);
            }
        }
    }

    private function mapEntity(string $entityType, int $wcId): void
    {
        if ($wcId > 0) {
            $GLOBALS['_cartshift_test_id_map'][$entityType][(string) $wcId] = $wcId + 10_000;
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;

            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
