<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Migration;

use CartShift\Domain\Migration\MappingPromoter;
use CartShift\Domain\Migration\MigrationOrchestratorFactory;
use CartShift\Domain\Scope\MigrationScope;
use CartShift\Migrator\ProductMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Storage\ProductMapRepository;
use CartShift\Support\Constants;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

require_once dirname(__DIR__, 3) . '/stubs/PostStatusStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * The single assembly point every migration run goes through.
 *
 * Three groups of tests, and they are three different kinds of risk.
 *
 *  - **Assembly** — that a run carries the owner's skip list, and that a
 *    counting caller gets the same exclusions without anything being promoted.
 *    /preview is read-only by construction and promoting from it would write
 *    ID map rows while the owner was still choosing.
 *  - **Promotion timing** — the reason this class exists rather than a helper
 *    function. On the path that starts a run there is no migration ID at the
 *    moment the orchestrator is built, so promotion waits for
 *    `cartshift/migration/started`; on a run already in flight it happens
 *    immediately. Getting this wrong files the owner's links under the wrong
 *    run, or under no run at all.
 *  - **The two FluentCart touchpoints** — `fcProductStillExists()` and
 *    `createOrphanVariant()`, both moved here from MigrationModule along with
 *    their tests when assembly moved. Named statics rather than inline
 *    closures precisely so a regression in either needs no container to catch.
 */
final class MigrationOrchestratorFactoryTest extends PluginTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['_cartshift_test_posts'] = [];
    }

    #[\Override]
    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_cartshift_test_insert_callback'],
            $GLOBALS['_cartshift_test_get_var_callback'],
            $GLOBALS['_cartshift_test_get_results_callback'],
        );

        parent::tearDown();
    }

    // ── fcProductStillExists ────────────────────────────────

    public function testAPublishedProductExists(): void
    {
        $GLOBALS['_cartshift_test_posts'][900] = ['status' => 'publish', 'type' => 'fluent-products'];

        $this->assertTrue(MigrationOrchestratorFactory::fcProductStillExists(900));
    }

    public function testADraftProductExists(): void
    {
        $GLOBALS['_cartshift_test_posts'][900] = ['status' => 'draft', 'type' => 'fluent-products'];

        $this->assertTrue(MigrationOrchestratorFactory::fcProductStillExists(900));
    }

    public function testAPrivateProductExists(): void
    {
        $GLOBALS['_cartshift_test_posts'][900] = ['status' => 'private', 'type' => 'fluent-products'];

        $this->assertTrue(MigrationOrchestratorFactory::fcProductStillExists(900));
    }

    /**
     * The regression this method exists to catch. wp_trash_post() sets
     * post_status = 'trash' without deleting the row or touching post_type,
     * so get_post_status() answers the string 'trash' — a value a naive
     * `!== false` check treats as "present". An owner trashing the linked FC
     * product between mapping and running must not have it promoted anyway.
     */
    public function testATrashedProductDoesNotExist(): void
    {
        $GLOBALS['_cartshift_test_posts'][900] = ['status' => 'trash', 'type' => 'fluent-products'];

        $this->assertFalse(MigrationOrchestratorFactory::fcProductStillExists(900));
    }

    public function testAMissingProductDoesNotExist(): void
    {
        // 900 was never inserted into the fixture at all — get_post_status()
        // and get_post_type() both answer false, matching a hard-deleted post.
        $this->assertFalse(MigrationOrchestratorFactory::fcProductStillExists(900));
    }

    /**
     * A post can exist, in a live status, and still not be a FluentCart
     * product — the id could belong to a WooCommerce 'product' post, a page,
     * anything. The type check is not redundant with the status check.
     */
    public function testAPublishedPostOfTheWrongTypeDoesNotExist(): void
    {
        $GLOBALS['_cartshift_test_posts'][900] = ['status' => 'publish', 'type' => 'product'];

        $this->assertFalse(MigrationOrchestratorFactory::fcProductStillExists(900));
    }

    // ── createOrphanVariant ─────────────────────────────────
    //
    // MappingPromoterOrphanTest injects a fake createVariant, so the payload
    // this method actually sends FluentCart is only ever looked at here. It
    // lands in a product the shop owner built by hand, which makes every
    // defaulted column a decision: the schema's own defaults render the variant
    // out of stock, unpriced, first in the owner's ordered list and with a NULL
    // other_info that trips fct_order_items' NOT NULL constraint downstream.

    /**
     * @return list<object>
     */
    private function createdVariants(): array
    {
        return \CartShiftFcModelStore::all('ProductVariation');
    }

    /**
     * The linked product's detail row, as `where('post_id', …)->first()` will
     * find it.
     *
     * Seeded rather than left absent in most tests below, because the creator
     * reads three separate things off it — the variation type it refuses, the
     * price floor a legacy descriptor falls back to, and the fulfilment type —
     * and writes the recomputed price range back to it afterwards.
     */
    private function seedDetail(array $overrides = []): object
    {
        return \CartShiftFcModelStore::seed('ProductDetail', array_merge([
            'id'                 => 1,
            'post_id'            => 900,
            'variation_type'     => 'simple_variations',
            'fulfillment_type'   => 'physical',
            'min_price'          => 1400.0,
            'max_price'          => 2400.0,
            'manage_stock'       => 0,
            'stock_availability' => 'in-stock',
        ], $overrides));
    }

    /**
     * A complete orphan descriptor, the shape MappingController now sends.
     */
    private function orphan(array $overrides = []): array
    {
        return array_merge([
            'id'               => 11,
            'sku'              => '',
            'name'             => 'XL',
            'price'            => 1999,
            'fulfillment_type' => 'physical',
            'downloadable'     => 'false',
        ], $overrides);
    }

    public function testASkulessOrphanIsCreatedWithANullSkuNotAnEmptyString(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['sku' => '']));

        $created = $this->createdVariants();

        $this->assertCount(1, $created);
        $this->assertNull(
            $created[0]->sku,
            "fct_product_variations has a globally UNIQUE sku column, so '' collides on the "
            . 'second SKU-less variant in a run — and a blank SKU is the normal case.',
        );
    }

    /**
     * The collision itself, spelled out: two orphans with no SKU in one run.
     * A unique index tolerates two NULLs and refuses two empty strings, so
     * this is the pair that used to lose the second variant.
     */
    public function testTwoSkulessOrphansInOneRunDoNotShareASkuValue(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['id' => 11, 'name' => 'L']));
        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['id' => 12, 'name' => 'XL']));

        $skus = array_map(static fn (object $row): mixed => $row->sku, $this->createdVariants());

        $this->assertSame([null, null], $skus);
    }

    public function testARealSkuIsKept(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['sku' => 'TS-XL']));

        $this->assertSame('TS-XL', $this->createdVariants()[0]->sku);
    }

    // ── The SKU the owner already used ──────────────────────
    //
    // The one that used to abort the run. `sku` carries two UNIQUE indexes, a
    // duplicate INSERT is a thrown QueryException, and nothing between here and
    // BatchProcessor caught it — so the Action Scheduler action died, no next
    // batch was queued, and the half-promoted decision could never be retried.
    // An owner who built their FluentCart product by copying their WooCommerce
    // SKUs across walks into this on the first orphan.

    public function testASkuTheLinkedProductAlreadyUsesIsSuffixedRatherThanThrown(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        // What a SKU probe finds when the owner already typed this one in.
        \CartShiftFcModelStore::seed('ProductVariation', ['id' => 501, 'sku' => 'TS-XL']);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['id' => 13, 'sku' => 'TS-XL']));

        $this->assertSame('TS-XL-wc13', $this->createdVariants()[0]->sku);
    }

    /**
     * `sku` is varchar(30) and WordPress strips STRICT_TRANS_TABLES from every
     * connection, so an over-length value is truncated rather than refused.
     * Probing the untruncated string therefore asks about a value the table
     * will never hold: two Woo SKUs sharing their first 30 characters both pass
     * the probe and the second INSERT throws anyway. Clamp first, then probe.
     */
    public function testAnOverlongSkuIsClampedToWhatTheColumnHolds(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        $long = str_repeat('A', 45);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['sku' => $long]));

        $this->assertSame(str_repeat('A', 30), $this->createdVariants()[0]->sku);
    }

    /**
     * And the suffix has to fit too. Truncating the `-wc13` end would hand back
     * the duplicate it was added to avoid, so the stem gives way instead.
     */
    public function testSuffixingAnOverlongSkuTruncatesTheStemNotTheSuffix(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        $long = str_repeat('A', 45);

        \CartShiftFcModelStore::seed('ProductVariation', ['id' => 501, 'sku' => str_repeat('A', 30)]);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['id' => 13, 'sku' => $long]));

        $sku = $this->createdVariants()[0]->sku;

        $this->assertSame(str_repeat('A', 25) . '-wc13', $sku);
        $this->assertSame(30, strlen($sku));
    }

    /**
     * Nothing in the creator may abort the run. A throw escaping it kills the
     * Action Scheduler action mid-migration; no next batch is queued, and the
     * decision is left half-promoted.
     */
    public function testAThrowingCreateIsSwallowedAndLoggedRatherThanKillingTheRun(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        $GLOBALS['_cartshift_test_fc_model_handler'] = static function (string $class): mixed {
            if (str_contains($class, 'ProductVariation')) {
                throw new \RuntimeException('Duplicate entry for key sku_unique');
            }

            return new \CartShiftFcQuery('ProductDetail');
        };

        // Logger::error() writes through error_log(), which in the CLI SAPI
        // goes to stderr unless it is pointed somewhere. Point it at a file so
        // the assertion can read it — and so the suite does not print it.
        $logFile = tempnam(sys_get_temp_dir(), 'cartshift-log-');
        $previous = (string) ini_get('error_log');
        ini_set('error_log', $logFile);

        try {
            $result = MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());
            $written = (string) file_get_contents($logFile);
        } finally {
            ini_set('error_log', $previous);
            unlink($logFile);
        }

        $this->assertNull($result, 'MappingPromoter reads null as "skip this orphan and keep going".');
        $this->assertStringContainsString('Duplicate entry for key sku_unique', $written);
    }

    // ── The one refusal: a subscription source ──────────────
    //
    // 7.4: automatic subscription-orphan creation is out of scope. Reproducing
    // a recurring contract exactly — cadence, trial, length, setup fee, and
    // the payment strategy that decides who charges the customer next — is a
    // whole feature this path does not have, and `payment_type=onetime`
    // silently turns a membership into a single sale. The shop only finds out
    // when the customer is never billed again, which is worse than refusing
    // outright and telling the owner to build the variant by hand.

    /**
     * Isolated: this is the one test in the file that needs
     * WC_Subscriptions_Product to exist. Loading it in the shared process
     * would flip VariationMapper's `class_exists()` gate to true for every
     * later test in the run — see the stub's own docblock.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASubscriptionSourceVariationIsNeverCreatedAsAOnetimeOrphan(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        \CartShiftFcModelStore::install();
        $this->seedDetail();

        $variation = new \WC_Product_Variation();
        (new \ReflectionProperty(\WC_Product_Variation::class, 'id'))->setValue($variation, 21);
        (new \ReflectionProperty(\WC_Product_Variation::class, 'meta'))->setValue($variation, [
            '_subscription_period' => 'month',
        ]);
        $GLOBALS['_cartshift_test_wc_products'][21] = $variation;

        // The refusal reports itself through Logger::error(); PHPUnit captures
        // error_log() output and fails the test unless the call declares it.
        $this->expectErrorLog();

        $result = MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['id' => 21]));

        $this->assertNull(
            $result,
            'MappingPromoter reads null as "could not create this orphan" and reports it in the failed list — '
            . 'the same outcome a write failure gets.',
        );
        $this->assertCount(
            0,
            $this->createdVariants(),
            'No one-time variant may be written into the owner\'s catalogue for a subscription source.',
        );
    }

    // ── the other half of the same hazard: the "Create" route ──

    /**
     * A mapping row CartShift reports as blocked still offers "Create", and
     * that route runs `ProductMigrator` and `VariationMapper`, which write
     * `repeat_interval` through the lenient cadence reading — `week/2` becomes
     * weekly, `year/2` yearly, `month/2` and `month/12` monthly. So an
     * operator's answer to "CartShift cannot express this contract" would be a
     * FluentCart product quietly claiming a different one.
     *
     * Isolated for the same reason as the orphan test above: this is the other
     * place in the file that needs `WC_Subscriptions_Product` to exist, and a
     * class declared in the shared process cannot be undeclared.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testASubscriptionProductWithAnUnrepresentableCadenceIsNotCreatable(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        $this->assertFalse(
            MigrationOrchestratorFactory::subscriptionCadenceIsRepresentable(
                $this->subscriptionProduct('month', 2),
            ),
            'A two-monthly contract is not "roughly monthly".',
        );
        $this->assertFalse(
            MigrationOrchestratorFactory::subscriptionCadenceIsRepresentable(
                $this->subscriptionProduct('year', 2),
            ),
        );
    }

    /**
     * And the six pairs section 7.2 does list are created exactly as before —
     * a gate that refused every subscription product would be a different bug
     * wearing the same coat.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheSixSupportedCadencesRemainCreatable(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';

        foreach ([['day', 1], ['week', 1], ['month', 1], ['month', 3], ['month', 6], ['year', 1]] as $pair) {
            [$period, $interval] = $pair;

            $this->assertTrue(
                MigrationOrchestratorFactory::subscriptionCadenceIsRepresentable(
                    $this->subscriptionProduct($period, $interval),
                ),
                sprintf('%s/%d is in the table.', $period, $interval),
            );
        }
    }

    /**
     * A one-time product has no cadence to be wrong about, so the gate has
     * nothing to say and says nothing.
     */
    public function testAnOrdinaryProductIsUnaffectedByTheCadenceGate(): void
    {
        $this->assertTrue(
            MigrationOrchestratorFactory::subscriptionCadenceIsRepresentable(new \WC_Product()),
        );
    }

    /**
     * The gate has to be live, not a blanket refusal: a WooCommerce row that
     * genuinely is not a subscription must be created exactly as it always
     * was, id looked up or not.
     */
    public function testAnOrdinaryWooVariationIsUnaffectedByTheSubscriptionGuard(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        $variation = new \WC_Product_Variation();
        (new \ReflectionProperty(\WC_Product_Variation::class, 'id'))->setValue($variation, 11);
        $GLOBALS['_cartshift_test_wc_products'][11] = $variation;

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['id' => 11]));

        $this->assertCount(1, $this->createdVariants());
        $this->assertSame('onetime', $this->createdVariants()[0]->payment_type);
    }

    /**
     * A WooCommerce subscription product on one exact cadence.
     */
    /**
     * The refusal has to reach the migration log, not `error_log`.
     *
     * A product dropped from the run was reported through `Logger::error()`
     * alone — PHP's `error_log`, which no operator opens — while the mapping
     * screen went on offering "Create" for the row that caused it. Every other
     * refusal in this plugin arrives as a coded log row, and this one now does
     * too.
     *
     * Driven through `forRun()` rather than through the private reader, because
     * WHICH CALLER REACHES THE WRITE is the whole question — see the counting
     * test below.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAnUnrepresentableCadenceIsRefusedIntoTheMigrationLog(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';
        require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

        $this->seedUnrepresentableCreateDecision();

        $state = new MigrationState();
        $state->start(['product']);

        $GLOBALS['_cartshift_test_queries'] = [];

        // As above: the refusal also goes out through Logger::error().
        $this->expectErrorLog();

        $this->factory($state)->forRun(['product']);

        $rows = $this->loggedRows();

        $this->assertNotSame([], $rows, 'The refusal never reached the migration log.');
        $this->assertSame(
            MigrationErrorCode::SubscriptionCadenceUnrepresentable->value,
            $rows[0][MigrationLogRepository::CODE_COLUMN],
        );
        $this->assertStringContainsString('every 2 month', (string) $rows[0]['message']);
    }

    /**
     * And the counting path writes nothing at all.
     *
     * `migratorsForCounting()` is what `PreviewController::preview()` builds
     * from, under a comment reading "this endpoint must stay read-only, so
     * nothing is promoted". With the log write inside the reader, every scope
     * preview on a store carrying one such decision appended an `error` row to
     * whatever migration ID happened to be in state — `''` with no run in
     * flight, or a FINISHED run's own log, corrupting a completed run's record
     * from a GET.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testTheCountingPathExcludesTheProductWithoutWritingALogRow(): void
    {
        require_once dirname(__DIR__, 3) . '/stubs/WcSubscriptionsProductStub.php';
        require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';

        $this->seedUnrepresentableCreateDecision();

        $state = new MigrationState();
        $state->start(['product']);

        $GLOBALS['_cartshift_test_queries'] = [];

        $migrators = $this->factory($state)->migratorsForCounting();

        $this->assertSame([], $this->loggedRows(), 'A read-only count wrote to the migration log.');

        // And the exclusion still applies: a preview that counted a product the
        // run will drop is a receipt for a different run.
        $products = array_values(array_filter(
            $migrators,
            static fn (object $migrator): bool => $migrator instanceof ProductMigrator,
        ));

        $this->assertNotSame([], $products);
        $this->assertStringContainsString(
            '770009',
            (string) json_encode((new \ReflectionProperty(ProductMigrator::class, 'excludedProductIds'))
                ->getValue($products[0])),
        );
    }

    /**
     * One `create` decision for a subscription product FluentCart cannot express.
     */
    private function seedUnrepresentableCreateDecision(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][770_009] = $this->subscriptionProduct('month', 2);
        $GLOBALS['_cartshift_test_get_results_callback'] = static fn (string $query): array
            => str_contains($query, 'cartshift_product_map')
                ? [(object) [
                    'wc_id'       => 770_009,
                    'wc_type'     => 'subscription',
                    'decision'    => 'create',
                    'fc_post_id'  => null,
                    'band'        => 'none',
                    'variant_map' => null,
                ]]
                : [];
    }

    /**
     * Every migration-log row written since the queries were last cleared.
     *
     * @return list<array<string, mixed>>
     */
    private function loggedRows(): array
    {
        return array_values(array_map(
            static fn (array $entry): array => (array) $entry[2],
            array_filter(
                $GLOBALS['_cartshift_test_queries'] ?? [],
                static fn (array $entry): bool => $entry[0] === 'insert'
                    && str_contains((string) $entry[1], 'cartshift_migration_log'),
            ),
        ));
    }

    private function subscriptionProduct(string $period, int $interval): \WC_Product
    {
        $product = new \WC_Product();

        (new \ReflectionProperty(\WC_Product::class, 'meta'))->setValue($product, [
            '_subscription_period'          => $period,
            '_subscription_period_interval' => (string) $interval,
        ]);

        return $product;
    }

    // ── The payload the owner has to live with ──────────────

    public function testThePayloadIsWhatFluentCartWritesForANewVariant(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['name' => 'Extra Large']));

        $created = $this->createdVariants()[0];

        $this->assertSame(900, $created->post_id);
        $this->assertSame('Extra Large', $created->variation_title);
        $this->assertSame('onetime', $created->payment_type);
        $this->assertSame('active', $created->item_status);

        // FluentCart forces in-stock when manage_stock is 0
        // (ProductVariationResource::create), and starts a new combination at
        // total_stock/available 1. The schema default is 'out-of-stock', which
        // renders the buy button disabled and labelled "Not Available".
        $this->assertSame('in-stock', $created->stock_status);
        $this->assertSame(0, $created->manage_stock);
        $this->assertSame(1, $created->total_stock);
        $this->assertSame(1, $created->available);
    }

    /**
     * The column holds the underscore-joined attribute *term IDs* of an
     * advanced-variation combination — FluentCart writes `implode('_', …)` and
     * reads it back with `explode('_', …)`. All 76 real variant rows in the
     * live store have it NULL. A slug there is a value in the wrong namespace.
     */
    public function testTheVariationIdentifierIsNullAndNotASlug(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['name' => 'Extra Large']));

        $this->assertNull($this->createdVariants()[0]->variation_identifier);
    }

    /**
     * NULL other_info is the failure FluentCart's own comment records: readers
     * of `other_info.payment_type` got NULL and tripped the
     * fct_order_items.payment_type NOT NULL constraint. It also blocks the
     * owner's next save of a `simple` product, whose validator requires it.
     */
    public function testOtherInfoCarriesFluentCartsOwnBaseline(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());

        $otherInfo = $this->createdVariants()[0]->other_info;

        $this->assertIsArray($otherInfo);
        $this->assertSame('onetime', $otherInfo['payment_type'], 'The key whose absence broke order items.');
        $this->assertSame('standard', $otherInfo['tax_class']);
        $this->assertSame('no', $otherInfo['tax_exempt']);
        $this->assertArrayHasKey('repeat_interval', $otherInfo);
        $this->assertArrayHasKey('weight', $otherInfo);
    }

    /**
     * serial_index is the display order of variants everywhere in FluentCart,
     * and NULL sorts *first* in both MySQL and the framework's
     * Collection::sortBy(). A migrated variant leading the owner's carefully
     * ordered list is the sort of thing they notice and cannot explain.
     */
    public function testTheVariantIsAppendedRatherThanPutFirst(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        // The owner's own variants, already ordered 1..3.
        foreach ([1, 2, 3] as $index) {
            \CartShiftFcModelStore::record('ProductVariation', [
                'post_id'      => 900,
                'serial_index' => $index,
                'item_price'   => 1400,
            ]);
        }

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());

        $created = $this->createdVariants();

        $this->assertSame(4, end($created)->serial_index);
    }

    /**
     * A product with no variants at all still has to land somewhere sensible:
     * max() answers NULL, and (int) NULL + 1 is 1.
     */
    public function testTheFirstVariantOnAnEmptyProductStartsAtOne(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());

        $this->assertSame(1, $this->createdVariants()[0]->serial_index);
    }

    public function testANamelessOrphanStillGetsATitle(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['name' => '']));

        $this->assertSame('Migrated variant', $this->createdVariants()[0]->variation_title);
    }

    // ── Price ───────────────────────────────────────────────
    //
    // item_price = 0 with item_status = 'active' adds a free, purchasable item
    // to a live catalogue — canPurchase() has no price floor — and drags the
    // product's min_price to 0 the next time the owner opens the editor and
    // clicks Save, long after the migration, with no visible cause.

    public function testTheVariantIsPricedFromTheWooVariationNotAtZero(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['price' => 1999]));

        $this->assertSame(
            1999.0,
            $this->createdVariants()[0]->item_price,
            'A free, buyable item in the owner catalogue is not a neutral default.',
        );
    }

    /**
     * A decision saved before the price travelled carries no price at all. The
     * linked product's own floor is the least wrong answer available: it is a
     * number the owner chose, on the product this variant is joining.
     */
    public function testALegacyDescriptorWithNoPriceFallsBackToTheProductsOwnFloor(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail(['min_price' => 1400.0]);

        MigrationOrchestratorFactory::createOrphanVariant(900, ['id' => 11, 'sku' => '', 'name' => 'XL']);

        $this->assertSame(1400.0, $this->createdVariants()[0]->item_price);
    }

    /**
     * The other half of the price fix. FluentCart maintains min_price/max_price
     * in exactly two places, both full-product saves, and ProductVariation
     * fires no create/save model events — so a raw create() leaves the detail
     * row quoting the owner's old range until the day they click Save and it
     * moves for no visible reason.
     */
    public function testTheProductsPriceRangeIsRecomputedFromItsVariants(): void
    {
        \CartShiftFcModelStore::install();
        $detail = $this->seedDetail(['min_price' => 1400.0, 'max_price' => 2400.0]);

        \CartShiftFcModelStore::record('ProductVariation', ['post_id' => 900, 'item_price' => 1400.0]);
        \CartShiftFcModelStore::record('ProductVariation', ['post_id' => 900, 'item_price' => 2400.0]);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan(['price' => 9900]));

        $this->assertSame(1400.0, $detail->min_price);
        $this->assertSame(9900.0, $detail->max_price);
        $this->assertContains($detail, $GLOBALS['_cartshift_test_fc_saved'] ?? [], 'The detail row has to be written back.');
    }

    /**
     * FluentCart refreshes stock_availability on every variant write, and its
     * branch is not the obvious one: a product that is *not* managing stock is
     * unconditionally in stock, whatever its variants say
     * (ProductDetailResource::update, action 'variant_modified').
     */
    public function testStockAvailabilityIsRefreshedTheWayFluentCartRefreshesIt(): void
    {
        \CartShiftFcModelStore::install();
        $detail = $this->seedDetail(['manage_stock' => 1, 'stock_availability' => 'out-of-stock']);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());

        $this->assertSame(
            'in-stock',
            $detail->stock_availability,
            'The variant is in stock, so the product is — leaving the detail row disagreeing with its '
            . 'variants is the delayed surprise the price-range fix exists to stop.',
        );
    }

    public function testAProductNotManagingStockIsAlwaysInStock(): void
    {
        \CartShiftFcModelStore::install();
        $detail = $this->seedDetail(['manage_stock' => 0, 'stock_availability' => 'out-of-stock']);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());

        $this->assertSame('in-stock', $detail->stock_availability);
    }

    // ── Fulfilment and downloads ────────────────────────────

    /**
     * Cart::requireShipping() reads fulfillment_type per line, so a
     * downloadable Woo variation landing as the column default 'physical'
     * makes FluentCart demand a shipping address and a shipping method for a
     * file.
     */
    public function testFulfilmentAndDownloadabilityComeFromTheWooVariation(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail(['fulfillment_type' => 'physical']);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan([
            'fulfillment_type' => 'digital',
            'downloadable'     => 'true',
        ]));

        $created = $this->createdVariants()[0];

        $this->assertSame('digital', $created->fulfillment_type);
        $this->assertSame('true', $created->downloadable);
    }

    public function testALegacyDescriptorFallsBackToTheLinkedProductsFulfilmentType(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail(['fulfillment_type' => 'digital']);

        MigrationOrchestratorFactory::createOrphanVariant(900, ['id' => 11, 'sku' => '', 'name' => 'XL']);

        $created = $this->createdVariants()[0];

        $this->assertSame('digital', $created->fulfillment_type);
        $this->assertSame('false', $created->downloadable);
    }

    // ── Advanced variations ─────────────────────────────────
    //
    // FluentCart regenerates such a product's variants from the attribute
    // cartesian on every combination save and deletes everything not in it
    // (AdvancedVariationService -> ProductAdminHelper::deleteOrphanVariant), so
    // a variant added here is destroyed later and every order line pointing at
    // it dangles. MappingController keeps these products out of the dropdown;
    // this is the refusal for a decision that reaches promotion anyway.

    public function testAnAdvancedVariationProductIsRefusedRatherThanWrittenInto(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail(['variation_type' => 'advanced_variations']);

        $result = MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());

        $this->assertNull($result);
        $this->assertSame(
            [],
            $this->createdVariants(),
            'FluentCart deletes this row on the owner next combination save, taking the order lines with it.',
        );
    }

    public function testASimpleVariationsProductIsStillWrittenInto(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail(['variation_type' => 'simple_variations']);

        $this->assertNotNull(MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan()));
        $this->assertCount(1, $this->createdVariants());
    }

    /**
     * FluentCart fires this after every one of its own variant writes, for
     * cache invalidators, search indexers and webhook subscribers. Omitting it
     * makes CartShift's write invisible to all of them.
     */
    public function testTheVariantsUpdatedActionIsFired(): void
    {
        \CartShiftFcModelStore::install();
        $this->seedDetail();

        $fired = [];

        add_action('fluent_cart/product/variants_updated', static function (array $payload) use (&$fired): void {
            $fired[] = $payload;
        }, 10, 1);

        MigrationOrchestratorFactory::createOrphanVariant(900, $this->orphan());

        $this->assertCount(1, $fired);
        $this->assertSame(900, $fired[0]['post_id']);
        $this->assertCount(1, $fired[0]['variants']);
        $this->assertSame($this->createdVariants()[0]->id, $fired[0]['variants'][0]['id']);
    }

    // ── logDeadLinksOnce ─────────────────────────────────────

    private function log(): MigrationLogRepository
    {
        return new MigrationLogRepository();
    }

    /**
     * Wire the real MigrationLogRepository through the $wpdb stub's global
     * callbacks — the same real-repository-over-stub pattern
     * MappingPromoterTest.php and MappingControllerTest.php both use for a
     * `final` repository. write() rows are captured into $rows keyed
     * sequentially; hasEntryFor()'s SELECT is served by searching that same
     * array for a row matching all four columns its WHERE clause names, so
     * the test proves the real SQL discriminates correctly rather than
     * asserting against a canned return value.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function driveLogThrough(array &$rows): void
    {
        $GLOBALS['_cartshift_test_insert_callback'] = static function (string $table, array $data) use (&$rows): int {
            if (str_contains($table, 'cartshift_migration_log')) {
                $rows[] = $data;
            }

            return 1;
        };

        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query) use (&$rows): ?string {
            if (!str_contains($query, 'cartshift_migration_log')) {
                return null;
            }

            preg_match(
                "/migration_id = '([^']*)' AND entity_type = '([^']*)' AND wc_id = '([^']*)' AND error_code = '([^']*)'/",
                $query,
                $m,
            );

            if ($m === []) {
                return null;
            }

            foreach ($rows as $index => $row) {
                if (
                    $row['migration_id'] === $m[1]
                    && $row['entity_type'] === $m[2]
                    && $row['wc_id'] === $m[3]
                    && $row['error_code'] === $m[4]
                ) {
                    return (string) ($index + 1);
                }
            }

            return null;
        };
    }

    public function testADeadLinkIsLoggedOnce(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900]);

        $this->assertCount(1, $rows);
        $this->assertSame(Constants::ENTITY_PRODUCT, $rows[0]['entity_type']);
        $this->assertSame('900', $rows[0]['wc_id']);
        $this->assertSame('warning', $rows[0]['status']);
        $this->assertSame(MigrationErrorCode::MappedFcProductMissing->value, $rows[0]['error_code']);
    }

    /**
     * The finding this method exists to prove fixed: the orchestrator factory
     * re-enters on every batch tick and MappingPromoter::promote() keeps
     * reporting the same dead id every time on purpose, so logDeadLinksOnce()
     * has to be safe to call more than once with the same id in the same run.
     */
    public function testASecondCallInTheSameRunDoesNotReLog(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900]);
        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900]);
        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900]);

        $this->assertCount(1, $rows, 'Three ticks reporting the same dead id must produce one warning row, not three.');
    }

    /**
     * De-duplication is scoped to (migration_id, entity_type, wc_id,
     * error_code) — a different run, or a genuinely different dead product
     * in the same run, must still log normally rather than being swallowed
     * by an unrelated row.
     */
    public function testANewDeadIdInTheSameRunStillLogs(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900]);
        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900, 901]);

        $this->assertCount(2, $rows);
        $this->assertSame('900', $rows[0]['wc_id']);
        $this->assertSame('901', $rows[1]['wc_id']);
    }

    public function testTheSameDeadIdInADifferentRunStillLogs(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900]);
        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-2', [900]);

        $this->assertCount(2, $rows, 'A dedup keyed on migration_id must not suppress a different run.');
    }

    // ── logOrphanFailuresOnce ────────────────────────────────
    //
    // The failure this exists for was entirely silent: promote() counted
    // `added: 0`, the wizard reported success, and the owner found out when an
    // order detail page showed a line item pointing at nothing.

    public function testAnOrphanCartShiftCouldNotCreateIsLoggedAgainstTheWooVariation(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logOrphanFailuresOnce($this->log(), 'run-1', [432]);

        $this->assertCount(1, $rows);
        $this->assertSame(
            Constants::ENTITY_VARIATION,
            $rows[0]['entity_type'],
            'Keyed on the WooCommerce variation, because that is the record the owner has to go and look at.',
        );
        $this->assertSame('432', $rows[0]['wc_id']);
        $this->assertSame('warning', $rows[0]['status']);
        $this->assertSame(MigrationErrorCode::OrphanVariantNotCreated->value, $rows[0]['error_code']);
    }

    public function testAnOrphanFailureIsNotReLoggedOnASecondTick(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logOrphanFailuresOnce($this->log(), 'run-1', [432]);
        MigrationOrchestratorFactory::logOrphanFailuresOnce($this->log(), 'run-1', [432]);

        $this->assertCount(1, $rows);
    }

    /**
     * The two dedups share a helper, so this pins that they do not share a
     * *key*: a dead product 900 must not swallow the warning for variation 900.
     */
    public function testADeadLinkAndAnOrphanFailureWithTheSameIdBothLog(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logDeadLinksOnce($this->log(), 'run-1', [900]);
        MigrationOrchestratorFactory::logOrphanFailuresOnce($this->log(), 'run-1', [900]);

        $this->assertCount(2, $rows);
    }

    // ── fcVariantIdsFor ──────────────────────────────────────
    //
    // Promotion's authority for whether a mapped variant is the linked
    // product's to map. The same `WHERE post_id = ?` MappingController builds
    // the screen's dropdown from, re-asked at run time.

    public function testTheProductsOwnVariantIdsComeBackAsIntegers(): void
    {
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array
            => str_contains($query, 'fct_product_variations') ? ['501', '502'] : [];

        $this->assertSame([501, 502], MigrationOrchestratorFactory::fcVariantIdsFor(900));
    }

    public function testAProductWithNoVariantsAnswersAnEmptyList(): void
    {
        $GLOBALS['_cartshift_test_get_col_callback'] = static fn (string $query): array => [];

        $this->assertSame([], MigrationOrchestratorFactory::fcVariantIdsFor(900));
    }

    public function testTheQueryIsScopedToTheProductAsked(): void
    {
        $seen = '';

        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use (&$seen): array {
            $seen = $query;

            return [];
        };

        MigrationOrchestratorFactory::fcVariantIdsFor(900);

        $this->assertStringContainsString('post_id = 900', $seen);
    }

    // ── linkLosesDownloads ───────────────────────────────────

    public function testAWooProductWithFilesLinkedToAnFcProductWithNoneLosesThem(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][99] = $this->downloadableProduct();
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string => '0';

        $this->assertTrue(MigrationOrchestratorFactory::linkLosesDownloads(99, 900));
    }

    public function testNothingIsLostWhenTheLinkedProductAlreadyCarriesFiles(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][99] = $this->downloadableProduct();
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string => '3';

        $this->assertFalse(MigrationOrchestratorFactory::linkLosesDownloads(99, 900));
    }

    public function testAWooProductWithNoFilesLosesNothing(): void
    {
        $GLOBALS['_cartshift_test_wc_products'][99] = new \WC_Product();
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string => '0';

        $this->assertFalse(MigrationOrchestratorFactory::linkLosesDownloads(99, 900));
    }

    /**
     * A variable product carries its files on the variations rather than on the
     * parent — which is why ProductMigrator has a separate
     * migrateVariableDownloads(). Reading the parent alone would report every
     * downloadable variable product as carrying nothing to lose.
     */
    public function testFilesOnAVariationCountAsFilesOnTheProduct(): void
    {
        $parent = new \WC_Product();
        $ref    = new \ReflectionClass($parent);
        $ref->getProperty('type')->setValue($parent, 'variable');
        $ref->getProperty('children')->setValue($parent, [105]);

        $GLOBALS['_cartshift_test_wc_products'][101] = $parent;
        $GLOBALS['_cartshift_test_wc_products'][105] = $this->downloadableProduct();
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string => '0';

        $this->assertTrue(MigrationOrchestratorFactory::linkLosesDownloads(101, 900));
    }

    /**
     * P1 regression: wooHasDownloads() used to compare the bare literal
     * 'variable', which a variable-subscription parent never matches — so it
     * never walked the children at all and reported every downloadable
     * variable-subscription product as carrying nothing to lose, the same way
     * a plain simple product with no downloads would.
     */
    public function testFilesOnAVariationCountAsFilesOnTheProductForVariableSubscription(): void
    {
        $parent = new \WC_Product();
        $ref    = new \ReflectionClass($parent);
        $ref->getProperty('type')->setValue($parent, 'variable-subscription');
        $ref->getProperty('children')->setValue($parent, [106]);

        $GLOBALS['_cartshift_test_wc_products'][102] = $parent;
        $GLOBALS['_cartshift_test_wc_products'][106] = $this->downloadableProduct();
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string => '0';

        $this->assertTrue(MigrationOrchestratorFactory::linkLosesDownloads(102, 900));
    }

    public function testAWooProductThatNoLongerExistsLosesNothing(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string => '0';

        $this->assertFalse(MigrationOrchestratorFactory::linkLosesDownloads(99, 900));
    }

    private function downloadableProduct(): \WC_Product
    {
        $product = new \WC_Product();
        $ref     = new \ReflectionClass($product);

        $ref->getProperty('downloadable')->setValue($product, true);
        $ref->getProperty('downloads')->setValue($product, [
            (object) ['id' => 'abc', 'file' => 'https://example.com/course.pdf'],
        ]);

        return $product;
    }

    // ── logForeignVariantsOnce / logLostDownloadsOnce ────────

    public function testADroppedVariantMappingIsLoggedAgainstTheWooVariation(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logForeignVariantsOnce($this->log(), 'run-1', [12]);

        $this->assertCount(1, $rows);
        $this->assertSame(Constants::ENTITY_VARIATION, $rows[0]['entity_type']);
        $this->assertSame('12', $rows[0]['wc_id']);
        $this->assertSame('warning', $rows[0]['status']);
        $this->assertSame(MigrationErrorCode::MappedVariantNotOnProduct->value, $rows[0]['error_code']);
    }

    public function testALinkedProductWithNoFilesIsLoggedAgainstTheWooProduct(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logLostDownloadsOnce($this->log(), 'run-1', [99]);

        $this->assertCount(1, $rows);
        $this->assertSame(Constants::ENTITY_PRODUCT, $rows[0]['entity_type']);
        $this->assertSame('99', $rows[0]['wc_id']);
        $this->assertSame(MigrationErrorCode::MappedProductHasNoDownloads->value, $rows[0]['error_code']);
    }

    /**
     * Promotion is re-entered on every batch tick, so both of these have to
     * de-dup the same way the dead-link and orphan warnings already do.
     */
    public function testNeitherNewWarningIsReLoggedOnASecondTick(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logForeignVariantsOnce($this->log(), 'run-1', [12]);
        MigrationOrchestratorFactory::logForeignVariantsOnce($this->log(), 'run-1', [12]);
        MigrationOrchestratorFactory::logLostDownloadsOnce($this->log(), 'run-1', [99]);
        MigrationOrchestratorFactory::logLostDownloadsOnce($this->log(), 'run-1', [99]);

        $this->assertCount(2, $rows);
    }

    /**
     * Skipped, not warned: the owner narrowed the run and promotion obeyed, so
     * nothing is wrong. Recorded anyway, because a link the owner drafted and
     * then did not see happen is otherwise indistinguishable from one that
     * failed — and a stale mapping is the likeliest reason a re-run appears to
     * "do nothing".
     */
    public function testAnOutOfScopeLinkIsLoggedAsHousekeepingNotAsAWarning(): void
    {
        $rows = [];
        $this->driveLogThrough($rows);

        MigrationOrchestratorFactory::logOutOfScopeLinksOnce($this->log(), 'run-1', [77]);
        MigrationOrchestratorFactory::logOutOfScopeLinksOnce($this->log(), 'run-1', [77]);

        $this->assertCount(1, $rows, 'Promotion re-enters on every batch tick.');
        $this->assertSame(Constants::ENTITY_PRODUCT, $rows[0]['entity_type']);
        $this->assertSame('77', $rows[0]['wc_id']);
        $this->assertSame('skipped', $rows[0]['status']);
        $this->assertSame(MigrationErrorCode::MappedProductOutOfScope->value, $rows[0]['error_code']);
    }

    // ── Assembly and promotion timing ───────────────────────

    /**
     * Serve the staging table from a fixture, and capture ID map writes.
     *
     * @param list<array<string, mixed>>                                    $mapRows staging rows, as stored
     * @param list<array{0: string, 1: string, 2: int, 3: string, 4: bool}> $stored  captured ID map writes
     */
    private function stubStagingTable(array $mapRows, array &$stored): void
    {
        $GLOBALS['_cartshift_test_posts'][900] = ['status' => 'publish', 'type' => 'fluent-products'];

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

        $GLOBALS['_cartshift_test_get_results_callback'] = static function (string $query) use ($mapRows): array {
            if (!str_contains($query, 'cartshift_product_map')) {
                return [];
            }

            $out = [];

            foreach ($mapRows as $row) {
                if (str_contains($query, "decision = 'link'") && $row['decision'] !== 'link') {
                    continue;
                }

                if (str_contains($query, "decision = 'skip'") && $row['decision'] !== 'skip') {
                    continue;
                }

                $out[] = (object) $row;
            }

            return $out;
        };

        // Nothing is promoted yet; the stub's default of 0 would read as
        // "fc_id 0", which promotion treats as already done.
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string|null
            => str_contains($query, 'cartshift_id_map') ? null : '0';

        // The linked FluentCart product owns the variants the decisions map to,
        // which is the state a decision is saved in — the mapping screen builds
        // that map from a `WHERE post_id = ?` query in the first place.
        // Promotion re-asks at run time, so without this the fixture would be
        // describing a product whose variants had all been deleted since.
        $GLOBALS['_cartshift_test_get_col_callback'] = static function (string $query) use ($mapRows): array {
            if (!str_contains($query, 'fct_product_variations')) {
                return [];
            }

            $ids = [];

            foreach ($mapRows as $row) {
                $envelope = json_decode((string) ($row['variant_map'] ?? ''), true);

                foreach ((array) ($envelope['map'] ?? []) as $fcVariationId) {
                    $ids[] = (int) $fcVariationId;
                }
            }

            return $ids;
        };
    }

    private function factory(MigrationState $state): MigrationOrchestratorFactory
    {
        return MigrationOrchestratorFactory::standalone(
            new IdMapRepository(),
            new MigrationLogRepository(),
            $state,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function linkRow(int $wcId, int $fcPostId, array $variantMap = []): array
    {
        return [
            'wc_id'       => $wcId,
            'wc_type'     => 'variable',
            'decision'    => 'link',
            'fc_post_id'  => $fcPostId,
            'band'        => 'strong',
            'variant_map' => (string) wp_json_encode(['map' => $variantMap, 'orphans' => []]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function skipRow(int $wcId): array
    {
        return [
            'wc_id'       => $wcId,
            'wc_type'     => 'simple',
            'decision'    => 'skip',
            'fc_post_id'  => null,
            'band'        => 'none',
            'variant_map' => null,
        ];
    }

    /**
     * The SQL a ProductMigrator carries its exclusions in. Read off the query
     * log rather than off a getter, because the exclusion is only real if it
     * reaches the source query — an instance holding a list nothing renders
     * would pass a getter-based assertion and migrate the products anyway.
     */
    private function productCountQuery(ProductMigrator $migrator): string
    {
        $GLOBALS['_cartshift_test_queries'] = [];

        $migrator->count();

        foreach ($GLOBALS['_cartshift_test_queries'] as $entry) {
            if (($entry[0] ?? '') === 'prepare' && str_contains((string) ($entry[1] ?? ''), 'wc_product_meta_lookup')) {
                return (string) $entry[1];
            }
        }

        $this->fail('ProductMigrator::count() did not query the product source table.');
    }

    public function testARunExcludesTheProductsTheOwnerSkipped(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->skipRow(7), $this->skipRow(9)], $stored);

        $migrators = $this->factory(new MigrationState())->migratorsForCounting();

        $this->assertInstanceOf(ProductMigrator::class, $migrators[0]);
        $this->assertStringContainsString(
            'p.ID NOT IN (7,9)',
            $this->productCountQuery($migrators[0]),
            'A skip decision is only honoured if it reaches the migrator source query.',
        );
    }

    /**
     * /preview and /counts are read-only by construction: the owner is still
     * choosing, and the UI calls them repeatedly as the selection changes.
     * Writing ID map rows from there would make the mapping screen's drafts
     * into facts behind the owner's back.
     */
    public function testCountingNeverPromotesAnything(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900, [11 => 501])], $stored);

        $this->factory(new MigrationState())->migratorsForCounting();

        $this->assertSame([], $stored);
    }

    /**
     * The timing case the whole class is shaped around. MigrationState mints
     * the migration ID inside startMigration(), which then runs the first
     * batch before returning — so at the moment forRun() is called on this
     * path there is no ID to stamp rows with, and promotion has to wait for
     * `cartshift/migration/started`.
     */
    public function testAStartingRunPromotesOnlyOnceTheMigrationIdExists(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900, [11 => 501])], $stored);

        $this->factory(new MigrationState())->forRun();

        $this->assertSame([], $stored, 'Nothing may be stamped with a migration id that does not exist yet.');

        do_action('cartshift/migration/started', 'run-7', ['product'], false);

        // Variants first, product last: the product row is MappingPromoter's
        // "this decision is finished" marker, so nothing may follow it.
        $this->assertSame([Constants::ENTITY_VARIATION, '11', 501, 'run-7', false], $stored[0] ?? null);
        $this->assertSame(
            [Constants::ENTITY_PRODUCT, '42', 900, 'run-7', false],
            $stored[1] ?? null,
        );
    }

    /**
     * The other half: a batch request or an Action Scheduler tick arrives
     * mid-run, where the ID is already real and no `started` action is coming.
     */
    public function testARunAlreadyInFlightPromotesImmediately(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900)], $stored);

        $state = new MigrationState();
        $state->start(['product']);

        $this->factory($state)->forRun();

        $this->assertSame(
            [Constants::ENTITY_PRODUCT, '42', 900, (string) $state->getMigrationId(), false],
            $stored[0] ?? null,
        );
    }

    /**
     * A finished run leaves its id in the stored state until reset. Promoting
     * under it would file this run's links where its own rollback cannot find
     * them, so "has an id" is not the question — "is running" is.
     */
    public function testAFinishedPreviousRunIsNotMistakenForALiveOne(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900)], $stored);

        $state = new MigrationState();
        $state->start(['product']);
        $previousId = (string) $state->getMigrationId();
        $state->complete();

        $this->factory($state)->forRun();

        $this->assertSame([], $stored, 'A completed run is not a run in flight.');

        do_action('cartshift/migration/started', 'run-8', ['product'], false);

        $this->assertSame('run-8', $stored[0][3] ?? null);
        $this->assertNotSame($previousId, $stored[0][3] ?? null);
    }

    // ── Which realm promotion writes into ───────────────────

    /**
     * A factory over a caller-supplied IdMapRepository, so a test can put the
     * repository into the wrong realm and watch promotion correct it.
     */
    private function factoryOver(IdMapRepository $idMap, MigrationState $state): MigrationOrchestratorFactory
    {
        $map = new ProductMapRepository();

        return new MigrationOrchestratorFactory(
            $idMap,
            new MigrationLogRepository(),
            $state,
            new MappingPromoter(
                $map,
                $idMap,
                MigrationOrchestratorFactory::fcProductStillExists(...),
                MigrationOrchestratorFactory::createOrphanVariant(...),
                MigrationOrchestratorFactory::fcVariantIdsFor(...),
                MigrationOrchestratorFactory::linkLosesDownloads(...),
            ),
            $map,
        );
    }

    /** The is_simulated flag on the first ID map insert, or null if there was none. */
    private function firstIdMapRealm(): ?int
    {
        foreach ($GLOBALS['_cartshift_test_queries'] as $entry) {
            if (($entry[0] ?? '') === 'insert' && str_contains((string) ($entry[1] ?? ''), 'cartshift_id_map')) {
                return (int) $entry[2]['is_simulated'];
            }
        }

        return null;
    }

    /**
     * The realm comes from MigrationState, not from whatever the repository was
     * last set to. This direction protects a real run: a repository left
     * simulating by something earlier must not quietly file the owner's links
     * as rows the run itself cannot see.
     */
    public function testARealRunPromotesIntoTheRealRealmWhateverTheRepositoryWasSetTo(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900)], $stored);

        $idMap = new IdMapRepository();
        $idMap->setSimulating(true);

        $this->factoryOver($idMap, new MigrationState())->promote('run-9');

        $this->assertSame(0, $this->firstIdMapRealm());
        $this->assertFalse($idMap->isSimulating());
    }

    /**
     * And the direction that matters for a rehearsal. Promotion runs *before*
     * MigrationOrchestrator::processBatch() derives the realm, and on a batch
     * tick the repository it gets is freshly constructed and not simulating —
     * so without deriving it here a dry run would promote for real: its links
     * would outlive the rehearsal, its orphan variants would be created in the
     * owner's catalogue, and the real run afterwards would find promotion
     * already done and skip the decision entirely.
     */
    public function testADryRunPromotesIntoTheSimulatedRealmEvenOnAFreshRepository(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900)], $stored);

        $state = new MigrationState();
        $state->start(['product'], true);

        // Exactly what a batch tick hands it: brand new, not simulating.
        $idMap = new IdMapRepository();

        $this->assertFalse($idMap->isSimulating(), 'Fixture check: this is the state a batch tick starts from.');

        $this->factoryOver($idMap, $state)->promote((string) $state->getMigrationId());

        $this->assertSame(1, $this->firstIdMapRealm());
        $this->assertTrue($idMap->isSimulating());
    }

    // ── Which products promotion covers ─────────────────────

    /**
     * The run's scope reaches promotion, and it comes from MigrationState.
     *
     * This is the wiring MappingPromoterScopeTest cannot see: the promoter is
     * handed a resolver by whoever calls it, and this class is the only caller.
     * Built fresh on every promote() rather than held, because a later batch is
     * a different request reading state for itself — the same rule
     * AbstractMigrator::scopeResolver() follows.
     */
    public function testPromotionCoversOnlyTheProductsTheRunsScopeSelects(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900)], $stored);

        $state = new MigrationState();
        $state->start(['product'], false, MigrationScope::fromArray([
            'mode'        => 'explicit',
            'product_ids' => [99],
        ]));

        $promotion = $this->factory($state)->promote((string) $state->getMigrationId());

        $this->assertSame([42], $promotion['outOfScope']);
        $this->assertSame(0, $promotion['linked']);
        $this->assertSame([], $stored, 'A run that never migrates product 42 must not link it either.');
    }

    /**
     * And the other direction, because a filter that is too eager is the
     * regression this pair exists to catch: the same staged link, the same
     * explicit mode, this time with the product actually picked.
     */
    public function testPromotionStillCoversAProductTheScopeDoesSelect(): void
    {
        $stored = [];
        $this->stubStagingTable([$this->linkRow(42, 900)], $stored);

        $state = new MigrationState();
        $state->start(['product'], false, MigrationScope::fromArray([
            'mode'        => 'explicit',
            'product_ids' => [42],
        ]));

        $promotion = $this->factory($state)->promote((string) $state->getMigrationId());

        $this->assertSame([], $promotion['outOfScope']);
        $this->assertSame(1, $promotion['linked']);
        $this->assertSame([Constants::ENTITY_PRODUCT, '42', 900], array_slice($stored[0], 0, 3));
    }
}
