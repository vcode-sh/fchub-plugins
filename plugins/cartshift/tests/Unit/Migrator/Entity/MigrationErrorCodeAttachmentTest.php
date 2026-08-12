<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Domain\Mapping\CouponMapper;
use CartShift\Domain\Mapping\OrderMapper;
use CartShift\Domain\Mapping\ProductMapper;
use CartShift\Domain\Subscription\Payment\PaymentMigrationDecision;
use CartShift\Domain\Subscription\SubscriptionAssessment;
use CartShift\Migrator\CouponMigrator;
use CartShift\Migrator\CustomerMigrator;
use CartShift\Migrator\OrderMigrator;
use CartShift\Migrator\ProductMigrator;
use CartShift\Migrator\SubscriptionMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/ProductMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/HttpCliStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/MapperStubs.php';

/**
 * Every failure the migrators can produce now carries a machine-readable reason
 * alongside the human sentence.
 *
 * The sentence is not what is under test here — it is unchanged on purpose,
 * because it carries the specifics (which SKU, which coupon code, which ID) a
 * fixed vocabulary never can. What is under test is that the code travels with
 * it, so a log of four thousand near-identical sentences can be grouped into one
 * cause and one fix.
 *
 * Representative sites, not exhaustive: one per shape of failure, chosen so the
 * assertion needs no scaffolding that could itself be wrong.
 */
final class MigrationErrorCodeAttachmentTest extends PluginTestCase
{
    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
        $this->state = new MigrationState();

        // PluginTestCase does not clear this one, and a callback left behind by
        // an earlier test would answer queries it knows nothing about.
        unset($GLOBALS['_cartshift_test_get_var_callback']);
    }

    // ──────────────────────────────────────────────
    // Coupons
    // ──────────────────────────────────────────────

    public function testACouponWithNoCodeIsLoggedAsCouponCodeMissing(): void
    {
        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);

        $migrator->processRecord($this->coupon(41, '', 'percent'));

        $this->assertLogged(MigrationErrorCode::CouponCodeMissing);
    }

    public function testAnOverlongCouponCodeIsLoggedAsCouponCodeTooLong(): void
    {
        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);

        $migrator->processRecord($this->coupon(42, str_repeat('A', 51), 'percent'));

        $this->assertLogged(MigrationErrorCode::CouponCodeTooLong);
    }

    /**
     * The mapper's warning about an unrecognised discount type used to be
     * collected and dropped on the floor by the real run — only the dry run ever
     * mentioned it. It is now flushed to the log with its code, before the
     * code-length and collision checks that might skip the coupon entirely.
     */
    public function testAnUnrecognisedDiscountTypeReachesTheLogOnARealRun(): void
    {
        $migrator = new CouponMigrator($this->idMap, $this->log, $this->state);

        $migrator->processRecord($this->coupon(43, '', 'some_third_party_type'));

        $this->assertLogged(MigrationErrorCode::UnknownCouponType);
    }

    public function testTheCouponMapperPairsItsWarningWithACode(): void
    {
        $mapper = new CouponMapper($this->idMap, 'USD');
        $mapper->map($this->coupon(44, 'SAVE', 'some_third_party_type'));

        $coded = $mapper->getCodedWarnings();

        $this->assertCount(1, $coded);
        $this->assertSame(MigrationErrorCode::UnknownCouponType, $coded[0]['code']);
        $this->assertSame(
            $mapper->getWarnings(),
            array_column($coded, 'message'),
            'getWarnings() must keep returning plain sentences for callers that predate the codes.',
        );
    }

    // ──────────────────────────────────────────────
    // Customers
    // ──────────────────────────────────────────────

    public function testAGuestWithNoEmailIsLoggedAsMissingEmail(): void
    {
        $migrator = new CustomerMigrator($this->idMap, $this->log, $this->state);

        $migrator->validateRecord(['type' => 'guest', 'data' => ['email' => '']]);

        $this->assertLogged(MigrationErrorCode::MissingEmail);
    }

    // ──────────────────────────────────────────────
    // Orders
    // ──────────────────────────────────────────────

    public function testAnAlreadyMigratedOrderIsLoggedAsAlreadyMigrated(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): string|int => str_contains($query, 'cartshift_id_map') ? 9 : 0;

        $migrator = new OrderMigrator($this->idMap, $this->log, $this->state);

        $migrator->validateRecord(new \WC_Order());

        $this->assertLogged(MigrationErrorCode::AlreadyMigrated);
    }

    /**
     * The order mapper has always zeroed post_id for an item whose product was
     * not migrated, which is the right thing to do — the name and the price stay,
     * so the books balance. What it never did was say so, which made the one
     * lossy thing about an otherwise perfect order invisible.
     */
    public function testTheOrderMapperPairsItsUnlinkedItemWarningWithACode(): void
    {
        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (string $query): null => null;

        $mapper = new OrderMapper($this->idMap, 'USD');

        $order = new \WC_Order();
        (new \ReflectionProperty(\WC_Order::class, 'id'))->setValue($order, 71);
        (new \ReflectionProperty(\WC_Order::class, 'status'))->setValue($order, 'completed');
        (new \ReflectionProperty(\WC_Order::class, 'items'))->setValue($order, [
            new \CartShiftTestOrderItem(202, 0, 'Retired thing'),
            new \CartShiftTestOrderItem(303, 0, 'Discontinued thing'),
        ]);

        $mapper->map($order);

        $coded = $mapper->getCodedWarnings();

        $this->assertCount(1, $coded, 'One order is one countable event, whatever the item count.');
        $this->assertSame(MigrationErrorCode::ProductLinkMissing, $coded[0]['code']);
        $this->assertSame(
            $mapper->getWarnings(),
            array_column($coded, 'message'),
            'getWarnings() must return plain sentences, as the other mappers do.',
        );
    }

    // ──────────────────────────────────────────────
    // Subscriptions
    // ──────────────────────────────────────────────

    /**
     * A guest subscription — `_customer_user = 0`, which is 349 of the 564
     * preserved Lapka records — whose FluentCart customer has not been
     * migrated. Everything else about it resolves, so the code names the one
     * thing that does not.
     */
    public function testASubscriptionWithNoCustomerIsLoggedAsCustomerNotFound(): void
    {
        $GLOBALS['_cartshift_test_id_map'] = [
            'order'     => ['808' => 8080],
            'product'   => ['101' => 1010],
            'variation' => ['101' => 1011],
        ];
        $GLOBALS['_cartshift_test_get_var_callback'] = static function (string $query): int|null {
            if (preg_match("/entity_type = '([^']*)' AND wc_id = '([^']*)'/", $query, $matches) === 1) {
                return $GLOBALS['_cartshift_test_id_map'][$matches[1]][$matches[2]] ?? null;
            }

            return null;
        };

        $migrator = new SubscriptionMigrator($this->idMap, $this->log, $this->state);

        $migrator->validateRecord(new \CartShiftTestSubscription(
            51,
            [new \CartShiftTestOrderItem(101, 0, 'Monthly Coffee')],
            0,
            'active',
            '',
            null,
            808,
            ['next_payment' => '2099-01-01 00:00:00'],
        ));

        $this->assertLogged(MigrationErrorCode::CustomerNotFound);
    }

    /**
     * Reason codes travel with their message, and they always have. What has
     * moved is where they are produced: the mapper used to accumulate warnings
     * as a side effect of mapping, which meant the payload and the diagnostics
     * were built by the same pass and a caller had to remember to drain one
     * after reading the other. The assessment now carries both, as data, before
     * anything is mapped at all.
     */
    public function testTheSubscriptionAssessmentPairsEveryReasonWithItsCode(): void
    {
        $assessment = new SubscriptionAssessment(
            SubscriptionAssessment::OUTCOME_BLOCKED,
            [['code' => SubscriptionAssessment::REASON_MULTI_ITEM, 'message' => 'multi item']],
            [['code' => SubscriptionAssessment::REASON_VARIATION_NOT_ON_PRODUCT, 'message' => 'wrong product']],
            [],
            new PaymentMigrationDecision(
                strategy: PaymentMigrationDecision::STRATEGY_MANUAL,
                outcome: PaymentMigrationDecision::OUTCOME_READY,
                collectionMethod: PaymentMigrationDecision::COLLECTION_MANUAL,
                currentPaymentMethod: '',
                nextActionOwner: PaymentMigrationDecision::OWNER_TARGET_MANUAL,
                vendorCustomerId: null,
                vendorPlanId: null,
                vendorSubscriptionId: null,
                activePaymentMethod: [],
                reasonCodes: [],
            ),
        );

        $this->assertSame(['multi_item_subscription'], $assessment->errorCodes());
        $this->assertSame(['target_variation_not_on_product'], $assessment->warningCodes());

        // And every code the assessment can produce is a code the log can file.
        foreach (array_merge($assessment->errorCodes(), $assessment->warningCodes()) as $code) {
            $this->assertNotNull(
                MigrationErrorCode::tryFrom($code),
                sprintf('"%s" has no entry in the log vocabulary.', $code),
            );
        }
    }

    // ──────────────────────────────────────────────
    // Products
    // ──────────────────────────────────────────────

    public function testAProductTypeFluentCartCannotExpressIsLoggedAsUnsupported(): void
    {
        $migrator = new ProductMigrator($this->idMap, $this->log, $this->state);

        $product = new \WC_Product();
        (new \ReflectionProperty(\WC_Product::class, 'type'))->setValue($product, 'external');

        $migrator->validateRecord($product);

        $this->assertLogged(MigrationErrorCode::UnsupportedProductType);
    }

    /**
     * `partial_catalog_visibility` used to be a filter offering zero rows.
     *
     * The product mapper raised the warning by handing it to
     * 'cartshift/mapper/product/warnings', which nothing consumes, and then
     * dropping it. The information loss was permanent — FluentCart cannot
     * express "in the catalog but not in search" — and the only record of it
     * lasted microseconds. It now reaches the log the way the coupon and
     * subscription mapper warnings do.
     */
    public function testPartialCatalogVisibilityReachesTheLog(): void
    {
        $migrator = new ProductMigrator($this->idMap, $this->log, $this->state);

        $migrator->validateRecord($this->partiallyVisibleProduct());

        $this->assertLogged(MigrationErrorCode::PartialCatalogVisibility);
    }

    /**
     * A dry run that says a product "would fail" without saying why cannot be
     * grouped, counted or acted on in bulk, which is the entire point of the
     * vocabulary.
     */
    public function testAProductThatWouldArriveWithNoVariationsIsCoded(): void
    {
        $migrator = new ProductMigrator($this->idMap, $this->log, $this->state);

        $product = new \WC_Product();
        (new \ReflectionProperty(\WC_Product::class, 'id'))->setValue($product, 66);
        (new \ReflectionProperty(\WC_Product::class, 'name'))->setValue($product, 'Nothing to sell');
        (new \ReflectionProperty(\WC_Product::class, 'type'))->setValue($product, 'variable');

        $migrator->validateRecord($product);

        $this->assertLogged(MigrationErrorCode::NoVariationsMapped);
    }

    public function testTheProductMapperPairsItsWarningWithACode(): void
    {
        $mapper = new ProductMapper('USD');
        $mapper->map($this->partiallyVisibleProduct());

        $coded = $mapper->getCodedWarnings();

        $this->assertCount(1, $coded);
        $this->assertSame(MigrationErrorCode::PartialCatalogVisibility, $coded[0]['code']);
        $this->assertSame(
            $mapper->getWarnings(),
            array_column($coded, 'message'),
            'getWarnings() must keep returning plain sentences, as the other mappers do.',
        );
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * A published product WooCommerce shows in the catalog but hides from search.
     */
    private function partiallyVisibleProduct(): \WC_Product
    {
        $product = new \WC_Product();

        foreach (
            [
                'id'                 => 65,
                'name'               => 'Half hidden',
                'status'             => 'publish',
                'catalog_visibility' => 'catalog',
            ] as $property => $value
        ) {
            (new \ReflectionProperty(\WC_Product::class, $property))->setValue($product, $value);
        }

        return $product;
    }

    private function coupon(int $id, string $code, string $type): \WC_Coupon
    {
        $coupon = new \WC_Coupon($id);

        foreach (['code' => $code, 'discount_type' => $type] as $property => $value) {
            $reflection = new \ReflectionProperty(\WC_Coupon::class, $property);
            $reflection->setValue($coupon, $value);
        }

        return $coupon;
    }

    /**
     * Assert that some log row was written carrying this code.
     */
    private function assertLogged(MigrationErrorCode $code): void
    {
        $written = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            $row = $query[2] ?? [];

            if (isset($row[MigrationLogRepository::CODE_COLUMN])) {
                $written[] = (string) $row[MigrationLogRepository::CODE_COLUMN];
            }
        }

        $this->assertContains(
            $code->value,
            $written,
            sprintf('Expected a log row coded "%s"; got [%s].', $code->value, implode(', ', $written)),
        );
    }
}
