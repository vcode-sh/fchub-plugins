<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Order;

use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Identity\TargetClaimStore;
use CartShift\Domain\Transfer\Order\FluentCartOrderWriter;
use CartShift\Domain\Transfer\Order\OrderProjectionContext;
use CartShift\Domain\Transfer\Order\OrderReconciler;
use CartShift\Domain\Transfer\Order\OrderStagePlan;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class OrderReconcilerTest extends PluginTestCase
{
    private string $package;

    protected function setUp(): void
    {
        parent::setUp();
        $this->package = sys_get_temp_dir() . '/cartshift-order-reconciler-' . bin2hex(random_bytes(8));
        mkdir($this->package, 0700, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->package)) {
            rmdir($this->package);
        }
        parent::tearDown();
    }

    public function testIndependentReloadNamesMoneyReferenceOwnershipAndMapFailures(): void
    {
        $record = OrderWriterFixture::record();
        $target = new MemoryOrderTargetGateway();
        $maps = new MemoryOrderMaps();
        $maps->seed($record->customer, 701);
        $maps->seed($record->productLines[0]->product, 501);
        $maps->seed($record->productLines[0]->variation, 601);
        $reconciler = new OrderReconciler($target, $maps);
        $claims = new class implements TargetClaimStore {
            public function claimOrThrow(SourceIdentity $identity, int $targetId, string $runId, string $sourceFingerprint, string $targetFingerprint, MapState $state): MappingRecord
            {
                return new MappingRecord($identity, $targetId, $sourceFingerprint, $targetFingerprint, $state);
            }
        };
        $writer = new FluentCartOrderWriter($target, $maps, $reconciler, $claims);
        $plan = OrderStagePlan::build(
            $record,
            new OrderProjectionContext(
                [$record->productLines[0]->identity->canonical() => [
                    'post_id' => 501,
                    'object_id' => 601,
                    'fulfillment_type' => 'digital',
                ]],
                [$record->couponLines[0]->identity->canonical() => null],
                [],
                'test',
                'Historical WooCommerce provenance',
                true,
            ),
            customerTargetId: 701,
        );
        $result = $writer->stage($plan, new StageContext($this->package, 'order-reconcile-run', 'runtime'));

        $target->orders[$result->targetId]['customer_id'] = 702;
        $target->orders[$result->targetId]['total_paid'] = 999;
        $target->items[array_key_first($target->items)]['other_info']['source_identity'] = 'lapka-web:order:5001:item:99';
        $target->transactions[array_key_first($target->transactions)]['meta']['cartshift_source_payment']['source_event_identity'] = 'lapka-web:order:5001:charge:99';
        unset($maps->records[$record->couponLines[0]->identity->canonical()]);

        $reconciliation = $reconciler->reconcile(
            $plan,
            $result->targetId,
            $result->targetFingerprint,
        );

        self::assertFalse($reconciliation->matches);
        self::assertContains('customer_reference_mismatch', $reconciliation->failures);
        self::assertContains('total_paid_mismatch', $reconciliation->failures);
        self::assertContains('line_source_identity_mismatch', $reconciliation->failures);
        self::assertContains('payment_source_identity_mismatch', $reconciliation->failures);
        self::assertContains('checked_map_missing', $reconciliation->failures);
    }
}
