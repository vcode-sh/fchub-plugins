<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SameSite\GuidedSourceDependencyIndex;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Tests\Unit\PluginTestCase;

final class GuidedSourceDependencyIndexTest extends PluginTestCase
{
    public function testClosureContainsTheRootAndExactReverseDependantsInTransferOrder(): void
    {
        $product = $this->record('product', '10');
        $customer = $this->record('customer', '20');
        $order = $this->record('order', '30', [$product->identity, $customer->identity]);
        $subscription = $this->record('subscription', '40', [$order->identity, $product->identity]);
        $unrelated = $this->record('order', '31');
        $reads = 0;
        $records = (static function () use (&$reads, $subscription, $unrelated, $order, $customer, $product): iterable {
            ++$reads;
            yield $subscription;
            yield $unrelated;
            yield $order;
            yield $customer;
            yield $product;
        })();

        $closure = (new GuidedSourceDependencyIndex($records))->closure($product->identity);

        self::assertSame(1, $reads);
        self::assertSame([
            'site-alpha:product:10',
            'site-alpha:order:30',
            'site-alpha:subscription:40',
        ], array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), $closure));
    }

    public function testDependencyCycleIsRejected(): void
    {
        $first = new SourceIdentity('site-alpha', 'order', '30');
        $second = new SourceIdentity('site-alpha', 'order', '31');

        $this->expectExceptionMessage('guided_source_dependency_cycle');
        new GuidedSourceDependencyIndex([
            $this->record('order', '30', [$second]),
            $this->record('order', '31', [$first]),
        ]);
    }

    public function testDuplicateSourceIdentityIsRejected(): void
    {
        $this->expectExceptionMessage('guided_source_dependency_duplicate');
        new GuidedSourceDependencyIndex([
            $this->record('order', '30'),
            $this->record('order', '30'),
        ]);
    }

    /** @param list<SourceIdentity> $dependencies */
    private function record(string $kind, string $id, array $dependencies = []): RecordEnvelope
    {
        return RecordEnvelope::forPayload(2, new SourceIdentity('site-alpha', $kind, $id), [
            'dependencies' => array_map(
                static fn (SourceIdentity $identity): string => $identity->canonical(),
                $dependencies,
            ),
        ]);
    }
}
