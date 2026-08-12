<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Migrator\OrderMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Support\DatabaseTransaction;
use CartShift\Support\Enums\MigrationErrorCode;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/MapperStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * What happens when CartShift goes round FluentCart's models and talks to
 * `$wpdb` itself.
 *
 * Two failures, one root. A real run against a live store wrote
 * `fct_orders.item_count` for every migrated order — a column FluentCart has
 * never had — and finished by reporting "Success: Migration complete. 25
 * migrated, 2 skipped", zero errors. Ten `WordPress database error Unknown
 * column 'item_count' in 'SET'` lines went to the PHP error log, where no shop
 * owner was ever going to look.
 *
 * FluentCart's models throw, and MigrationOrchestrator::processBatch() catches
 * and counts that. `$wpdb` does neither: it records the failure in
 * `$wpdb->last_error`, returns false, and lets the caller carry on believing.
 * So this file guards both halves — that CartShift writes only columns that
 * exist, and that when the database refuses one anyway the run says so.
 */
final class DirectDatabaseWriteTest extends PluginTestCase
{
    /**
     * Every column `wp_fct_orders` actually has.
     *
     * Read off a live DESCRIBE and checked against FluentCart 1.6.0's
     * OrdersMigrator — both getSqlSchema() and the ALTERs in migrated(), which
     * between them are every version of this table there has ever been.
     * `item_count` is in neither, which is the point of pinning it here: the
     * next phantom column fails this test rather than a customer's error log.
     *
     * @var list<string>
     */
    private const array FCT_ORDERS_COLUMNS = [
        'id', 'status', 'parent_id', 'receipt_number', 'invoice_no', 'fulfillment_type',
        'type', 'mode', 'shipping_status', 'customer_id', 'payment_method', 'payment_status',
        'payment_method_title', 'currency', 'subtotal', 'discount_tax', 'manual_discount_total',
        'coupon_discount_total', 'shipping_tax', 'shipping_total', 'fee_total', 'tax_total',
        'total_amount', 'total_paid', 'total_refund', 'rate', 'tax_behavior', 'note',
        'ip_address', 'completed_at', 'refunded_at', 'uuid', 'config', 'created_at', 'updated_at',
    ];

    private IdMapRepository $idMap;
    private MigrationLogRepository $log;
    private MigrationState $state;
    private ?object $originalWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The order path reads $wpdb->comments and $wpdb->commentmeta, which the
        // shared stub does not declare.
        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new \CartShiftTestWpdb();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_get_var_callback'] = static fn (): null => null;

        $this->idMap = new IdMapRepository();
        $this->log   = new MigrationLogRepository();
        $this->state = new MigrationState();
    }

    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // Only columns that exist
    // ──────────────────────────────────────────────

    public function testAnOrderWritesNoColumnFluentCartDoesNotHave(): void
    {
        $this->migrateRefundedOrder(601);

        $written = [];

        foreach ($this->directWritesTo('fct_orders') as $data) {
            $written = [...$written, ...array_keys($data)];
        }

        $this->assertNotSame([], $written, 'The partially-refunded order should have written something.');
        $this->assertSame(
            [],
            array_values(array_diff($written, self::FCT_ORDERS_COLUMNS)),
            'wp_fct_orders has no such column, so MySQL rejects the whole statement.',
        );
    }

    /**
     * Named on its own because this is the column that was actually there, and
     * a diff of column names is not the first thing anyone reads.
     */
    public function testTheItemCountColumnIsNeverWritten(): void
    {
        $this->migrateRefundedOrder(602);

        foreach ($this->directWritesTo('fct_orders') as $data) {
            $this->assertArrayNotHasKey(
                'item_count',
                $data,
                'item_count is a cart key in FluentCart and a derived value for orders. It is not a column.',
            );
        }
    }

    // ──────────────────────────────────────────────
    // A refused write is a reported write
    // ──────────────────────────────────────────────

    public function testARefusedOrderUpdateIsReportedAgainstTheOrder(): void
    {
        $this->refuseWritesTo('fct_orders');

        $this->migrateRefundedOrder(603);

        $this->assertSame(
            1,
            $this->countLogged(MigrationErrorCode::DatabaseWriteFailed),
            'A payment status that never landed leaves a refunded order reading as fully paid.',
        );
    }

    /**
     * The MySQL error is the only part of the row that says which column or
     * constraint, so it travels verbatim.
     */
    public function testTheReportCarriesTheDatabaseErrorVerbatim(): void
    {
        $this->refuseWritesTo('fct_orders', "Unknown column 'item_count' in 'SET'");

        $this->migrateRefundedOrder(604);

        $this->assertStringContainsString(
            "Unknown column 'item_count' in 'SET'",
            $this->firstLoggedMessage(MigrationErrorCode::DatabaseWriteFailed) ?? '',
        );
    }

    /**
     * The mapping is what rollback deletes by and what a re-run checks against,
     * so a record created without one is orphaned twice over.
     */
    public function testARefusedIdMapWriteIsReported(): void
    {
        $this->refuseWritesTo('cartshift_id_map');

        $this->migrateRefundedOrder(605);

        $this->assertGreaterThan(
            0,
            $this->countLogged(MigrationErrorCode::DatabaseWriteFailed),
            'Rollback cannot remove what the ID map never recorded.',
        );
    }

    /**
     * The other half of the contract: a run where nothing was refused must not
     * invent an error, or the code becomes noise the owner learns to ignore.
     */
    public function testAnUntroubledRunReportsNoWriteFailure(): void
    {
        $this->migrateRefundedOrder(606);

        $this->assertSame(0, $this->countLogged(MigrationErrorCode::DatabaseWriteFailed));
    }

    public function testLegacyRefundRowInheritsItsInertChargeAndExactTargetParent(): void
    {
        $this->migrateRefundedOrder(607);

        $transactions = \CartShiftFcModelStore::all('OrderTransaction');
        self::assertCount(2, $transactions);
        [$charge, $refund] = $transactions;

        self::assertSame('succeeded', $charge->status);
        self::assertSame(1000, $charge->meta['refunded_total']);
        self::assertSame('refund', $refund->transaction_type);
        self::assertSame('refunded', $refund->status);
        self::assertSame($charge->id, $refund->meta['parent_id']);
        self::assertSame($charge->order_type, $refund->order_type);
        self::assertSame($charge->payment_mode, $refund->payment_mode);
        self::assertSame('wc_migrated', $refund->payment_method);
        self::assertSame('historical_provenance', $refund->payment_method_type);
        self::assertSame('', $refund->vendor_charge_id);
        self::assertSame(
            're_legacy_source',
            $refund->meta['cartshift_source_payment']['provider_reference'],
        );
    }

    public function testTargetEntityRollsBackWhenCheckedIdentityInsertFails(): void
    {
        $database = new TransactionalWriteWpdb();
        $GLOBALS['wpdb'] = $database;
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $context): string =>
            str_contains($context, 'cartshift_id_map') ? 'injected identity-map failure' : '';
        $repository = new IdMapRepository('lapka-web');

        try {
            DatabaseTransaction::begin();
            $database->insert('wp_fct_orders', ['invoice_no' => 'private-test-value']);
            $repository->storeOrThrow(
                new SourceIdentity('lapka-web', 'order', '605'),
                91,
                'run-1',
                str_repeat('a', 64),
                str_repeat('b', 64),
                MapState::Staged,
                true,
            );
            DatabaseTransaction::commit();
            self::fail('Expected the checked identity write to abort the record.');
        } catch (\RuntimeException $exception) {
            DatabaseTransaction::rollback($exception);
            self::assertSame([], $database->targetRows);
            self::assertSame(0, DatabaseTransaction::depth());
        }
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Migrate an order that took a partial refund, which is what makes
     * OrderMigrator reach for `$wpdb` rather than a FluentCart model.
     */
    private function migrateRefundedOrder(int $wcId): void
    {
        $order = new \WC_Order();
        $refund = new \WC_Order_Refund();
        $refundProperties = [
            'id' => $wcId + 100_000,
            'amount' => '10.00',
            'currency' => 'USD',
            'reason' => 'Source reason remains private',
            'transaction_id' => 're_legacy_source',
            'date_created' => new \DateTimeImmutable('2026-08-01 10:00:00 UTC'),
        ];
        foreach ($refundProperties as $property => $value) {
            (new \ReflectionProperty(\WC_Order_Refund::class, $property))->setValue($refund, $value);
        }

        $properties = [
            'id'              => $wcId,
            'status'          => 'completed',
            'customer_id'     => 0,
            'billing_email'   => 'ada@example.com',
            'billing_country' => 'GB',
            'total'           => '99.00',
            'total_refunded'  => '10.00',
            'refunds'         => [$refund],
        ];

        foreach ($properties as $property => $value) {
            (new \ReflectionProperty(\WC_Order::class, $property))->setValue($order, $value);
        }

        (new OrderMigrator($this->idMap, $this->log, $this->state))->processRecord($order);
    }

    /**
     * Make MySQL refuse every write to one table, the way it refuses a statement
     * naming a column that is not there.
     */
    private function refuseWritesTo(string $table, string $error = 'Unknown column in SET'): void
    {
        $GLOBALS['_cartshift_test_db_error_callback'] = static fn (string $context): string
            => str_contains($context, $table) ? $error : '';
    }

    /**
     * The payloads of every raw `$wpdb->update()` against a table.
     *
     * Model writes do not appear here and should not: they go through
     * FluentCart's own fillable lists and throw when they are wrong.
     *
     * @return list<array<string, mixed>>
     */
    private function directWritesTo(string $table): array
    {
        $writes = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') === 'update' && str_contains((string) ($query[1] ?? ''), $table)) {
                $writes[] = $query[2];
            }
        }

        return $writes;
    }

    private function countLogged(MigrationErrorCode $code): int
    {
        return count($this->loggedRows($code));
    }

    private function firstLoggedMessage(MigrationErrorCode $code): ?string
    {
        $rows = $this->loggedRows($code);

        return $rows === [] ? null : (string) ($rows[0]['message'] ?? '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loggedRows(MigrationErrorCode $code): array
    {
        $rows = [];

        foreach ($GLOBALS['_cartshift_test_queries'] ?? [] as $query) {
            if (($query[0] ?? '') !== 'insert') {
                continue;
            }

            if ((string) ($query[2][MigrationLogRepository::CODE_COLUMN] ?? '') === $code->value) {
                $rows[] = $query[2];
            }
        }

        return $rows;
    }
}

final class TransactionalWriteWpdb extends \CartShiftTestWpdb
{
    /** @var list<array<string, mixed>> */
    public array $targetRows = [];
    private int $transactionStart = 0;

    public function insert(string $table, array $data, ?array $format = null): int|false
    {
        $result = parent::insert($table, $data, $format);

        if ($result !== false && str_ends_with($table, 'fct_orders')) {
            $this->targetRows[] = $data;
        }

        return $result;
    }

    public function query(string $query): int|false
    {
        $result = parent::query($query);

        if ($result === false) {
            return false;
        }

        if ($query === 'START TRANSACTION') {
            $this->transactionStart = count($this->targetRows);
        } elseif ($query === 'ROLLBACK') {
            $this->targetRows = array_slice($this->targetRows, 0, $this->transactionStart);
        }

        return $result;
    }
}
