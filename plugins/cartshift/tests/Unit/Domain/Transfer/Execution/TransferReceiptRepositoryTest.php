<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Execution\TransferReceipt;
use CartShift\Domain\Transfer\Execution\TransferReceiptRepository;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferReceiptRepositoryTest extends PluginTestCase
{
    public function testReceiptExportIsPrivateImmutableAndRejectsChangedBytesAtSameIdentity(): void
    {
        $directory = sys_get_temp_dir() . '/cartshift-receipts-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        chmod($directory, 0700);
        try {
            $repository = new TransferReceiptRepository($directory);
            $receipt = $this->receipt(str_repeat('a', 64));
            $repository->export($receipt);
            $files = glob($directory . '/run-receipt-22/receipts/*.json') ?: [];

            self::assertCount(1, $files);
            self::assertSame(0600, fileperms($files[0]) & 0777);
            $repository->export($receipt);

            try {
                $repository->export($this->receipt(str_repeat('b', 64)));
                self::fail('Changed receipt bytes replaced immutable evidence.');
            } catch (\RuntimeException $exception) {
                self::assertSame('transfer_receipt_immutable_conflict', $exception->getMessage());
            }
        } finally {
            foreach (glob($directory . '/run-receipt-22/receipts/*') ?: [] as $file) unlink($file);
            @rmdir($directory . '/run-receipt-22/receipts');
            @rmdir($directory . '/run-receipt-22');
            rmdir($directory);
        }
    }

    private function receipt(string $after): TransferReceipt
    {
        return new TransferReceipt(
            'run-receipt-22', 'product', 'shop-alpha:product:4', 1, str_repeat('1', 64),
            'created', ['primary' => 44], null, $after, 1,
            '2026-08-10T12:00:00Z', '2026-08-10T12:00:01Z',
        );
    }
}
