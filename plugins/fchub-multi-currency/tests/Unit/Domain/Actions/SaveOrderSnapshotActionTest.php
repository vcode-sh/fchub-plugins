<?php

declare(strict_types=1);

namespace FChubMultiCurrency\Tests\Unit\Domain\Actions;

use FChubMultiCurrency\Domain\Actions\SaveOrderSnapshotAction;
use FChubMultiCurrency\Tests\Support\MockBuilder;
use FChubMultiCurrency\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class SaveOrderSnapshotActionTest extends TestCase
{
    #[Test]
    public function testSkipsSnapshotWhenBaseDisplay(): void
    {
        $order = $this->createMockOrder(1);

        (new SaveOrderSnapshotAction())->execute($order, MockBuilder::baseOnlyContext());

        $this->assertEmpty($order->meta);
    }

    #[Test]
    public function testSavesSnapshotWhenDifferentCurrency(): void
    {
        $order = $this->createMockOrder(42);

        (new SaveOrderSnapshotAction())->execute($order, MockBuilder::context(['is_base_display' => false]));

        $this->assertSame('EUR', $order->meta['_fchub_mc_display_currency']);
        $this->assertSame('USD', $order->meta['_fchub_mc_base_currency']);
    }

    private function createMockOrder(int $id): object
    {
        return new class($id) {
            /** @var array<string, mixed> */
            public array $meta = [];

            public function __construct(public int $id)
            {
            }

            public function updateMeta(string $key, mixed $value): void
            {
                $this->meta[$key] = $value;
            }
        };
    }
}
