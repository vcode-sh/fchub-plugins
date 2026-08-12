<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Contract;

require_once __DIR__ . '/InstalledContractTestCase.php';

final class WooOrderStorageParityContractTest extends InstalledContractTestCase
{
    public function testCptAndHposProduceTheSameCanonicalOrderSemantics(): void
    {
        $sync = $this->runWpCliCommand([
            'option', 'update', 'woocommerce_custom_orders_table_data_sync_enabled', 'no',
        ]);
        self::assertSame(0, $sync['status'], $sync['stderr']);

        $useCpt = $this->runWpCliCommand([
            'option', 'update', 'woocommerce_custom_orders_table_enabled', 'no',
        ]);
        self::assertSame(0, $useCpt['status'], $useCpt['stderr']);
        $cpt = $this->runRuntimeContractWithArguments('woo-order-storage-parity', ['cpt']);

        $syncOrders = $this->runWpCliCommand(['wc', 'hpos', 'sync', '--batch-size=100']);
        self::assertSame(0, $syncOrders['status'], $syncOrders['stderr']);

        $useHpos = $this->runWpCliCommand([
            'option', 'update', 'woocommerce_custom_orders_table_enabled', 'yes',
        ]);
        self::assertSame(0, $useHpos['status'], $useHpos['stderr']);
        $hpos = $this->runRuntimeContractWithArguments('woo-order-storage-parity', ['hpos']);

        self::assertSame('cpt', $cpt['store']);
        self::assertSame('hpos', $hpos['store']);
        self::assertTrue($cpt['authoritative_row_exists']);
        self::assertTrue($hpos['authoritative_row_exists']);
        self::assertSame($cpt['semantic'], $hpos['semantic']);
    }
}
