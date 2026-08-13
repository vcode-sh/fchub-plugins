<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\LoadedTargetRecordPlanFactory;
use CartShift\Domain\Transfer\Execution\TransferRecordHydrator;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Package\TransferPackageReader;
use CartShift\Domain\Transfer\Package\TransferPackageValidator;
use CartShift\Domain\Transfer\Product\ProductStagePlan;
use CartShift\Domain\Transfer\Product\LinkedProductPlan;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Storage\IdMapRepository;

defined('ABSPATH') || exit;

/** Validates a package and proves what the target can safely represent before prepare. */
final readonly class GuidedTargetReadinessInspector
{
    public function __construct(
        private ?\Closure $packageValidator = null,
        private ?\Closure $targetReadiness = null,
    ) {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function inspect(array $input): array
    {
        $package = (string) $input['package'];
        $manifest = $this->packageValidator !== null
            ? ($this->packageValidator)($package)
            : (new TransferPackageValidator())->assertValid($package);
        $exceptions = [];
        if ($this->targetReadiness !== null) {
            $readiness = ($this->targetReadiness)($package, (string) $input['decision_set']);
            if (is_array($readiness)) {
                $exceptions = array_values($readiness);
            }
        } else {
            $exceptions = $this->assertTargetReadiness(
                $package,
                (string) $input['decision_set'],
                $manifest->createdAtUtc,
            );
        }

        return [
            'status' => 'validated',
            'source_key' => $manifest->sourceKey,
            'selection_fingerprint' => $manifest->selectionFingerprint,
            'records_sha256' => $manifest->recordsSha256,
            'record_counts' => $manifest->recordCounts,
            'migration_exceptions' => $exceptions,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function assertTargetReadiness(string $package, string $decisionSetPath, string $evaluationUtc): array
    {
        $validator = new TransferPackageValidator();
        $reader = new TransferPackageReader($package, $validator);
        $records = iterator_to_array($reader->records(), false);
        $decisions = TransferDecisionSet::fromFile($decisionSetPath);
        $manifest = $reader->manifest();
        $decisions->assertSourceKey($manifest->sourceKey);
        $plans = LoadedTargetRecordPlanFactory::create(
            $decisions,
            new IdMapRepository($manifest->sourceKey),
            $package,
            $records,
            $evaluationUtc,
        );
        $hydrator = new TransferRecordHydrator();
        $knownVariations = [];
        foreach ($records as $record) {
            if ($record->identity->kind() !== RecordKind::Product) {
                continue;
            }
            foreach ((array) ($record->payload['variations'] ?? []) as $variation) {
                if (is_array($variation) && is_string($variation['identity'] ?? null)) {
                    $knownVariations[$variation['identity']] = true;
                }
            }
        }
        $exceptions = [];
        foreach ($records as $record) {
            match ($record->identity->kind()) {
                RecordKind::Product => $this->inspectProductPlan($plans->product($record), $exceptions),
                RecordKind::Customer => $plans->customer($record),
                // Their exact plans need dependency target IDs created during stage.
                RecordKind::Order => $this->inspectOrder($hydrator->order($record), $knownVariations, $exceptions),
                RecordKind::Subscription => null,
                RecordKind::TaxonomyGroup,
                RecordKind::TaxonomyTerm,
                RecordKind::MediaAsset,
                RecordKind::DownloadAsset => null,
            };
        }

        return $exceptions;
    }

    /** @param array<string,true> $knownVariations @param list<array<string,mixed>> $exceptions */
    private function inspectOrder(OrderRecord $record, array $knownVariations, array &$exceptions): void
    {
        $types = [];
        foreach ($record->productLines as $line) {
            $types[(string) ($line->otherInfo['source_fulfilment_type'] ?? '')] = true;
        }
        if (!isset($types['physical'])) {
            return;
        }
        $status = strtolower(trim($record->sourceStatus));
        if (str_starts_with($status, 'wc-')) {
            $status = substr($status, 3);
        }
        $exceptions[] = [
            'kind' => 'physical_order_fulfilment',
            'source_order' => $record->identity->canonical(),
            'projection' => $status === 'completed' && $record->completedUtc !== null ? 'delivered' : 'unshipped',
            'mixed' => count($types) > 1,
        ];
        foreach ($record->productLines as $line) {
            if (isset($knownVariations[$line->variation->canonical()])) {
                continue;
            }
            $exceptions[] = [
                'kind' => 'historical_order_variation_unlinked',
                'source_order' => $record->identity->canonical(),
                'source_line' => $line->identity->canonical(),
                'line_name' => $line->name,
            ];
        }
    }

    /** @param list<array<string,mixed>> $exceptions */
    private function inspectProductPlan(ProductStagePlan|LinkedProductPlan $plan, array &$exceptions): void
    {
        if ($plan instanceof LinkedProductPlan) {
            return;
        }
        foreach ($plan->variations as $variation) {
            $stockException = $variation->targetOtherInfo['stock_migration_exception'] ?? null;
            if (is_array($stockException)) {
                $sourceStock = is_array($stockException['source_stock'] ?? null) ? $stockException['source_stock'] : [];
                $exceptions[] = [
                    'kind' => 'shared_parent_stock',
                    'product_name' => $plan->record->name,
                    'variation_name' => $variation->targetTitle,
                    'sku' => (string) ($variation->targetFields['sku'] ?? ''),
                    'source_variation' => (string) ($stockException['source_variation'] ?? ''),
                    'source_owner' => (string) ($sourceStock['owner'] ?? ''),
                    'source_quantity' => is_int($sourceStock['quantity'] ?? null) ? $sourceStock['quantity'] : null,
                    'source_status' => (string) ($sourceStock['status'] ?? ''),
                    'source_backorders' => (string) ($sourceStock['backorders'] ?? ''),
                    'source_stock' => $sourceStock,
                ];
            }
            $skuException = $variation->targetOtherInfo['sku_migration_exception'] ?? null;
            if (is_array($skuException)) {
                $exceptions[] = [
                    'kind' => 'duplicate_variation_sku',
                    'source_product' => $plan->record->identity->canonical(),
                    'product_name' => $plan->record->name,
                    'variation_name' => $variation->targetTitle,
                    'source_variation' => (string) ($skuException['source_variation'] ?? ''),
                    'source_sku' => (string) ($skuException['source_sku'] ?? ''),
                    'target_sku' => (string) ($skuException['target_sku'] ?? ''),
                ];
            }
        }
    }
}
