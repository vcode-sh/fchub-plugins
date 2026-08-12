<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Migrator\Entity;

use CartShift\Domain\Transfer\Order\OrderStageResult;
use CartShift\Domain\Transfer\Order\WooOrderStage;
use CartShift\Migrator\OrderMigrator;
use CartShift\State\MigrationState;
use CartShift\Storage\IdMapRepository;
use CartShift\Storage\MigrationLogRepository;
use CartShift\Tests\Unit\PluginTestCase;

require_once dirname(__DIR__, 3) . '/stubs/EntityMigratorStubs.php';
require_once dirname(__DIR__, 3) . '/stubs/FluentCartModelStubs.php';

final class OrderMigratorSharedWriterSeamTest extends PluginTestCase
{
    public function testCanonicalStageBypassesEveryLegacyOrderModelWriter(): void
    {
        \CartShiftFcModelStore::install();
        $stage = new RecordingWooOrderStage();
        $order = new \WC_Order();
        (new \ReflectionProperty(\WC_Order::class, 'id'))->setValue($order, 880_501);
        $migrator = new OrderMigrator(
            new IdMapRepository('lapka-web'),
            new MigrationLogRepository(),
            new MigrationState(),
            canonicalStage: $stage,
        );

        $result = $migrator->processRecord($order);

        self::assertSame(93_001, $result);
        self::assertSame([880_501], $stage->sourceOrderIds);
        self::assertSame([], \CartShiftFcModelStore::all('Order'));
        self::assertSame([], \CartShiftFcModelStore::all('OrderItem'));
        self::assertSame([], \CartShiftFcModelStore::all('OrderTransaction'));
    }
}

final class RecordingWooOrderStage implements WooOrderStage
{
    /** @var list<int> */
    public array $sourceOrderIds = [];

    public function stage(object $wooOrder, string $migrationId): OrderStageResult
    {
        $this->sourceOrderIds[] = (int) $wooOrder->get_id();
        return new OrderStageResult(93_001, ['lapka-web:order:880501' => 93_001], str_repeat('c', 64), false);
    }
}
