<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\Audit\RecordContractInspector;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class RecordContractInspectorTest extends PluginTestCase
{
    public function testEveryAttemptRunsAndOnlyStableRedactedEvidenceLeavesTheInspector(): void
    {
        $calls = [];
        $reader = static function (TransferSelection $selection) use (&$calls): iterable {
            yield [
                'identity' => new SourceIdentity($selection->sourceKey, 'order', '7'),
                'assert' => static function () use (&$calls): void {
                    $calls[] = 7;
                    throw new SourceRecordException('order_money_mismatch', 'buyer@example.test paid a private amount');
                },
            ];
            yield [
                'identity' => new SourceIdentity($selection->sourceKey, 'order', '8'),
                'assert' => static function () use (&$calls): void {
                    $calls[] = 8;
                },
            ];
            yield [
                'identity' => new SourceIdentity($selection->sourceKey, 'product', '9'),
                'assert' => static function () use (&$calls): void {
                    $calls[] = 9;
                    throw new \RuntimeException('private filesystem path');
                },
            ];
        };

        $report = (new RecordContractInspector($reader))->inspect($this->selection());
        $findings = $report->findings;

        self::assertSame([7, 8, 9], $calls);
        self::assertSame(
            ['order_money_mismatch', 'record_contract_inspection_failed'],
            array_column($findings, 'code'),
        );
        self::assertSame(
            ['lapka-web:order:7', 'lapka-web:product:9'],
            array_column($findings, 'identity'),
        );
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $findings[0]['context']['diagnostic_fingerprint']);
        $json = json_encode($findings, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('buyer@example.test', $json);
        self::assertStringNotContainsString('private filesystem path', $json);
        self::assertSame(['considered' => 2, 'ready' => 1, 'blocked' => 1], $report->counts['order']);
        self::assertSame(['considered' => 1, 'ready' => 0, 'blocked' => 1], $report->counts['product']);
    }

    public function testAnAttemptForAnotherSourceKeyFailsClosed(): void
    {
        $reader = static fn (): iterable => yield [
            'identity' => new SourceIdentity('another-store', 'order', '7'),
            'assert' => static fn (): null => null,
        ];

        $this->expectException(\LogicException::class);
        (new RecordContractInspector($reader))->inspect($this->selection());
    }

    private function selection(): TransferSelection
    {
        return new TransferSelection(
            'lapka-web',
            SelectionClause::all(),
            SelectionClause::none(),
            SelectionClause::all(),
            SelectionClause::all(),
        );
    }
}
