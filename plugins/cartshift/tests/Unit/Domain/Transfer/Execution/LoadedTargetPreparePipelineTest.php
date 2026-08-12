<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\LoadedTargetPreparePipeline;
use CartShift\Domain\Transfer\Execution\LoadedTargetStateInspector;
use CartShift\Domain\Transfer\Execution\PreparedTargetBaseline;
use CartShift\Domain\Transfer\Execution\PreparedDecisionSetRepository;
use CartShift\Domain\Transfer\Execution\PreparedTargetBaselineProbe;
use CartShift\Domain\Transfer\Execution\PreparedTargetBaselineRepository;
use CartShift\Domain\Transfer\Execution\PreparedTransferRepository;
use CartShift\Domain\Transfer\Execution\TargetSettingsInspector;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\TransferPackageWriter;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Tests\Unit\PluginTestCase;

final class LoadedTargetPreparePipelineTest extends PluginTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-target-prepare-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/packages', 0700, true);
        mkdir($this->root . '/evidence', 0700);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testPrepareSealsRuntimeSettingsAndPreexistingTargetBaselineWithoutWordPressWrites(): void
    {
        [$package, $decisions, $selection] = $this->inputs();
        $runtime = new FixedPrepareRuntime(str_repeat('3', 64));
        $settings = new FixedTargetSettings(str_repeat('4', 64), str_repeat('5', 64));
        $baseline = new RecordingTargetBaselineProbe(new PreparedTargetBaseline(
            'shop-alpha',
            ['mapped' => [['identity' => 'shop-alpha:customer:7', 'target_id' => 81]]],
            [],
        ));
        $pipeline = new LoadedTargetPreparePipeline(
            $runtime,
            $settings,
            $baseline,
            static fn (): string => '2026-08-11T10:11:12Z',
        );

        $result = $pipeline($this->pipelineInput($package, $decisions, $selection));

        self::assertMatchesRegularExpression('/\Atr-[a-f0-9]{24}\z/', $result['descriptor']);
        self::assertSame('prepared', $result['state']);
        self::assertSame([], $result['blocking_findings']);
        self::assertFalse($result['leave_draft_accepted']);
        self::assertSame($selection->fingerprint(), $result['selection_fingerprint']);
        self::assertSame(str_repeat('3', 64), $result['compatibility_fingerprint']);
        self::assertSame(str_repeat('4', 64), $result['settings_fingerprint']);
        self::assertSame(str_repeat('5', 64), $result['gateway_fingerprint']);
        self::assertSame($baseline->baseline->fingerprint(), $result['target_fingerprint']);
        self::assertSame($result['descriptor'], $baseline->capturedRunId);
        self::assertSame(1, $baseline->captureCalls);

        $prepared = (new PreparedTransferRepository($this->root . '/evidence'))->get($result['descriptor']);
        $storedBaseline = (new PreparedTargetBaselineRepository($this->root . '/evidence'))->get($result['descriptor']);
        $storedDecisions = (new PreparedDecisionSetRepository($this->root . '/evidence'))->get($result['descriptor']);
        self::assertSame($result['target_fingerprint'], $prepared->targetState->targetHash);
        self::assertSame($baseline->baseline->fingerprint(), $storedBaseline->fingerprint());
        self::assertSame(TransferDecisionSet::fromFile($decisions)->fingerprint(), $storedDecisions->fingerprint());
        self::assertSame([], array_filter(
            $GLOBALS['_cartshift_test_queries'],
            static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete', 'query'], true),
        ));
    }

    public function testPrepareStopsOnUnreadyRuntimeOrAnUnresolvedTargetFindingAndPersistsNothing(): void
    {
        [$package, $decisions, $selection] = $this->inputs();
        $cases = [
            [new FixedPrepareRuntime(str_repeat('3', 64), ['target_schema_missing']), new PreparedTargetBaseline('shop-alpha', [], []), 'target_runtime_not_ready'],
            [new FixedPrepareRuntime(str_repeat('3', 64)), new PreparedTargetBaseline('shop-alpha', [], ['legacy_mapping_requires_audit:shop-alpha:order:9']), 'target_preflight_blocked'],
        ];

        foreach ($cases as [$runtime, $baseline, $message]) {
            $private = $this->root . '/evidence/' . bin2hex(random_bytes(4));
            mkdir($private, 0700);
            $pipeline = new LoadedTargetPreparePipeline(
                $runtime,
                new FixedTargetSettings(str_repeat('4', 64), str_repeat('5', 64)),
                new RecordingTargetBaselineProbe($baseline),
                static fn (): string => '2026-08-11T10:11:12Z',
            );
            try {
                $pipeline(array_replace($this->pipelineInput($package, $decisions, $selection), ['private_dir' => $private]));
                self::fail($message . ' was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringStartsWith($message, $exception->getMessage());
            }
            self::assertSame([], array_values(array_diff(scandir($private) ?: [], ['.', '..'])));
        }
    }

    public function testOnlyLinkedOrDraftProductsNeedNoCatalogueWriteStep(): void
    {
        [$package, $decisions, $selection] = $this->inputs();
        $record = iterator_to_array((new \CartShift\Domain\Transfer\Package\TransferPackageReader(
            $package,
            new TransferPackageValidator(),
        ))->records(), false)[0];
        $decisionSet = TransferDecisionSet::fromArray([[
            'identity' => $record->identity->canonical(),
            'action' => 'link_existing_product',
            'target_product_id' => 501,
            'target_fingerprint' => str_repeat('a', 64),
            'variation_links' => [[
                'source_variation' => 'shop-alpha:product:9:variation:1',
                'target_variation_id' => 901,
                'source_fingerprint' => str_repeat('b', 64),
                'target_fingerprint' => str_repeat('c', 64),
            ]],
            'source_fingerprint' => $record->sourceContentDigest,
            'operator' => 'wp-user:1',
            'reason' => 'The owner chose an existing FluentCart product.',
            'decided_at' => '2026-08-12T21:00:00Z',
        ]]);
        file_put_contents($decisions, $decisionSet->canonicalJson());
        $pipeline = new LoadedTargetPreparePipeline(
            new FixedPrepareRuntime(str_repeat('3', 64)),
            new FixedTargetSettings(str_repeat('4', 64), str_repeat('5', 64)),
            new RecordingTargetBaselineProbe(new PreparedTargetBaseline('shop-alpha', [], [])),
            static fn (): string => '2026-08-11T10:11:12Z',
        );

        $result = $pipeline($this->pipelineInput($package, $decisions, $selection));

        self::assertTrue($result['leave_draft_accepted']);
    }

    public function testCurrentStateRevalidatesRuntimeSettingsAndTheImmutableBaselineButKeepsItsTargetHashStable(): void
    {
        $baseline = new PreparedTargetBaseline('shop-alpha', ['mapped' => []], []);
        $probe = new RecordingTargetBaselineProbe($baseline);
        $runtime = new FixedPrepareRuntime(str_repeat('3', 64));
        $settings = new FixedTargetSettings(str_repeat('4', 64), str_repeat('5', 64));
        $inspector = new LoadedTargetStateInspector(
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('5', 64),
            $baseline,
            'run-state-22',
            $runtime,
            $settings,
            $probe,
            str_repeat('3', 64),
            str_repeat('4', 64),
            str_repeat('5', 64),
        );

        $state = $inspector->inspect();

        self::assertSame($baseline->fingerprint(), $state->targetHash);
        self::assertSame(1, $probe->verifyCalls);
        $runtime->fingerprint = str_repeat('9', 64);
        $this->expectExceptionMessage('target_runtime_fingerprint_changed');
        $inspector->inspect();
    }

    /** @return array{string,string,TransferSelection} */
    private function inputs(): array
    {
        $selection = new TransferSelection(
            'shop-alpha',
            SelectionClause::ids([9]),
            SelectionClause::none(),
            SelectionClause::none(),
            SelectionClause::none(),
        );
        $record = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'product', '9'), [
            'identity' => 'shop-alpha:product:9',
            'dependencies' => [],
        ]);
        $package = (new TransferPackageWriter(new TransferPackageValidator()))->write(
            $record->identity,
            $selection,
            [$record],
            [],
            [
                'destination' => $this->root . '/packages',
                'source_instance_fingerprint' => str_repeat('a', 64),
                'source_url_hash' => str_repeat('b', 64),
                'source_runtime_fingerprint' => str_repeat('c', 64),
                'source_settings_fingerprint' => str_repeat('d', 64),
                'source_capability_fingerprint' => str_repeat('e', 64),
                'cartshift_version' => '1.5.0',
                'woocommerce_version' => '10.3.0',
                'wcs_version' => null,
                'created_at_utc' => '2026-08-11T10:00:00Z',
            ],
        );
        $decisionPath = $this->root . '/evidence/decisions.json';
        file_put_contents($decisionPath, TransferDecisionSet::empty()->canonicalJson());
        chmod($decisionPath, 0600);
        return [$package, $decisionPath, $selection];
    }

    /** @return array<string,mixed> */
    private function pipelineInput(string $package, string $decisionPath, TransferSelection $selection): array
    {
        $manifest = (new TransferPackageValidator())->assertValid($package);
        return [
            'package' => $package,
            'decision_set' => $decisionPath,
            'private_dir' => $this->root . '/evidence',
            'execution_context' => 'rehearsal',
            'package_hash' => hash('sha256', $manifest->canonicalJson()),
            'decision_hash' => TransferDecisionSet::fromFile($decisionPath)->fingerprint(),
            'selection_hash' => $selection->fingerprint(),
            'source_key' => 'shop-alpha',
        ];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $item = $path . '/' . $entry;
            is_dir($item) ? $this->removeTree($item) : unlink($item);
        }
        rmdir($path);
    }
}

final class FixedPrepareRuntime implements TransferRuntimeInspector
{
    /** @param list<string> $errors */
    public function __construct(public string $fingerprint, private array $errors = []) {}

    public function inspect(string $role): TransferRuntimeReport
    {
        return new TransferRuntimeReport($role, $this->fingerprint, [], [], $this->errors, []);
    }
}

final class FixedTargetSettings implements TargetSettingsInspector
{
    public function __construct(public string $fingerprint, public string $gatewayFingerprint) {}
    public function fingerprint(): string { return $this->fingerprint; }
    public function gatewayFingerprint(): string { return $this->gatewayFingerprint; }
}

final class RecordingTargetBaselineProbe implements PreparedTargetBaselineProbe
{
    public int $captureCalls = 0;
    public int $verifyCalls = 0;
    public ?string $capturedRunId = null;

    public function __construct(public PreparedTargetBaseline $baseline) {}

    public function capture(string $sourceKey, array $records, TransferDecisionSet $decisions, string $runId): PreparedTargetBaseline
    {
        ++$this->captureCalls;
        $this->capturedRunId = $runId;
        return $this->baseline;
    }

    public function verify(PreparedTargetBaseline $baseline, string $runId): void
    {
        ++$this->verifyCalls;
        if (!hash_equals($this->baseline->fingerprint(), $baseline->fingerprint())) {
            throw new \RuntimeException('target_baseline_changed');
        }
    }
}
