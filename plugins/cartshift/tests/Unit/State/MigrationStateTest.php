<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\State;

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
}
