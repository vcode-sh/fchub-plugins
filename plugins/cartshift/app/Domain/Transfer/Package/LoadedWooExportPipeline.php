<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Package;

use CartShift\Domain\Transfer\Audit\LoadedWooSourceApi;
use CartShift\Domain\Transfer\Audit\LoadedWooTransferAuditor;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Graph\SourceClosureResolver;
use CartShift\Domain\Transfer\RecordKind;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\TransferSelection;

defined('ABSPATH') || exit;

/** Production source-side export composition. It has no target writer and therefore nowhere amusing to put a DB mutation. */
final class LoadedWooExportPipeline
{
    /** @return array{path:string,records_sha256:string} */
    public function __invoke(
        TransferSelection $selection,
        string $destination,
        string $decisionPath,
    ): array {
        $decisions = TransferDecisionSet::fromFile($decisionPath);
        $decisions->assertSourceKey($selection->sourceKey);
        $auditor = LoadedWooTransferAuditor::create();
        $before = $auditor->audit($selection, $decisions);
        if (!$before->ready
            || !hash_equals($selection->fingerprint(), $before->selectionFingerprint)
            || !hash_equals($decisions->fingerprint(), $before->decisionFingerprint)) {
            throw new \RuntimeException('source_audit_not_ready');
        }

        $sourceApi = new LoadedWooSourceApi();
        $census = new LoadedWooRootCensus($sourceApi);
        $loader = LoadedWooTransferRecordLoader::fromLoadedRuntime($selection, $decisions, $census);
        $reverse = null;
        $repository = new DecisionBoundSourceRepository(
            $selection,
            $decisions,
            $census,
            [
                RecordKind::Product->value => $loader->load(...),
                RecordKind::Customer->value => $loader->load(...),
                RecordKind::Order->value => $loader->load(...),
                RecordKind::Subscription->value => $loader->load(...),
            ],
            static function ($record, string $kind) use (&$reverse): iterable {
                if (!$reverse instanceof LoadedWooReverseDependencyLookup) {
                    throw new \RuntimeException('reverse_dependency_source_index_unavailable');
                }
                yield from $reverse->records($record, $kind);
            },
        );
        $reverse = new LoadedWooReverseDependencyLookup(
            $selection->sourceKey,
            $census,
            $repository->lookup(...),
        );

        $registry = new SourceInstanceRegistry(dirname($decisionPath) . '/source-instance-registry.json');
        $runtimeBuilder = new LoadedWooExportRuntime();
        $runtime = $runtimeBuilder->descriptor($destination, $before, $registry);
        if (!function_exists('wp_get_upload_dir')) {
            throw new \RuntimeException('wordpress_upload_contract_unavailable');
        }
        $uploads = (array) wp_get_upload_dir();
        if (($uploads['error'] ?? false) !== false
            || !is_string($uploads['baseurl'] ?? null)
            || !is_string($uploads['basedir'] ?? null)) {
            throw new \RuntimeException('wordpress_upload_contract_unavailable');
        }
        $assetOpener = new LocalWordPressAssetOpener($uploads['baseurl'], $uploads['basedir']);
        $assessor = new DecisionBoundSourceRecordAssessor();
        $assessors = [];
        foreach (RecordKind::cases() as $kind) {
            $assessors[$kind->value] = $assessor;
        }
        $exporter = new TransferExporter(
            new SourceClosureResolver(),
            new TransferPackageWriter(new TransferPackageValidator()),
            $registry,
            $assessors,
        );
        $path = $exporter->export(
            new SourceIdentity($selection->sourceKey, RecordKind::Product->value, '1'),
            $selection,
            $decisions,
            $repository->roots(),
            $repository->lookup(...),
            $repository->reverseLookup(...),
            $runtime,
            $assetOpener(...),
        );

        $after = $auditor->audit($selection, $decisions);
        if (!$after->ready || !hash_equals($before->auditFingerprint, $after->auditFingerprint)) {
            throw new \RuntimeException('source_drifted_during_export');
        }
        $afterRuntime = $runtimeBuilder->descriptor($destination, $after, $registry);
        foreach ([
            'source_instance_fingerprint',
            'source_url_hash',
            'source_runtime_fingerprint',
            'source_settings_fingerprint',
            'source_capability_fingerprint',
            'cartshift_version',
            'woocommerce_version',
            'wcs_version',
        ] as $field) {
            if (($runtime[$field] ?? null) !== ($afterRuntime[$field] ?? null)) {
                throw new \RuntimeException('source_drifted_during_export');
            }
        }
        $manifest = (new TransferPackageValidator())->assertValid($path);

        return ['path' => $path, 'records_sha256' => $manifest->recordsSha256];
    }
}
