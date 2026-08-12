<?php

declare(strict_types=1);

namespace CartShift\Tests\Integration\Failure;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\TargetStateFingerprint;
use CartShift\Domain\Transfer\Execution\TransferPlan;
use CartShift\Support\CanonicalJson;

final class CompatibilityDriftTest extends FailureTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-compatibility-drift-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->root) ?: [], ['.', '..']) as $entry) {
            unlink($this->root . '/' . $entry);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testCanonicalDescriptorTamperingCannotBeMadeValidByReencodingIt(): void
    {
        $prepared = $this->prepared();
        $repository = new PreparedTransferRepository($this->root);
        $path = $repository->save($prepared);
        $document = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $document['descriptor']['target_state']['compatibility'] = str_repeat('f', 64);
        file_put_contents($path, CanonicalJson::encode($document) . "\n");

        $this->expectExceptionMessage('prepared_transfer_descriptor_hash_mismatch');
        $repository->get($prepared->runId);
    }

    public function testDecisionBytesAreComparedBeforeDependencyPlanningOrWriting(): void
    {
        $prepared = $this->prepared();
        $record = $this->record();
        $changed = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'excluded_by_policy',
            'source_fingerprint' => $record->privateContentDigest,
            'operator' => 'contract-owner',
            'reason' => 'Injected decision drift.',
            'decided_at' => '2026-08-10T12:00:00Z',
        ]]);

        $this->expectExceptionMessage('prepared_decision_fingerprint_changed');
        TransferPlan::build($prepared, [$record], $changed);
    }

    public function testCompatibilitySettingsAndGatewayDriftHaveDistinctStableReasons(): void
    {
        $prepared = $this->prepared();
        $base = $prepared->targetState->toArray();
        $reasons = [];
        foreach (['compatibility', 'settings', 'gateway'] as $field) {
            $changed = $base;
            $changed[$field] = str_repeat('f', 64);
            try {
                $prepared->assertCurrent(TargetStateFingerprint::fromArray($changed));
                self::fail('Changed ' . $field . ' fingerprint was accepted.');
            } catch (\RuntimeException $exception) {
                $reasons[] = $exception->getMessage();
            }
        }

        self::assertSame([
            'prepared_transfer_fingerprint_changed:compatibility',
            'prepared_transfer_fingerprint_changed:settings',
            'prepared_transfer_fingerprint_changed:gateway',
        ], $reasons);
    }
}
