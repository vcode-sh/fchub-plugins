<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\State;

use CartShift\Domain\Scope\MigrationScope;
use CartShift\State\MigrationState;
use CartShift\Tests\Unit\PluginTestCase;

final class MigrationStateTest extends PluginTestCase
{
    private MigrationState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = new MigrationState();
    }

    public function testStartSetsRunningStatus(): void
    {
        $result = $this->state->start(['product', 'order']);

        $this->assertSame('running', $result['status']);
        $this->assertTrue($this->state->isRunning());
        $this->assertFalse($this->state->isCancelled());
        $this->assertSame(['product', 'order'], $result['entity_types']);
        $this->assertSame(0, $result['current_entity_index']);
        $this->assertSame(0, $result['current_offset']);
        $this->assertSame('pending', $result['entities']['product']['status']);
        $this->assertSame('pending', $result['entities']['order']['status']);
    }

    public function testSetCancelledDoesNotMarkCompleted(): void
    {
        // F7: setCancelled must set entity status to 'cancelled', NOT 'completed'.
        $this->state->start(['product', 'order']);
        $this->state->setCancelled('product');

        $current = $this->state->getCurrent();
        $this->assertSame('cancelled', $current['entities']['product']['status']);
        $this->assertNotSame('completed', $current['entities']['product']['status']);
        // The other entity should remain untouched.
        $this->assertSame('pending', $current['entities']['order']['status']);
    }

    public function testAdvanceOffsetIncrementsCorrectly(): void
    {
        $this->state->start(['product']);

        $this->assertSame(0, $this->state->getCurrentOffset());

        $this->state->advanceOffset(25);
        $this->assertSame(25, $this->state->getCurrentOffset());

        $this->state->advanceOffset(25);
        $this->assertSame(50, $this->state->getCurrentOffset());
    }

    // ──────────────────────────────────────────────
    // In-request memoisation
    // ──────────────────────────────────────────────

    /**
     * Repeated accessors must not re-read the option. processBatch() calls these
     * fifteen-odd times per batch; every one used to be a fresh get_option().
     */
    public function testRepeatedReadsAreServedFromTheMemo(): void
    {
        $this->state->start(['product', 'order']);

        $reads = $this->countOptionReads(function (): void {
            $this->state->getCurrentEntityIndex();
            $this->state->getCurrentOffset();
            $this->state->getEntityTypes();
            $this->state->getMigrationId();
            $this->state->isDryRun();
            $this->state->isRunning();
            $this->state->getCurrent();
            $this->state->getProgress();
        });

        $this->assertSame(0, $reads, 'Eight accessors should hit the option store zero times.');
    }

    /**
     * The memo must never be behind this request's own writes.
     */
    public function testWritesRefreshTheMemo(): void
    {
        $this->state->start(['product']);

        $this->state->advanceOffset(10);
        $this->assertSame(10, $this->state->getCurrentOffset());

        $this->state->advanceEntity();
        $this->assertSame(1, $this->state->getCurrentEntityIndex());
        $this->assertSame(0, $this->state->getCurrentOffset());

        $this->state->complete();
        $this->assertFalse($this->state->isRunning());
        $this->assertSame('completed', $this->state->getCurrent()['status']);
    }

    /**
     * CRITICAL: the UI cancels through a separate REST request while a batch is
     * mid-flight, so isCancelled() must read past the memo. If it were cached the
     * batch would grind on to the end of the entity after the user hit cancel.
     */
    public function testIsCancelledObservesAWriteFromAnotherRequest(): void
    {
        $this->state->start(['product']);

        // Warm the memo the way a batch would.
        $this->state->getCurrent();
        $this->assertFalse($this->state->isCancelled());

        // Another PHP request cancels: the option changes underneath this instance.
        $option = $GLOBALS['_cartshift_test_options']['cartshift_migration_state'];
        $option['status'] = 'cancelled';
        $GLOBALS['_cartshift_test_options']['cartshift_migration_state'] = $option;

        $this->assertTrue(
            $this->state->isCancelled(),
            'isCancelled() must bypass the memo or cancel-mid-batch stops working.',
        );
    }

    /**
     * isCancelled() writes what it read back into the memo, so the cancellation is
     * visible to every later reader in the same request too.
     */
    public function testCancellationSeenByIsCancelledPropagatesToOtherReaders(): void
    {
        $this->state->start(['product']);
        $this->state->getCurrent();

        $option = $GLOBALS['_cartshift_test_options']['cartshift_migration_state'];
        $option['status'] = 'cancelled';
        $GLOBALS['_cartshift_test_options']['cartshift_migration_state'] = $option;

        $this->assertTrue($this->state->isCancelled());
        $this->assertFalse($this->state->isRunning());
        $this->assertSame('cancelled', $this->state->getCurrent()['status']);
    }

    /**
     * isCancelled() must genuinely hit the option store every time.
     */
    public function testIsCancelledAlwaysReadsFresh(): void
    {
        $this->state->start(['product']);

        $reads = $this->countOptionReads(function (): void {
            $this->state->isCancelled();
            $this->state->isCancelled();
            $this->state->isCancelled();
        });

        $this->assertSame(3, $reads);
    }

    /**
     * reset() clears the memo — a stale copy would otherwise outlive the option.
     */
    public function testResetClearsTheMemo(): void
    {
        $this->state->start(['product']);
        $this->state->getCurrent();

        $this->state->reset();

        $this->assertNull($this->state->getCurrent());
        $this->assertSame(['status' => 'idle'], $this->state->getProgress());
        $this->assertFalse($this->state->isRunning());
    }

    /**
     * A fresh instance in a fresh request sees whatever is in the option store.
     */
    public function testMemoIsPerInstanceNotShared(): void
    {
        $this->state->start(['product']);
        $this->state->advanceOffset(7);

        $other = new MigrationState();

        $this->assertSame(7, $other->getCurrentOffset());
    }

    public function testStartRecordsTheScopeAlongsideTheEntityTypes(): void
    {
        $state = new MigrationState();
        $state->start(['order'], false, MigrationScope::fromArray(['mode' => 'explicit', 'product_ids' => [12]]));

        $stored = $state->getProgress();

        $this->assertSame(['order'], $stored['entity_types']);
        $this->assertSame([12], $stored['scope']['product_ids']);
    }

    /**
     * Count reads of the migration-state option during a callback.
     *
     * The option store is a plain array in the test bootstrap, so it is swapped for
     * an ArrayAccess proxy that tallies reads of the one key we care about and
     * otherwise behaves identically.
     */
    private function countOptionReads(callable $fn): int
    {
        $key = 'cartshift_migration_state';
        $realStore = $GLOBALS['_cartshift_test_options'];

        $proxy = new class ($realStore, $key) implements \ArrayAccess {
            public int $reads = 0;

            /** @param array<string, mixed> $data */
            public function __construct(
                public array $data,
                private readonly string $watched,
            ) {
            }

            public function offsetExists(mixed $offset): bool
            {
                if ($offset === $this->watched) {
                    $this->reads++;
                }

                return isset($this->data[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->data[$offset] ?? null;
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                $this->data[$offset] = $value;
            }

            public function offsetUnset(mixed $offset): void
            {
                unset($this->data[$offset]);
            }
        };

        $GLOBALS['_cartshift_test_options'] = $proxy;

        try {
            $fn();
        } finally {
            $GLOBALS['_cartshift_test_options'] = $proxy->data;
        }

        return $proxy->reads;
    }
}
