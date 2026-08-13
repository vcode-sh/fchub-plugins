<?php

declare(strict_types=1);

namespace CartShift\Tests\Unit\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\Identity\MappingRecord;
use CartShift\Domain\Transfer\Product\FluentCartProductWriter;
use CartShift\Domain\Transfer\Product\ProductAssessmentContext;
use CartShift\Domain\Transfer\Product\ProductFieldDecisionSet;
use CartShift\Domain\Transfer\Product\ProductFieldDisposition;
use CartShift\Domain\Transfer\Product\ProductStagePlan;
use CartShift\Domain\Transfer\Product\ProductTargetGateway;
use CartShift\Domain\Transfer\Product\ProductReconciler;
use CartShift\Domain\Transfer\Package\AssetManifestEntry;
use CartShift\Domain\Transfer\Product\AssetReference;
use CartShift\Domain\Transfer\Product\DownloadReference;
use CartShift\Domain\Transfer\Product\FluentCartDownloadStager;
use CartShift\Domain\Transfer\Product\WordPressMediaGateway;
use CartShift\Domain\Transfer\Product\WordPressMediaStager;
use CartShift\Domain\Transfer\Product\HistoricalProductPlaceholder;
use CartShift\Domain\Transfer\Product\LinkedProductPlan;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\Product\TaxonomyAssignment;
use CartShift\Domain\Transfer\SourceRecordException;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Support\DatabaseTransaction;
use CartShift\Tests\Unit\PluginTestCase;

final class FluentCartProductWriterTest extends PluginTestCase
{
    private string $package;
    private string $root;
    private string $uploads;
    private string $downloads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/cartshift-product-writer-' . bin2hex(random_bytes(8));
        $this->package = $this->root . '/package';
        $this->uploads = $this->root . '/uploads';
        $this->downloads = $this->uploads . '/fluent-cart';
        mkdir($this->package . '/assets', 0700, true);
        mkdir($this->uploads, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
        parent::tearDown();
    }

    public function testVariationMapFailureRollsBackWholeProduct(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $maps->failForCompoundVariation = true;
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));

        try {
            $writer->stage($this->plan(), $this->context());
            self::fail('The variation map failure did not abort the product.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced variation map failure', $exception->getMessage());
        }

        self::assertSame([], $gateway->products);
        self::assertSame([], $gateway->details);
        self::assertSame([], $gateway->variations);
        self::assertSame([], $maps->records);
        self::assertSame(0, DatabaseTransaction::depth());
    }

    public function testRetryReturnsReconciledExistingTargetWithoutWriting(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));

        $first = $writer->stage($this->plan(), $this->context());
        $before = $gateway->databaseFingerprint();
        $writeCount = $gateway->writeCount;
        $second = $writer->stage($this->plan(), $this->context());

        self::assertSame($first->targetId, $second->targetId);
        self::assertSame($first->targetFingerprint, $second->targetFingerprint);
        self::assertTrue($second->reused);
        self::assertSame($before, $gateway->databaseFingerprint());
        self::assertSame($writeCount, $gateway->writeCount);
    }

    public function testRetryRefusesPersistedTargetDriftWithoutWriting(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));
        $first = $writer->stage($this->plan(), $this->context());
        $gateway->products[$first->targetId]['post_title'] = 'Changed by somebody else';
        $writeCount = $gateway->writeCount;

        try {
            $writer->stage($this->plan(), $this->context());
            self::fail('A drifted target was silently reused.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('target_reconciliation_failed', $exception->getMessage());
        }

        self::assertSame($writeCount, $gateway->writeCount);
        self::assertSame('Changed by somebody else', $gateway->products[$first->targetId]['post_title']);
    }

    public function testApprovedExistingProductCreatesOnlyNonOwningMappings(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $sourcePlan = $this->plan();
        $record = $sourcePlan->record;
        $envelope = $record->envelope();
        $gateway->products[501] = [
            'ID' => 501,
            'post_title' => 'Existing FluentCart product',
            'post_status' => 'publish',
        ];
        $gateway->details[501] = ['post_id' => 501, 'variation_type' => 'simple'];
        foreach ($record->variations as $index => $variation) {
            $targetId = 901 + $index;
            $gateway->variations[$targetId] = [
                'id' => $targetId,
                'post_id' => 501,
                'variation_title' => 'Existing ' . ($index + 1),
                'sku' => 'EXISTING-' . ($index + 1),
                'item_price' => 2500 + $index,
            ];
        }
        $snapshot = $gateway->snapshot(501);
        $sourceMap = [$record->identity->canonical() => 501];
        $links = [];
        foreach ($record->variations as $index => $variation) {
            $targetId = 901 + $index;
            $sourcePayload = $envelope->payload['variations'][$index];
            $targetVariation = $gateway->variations[$targetId];
            $sourceMap[$variation->identity->canonical()] = $targetId;
            $links[] = [
                'source_variation' => $variation->identity->canonical(),
                'target_variation_id' => $targetId,
                'source_fingerprint' => \CartShift\Support\CanonicalJson::fingerprint($sourcePayload),
                'target_fingerprint' => \CartShift\Support\CanonicalJson::fingerprint($targetVariation),
            ];
        }
        $linked = LinkedProductPlan::fromDecision($record, $envelope, [
            'target_product_id' => 501,
            'target_fingerprint' => (new ProductTargetFingerprint())->fingerprint($snapshot, $sourceMap),
            'variation_links' => $links,
        ], $snapshot);
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));
        $before = $gateway->databaseFingerprint();
        $writeCount = $gateway->writeCount;

        try {
            $result = $writer->stage($linked, $this->context());
        } catch (\TypeError $exception) {
            self::fail('The product writer cannot execute the approved existing-product plan.');
        }

        self::assertTrue($result->reused);
        self::assertSame(501, $result->targetId);
        self::assertSame([901, 902], $result->variationIds);
        self::assertSame($sourceMap, $result->sourceTargetIds);
        self::assertSame($before, $gateway->databaseFingerprint());
        self::assertSame($writeCount, $gateway->writeCount);
        foreach ($linked->sourceIdentities() as $identity) {
            self::assertSame(MapState::Reconciled, $maps->get($identity)?->state);
            self::assertFalse($maps->createdByMigration[$identity->canonical()] ?? true);
        }
    }

    public function testInitialReadbackRejectsAFieldTheTargetSilentlyDropped(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $gateway->omitProductField = 'post_excerpt';
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));

        try {
            $writer->stage($this->plan(), $this->context());
            self::fail('A silently dropped persisted field became the accepted baseline.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('product_field_mismatch', $exception->getMessage());
        }

        self::assertSame([], $gateway->products);
        self::assertSame([], $maps->records);
    }

    public function testInitialReadbackRejectsATaxonomyFieldTheTargetSilentlyDropped(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $gateway->omitTaxonomyField = 'description';
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter($gateway, $maps, new ProductReconciler($gateway, $maps));
        $term = new TaxonomyAssignment(
            'product_cat',
            new SourceIdentity('lapka-web', RecordKind::TaxonomyTerm->value, '10:product-cat'),
            'Training',
            'training',
            'Must survive the round trip',
            null,
            0,
            'assign',
        );

        try {
            $writer->stage($this->planWithTaxonomy('42', $term), $this->context());
            self::fail('A silently dropped taxonomy field became the accepted baseline.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('taxonomy_field_mismatch', $exception->getMessage());
        }

        self::assertSame([], $gateway->products);
        self::assertSame([], $gateway->taxonomyTerms);
        self::assertSame([], $maps->records);
    }

    public function testTaxonomyRelationshipOrderSurvivesTargetRoundTripAndIsReconciled(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $reconciler = new ProductReconciler($gateway, $maps);
        $writer = new FluentCartProductWriter($gateway, $maps, $reconciler);
        $term = new TaxonomyAssignment(
            'product_cat',
            new SourceIdentity('lapka-web', RecordKind::TaxonomyTerm->value, '10:product-cat'),
            'Training',
            'training',
            'Ordered relation',
            null,
            17,
            'assign',
        );
        $plan = $this->planWithTaxonomy('42', $term);

        $result = $writer->stage($plan, $this->context());
        $snapshot = $gateway->snapshot($result->targetId);

        self::assertSame(17, $snapshot['taxonomy_rows'][0]['term_order'] ?? null);
        self::assertTrue($reconciler->reconcile($plan, $result->targetId, $result->targetFingerprint)->matches);

        $gateway->taxonomyRelations[$result->targetId][0]['term_order'] = 0;
        self::assertContains(
            'taxonomy_relation_mismatch',
            $reconciler->reconcile($plan, $result->targetId, $result->targetFingerprint)->failures,
        );
    }

    public function testStageIsDraftFirstAndCreatesOneTargetVariationPerSource(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $result = (new FluentCartProductWriter(
            $gateway,
            $maps,
            new ProductReconciler($gateway, $maps),
        ))->stage($this->plan(), $this->context());

        self::assertSame('draft', $gateway->products[$result->targetId]['post_status']);
        self::assertCount(2, $result->variationIds);
        self::assertCount(2, $gateway->variations);
        self::assertSame(
            ['42:variation:101', '42:variation:102'],
            array_map(
                static fn (array $row): string => explode(':product:', $row['other_info']['source_identity'], 2)[1],
                array_values($gateway->variations),
            ),
        );
        self::assertSame(MapState::Reconciled, $maps->get($this->plan()->record->identity)?->state);
        self::assertSame([], $gateway->sideEffects);
    }

    public function testTwoProductsSharingOneSourceCategoryCreateOneTermAndBothRemainReconciled(): void
    {
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $reconciler = new ProductReconciler($gateway, $maps);
        $writer = new FluentCartProductWriter($gateway, $maps, $reconciler);
        $term = new TaxonomyAssignment(
            'product_cat',
            new SourceIdentity('lapka-web', RecordKind::TaxonomyTerm->value, '10:product-cat'),
            'Training',
            'training',
            'Shared category',
            null,
            0,
            'assign',
        );
        $firstPlan = $this->planWithTaxonomy('42', $term);
        $secondPlan = $this->planWithTaxonomy('43', $term);

        $first = $writer->stage($firstPlan, $this->context());
        $second = $writer->stage($secondPlan, $this->context());

        self::assertCount(1, $gateway->taxonomyTerms, 'The second product duplicated a category created earlier in the same run.');
        self::assertTrue($reconciler->reconcile($firstPlan, $first->targetId, $first->targetFingerprint)->matches);
        self::assertTrue($reconciler->reconcile($secondPlan, $second->targetId, $second->targetFingerprint)->matches);
        self::assertSame(
            $gateway->taxonomyRelations[$first->targetId],
            $gateway->taxonomyRelations[$second->targetId],
        );
    }

    public function testFilesystemAssetsRollBackWhenDownloadPolicyBlocksAfterCopy(): void
    {
        [$plan, $mediaGateway] = $this->assetPlan(14);
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter(
            $gateway,
            $maps,
            new ProductReconciler($gateway, $maps),
            new WordPressMediaStager($this->uploads, $mediaGateway),
            new FluentCartDownloadStager($this->downloads, 'local'),
        );

        try {
            $writer->stage($plan, $this->context());
            self::fail('A day-to-month download policy mismatch was written.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('months', $exception->getMessage());
        }

        self::assertSame([], $gateway->products);
        self::assertSame([], $maps->records);
        self::assertSame([], $mediaGateway->attachments);
        self::assertSame([], $this->filesUnder($this->uploads));
    }

    public function testCompleteAssetGraphIsMappedAndReconciled(): void
    {
        [$plan, $mediaGateway] = $this->assetPlan(-1);
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $result = (new FluentCartProductWriter(
            $gateway,
            $maps,
            new ProductReconciler($gateway, $maps),
            new WordPressMediaStager($this->uploads, $mediaGateway),
            new FluentCartDownloadStager($this->downloads, 'local'),
        ))->stage($plan, $this->context());

        self::assertCount(1, $result->mediaIds);
        self::assertCount(1, $result->downloadIds);
        self::assertCount(1, $gateway->media[$result->targetId]);
        self::assertCount(1, $gateway->downloads);
        self::assertNotNull($maps->get($plan->media[0]['reference']->identity));
        self::assertNotNull($maps->get($plan->downloads[0]['reference']->identity));
        self::assertFileExists($this->downloads . '/' . $gateway->downloads[$result->downloadIds[0]]['file_path']);
    }

    public function testMediaRoleLossIsRejectedEvenWhenTheRenderedBytesStillMatch(): void
    {
        [$plan, $mediaGateway] = $this->assetPlan(-1);
        $gateway = new InMemoryProductTargetGateway();
        $gateway->omitMediaField = 'role';
        $maps = new InMemoryCheckedMappingStore();
        $writer = new FluentCartProductWriter(
            $gateway,
            $maps,
            new ProductReconciler($gateway, $maps),
            new WordPressMediaStager($this->uploads, $mediaGateway),
            new FluentCartDownloadStager($this->downloads, 'local'),
        );

        try {
            $writer->stage($plan, $this->context());
            self::fail('Rendered image bytes concealed the missing featured/gallery role.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('media_relation_mismatch', $exception->getMessage());
        }

        self::assertSame([], $gateway->products);
        self::assertSame([], $maps->records);
        self::assertSame([], $mediaGateway->attachments);
        self::assertSame([], $this->filesUnder($this->uploads));
    }

    public function testVariationOwnedMediaAndDownloadReachTheirExactTargetVariation(): void
    {
        $productIdentity = ProductAssessmentFixture::identity('86');
        $variationIdentity = ProductAssessmentFixture::identity('86:variation:201');
        $mediaIdentity = new SourceIdentity('lapka-web', RecordKind::MediaAsset->value, '601');
        $downloadIdentity = new SourceIdentity(
            'lapka-web',
            RecordKind::DownloadAsset->value,
            '86:variation:201:download:manual',
        );
        $mediaBytes = "\xff\xd8\xffvariation-photo";
        $downloadBytes = "%PDF-1.4\nvariation manual";
        $mediaHash = hash('sha256', $mediaBytes);
        $downloadHash = hash('sha256', $downloadBytes);
        file_put_contents($this->package . '/assets/' . $mediaHash, $mediaBytes);
        file_put_contents($this->package . '/assets/' . $downloadHash, $downloadBytes);
        $mediaAsset = new AssetManifestEntry(
            $mediaHash,
            strlen($mediaBytes),
            'image/jpeg',
            'variation.jpg',
            'local',
        );
        $downloadAsset = new AssetManifestEntry(
            $downloadHash,
            strlen($downloadBytes),
            'application/pdf',
            'manual.pdf',
            'local',
        );
        $record = ProductAssessmentFixture::product([
            'identity' => $productIdentity,
            'productType' => 'variable',
            'fulfilmentType' => 'digital',
            'variations' => [ProductAssessmentFixture::variation($productIdentity, [
                'identity' => $variationIdentity,
                'fulfilmentType' => 'digital',
                'media' => [new AssetReference(
                    $mediaIdentity,
                    'https://example.com/wp-content/uploads/variation.jpg',
                    'variation',
                    'image/jpeg',
                    strlen($mediaBytes),
                    $variationIdentity,
                    'own',
                    $mediaHash,
                )],
                'downloads' => [new DownloadReference(
                    $downloadIdentity,
                    'https://example.com/wp-content/uploads/manual.pdf',
                    $downloadHash,
                    $variationIdentity,
                    'Variation manual',
                    2,
                    -1,
                )],
            ])],
        ]);
        $context = new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        );
        $plan = ProductStagePlan::build($record, $context, assetManifest: [
            $mediaIdentity->canonical() => $mediaAsset,
            $downloadIdentity->canonical() => $downloadAsset,
        ]);
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $mediaGateway = new WriterMediaGateway();
        $result = (new FluentCartProductWriter(
            $gateway,
            $maps,
            new ProductReconciler($gateway, $maps),
            new WordPressMediaStager($this->uploads, $mediaGateway),
            new FluentCartDownloadStager($this->downloads, 'local'),
        ))->stage($plan, $this->context());

        self::assertCount(1, $plan->media);
        self::assertCount(1, $plan->downloads);
        self::assertSame(
            $variationIdentity->canonical(),
            $gateway->media[$result->targetId][0]['owner_identity'],
        );
        self::assertSame('own', $gateway->media[$result->targetId][0]['provenance']);
        self::assertSame([$result->variationIds[0]], $gateway->downloads[$result->downloadIds[0]]['variation_ids']);
        self::assertSame(1, $gateway->details[$result->targetId]['manage_downloadable']);
    }

    public function testParentImageInheritedByVariationStagesOnceAndLinksTwice(): void
    {
        $productIdentity = ProductAssessmentFixture::identity('87');
        $variationIdentity = ProductAssessmentFixture::identity('87:variation:301');
        $mediaIdentity = new SourceIdentity('lapka-web', RecordKind::MediaAsset->value, '701');
        $bytes = "\x89PNG\r\nshared-parent-image";
        $hash = hash('sha256', $bytes);
        file_put_contents($this->package . '/assets/' . $hash, $bytes);
        $asset = new AssetManifestEntry($hash, strlen($bytes), 'image/png', 'parent.png', 'local');
        $record = ProductAssessmentFixture::product([
            'identity' => $productIdentity,
            'productType' => 'variable',
            'media' => [new AssetReference(
                $mediaIdentity,
                'https://example.com/wp-content/uploads/parent.png',
                'featured',
                'image/png',
                strlen($bytes),
                $productIdentity,
                'own',
                $hash,
            )],
            'variations' => [ProductAssessmentFixture::variation($productIdentity, [
                'identity' => $variationIdentity,
                'media' => [new AssetReference(
                    $mediaIdentity,
                    'https://example.com/wp-content/uploads/parent.png',
                    'variation',
                    'image/png',
                    strlen($bytes),
                    $variationIdentity,
                    'inherited',
                    $hash,
                )],
            ])],
        ]);
        $plan = ProductStagePlan::build(
            $record,
            new ProductAssessmentContext(
                ['standard', 'none'],
                [],
                ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
                targetShippingClasses: ['none' => 0],
            ),
            assetManifest: [$mediaIdentity->canonical() => $asset],
        );
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $mediaGateway = new WriterMediaGateway();
        $writer = new FluentCartProductWriter(
            $gateway,
            $maps,
            new ProductReconciler($gateway, $maps),
            new WordPressMediaStager($this->uploads, $mediaGateway),
        );

        $first = $writer->stage($plan, $this->context());
        $retry = $writer->stage($plan, $this->context());
        $links = $gateway->media[$first->targetId];

        self::assertCount(2, $plan->media);
        self::assertSame(1, $mediaGateway->insertCalls);
        self::assertCount(2, $links);
        self::assertSame(1, count(array_unique(array_column($links, 'target_id'))));
        self::assertSame(['featured', 'variation'], array_column($links, 'role'));
        self::assertSame(['own', 'inherited'], array_column($links, 'provenance'));
        self::assertSame([
            $productIdentity->canonical(),
            $variationIdentity->canonical(),
        ], array_column($links, 'owner_identity'));
        self::assertCount(1, $first->mediaIds);
        self::assertSame($first->mediaIds, $retry->mediaIds);
        self::assertTrue($retry->reused);
    }

    public function testTwoProductsSharingOneSourceImageReuseOneVerifiedAttachment(): void
    {
        $mediaIdentity = new SourceIdentity('lapka-web', RecordKind::MediaAsset->value, '501');
        $bytes = "\xff\xd8\xffshared-writer-photo";
        $hash = hash('sha256', $bytes);
        file_put_contents($this->package . '/assets/' . $hash, $bytes);
        $asset = new AssetManifestEntry($hash, strlen($bytes), 'image/jpeg', 'shared.jpg', 'local');
        $gateway = new InMemoryProductTargetGateway();
        $maps = new InMemoryCheckedMappingStore();
        $mediaGateway = new WriterMediaGateway();
        $reconciler = new ProductReconciler($gateway, $maps);
        $writer = new FluentCartProductWriter(
            $gateway,
            $maps,
            $reconciler,
            new WordPressMediaStager($this->uploads, $mediaGateway),
        );
        $firstPlan = $this->planWithSharedMedia('84', $mediaIdentity, $asset);
        $secondPlan = $this->planWithSharedMedia('85', $mediaIdentity, $asset);

        $first = $writer->stage($firstPlan, $this->context());
        $second = $writer->stage($secondPlan, $this->context());

        self::assertSame(1, $mediaGateway->insertCalls, 'The second product duplicated a migration-owned attachment.');
        self::assertSame($first->mediaIds, $second->mediaIds);
        self::assertTrue($reconciler->reconcile($firstPlan, $first->targetId, $first->targetFingerprint)->matches);
        self::assertTrue($reconciler->reconcile($secondPlan, $second->targetId, $second->targetFingerprint)->matches);
    }

    public function testHistoricalPlaceholderRequiresExactApprovalAndStaysInert(): void
    {
        $identity = ProductAssessmentFixture::identity('999');
        $line = ['name' => 'Deleted course', 'sku' => 'OLD-1', 'unit_total' => 2500, 'currency' => 'PLN'];
        $context = new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        );
        $placeholder = new HistoricalProductPlaceholder();
        try {
            $placeholder->plan($identity, $line, $context, str_repeat('0', 64));
            self::fail('An unapproved historical placeholder was accepted.');
        } catch (SourceRecordException $exception) {
            self::assertSame('historical_product_missing', $exception->reasonCode);
        }

        $plan = $placeholder->plan(
            $identity,
            $line,
            $context,
            HistoricalProductPlaceholder::approvalFingerprint($identity, $line),
        );
        $record = $placeholder->record(
            $identity,
            $line,
            HistoricalProductPlaceholder::approvalFingerprint($identity, $line),
        );
        self::assertSame($plan->record->toArray(), $record->toArray());
        self::assertSame($identity->canonical(), $record->envelope()->identity->canonical());
        $gateway = new InMemoryProductTargetGateway();
        $gateway->buySectionRendered = false;
        $gateway->cartableOverride = [];
        $gateway->checkoutOverride = [];
        $maps = new InMemoryCheckedMappingStore();
        $result = (new FluentCartProductWriter(
            $gateway,
            $maps,
            new ProductReconciler($gateway, $maps),
        ))->stage($plan, $this->context());

        self::assertTrue($plan->historicalPlaceholder);
        self::assertSame('draft', $gateway->products[$result->targetId]['post_status']);
        self::assertSame('draft', $gateway->variations[$result->variationIds[0]]['item_status']);
        self::assertSame('out-of-stock', $gateway->variations[$result->variationIds[0]]['stock_status']);
        self::assertSame(0, $gateway->variations[$result->variationIds[0]]['available']);
    }

    private function plan(): ProductStagePlan
    {
        $parent = ProductAssessmentFixture::identity('42');
        $product = ProductAssessmentFixture::product([
            'productType' => 'variable',
            'variations' => [
                ProductAssessmentFixture::variation($parent, ['identity' => ProductAssessmentFixture::identity('42:variation:101')]),
                ProductAssessmentFixture::variation($parent, ['identity' => ProductAssessmentFixture::identity('42:variation:102')]),
            ],
        ]);

        return ProductStagePlan::build($product, new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        ));
    }

    private function context(): StageContext
    {
        return new StageContext($this->package, 'writer-run', 'source-runtime');
    }

    private function planWithTaxonomy(string $sourceId, TaxonomyAssignment $taxonomy): ProductStagePlan
    {
        $identity = ProductAssessmentFixture::identity($sourceId);
        $product = ProductAssessmentFixture::product([
            'identity' => $identity,
            'variations' => [ProductAssessmentFixture::variation($identity, [
                'identity' => ProductAssessmentFixture::identity($sourceId . ':variation:' . $sourceId),
            ])],
            'taxonomies' => [$taxonomy],
        ]);
        return ProductStagePlan::build($product, new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        ));
    }

    private function planWithSharedMedia(
        string $sourceId,
        SourceIdentity $mediaIdentity,
        AssetManifestEntry $asset,
    ): ProductStagePlan {
        $identity = ProductAssessmentFixture::identity($sourceId);
        $record = ProductAssessmentFixture::product([
            'identity' => $identity,
            'variations' => [ProductAssessmentFixture::variation($identity, [
                'identity' => ProductAssessmentFixture::identity($sourceId . ':variation:' . $sourceId),
            ])],
            'media' => [new AssetReference(
                $mediaIdentity,
                'https://example.com/wp-content/uploads/shared.jpg',
                'featured',
                'image/jpeg',
                $asset->bytes,
                $identity,
                'own',
                $asset->sha256,
            )],
        ]);
        return ProductStagePlan::build($record, new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        ), assetManifest: [$mediaIdentity->canonical() => $asset]);
    }

    /** @return array{ProductStagePlan, WriterMediaGateway} */
    private function assetPlan(int $expiryDays): array
    {
        $productIdentity = ProductAssessmentFixture::identity('84');
        $mediaIdentity = new SourceIdentity('lapka-web', RecordKind::MediaAsset->value, '501');
        $downloadIdentity = new SourceIdentity('lapka-web', RecordKind::DownloadAsset->value, '84:download:manual');
        $mediaBytes = "\xff\xd8\xffwriter-photo";
        $downloadBytes = "%PDF-1.4\nwriter download";
        $mediaHash = hash('sha256', $mediaBytes);
        $downloadHash = hash('sha256', $downloadBytes);
        file_put_contents($this->package . '/assets/' . $mediaHash, $mediaBytes);
        file_put_contents($this->package . '/assets/' . $downloadHash, $downloadBytes);
        $mediaAsset = new AssetManifestEntry($mediaHash, strlen($mediaBytes), 'image/jpeg', 'photo.jpg', 'local');
        $downloadAsset = new AssetManifestEntry($downloadHash, strlen($downloadBytes), 'application/pdf', 'manual.pdf', 'local');
        $record = ProductAssessmentFixture::product([
            'identity' => $productIdentity,
            'media' => [new AssetReference(
                $mediaIdentity,
                'https://example.com/wp-content/uploads/photo.jpg',
                'featured',
                'image/jpeg',
                strlen($mediaBytes),
                $productIdentity,
                'own',
                $mediaHash,
            )],
            'downloads' => [new DownloadReference(
                $downloadIdentity,
                'https://example.com/wp-content/uploads/manual.pdf',
                $downloadHash,
                $productIdentity,
                'Manual',
                2,
                $expiryDays,
            )],
            'fulfilmentType' => 'digital',
        ]);
        $context = new ProductAssessmentContext(
            ['standard', 'none'],
            [],
            ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate),
            targetShippingClasses: ['none' => 0],
        );
        return [ProductStagePlan::build($record, $context, assetManifest: [
            $mediaIdentity->canonical() => $mediaAsset,
            $downloadIdentity->canonical() => $downloadAsset,
        ]), new WriterMediaGateway()];
    }

    /** @return list<string> */
    private function filesUnder(string $root): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $root,
            \FilesystemIterator::SKIP_DOTS,
        )) as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        foreach (new \FilesystemIterator($path) as $entry) {
            $this->removeTree($entry->getPathname());
        }
        rmdir($path);
    }
}

final class WriterMediaGateway implements WordPressMediaGateway
{
    /** @var array<int, string> */
    public array $attachments = [];
    /** @var array<int, array<string, string>> */
    public array $metadata = [];
    public int $insertCalls = 0;

    public function insert(string $file, string $mimeType, string $title): int
    {
        ++$this->insertCalls;
        $id = 700 + count($this->attachments);
        $this->attachments[$id] = $file;
        return $id;
    }

    public function generateMetadata(int $attachmentId, string $file): void
    {
    }

    public function updateMeta(int $attachmentId, string $key, string $value): void
    {
        $this->metadata[$attachmentId][$key] = $value;
    }

    public function file(int $attachmentId): ?string
    {
        return $this->attachments[$attachmentId] ?? null;
    }

    public function files(int $attachmentId): array
    {
        $file = $this->file($attachmentId);
        return $file === null ? [] : [$file];
    }

    public function meta(int $attachmentId, string $key): ?string
    {
        return $this->metadata[$attachmentId][$key] ?? null;
    }

    public function delete(int $attachmentId): bool
    {
        if (!isset($this->attachments[$attachmentId])) {
            return false;
        }
        unset($this->attachments[$attachmentId], $this->metadata[$attachmentId]);
        return true;
    }
}

final class InMemoryCheckedMappingStore implements CheckedMappingStore
{
    /** @var array<string, MappingRecord> */
    public array $records = [];
    /** @var array<string,bool> */
    public array $createdByMigration = [];
    public bool $failForCompoundVariation = false;
    private bool $rollbackRegistered = false;

    public function get(SourceIdentity $identity): ?MappingRecord
    {
        return $this->records[$identity->canonical()] ?? null;
    }

    public function storeOrThrow(
        SourceIdentity $identity,
        int $targetId,
        string $migrationId,
        string $sourceFingerprint,
        string $targetFingerprint,
        MapState $state,
        bool $createdByMigration,
        int $generation = 1,
    ): MappingRecord {
        if ($this->failForCompoundVariation && str_contains($identity->sourceId, ':variation:')) {
            throw new \RuntimeException('forced variation map failure');
        }
        $this->registerRollback();
        $record = new MappingRecord($identity, $targetId, $sourceFingerprint, $targetFingerprint, $state);
        $existing = $this->records[$identity->canonical()] ?? null;
        if ($existing !== null) {
            if ($existing->isCompatibleWith($record)) return $existing;
            throw new \RuntimeException('identity mapping conflict');
        }
        $this->records[$identity->canonical()] = $record;
        $this->createdByMigration[$identity->canonical()] = $createdByMigration;
        return $record;
    }

    public function transitionOrThrow(
        SourceIdentity $identity,
        MapState $expected,
        MapState $next,
        string $expectedTargetFingerprint,
        string $nextTargetFingerprint,
    ): MappingRecord {
        $current = $this->get($identity);
        if ($current === null || $current->state !== $expected || $current->targetFingerprint !== $expectedTargetFingerprint) {
            throw new \RuntimeException('mapping transition conflict');
        }
        $this->registerRollback();
        $record = new MappingRecord($identity, $current->targetId, $current->sourceFingerprint, $nextTargetFingerprint, $next);
        $this->records[$identity->canonical()] = $record;
        return $record;
    }

    private function registerRollback(): void
    {
        if ($this->rollbackRegistered) {
            return;
        }
        $beforeRecords = $this->records;
        $beforeOwnership = $this->createdByMigration;
        DatabaseTransaction::afterRollback(function () use ($beforeRecords, $beforeOwnership): void {
            $this->records = $beforeRecords;
            $this->createdByMigration = $beforeOwnership;
            $this->rollbackRegistered = false;
        });
        DatabaseTransaction::afterCommit(function (): void {
            $this->rollbackRegistered = false;
        });
        $this->rollbackRegistered = true;
    }
}

final class InMemoryProductTargetGateway implements ProductTargetGateway
{
    /** @var array<int, array<string, mixed>> */
    public array $products = [];
    /** @var array<int, array<string, mixed>> */
    public array $details = [];
    /** @var array<int, array<string, mixed>> */
    public array $variations = [];
    /** @var array<int, array<string, mixed>> */
    public array $taxonomyTerms = [];
    /** @var array<int, list<array{target_id: int, term_order: int}>> */
    public array $taxonomyRelations = [];
    /** @var array<int, array<string, mixed>> */
    public array $media = [];
    /** @var array<int, array<string, mixed>> */
    public array $downloads = [];
    /** @var list<string> */
    public array $sideEffects = [];
    public int $writeCount = 0;
    public ?string $omitProductField = null;
    public ?string $omitTaxonomyField = null;
    public ?string $omitMediaField = null;
    public bool $buySectionRendered = true;
    public bool $stockManagementActive = true;
    /** @var list<int>|null */
    public ?array $cartableOverride = null;
    /** @var list<int>|null */
    public ?array $checkoutOverride = null;
    private int $nextId = 100;
    private bool $rollbackRegistered = false;

    public function createTaxonomyTerm(array $plan, ?int $parentTargetId): int
    {
        $this->beforeWrite();
        $id = $this->nextId++;
        if ($this->omitTaxonomyField !== null) unset($plan[$this->omitTaxonomyField]);
        $this->taxonomyTerms[$id] = [...$plan, 'parent_target_id' => $parentTargetId];
        return $id;
    }

    public function createDraftProduct(array $fields): int
    {
        $this->beforeWrite();
        $id = $this->nextId++;
        if ($this->omitProductField !== null) {
            unset($fields[$this->omitProductField]);
        }
        $this->products[$id] = $fields;
        return $id;
    }

    public function createProductDetail(int $productId, array $fields): int
    {
        $this->beforeWrite();
        $this->details[$productId] = $fields;
        return $this->nextId++;
    }

    public function createVariation(int $productId, array $fields): int
    {
        $this->beforeWrite();
        $id = $this->nextId++;
        $this->variations[$id] = ['id' => $id, 'post_id' => $productId, ...$fields];
        return $id;
    }

    public function finishProductDetail(int $productId, int $defaultVariationId, int $minPrice, int $maxPrice): void
    {
        $this->beforeWrite();
        $this->details[$productId] = [
            ...$this->details[$productId],
            'default_variation_id' => $defaultVariationId,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
        ];
    }

    public function assignTaxonomies(int $productId, array $relations): void
    {
        $this->beforeWrite();
        usort($relations, static fn (array $left, array $right): int => $left['target_id'] <=> $right['target_id']);
        $this->taxonomyRelations[$productId] = $relations;
        foreach ($relations as $relation) {
            $termId = $relation['target_id'];
            $this->taxonomyTerms[$termId]['count'] = count(array_filter(
                $this->taxonomyRelations,
                static fn (array $candidateRelations): bool => in_array(
                    $termId,
                    array_column($candidateRelations, 'target_id'),
                    true,
                ),
            ));
        }
    }

    public function attachMedia(int $productId, array $variationIds, array $stagedMedia): array
    {
        $this->beforeWrite();
        if ($this->omitMediaField !== null) {
            foreach ($stagedMedia as &$item) {
                unset($item[$this->omitMediaField]);
            }
            unset($item);
        }
        $this->media[$productId] = $stagedMedia;
        return array_values(array_unique(array_filter(array_column($stagedMedia, 'target_id'), 'is_int')));
    }

    public function createDownload(int $productId, array $variationIds, array $fields): int
    {
        $this->beforeWrite();
        $id = $this->nextId++;
        $this->downloads[$id] = [
            'id' => $id,
            'post_id' => $productId,
            'variation_ids' => $variationIds,
            ...$fields,
        ];
        return $id;
    }

    public function exists(int $productId): bool
    {
        return isset($this->products[$productId]);
    }

    public function snapshot(int $productId): array
    {
        $variations = array_values(array_filter(
            $this->variations,
            static fn (array $row): bool => $row['post_id'] === $productId,
        ));
        usort($variations, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $taxonomyRelations = $this->taxonomyRelations[$productId] ?? [];
        $taxonomyRows = [];
        foreach ($taxonomyRelations as $relation) {
            $termId = $relation['target_id'];
            $term = $this->taxonomyTerms[$termId] ?? [];
            $taxonomyRows[] = [
                'term_id' => $termId,
                'taxonomy' => $term['target_taxonomy'] ?? null,
                'name' => $term['name'] ?? null,
                'slug' => $term['slug'] ?? null,
                'description' => $term['description'] ?? null,
                'parent' => $term['parent_target_id'] ?? 0,
                'count' => $term['count'] ?? 0,
                'term_order' => $relation['term_order'],
            ];
        }
        return [
            'product' => $this->products[$productId] ?? null,
            'detail' => $this->details[$productId] ?? null,
            'variations' => $variations,
            'taxonomies' => array_column($taxonomyRelations, 'target_id'),
            'taxonomy_rows' => $taxonomyRows,
            'media' => $this->media[$productId] ?? [],
            'downloads' => array_values(array_filter(
                $this->downloads,
                static fn (array $row): bool => $row['post_id'] === $productId,
            )),
        ];
    }

    public function behaviour(int $productId, array $variationIds): array
    {
        $productManagesStock = ($this->details[$productId]['manage_stock'] ?? 0) === 1;
        $cartable = array_values(array_filter(
            $variationIds,
            fn (int $variationId): bool => isset($this->variations[$variationId])
                && ($this->variations[$variationId]['item_status'] ?? null) === 'active'
                && (!$this->stockManagementActive
                    || !$productManagesStock
                    || ($this->variations[$variationId]['manage_stock'] ?? 0) !== 1
                    || ($this->variations[$variationId]['stock_status'] ?? null) !== 'out-of-stock'),
        ));

        return [
            'buy_section_rendered' => $this->buySectionRendered,
            'cartable_variation_ids' => $this->cartableOverride ?? $cartable,
            'checkout_object_ids' => $this->checkoutOverride ?? $cartable,
            'rendered_media_hashes' => array_column($this->media[$productId] ?? [], 'sha256'),
            'readable_download_hashes' => array_column($this->downloads, 'sha256'),
        ];
    }

    public function databaseFingerprint(): string
    {
        return hash('sha256', serialize([
            $this->products,
            $this->details,
            $this->variations,
            $this->taxonomyTerms,
            $this->taxonomyRelations,
            $this->media,
            $this->downloads,
        ]));
    }

    private function beforeWrite(): void
    {
        $this->writeCount++;
        if ($this->rollbackRegistered) {
            return;
        }
        $before = [
            $this->products,
            $this->details,
            $this->variations,
            $this->taxonomyTerms,
            $this->taxonomyRelations,
            $this->media,
            $this->downloads,
            $this->nextId,
        ];
        DatabaseTransaction::afterRollback(function () use ($before): void {
            [
                $this->products,
                $this->details,
                $this->variations,
                $this->taxonomyTerms,
                $this->taxonomyRelations,
                $this->media,
                $this->downloads,
                $this->nextId,
            ] = $before;
            $this->rollbackRegistered = false;
        });
        DatabaseTransaction::afterCommit(function (): void {
            $this->rollbackRegistered = false;
        });
        $this->rollbackRegistered = true;
    }
}
