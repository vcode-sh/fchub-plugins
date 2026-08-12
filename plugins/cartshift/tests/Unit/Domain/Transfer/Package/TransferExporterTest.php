<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Package;

use CartShift\Domain\Transfer\AssessmentContext;
use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Graph\SourceClosureResolver;
use CartShift\Domain\Transfer\Graph\TransferDependencyGraph;
use CartShift\Domain\Transfer\Execution\TransferRecordHydrator;
use CartShift\Domain\Transfer\Package\SourceInstanceRegistry;
use CartShift\Domain\Transfer\Package\TransferExporter;
use CartShift\Domain\Transfer\Package\TransferPackageReader;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Package\TransferPackageWriter;
use CartShift\Domain\Transfer\RecordAssessment;
use CartShift\Domain\Transfer\RecordAssessor;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SelectionClause;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\TransferSelection;
use CartShift\Domain\Transfer\Product\AssetReference;
use CartShift\Domain\Transfer\Product\DownloadReference;
use CartShift\Tests\Unit\Domain\Transfer\Product\ProductAssessmentFixture;
use CartShift\Tests\Unit\PluginTestCase;

final class TransferExporterTest extends PluginTestCase
{
    private string $root;
    private string $destination;
    private string $fingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-exporter-' . bin2hex(random_bytes(8));
        $this->destination = $this->root . '/packages';
        mkdir($this->destination, 0700, true);
        $this->fingerprint = str_repeat('1', 64);
    }

    protected function tearDown(): void { $this->remove($this->root); parent::tearDown(); }

    public function testExportsFixedClosureInCanonicalOrderAndStreamsSharedAssetOnce(): void
    {
        $asset = 'same private asset';
        $hash = hash('sha256', $asset);
        $productA = $this->record('product', '10', [], [['sha256' => $hash, 'bytes' => strlen($asset), 'locator' => 'private://a']]);
        $productB = $this->record('product', '9', [], [['sha256' => $hash, 'bytes' => strlen($asset), 'locator' => 'private://b']]);
        $order = $this->record('order', '41', [$productA->identity, $productB->identity]);
        $lookup = [];
        foreach ([$order, $productA, $productB] as $record) $lookup[$record->identity->canonical()] = $record;
        $opens = 0;

        $path = $this->exporter()->export(
            new SourceIdentity('shop-alpha', 'order', '1'),
            $this->selection(),
            TransferDecisionSet::empty(),
            [$order],
            static fn (SourceIdentity $identity): ?RecordEnvelope => $lookup[$identity->canonical()] ?? null,
            null,
            $this->runtime(),
            static function (array $reference) use (&$opens, $asset) {
                ++$opens;
                $stream = fopen('php://temp', 'w+b'); fwrite($stream, $asset); rewind($stream); return $stream;
            },
        );

        self::assertSame(1, $opens, 'Two records sharing one content hash must not read or write the asset twice.');
        $reader = new TransferPackageReader($path, new TransferPackageValidator());
        self::assertSame(
            ['shop-alpha:product:9', 'shop-alpha:product:10', 'shop-alpha:order:41'],
            array_map(static fn (RecordEnvelope $record): string => $record->identity->canonical(), iterator_to_array($reader->records())),
        );
        self::assertStringNotContainsString('private://', (string) file_get_contents($path . '/records.ndjson'));
        self::assertSame([], array_filter($GLOBALS['_cartshift_test_queries'], static fn (array $query): bool => in_array($query[0] ?? '', ['insert', 'update', 'delete'], true)));
    }

    public function testBlockedAssessmentStopsBeforeOpeningAssetsOrCreatingPackageBytes(): void
    {
        $record = $this->record('order', '41', [], [['sha256' => str_repeat('a', 64), 'bytes' => 4, 'locator' => 'private://secret']]);
        $opens = 0;
        $exporter = $this->exporter(AssessmentOutcome::Blocked);

        try {
            $exporter->export(
                new SourceIdentity('shop-alpha', 'order', '1'), $this->selection(), TransferDecisionSet::empty(), [$record], static fn (): ?RecordEnvelope => null,
                null, $this->runtime(), static function () use (&$opens) { ++$opens; return fopen('php://temp', 'w+b'); },
            );
            self::fail('Blocked record was exported.');
        } catch (SourceRecordException $exception) {
            self::assertSame('record_blocked', $exception->reasonCode);
        }

        self::assertSame(0, $opens);
        self::assertSame([], array_values(array_diff(scandir($this->destination) ?: [], ['.', '..'])));
    }

    public function testUnknownSourceAssetHashIsComputedOnceAndLocatorNeverShips(): void
    {
        $identity = new SourceIdentity('shop-alpha', 'media_asset', '77');
        $product = RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', 'product', '9'), [
            'dependencies' => [],
            'media' => [[
                'identity' => $identity->canonical(), 'locator' => 'private://signed-secret',
                'expected_sha256' => null, 'size' => null,
            ]],
            'assets' => [],
        ]);
        $bytes = 'unfingerprinted source asset';
        $path = $this->exporter()->export(
            new SourceIdentity('shop-alpha', 'product', '1'), $this->selection(), TransferDecisionSet::empty(), [$product], static fn (): ?RecordEnvelope => null,
            null, $this->runtime(), static function () use ($bytes) { $stream = fopen('php://temp', 'w+b'); fwrite($stream, $bytes); rewind($stream); return $stream; },
        );
        $records = (string) file_get_contents($path . '/records.ndjson');
        $packaged = iterator_to_array((new TransferPackageReader(
            $path,
            new TransferPackageValidator(),
        ))->records());
        $packagedProduct = array_values(array_filter(
            $packaged,
            static fn (RecordEnvelope $record): bool => $record->identity->entityType === 'product',
        ))[0];

        self::assertStringContainsString(hash('sha256', $bytes), $records);
        self::assertStringNotContainsString('private://', $records);
        self::assertSame(
            'https://cartshift-package.invalid/assets/' . hash('sha256', $bytes) . '/signed-secret',
            $packagedProduct->payload['media'][0]['locator'],
        );
        self::assertFileExists($path . '/assets/' . hash('sha256', $bytes));
    }

    public function testTypedProductWithVariationAssetsHydratesFromSanitisedPackageBytes(): void
    {
        $productIdentity = new SourceIdentity('shop-alpha', RecordKind::Product->value, '9');
        $variationIdentity = new SourceIdentity('shop-alpha', RecordKind::Product->value, '9:variation:91');
        $mediaIdentity = new SourceIdentity('shop-alpha', RecordKind::MediaAsset->value, '77');
        $downloadIdentity = new SourceIdentity(
            'shop-alpha',
            RecordKind::DownloadAsset->value,
            '9:variation:91:download:manual',
        );
        $mediaBytes = 'typed product image';
        $downloadBytes = 'typed variation download';
        $mediaHash = hash('sha256', $mediaBytes);
        $downloadHash = hash('sha256', $downloadBytes);
        $product = ProductAssessmentFixture::product([
            'identity' => $productIdentity,
            'productType' => 'variable',
            'media' => [new AssetReference(
                $mediaIdentity,
                'https://source.example/private/photo.jpg?token=must-not-ship',
                'featured',
                'image/jpeg',
                strlen($mediaBytes),
                $productIdentity,
                'own',
                $mediaHash,
            )],
            'variations' => [ProductAssessmentFixture::variation($productIdentity, [
                'identity' => $variationIdentity,
                'downloads' => [new DownloadReference(
                    $downloadIdentity,
                    '/private/source/manual.pdf',
                    $downloadHash,
                    $variationIdentity,
                    'Manual',
                    2,
                    -1,
                )],
            ])],
        ]);
        $path = $this->exporter()->export(
            new SourceIdentity('shop-alpha', 'product', '1'),
            $this->selection(),
            TransferDecisionSet::empty(),
            [$product->envelope()],
            static fn (): ?RecordEnvelope => null,
            null,
            $this->runtime(),
            static function (array $reference) use ($mediaIdentity, $mediaBytes, $downloadBytes) {
                $bytes = $reference['identity'] === $mediaIdentity->canonical() ? $mediaBytes : $downloadBytes;
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $bytes);
                rewind($stream);
                return $stream;
            },
        );
        $records = iterator_to_array((new TransferPackageReader(
            $path,
            new TransferPackageValidator(),
        ))->records());
        $productEnvelope = array_values(array_filter(
            $records,
            static fn (RecordEnvelope $record): bool => $record->identity->entityType === 'product',
        ))[0];
        $hydrated = (new TransferRecordHydrator())->product($productEnvelope);
        $rawRecords = (string) file_get_contents($path . '/records.ndjson');

        self::assertSame($mediaHash, $hydrated->media[0]->expectedSha256);
        self::assertSame($downloadHash, $hydrated->variations[0]->downloads[0]->contentSha256);
        self::assertStringStartsWith('https://cartshift-package.invalid/assets/', $hydrated->media[0]->locator);
        self::assertStringStartsWith(
            'https://cartshift-package.invalid/assets/',
            $hydrated->variations[0]->downloads[0]->locator,
        );
        self::assertStringNotContainsString('source.example', $rawRecords);
        self::assertStringNotContainsString('must-not-ship', $rawRecords);
        self::assertStringNotContainsString('/private/source/', $rawRecords);
    }

    public function testSanitisedPackagePreservesTheOwnerReviewedSourceDigest(): void
    {
        $identity = new SourceIdentity('shop-alpha', RecordKind::Product->value, '9');
        $bytes = 'owner reviewed image';
        $hash = hash('sha256', $bytes);
        $source = RecordEnvelope::forPayload(2, $identity, [
            'dependencies' => [],
            'status' => 'publish',
            'media' => [[
                'identity' => 'shop-alpha:media_asset:77',
                'locator' => 'https://source.example/private/photo.jpg?token=must-not-ship',
                'expected_sha256' => $hash,
                'size' => strlen($bytes),
            ]],
            'assets' => [],
        ]);
        $decisions = TransferDecisionSet::fromArray([[
            'identity' => $identity->canonical(),
            'scope' => 'record',
            'action' => 'activate_catalogue',
            'target_status' => 'publish',
            'source_fingerprint' => $source->privateContentDigest,
            'operator' => 'owner',
            'reason' => 'Reviewed exact source record.',
            'decided_at' => '2026-08-11T06:51:14Z',
        ]]);

        $path = $this->exporter()->export(
            new SourceIdentity('shop-alpha', RecordKind::Product->value, '1'),
            $this->selection(),
            $decisions,
            [$source],
            static fn (): ?RecordEnvelope => null,
            null,
            $this->runtime(),
            static function () use ($bytes) {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, $bytes);
                rewind($stream);
                return $stream;
            },
        );
        $packaged = iterator_to_array((new TransferPackageReader($path, new TransferPackageValidator()))->records());
        $packagedProduct = array_values(array_filter(
            $packaged,
            static fn (RecordEnvelope $record): bool => $record->identity->entityType === RecordKind::Product->value,
        ))[0];

        self::assertNotSame($source->privateContentDigest, $packagedProduct->privateContentDigest);
        self::assertSame($source->privateContentDigest, $packagedProduct->sourceContentDigest);
        self::assertTrue((new TransferDependencyGraph())->validate($packaged, $decisions)->closed);
        self::assertStringNotContainsString('source.example', (string) file_get_contents($path . '/records.ndjson'));
    }

    private function exporter(AssessmentOutcome $outcome = AssessmentOutcome::Ready): TransferExporter
    {
        $registry = new SourceInstanceRegistry($this->root . '/source-registry.json');
        $registry->bindOwnerApproved('shop-alpha', $this->fingerprint, SourceInstanceRegistry::approval('shop-alpha', $this->fingerprint));
        $assessor = new class($outcome) implements RecordAssessor {
            public function __construct(private AssessmentOutcome $outcome) {}
            public function assess(RecordEnvelope $record, AssessmentContext $context): RecordAssessment { return new RecordAssessment($this->outcome, $this->outcome === AssessmentOutcome::Blocked ? 'fixture_blocked' : 'fixture_ready'); }
        };
        return new TransferExporter(new SourceClosureResolver(), new TransferPackageWriter(new TransferPackageValidator()), $registry, [
            'media_asset' => $assessor, 'download_asset' => $assessor,
            'product' => $assessor, 'order' => $assessor,
        ]);
    }

    private function selection(): TransferSelection { return new TransferSelection('shop-alpha', SelectionClause::ids([9, 10]), SelectionClause::none(), SelectionClause::ids([41]), SelectionClause::none()); }
    /** @param list<SourceIdentity> $dependencies @param list<array{sha256:string,bytes:int,locator:string}> $assets */
    private function record(string $kind, string $id, array $dependencies = [], array $assets = []): RecordEnvelope { return RecordEnvelope::forPayload(2, new SourceIdentity('shop-alpha', $kind, $id), ['dependencies' => array_map(static fn (SourceIdentity $i): string => $i->canonical(), $dependencies), 'assets' => $assets]); }
    /** @return array<string, mixed> */
    private function runtime(): array { return ['destination' => $this->destination, 'source_instance_fingerprint' => $this->fingerprint, 'source_url_hash' => str_repeat('2', 64), 'source_runtime_fingerprint' => str_repeat('3', 64), 'source_settings_fingerprint' => str_repeat('4', 64), 'source_capability_fingerprint' => str_repeat('5', 64), 'cartshift_version' => '2.0.0', 'woocommerce_version' => '11.0.0', 'wcs_version' => '8.7.0', 'created_at_utc' => '2026-08-10T12:00:00Z']; }
    private function remove(string $path): void { if (!file_exists($path) && !is_link($path)) return; if (is_file($path) || is_link($path)) { unlink($path); return; } foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) $this->remove($path . '/' . $entry); rmdir($path); }
}
