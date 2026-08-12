<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ProductTargetGateway;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class LoadedCatalogueActivator implements CatalogueActivator
{
    public function __construct(
        private string $sourceKey,
        private ProductTargetGateway $gateway = new LoadedFluentCartProductGateway(),
        private ProductTargetFingerprint $productFingerprint = new ProductTargetFingerprint(),
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
    }

    public function activate(TransferReceipt $productReceipt, string $approvedStatus): CatalogueStatusChange
    {
        if ($productReceipt->recordKind !== 'product' || $productReceipt->action !== 'created' || $approvedStatus !== 'publish') {
            throw new \InvalidArgumentException('catalogue_activation_receipt_invalid');
        }
        $targetId = $productReceipt->targetIds['primary'];
        if (!hash_equals($productReceipt->afterFingerprint, $this->productGraphFingerprint($productReceipt))) {
            throw new \RuntimeException('catalogue_activation_target_drift:' . $productReceipt->sourceIdentity);
        }
        $before = $this->status($targetId);
        if ($before !== 'draft') {
            throw new \RuntimeException('catalogue_activation_status_changed:' . $productReceipt->sourceIdentity);
        }
        $beforeFingerprint = $this->statusFingerprint($productReceipt->sourceIdentity, $targetId, 'draft');
        global $wpdb;
        $updated = $wpdb->update($wpdb->posts, ['post_status' => 'publish'], [
            'ID' => $targetId,
            'post_type' => 'fluent-products',
            'post_status' => 'draft',
        ]);
        if ($updated !== 1 || $this->status($targetId) !== 'publish') {
            throw new \RuntimeException('catalogue_activation_write_failed:' . $productReceipt->sourceIdentity);
        }
        return new CatalogueStatusChange(
            $productReceipt->sourceIdentity,
            $targetId,
            'draft',
            'publish',
            $beforeFingerprint,
            $this->statusFingerprint($productReceipt->sourceIdentity, $targetId, 'publish'),
        );
    }

    public function fingerprint(CatalogueStatusChange $change): string
    {
        return $this->statusFingerprint($change->sourceIdentity, $change->targetId, $this->status($change->targetId));
    }

    public function fingerprintReceipt(TransferReceipt $receipt): string
    {
        $this->assertStatusReceipt($receipt);
        return $this->statusFingerprint($receipt->sourceIdentity, $receipt->targetIds['primary'], $this->status($receipt->targetIds['primary']));
    }

    public function restore(CatalogueStatusChange $change): void
    {
        if (!hash_equals($change->afterFingerprint, $this->fingerprint($change))) {
            throw new \RuntimeException('catalogue_activation_restore_target_drift:' . $change->sourceIdentity);
        }
        $this->restoreStatus($change->sourceIdentity, $change->targetId, $change->beforeFingerprint);
    }

    public function restoreReceipt(TransferReceipt $receipt): void
    {
        $this->assertStatusReceipt($receipt);
        if (!hash_equals($receipt->afterFingerprint, $this->fingerprintReceipt($receipt))) {
            throw new \RuntimeException('catalogue_activation_restore_target_drift:' . $receipt->sourceIdentity);
        }
        $this->restoreStatus($receipt->sourceIdentity, $receipt->targetIds['primary'], (string) $receipt->beforeFingerprint);
    }

    public function storefrontAndCartReconcile(array $statusReceipts): bool
    {
        if ($statusReceipts === []) {
            return false;
        }
        foreach ($statusReceipts as $receipt) {
            if (!$receipt instanceof TransferReceipt) {
                return false;
            }
            try {
                $this->assertStatusReceipt($receipt);
                if (!hash_equals($receipt->afterFingerprint, $this->fingerprintReceipt($receipt))) {
                    return false;
                }
                $variations = $this->variationIds($receipt);
                $behaviour = $this->gateway->behaviour($receipt->targetIds['primary'], $variations);
                $cartable = array_values(array_map('intval', (array) ($behaviour['cartable_variation_ids'] ?? [])));
                sort($cartable);
                sort($variations);
                if (($behaviour['buy_section_rendered'] ?? false) !== true || $cartable !== $variations) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        return true;
    }

    private function restoreStatus(string $sourceIdentity, int $targetId, string $expectedBefore): void
    {
        global $wpdb;
        $updated = $wpdb->update($wpdb->posts, ['post_status' => 'draft'], [
            'ID' => $targetId,
            'post_type' => 'fluent-products',
            'post_status' => 'publish',
        ]);
        if ($updated !== 1
            || !hash_equals($expectedBefore, $this->statusFingerprint($sourceIdentity, $targetId, $this->status($targetId)))) {
            throw new \RuntimeException('catalogue_activation_restore_failed:' . $sourceIdentity);
        }
    }

    private function productGraphFingerprint(TransferReceipt $receipt): string
    {
        $map = $this->sourceMap($receipt);
        return $this->productFingerprint->fingerprint($this->gateway->snapshot($receipt->targetIds['primary']), $map);
    }

    /** @return array<string,int> */
    private function sourceMap(TransferReceipt $receipt): array
    {
        $expected = [];
        foreach ($receipt->targetIds as $canonical => $targetId) {
            if (!str_starts_with($canonical, $this->sourceKey . ':')) {
                continue;
            }
            $identity = SourceIdentity::fromCanonical($canonical);
            if ($identity->sourceKey !== $this->sourceKey) {
                throw new \RuntimeException('catalogue_activation_map_read_failed');
            }
            $expected[$canonical] = $targetId;
        }
        if (($expected[$receipt->sourceIdentity] ?? null) !== $receipt->targetIds['primary']) {
            throw new \RuntimeException('catalogue_activation_root_map_missing');
        }

        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT entity_type, wc_id, fc_id, target_fingerprint, record_state FROM {$wpdb->prefix}cartshift_id_map
             WHERE source_key = %s AND migration_id = %s AND is_simulated = 0
               AND record_state <> 'rolled_back'
             ORDER BY entity_type, wc_id",
            $this->sourceKey,
            $receipt->runId,
        ), ARRAY_A);
        if (trim((string) ($wpdb->last_error ?? '')) !== '' || !is_array($rows)) {
            throw new \RuntimeException('catalogue_activation_map_read_failed');
        }
        $map = [];
        foreach ($rows as $row) {
            $canonical = $this->sourceKey . ':' . (string) $row['entity_type'] . ':' . (string) $row['wc_id'];
            if (!array_key_exists($canonical, $expected)) {
                continue;
            }
            $targetId = (int) $row['fc_id'];
            if (isset($map[$canonical]) || $targetId !== $expected[$canonical]) {
                throw new \RuntimeException('catalogue_activation_map_changed:' . $canonical);
            }
            $map[$canonical] = $targetId;
        }
        ksort($expected, SORT_STRING);
        ksort($map, SORT_STRING);
        if ($map !== $expected) {
            throw new \RuntimeException('catalogue_activation_map_missing');
        }
        return $map;
    }

    /** @return list<int> */
    private function variationIds(TransferReceipt $receipt): array
    {
        $root = SourceIdentity::fromCanonical($receipt->sourceIdentity);
        $ids = [];
        foreach ($this->sourceMap($receipt) as $canonical => $targetId) {
            $identity = SourceIdentity::fromCanonical($canonical);
            if ($identity->entityType === 'product' && str_starts_with($identity->sourceId, $root->sourceId . ':variation:')) {
                $ids[] = $targetId;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    private function status(int $targetId): string
    {
        global $wpdb;
        $status = $wpdb->get_var($wpdb->prepare(
            "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'fluent-products' LIMIT 1",
            $targetId,
        ));
        if (!is_string($status) || !in_array($status, ['draft', 'publish'], true)) {
            throw new \RuntimeException('catalogue_activation_target_missing');
        }
        return $status;
    }

    private function statusFingerprint(string $sourceIdentity, int $targetId, string $status): string
    {
        return CanonicalJson::fingerprint([
            'source_identity' => $sourceIdentity,
            'target_id' => $targetId,
            'post_type' => 'fluent-products',
            'post_status' => $status,
        ]);
    }

    private function assertStatusReceipt(TransferReceipt $receipt): void
    {
        if ($receipt->recordKind !== 'catalogue_status' || $receipt->action !== 'catalogue_status') {
            throw new \InvalidArgumentException('catalogue_status_receipt_invalid');
        }
    }
}
