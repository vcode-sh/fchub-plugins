<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Audit;

use CartShift\Domain\Transfer\Runtime\TransferRuntimeInspector;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeProbe;
use CartShift\Domain\Transfer\Runtime\TransferRuntimeReport;
use CartShift\Support\WooStorage;

defined('ABSPATH') || exit;

/** One production composition for audit and export, because two slightly different truths would be rather sporting. */
final class LoadedWooTransferAuditor
{
    public static function create(): TransferAuditor
    {
        $probe = new TransferRuntimeProbe();
        $runtimeReport = $probe->inspect(TransferRuntimeProbe::ROLE_SOURCE);
        $runtime = new class($runtimeReport) implements TransferRuntimeInspector {
            public function __construct(private readonly TransferRuntimeReport $report) {}

            public function inspect(string $role): TransferRuntimeReport
            {
                if ($role !== $this->report->role) {
                    throw new \InvalidArgumentException('Cached runtime role mismatch.');
                }

                return $this->report;
            }
        };
        $storage = WooStorage::isHposEnabled()
            ? new HposStorageIntegrityReader()
            : new CptStorageIntegrityReader();
        $source = new LoadedWooSourceApi();

        return new TransferAuditor(
            $runtime,
            new WooSourceInventoryReader($source, $storage, $runtimeReport->fingerprint),
            new RecordContractInspector((new LoadedWooRecordContractAttempts($source))->attempts(...)),
        );
    }
}
