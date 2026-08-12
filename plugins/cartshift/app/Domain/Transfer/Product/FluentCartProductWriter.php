<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Product;

use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Identity\MapState;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\StageContext;
use CartShift\Domain\Transfer\StageResult;
use CartShift\Support\CanonicalJson;
use CartShift\Support\DatabaseTransaction;

defined('ABSPATH') || exit;

final class FluentCartProductWriter
{
    public function __construct(
        private readonly ProductTargetGateway $gateway,
        private readonly CheckedMappingStore $maps,
        private readonly ProductReconciler $reconciler,
        private readonly ?WordPressMediaStager $mediaStager = null,
        private readonly ?FluentCartDownloadStager $downloadStager = null,
        private readonly ProductTargetFingerprint $targetFingerprint = new ProductTargetFingerprint(),
    ) {
    }

    public function stage(ProductStagePlan $plan, StageContext $context): StageResult
    {
        $existing = $this->maps->get($plan->record->identity);
        if ($existing !== null) {
            DatabaseTransaction::begin();
            try {
                if (!$existing->isActive() || !$this->gateway->exists($existing->targetId)) {
                    throw new \RuntimeException('target_reconciliation_failed: mapped product target is absent or inactive');
                }
                $reconciliation = $this->reconciler->reconcile(
                    $plan,
                    $existing->targetId,
                    (string) $existing->targetFingerprint,
                );
                if (!$reconciliation->matches) {
                    throw new \RuntimeException('target_reconciliation_failed: ' . implode(',', $reconciliation->failures));
                }
                $result = $this->resultFromMappings(
                    $plan,
                    $existing->targetId,
                    $reconciliation->actualFingerprint,
                    true,
                );
                DatabaseTransaction::commit();
                return $result;
            } catch (\Throwable $exception) {
                DatabaseTransaction::rollback($exception);
                throw $exception;
            }
        }

        DatabaseTransaction::begin();
        try {
            $targetMap = [];
            $createdByMigration = [];
            $reusedMappings = [];
            $taxonomyTargetBySource = [];
            $assignedTaxonomyRelations = [];
            foreach ($plan->taxonomyPlans as $taxonomy) {
                $source = $this->identityFromCanonical((string) $taxonomy['source_identity']);
                if ($taxonomy['action'] === 'provenance') {
                    continue;
                }
                $existingTaxonomy = $this->maps->get($source);
                $parentId = $taxonomy['parent_source'] === null
                    ? null
                    : ($taxonomyTargetBySource[(string) $taxonomy['parent_source']] ?? null);
                if ($existingTaxonomy !== null) {
                    if (!$existingTaxonomy->isActive()
                        || !hash_equals((string) $existingTaxonomy->sourceFingerprint, $this->sourceFingerprint($plan, $source))
                        || ($taxonomy['action'] === 'link' && (int) $taxonomy['target_id'] !== $existingTaxonomy->targetId)) {
                        throw new \RuntimeException('target_reconciliation_failed: shared taxonomy mapping changed');
                    }
                    $targetId = $existingTaxonomy->targetId;
                    $reusedMappings[$source->canonical()] = true;
                } else {
                    $targetId = $taxonomy['action'] === 'link'
                        ? (int) $taxonomy['target_id']
                        : $this->gateway->createTaxonomyTerm($taxonomy, $parentId);
                }
                if ($targetId <= 0) {
                    throw new \RuntimeException('target_write_failed: taxonomy term');
                }
                $taxonomyTargetBySource[$source->canonical()] = $targetId;
                $targetMap[$source->canonical()] = $targetId;
                $createdByMigration[$source->canonical()] = $taxonomy['action'] === 'create';
                if ((int) ($taxonomy['assigned'] ?? 0) === 1) {
                    $assignedTaxonomyRelations[] = [
                        'target_id' => $targetId,
                        'term_order' => (int) $taxonomy['order'],
                    ];
                }
            }

            $productId = $this->gateway->createDraftProduct($plan->productFields);
            if ($productId <= 0) {
                throw new \RuntimeException('target_write_failed: product post');
            }
            $this->gateway->createProductDetail($productId, $plan->detailFields);

            $variationIds = [];
            $variationBySource = [];
            foreach ($plan->variations as $variation) {
                $fields = $variation->targetFields;
                unset($fields['variation_type']);
                $targetVariationId = $this->gateway->createVariation($productId, $fields);
                if ($targetVariationId <= 0) {
                    throw new \RuntimeException('target_write_failed: product variation');
                }
                $variationIds[] = $targetVariationId;
                $variationBySource[$variation->sourceVariation->canonical()] = $targetVariationId;
                $targetMap[$variation->sourceVariation->canonical()] = $targetVariationId;
                $createdByMigration[$variation->sourceVariation->canonical()] = true;
            }
            $prices = array_map(static fn (SimpleVariationPlan $item): int => (int) $item->targetFields['item_price'], $plan->variations);
            $this->gateway->finishProductDetail($productId, $variationIds[0], min($prices), max($prices));
            $this->gateway->assignTaxonomies($productId, $assignedTaxonomyRelations);

            $stagedMedia = [];
            $stagedMediaTargets = [];
            $filesystemOperationIds = [];
            foreach ($plan->media as $mediaPlan) {
                $mediaIdentity = $mediaPlan['reference']->identity;
                $existingMedia = $this->maps->get($mediaIdentity);
                if ($existingMedia !== null) {
                    if (!$existingMedia->isActive()
                        || !hash_equals((string) $existingMedia->sourceFingerprint, $this->sourceFingerprint($plan, $mediaIdentity))) {
                        throw new \RuntimeException('target_reconciliation_failed: shared media mapping changed');
                    }
                    $targetMap[$mediaIdentity->canonical()] = $existingMedia->targetId;
                    $reusedMappings[$mediaIdentity->canonical()] = true;
                    $stagedMedia[] = [
                        'source_identity' => $mediaIdentity->canonical(),
                        'owner_identity' => $mediaPlan['reference']->owner->canonical(),
                        'role' => $mediaPlan['reference']->role,
                        'provenance' => $mediaPlan['reference']->provenance,
                        'sha256' => $mediaPlan['asset']->sha256,
                        'target_id' => $existingMedia->targetId,
                    ];
                    continue;
                }
                if (isset($stagedMediaTargets[$mediaIdentity->canonical()])) {
                    $stagedMedia[] = [
                        'source_identity' => $mediaIdentity->canonical(),
                        'owner_identity' => $mediaPlan['reference']->owner->canonical(),
                        'role' => $mediaPlan['reference']->role,
                        'provenance' => $mediaPlan['reference']->provenance,
                        'sha256' => $mediaPlan['asset']->sha256,
                        'target_id' => $stagedMediaTargets[$mediaIdentity->canonical()],
                    ];
                    continue;
                }
                if ($this->mediaStager === null) {
                    throw new \RuntimeException('target_write_failed: media stager unavailable');
                }
                $staged = $this->mediaStager->stage($mediaPlan['asset'], $context);
                DatabaseTransaction::afterRollback(fn () => $this->mediaStager?->rollbackWithSaga($staged, $context));
                array_push($filesystemOperationIds, ...$staged->filesystemOperationIds());
                $stagedMedia[] = [
                    'source_identity' => $mediaPlan['reference']->identity->canonical(),
                    'owner_identity' => $mediaPlan['reference']->owner->canonical(),
                    'role' => $mediaPlan['reference']->role,
                    'provenance' => $mediaPlan['reference']->provenance,
                    'sha256' => $staged->sha256,
                    'target_id' => $staged->targetId,
                    'target_path' => $staged->targetPath,
                ];
                if ($staged->targetId !== null) {
                    $stagedMediaTargets[$mediaIdentity->canonical()] = $staged->targetId;
                    $targetMap[$mediaPlan['reference']->identity->canonical()] = $staged->targetId;
                    $createdByMigration[$mediaPlan['reference']->identity->canonical()] = $staged->createdByMigration;
                }
            }
            $mediaIds = $this->gateway->attachMedia($productId, $variationBySource, $stagedMedia);

            $downloadIds = [];
            foreach ($plan->downloads as $downloadPlan) {
                if ($this->downloadStager === null) {
                    throw new \RuntimeException('target_write_failed: download stager unavailable');
                }
                $staged = $this->downloadStager->stage($downloadPlan['asset'], $context);
                DatabaseTransaction::afterRollback(fn () => $this->downloadStager?->rollbackWithSaga($staged, $context));
                array_push($filesystemOperationIds, ...$staged->filesystemOperationIds());
                $reference = $downloadPlan['reference'];
                $ownerVariations = isset($variationBySource[$reference->owner->canonical()])
                    ? [$variationBySource[$reference->owner->canonical()]]
                    : [];
                $downloadId = $this->gateway->createDownload($productId, $ownerVariations, [
                    'source_identity' => $reference->identity->canonical(),
                    'sha256' => $staged->sha256,
                    'title' => $reference->name,
                    'type' => $downloadPlan['asset']->mimeType,
                    'driver' => 'local',
                    'file_name' => $downloadPlan['asset']->originalName,
                    'file_path' => $staged->relativePath,
                    'file_url' => '',
                    'file_size' => $staged->bytes,
                    'settings' => $this->downloadStager->settings($reference->limit, $reference->expiryDays),
                    'serial' => count($downloadIds) + 1,
                ]);
                $downloadIds[] = $downloadId;
                $targetMap[$reference->identity->canonical()] = $downloadId;
                $createdByMigration[$reference->identity->canonical()] = true;
            }

            $targetMap[$plan->record->identity->canonical()] = $productId;
            $createdByMigration[$plan->record->identity->canonical()] = true;
            $fingerprint = $this->targetFingerprint->fingerprint($this->gateway->snapshot($productId), $targetMap);

            $mappingIdentities = array_values(array_filter(
                $plan->sourceIdentities(),
                fn (SourceIdentity $identity): bool => $identity != $plan->record->identity,
            ));
            $mappingIdentities[] = $plan->record->identity;
            $newMappingIdentities = [];
            foreach ($mappingIdentities as $identity) {
                $targetId = $targetMap[$identity->canonical()] ?? null;
                if (!is_int($targetId) || $targetId <= 0) {
                    if ($identity->kind()->value === 'taxonomy_term') {
                        continue;
                    }
                    throw new \RuntimeException('target_reconciliation_failed: source target map missing');
                }
                if (isset($reusedMappings[$identity->canonical()])) {
                    $existing = $this->maps->get($identity);
                    if ($existing === null || !$existing->isActive() || $existing->targetId !== $targetId) {
                        throw new \RuntimeException('target_reconciliation_failed: shared taxonomy mapping changed');
                    }
                    continue;
                }
                if ($this->maps->get($identity) !== null) {
                    throw new \RuntimeException('target_reconciliation_failed: orphan product child mapping');
                }
                $this->maps->storeOrThrow(
                    $identity,
                    $targetId,
                    $context->migrationId,
                    $this->sourceFingerprint($plan, $identity),
                    $fingerprint,
                    MapState::Staged,
                    $createdByMigration[$identity->canonical()] ?? true,
                    $context->generation,
                );
                $newMappingIdentities[] = $identity;
            }

            $reconciliation = $this->reconciler->reconcile($plan, $productId, $fingerprint);
            if (!$reconciliation->matches) {
                throw new \RuntimeException('target_reconciliation_failed: ' . implode(',', $reconciliation->failures));
            }

            foreach ($newMappingIdentities as $identity) {
                $this->maps->transitionOrThrow(
                    $identity,
                    MapState::Staged,
                    MapState::Reconciled,
                    $fingerprint,
                    $reconciliation->actualFingerprint,
                );
            }
            DatabaseTransaction::commit();

            $filesystemOperationIds = array_values(array_unique($filesystemOperationIds));
            sort($filesystemOperationIds, SORT_STRING);

            return new StageResult(
                $productId,
                $variationIds,
                $mediaIds,
                $downloadIds,
                $reconciliation->actualFingerprint,
                false,
                $filesystemOperationIds,
                self::sortedMap($targetMap),
            );
        } catch (\Throwable $exception) {
            DatabaseTransaction::rollback($exception);
            throw $exception;
        }
    }

    private function resultFromMappings(ProductStagePlan $plan, int $productId, string $fingerprint, bool $reused): StageResult
    {
        $variationIds = [];
        $mediaIds = [];
        $downloadIds = [];
        foreach ($plan->variations as $variation) {
            $id = $this->maps->get($variation->sourceVariation)?->targetId;
            if ($id !== null) {
                $variationIds[] = $id;
            }
        }
        foreach ($plan->media as $media) {
            $id = $this->maps->get($media['reference']->identity)?->targetId;
            if ($id !== null) {
                $mediaIds[] = $id;
            }
        }
        foreach ($plan->downloads as $download) {
            $id = $this->maps->get($download['reference']->identity)?->targetId;
            if ($id !== null) {
                $downloadIds[] = $id;
            }
        }
        $targetMap = [];
        foreach ($plan->sourceIdentities() as $identity) {
            $id = $this->maps->get($identity)?->targetId;
            if ($id !== null) {
                $targetMap[$identity->canonical()] = $id;
            }
        }
        return new StageResult(
            $productId,
            array_values(array_unique($variationIds)),
            array_values(array_unique($mediaIds)),
            array_values(array_unique($downloadIds)),
            $fingerprint,
            $reused,
            [],
            self::sortedMap($targetMap),
        );
    }

    /** @param array<string,int> $map @return array<string,int> */
    private static function sortedMap(array $map): array
    {
        ksort($map, SORT_STRING);
        return $map;
    }

    private function sourceFingerprint(ProductStagePlan $plan, SourceIdentity $identity): string
    {
        if ($identity == $plan->record->identity) {
            return $plan->sourceFingerprint;
        }
        foreach ($plan->taxonomyPlans as $taxonomy) {
            if (($taxonomy['source_identity'] ?? null) === $identity->canonical()) {
                return CanonicalJson::fingerprint(['taxonomy_term' => [
                    'identity' => $identity->canonical(),
                    'source_taxonomy' => $taxonomy['source_taxonomy'],
                    'target_taxonomy' => $taxonomy['target_taxonomy'],
                    'name' => $taxonomy['name'],
                    'slug' => $taxonomy['slug'],
                    'description' => $taxonomy['description'],
                    'parent_source' => $taxonomy['parent_source'],
                ]]);
            }
        }
        foreach ($plan->media as $media) {
            if ($media['reference']->identity == $identity) {
                return CanonicalJson::fingerprint(['media_asset' => [
                    'identity' => $identity->canonical(),
                    'locator' => $media['reference']->locator,
                    'mime_type' => $media['reference']->mimeType,
                    'size' => $media['reference']->size,
                    'expected_sha256' => $media['reference']->expectedSha256,
                    'asset' => $media['asset']->toArray(),
                ]]);
            }
        }
        return CanonicalJson::fingerprint([
            'plan_fingerprint' => $plan->planFingerprint,
            'source_identity' => $identity->canonical(),
        ]);
    }

    private function identityFromCanonical(string $canonical): SourceIdentity
    {
        $parts = explode(':', $canonical, 3);
        return new SourceIdentity($parts[0], $parts[1], $parts[2]);
    }
}
