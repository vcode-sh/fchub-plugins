<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Subscription;

use CartShift\Domain\Subscription\InvalidSourceRecord;
use CartShift\Domain\Subscription\OrderRecord;
use CartShift\Domain\Subscription\SubscriptionHistoryIndex;
use CartShift\Domain\Subscription\SubscriptionRecord;
use CartShift\Domain\Subscription\SubscriptionRecordFactory;
use CartShift\Storage\IdMapRepository;
use CartShift\Support\Constants;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

/**
 * The harness the history, reconciliation and idempotency tests share.
 *
 * Two things it does that matter and are easy to get wrong separately.
 *
 * THE ID MAP IS PERSISTENT ACROSS REPOSITORY INSTANCES. `IdMapRepository`
 * memoises in the instance and writes through `$wpdb->insert()`, so a test that
 * only ever holds one repository proves nothing about a second run — which
 * builds a fresh one and reads the table. The insert callback below mirrors
 * every mapping row into the same global the `get_var()` reader answers from,
 * so "run it again" means what it says.
 *
 * THE CLOSURE IS BUILT FROM PAYLOADS, NOT FROM LIVE OBJECTS. Everything under
 * test consumes `OrderRecord`s, which is the point: section 6.2 requires the
 * payload for every reference, and a package must be importable without the
 * source being reachable at all.
 */
abstract class SubscriptionHistoryTestCase extends PluginTestCase
{
    protected const string SOURCE_KEY = 'lapka';

    /** @var array<string, callable> */
    protected array $shapes;

    protected SubscriptionRecordFactory $factory;

    private ?object $originalWpdb = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']    = new \CartShiftTestWpdb();

        \CartShiftFcModelStore::install();

        $GLOBALS['_cartshift_test_id_map']           = [];
        $GLOBALS['_cartshift_test_get_var_callback'] = $this->reader();
        $GLOBALS['_cartshift_test_insert_callback']  = $this->idMapMirror();

        $this->shapes  = require dirname(__DIR__, 3) . '/fixtures/lapka-subscription-shapes.php';
        $this->factory = new SubscriptionRecordFactory();

        // The references the writer would have resolved. Orders are deliberately
        // absent: the importer is what puts them there.
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_CUSTOMER]['660001']                        = 501;
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_PRODUCT][(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID] = 701;
        $GLOBALS['_cartshift_test_id_map'][Constants::ENTITY_VARIATION][(string) CARTSHIFT_LAPKA_MONTHLY_PRODUCT_ID] = 801;
    }

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->originalWpdb !== null) {
            $GLOBALS['wpdb'] = $this->originalWpdb;
        }

        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    // Records
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     */
    protected function subscriptionRecord(array $overrides = []): SubscriptionRecord
    {
        $record = $this->factory->subscriptionFromPayload(
            self::SOURCE_KEY,
            $this->shapes['subscriptionPayload']($overrides),
        );

        $this->assertNotInstanceOf(InvalidSourceRecord::class, $record);

        /** @var SubscriptionRecord $record */
        return $record;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function orderRecord(string $shape, array $overrides = []): OrderRecord
    {
        $record = $this->factory->orderFromPayload(self::SOURCE_KEY, $this->shapes[$shape]($overrides));

        $this->assertNotInstanceOf(InvalidSourceRecord::class, $record);

        /** @var OrderRecord $record */
        return $record;
    }

    /**
     * The complete closure for the canonical fixture subscription: its parent
     * order and its one renewal, both paid, both carrying their charge.
     *
     * @param list<OrderRecord> $extra
     */
    protected function completeIndex(?SubscriptionRecord $record = null, array $extra = []): SubscriptionHistoryIndex
    {
        $record ??= $this->subscriptionRecord();

        return SubscriptionHistoryIndex::fromRecords(self::SOURCE_KEY, array_merge([
            $record,
            $this->orderRecord('parentOrderPayload'),
            $this->orderRecord('renewalOrderPayload'),
        ], $extra));
    }

    protected function idMap(): IdMapRepository
    {
        return new IdMapRepository(self::SOURCE_KEY);
    }

    // ──────────────────────────────────────────────
    // Stubs
    // ──────────────────────────────────────────────

    /**
     * Every mapping row the run writes, readable by the next repository.
     */
    private function idMapMirror(): callable
    {
        return static function (string $table, array $data): int {
            if (!isset($data['entity_type'], $data['wc_id'], $data['fc_id'])) {
                return 1;
            }

            $GLOBALS['_cartshift_test_id_map'][(string) $data['entity_type']][(string) $data['wc_id']]
                ??= (int) $data['fc_id'];

            return 1;
        };
    }

    private function reader(): callable
    {
        return cartshift_test_id_map_reader();
    }
}
