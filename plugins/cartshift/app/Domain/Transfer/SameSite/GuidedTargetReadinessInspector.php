<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Execution\LoadedTargetRecordPlanFactory;
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
        $exceptions = [];
        foreach ($records as $record) {
            match ($record->identity->kind()) {
                RecordKind::Product => $this->inspectProductPlan($plans->product($record), $exceptions),
                RecordKind::Customer => $plans->customer($record),
                // Their exact plans need dependency target IDs created during stage.
                RecordKind::Order, RecordKind::Subscription => null,
                RecordKind::TaxonomyGroup,
                RecordKind::TaxonomyTerm,
                RecordKind::MediaAsset,
                RecordKind::DownloadAsset => null,
            };
        }

        return $exceptions;
    }

    /** @param list<array<string,mixed>> $exceptions */
    private function inspectProductPlan(ProductStagePlan|LinkedProductPlan $plan, array &$exceptions): void
    {
        if ($plan instanceof LinkedProductPlan) {
            return;
        }
        foreach ($plan->variations as $variation) {
            $exception = $variation->targetOtherInfo['stock_migration_exception'] ?? null;
            if (!is_array($exception)) {
                continue;
            }
            $sourceStock = is_array($exception['source_stock'] ?? null) ? $exception['source_stock'] : [];
            $exceptions[] = [
                'kind' => 'shared_parent_stock',
                'product_name' => $plan->record->name,
                'variation_name' => $variation->targetTitle,
                'sku' => (string) ($variation->targetFields['sku'] ?? ''),
                'source_variation' => (string) ($exception['source_variation'] ?? ''),
                'source_owner' => (string) ($sourceStock['owner'] ?? ''),
                'source_quantity' => is_int($sourceStock['quantity'] ?? null) ? $sourceStock['quantity'] : null,
                'source_status' => (string) ($sourceStock['status'] ?? ''),
                'source_backorders' => (string) ($sourceStock['backorders'] ?? ''),
                'source_stock' => $sourceStock,
            ];
        }
    }
}
