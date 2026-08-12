<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\Customer\LoadedFluentCartCustomerGateway;
use CartShift\Domain\Transfer\Customer\CustomerTargetGateway;
use CartShift\Domain\Transfer\Order\LoadedFluentCartOrderGateway;
use CartShift\Domain\Transfer\Order\OrderTargetGateway;
use CartShift\Domain\Transfer\Order\OrderTargetFingerprint;
use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ProductTargetGateway;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\Subscription\LoadedFluentCartSubscriptionGateway;
use CartShift\Domain\Transfer\Subscription\SubscriptionTargetGateway;
use CartShift\Domain\Transfer\Subscription\SubscriptionTargetFingerprint;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Re-reads and removes only receipt-owned target graphs. */
final readonly class LoadedRollbackTargetGateway implements RollbackTargetGateway, CommittedRollbackRecovery
{
    public function __construct(
        private string $sourceKey,
        private FilesystemSagaRepository $filesystem,
        private ProductTargetGateway $products = new LoadedFluentCartProductGateway(),
        private CustomerTargetGateway $customers = new LoadedFluentCartCustomerGateway(),
        private OrderTargetGateway $orders = new LoadedFluentCartOrderGateway(),
        private SubscriptionTargetGateway $subscriptions = new LoadedFluentCartSubscriptionGateway(),
    ) {
        SourceIdentity::assertValidSourceKey($sourceKey);
    }

    public function fingerprint(TransferReceipt $receipt): ?string
    {
        try {
            $targetId = $receipt->targetIds['primary'];
            $map = $this->sourceMap($receipt);
            return match ($receipt->recordKind) {
                'product' => (new ProductTargetFingerprint())->fingerprint($this->products->snapshot($targetId), $map),
                'customer' => $this->customerFingerprint($targetId, $map, $receipt->sourceIdentity),
                'order' => (new OrderTargetFingerprint())->fingerprint($this->orders->snapshot($targetId), $map),
                'subscription' => (new SubscriptionTargetFingerprint())->fingerprint(
                    $this->subscriptions->snapshot($targetId),
                    [$receipt->sourceIdentity => $targetId],
                ),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    public function delete(TransferReceipt $receipt): void
    {
        if (!hash_equals($receipt->afterFingerprint, (string) $this->fingerprint($receipt))) {
            throw new \RuntimeException('rollback_target_drift_during_delete:' . $receipt->sourceIdentity);
        }
        foreach ($receipt->filesystemOperationIds as $operationId) {
            $this->filesystem->quarantineFinalisedTarget($receipt->runId, $operationId);
        }
        match ($receipt->recordKind) {
            'product' => $this->deleteProduct($receipt),
            'customer' => $this->deleteCustomer($receipt),
            'order' => $this->deleteOrder($receipt),
            'subscription' => $this->deleteSubscription($receipt),
            default => throw new \RuntimeException('rollback_record_kind_unsupported'),
        };
    }

    public function completeCommittedRollback(RollbackPlan $plan): void
    {
        foreach ($plan->deletions as $item) {
            $receipt = $item['receipt'];
            $exists = match ($receipt->recordKind) {
                'product' => $this->products->exists($receipt->targetIds['primary']),
                'customer' => $this->customers->exists($receipt->targetIds['primary']),
                'order' => $this->orders->exists($receipt->targetIds['primary']),
                'subscription' => $this->subscriptions->exists($receipt->targetIds['primary']),
                default => throw new \RuntimeException('rollback_record_kind_unsupported'),
            };
            if ($exists || !$this->rollbackEvidenceCommitted($receipt)) {
                throw new \RuntimeException('rollback_committed_state_unproven:' . $receipt->sourceIdentity);
            }
            foreach ($receipt->filesystemOperationIds as $operationId) {
                $this->filesystem->completeQuarantinedRollback($receipt->runId, $operationId);
            }
        }
    }

    private function rollbackEvidenceCommitted(TransferReceipt $receipt): bool
    {
        global $wpdb;
        $record = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_transfer_records
             WHERE run_id = %s AND record_kind = %s AND source_identity = %s AND generation = %d
               AND state = 'rolled_back' AND after_hash = %s",
            $receipt->runId, $receipt->recordKind, $receipt->sourceIdentity, $receipt->generation, $receipt->afterFingerprint,
        ));
        if ($record !== 1) return false;
        foreach ($this->sourceMap($receipt) as $canonical => $targetId) {
            $identity = SourceIdentity::fromCanonical($canonical);
            $mapFingerprint = $wpdb->get_var($wpdb->prepare(
                "SELECT target_fingerprint FROM {$wpdb->prefix}cartshift_id_map
                 WHERE source_key = %s AND entity_type = %s AND wc_id = %s AND fc_id = %d
                   AND migration_id = %s AND is_simulated = 0 AND record_state = 'rolled_back'
                 ORDER BY id ASC LIMIT 2",
                $identity->sourceKey, $identity->entityType, $identity->sourceId, $targetId,
                $receipt->runId,
            ));
            if (!is_string($mapFingerprint) || preg_match('/\A[a-f0-9]{64}\z/D', $mapFingerprint) !== 1) return false;
            $owner = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_transfer_records
                 WHERE run_id = %s AND generation = %d AND state = 'rolled_back' AND after_hash = %s",
                $receipt->runId, $receipt->generation, $mapFingerprint,
            ));
            if ($owner !== 1) return false;
        }
        if (in_array($receipt->recordKind, ['order', 'subscription'], true)) {
            $claims = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}cartshift_target_claims
                 WHERE entity_type = %s AND target_id = %d AND run_id = %s
                   AND source_fingerprint = %s AND target_fingerprint = %s AND claim_state = 'rolled_back'",
                $receipt->recordKind, $receipt->targetIds['primary'], $receipt->runId,
                $receipt->sourceFingerprint, $receipt->afterFingerprint,
            ));
            if ($claims !== 1) return false;
        }
        return true;
    }

    /** @return array<string,int> */
    private function sourceMap(TransferReceipt $receipt): array
    {
        $map = [];
        foreach ($receipt->targetIds as $canonical => $targetId) {
            try {
                $identity = SourceIdentity::fromCanonical((string) $canonical);
            } catch (\Throwable) {
                continue;
            }
            if ($identity->sourceKey !== $this->sourceKey) {
                throw new \RuntimeException('rollback_receipt_source_namespace_changed');
            }
            $map[$identity->canonical()] = $targetId;
        }
        if (($map[$receipt->sourceIdentity] ?? null) !== $receipt->targetIds['primary']) {
            throw new \RuntimeException('rollback_receipt_source_map_missing');
        }
        ksort($map, SORT_STRING);
        return $map;
    }

    /** @param array<string,int> $map */
    private function customerFingerprint(int $targetId, array $map, string $root): string
    {
        $snapshot = $this->customers->snapshot($targetId);
        unset($map[$root]);
        return CanonicalJson::fingerprint([
            'customer' => $snapshot['customer'] ?? null,
            'addresses' => $snapshot['addresses'] ?? [],
            'address_map' => $map,
        ]);
    }

    private function deleteProduct(TransferReceipt $receipt): void
    {
        global $wpdb;
        $productId = $receipt->targetIds['primary'];
        $ownedTaxonomies = [];
        $ownedMedia = [];
        $variationIds = [];
        $taxonomyIds = [];
        foreach ($this->sourceMap($receipt) as $canonical => $targetId) {
            $identity = SourceIdentity::fromCanonical($canonical);
            if ($identity->entityType === 'product' && str_contains($identity->sourceId, ':variation:')) {
                $variationIds[] = $targetId;
            }
            if ($identity->entityType === 'taxonomy_term') {
                $taxonomyIds[] = $targetId;
            }
            if (!$this->createdByReceipt($receipt, $identity, $targetId)) {
                continue;
            }
            if ($identity->entityType === 'taxonomy_term') {
                $ownedTaxonomies[] = $targetId;
            } elseif ($identity->entityType === 'media_asset') {
                $ownedMedia[] = $targetId;
            }
        }
        foreach ($ownedMedia as $attachmentId) {
            $this->assertOwnedAttachment($receipt, $attachmentId);
        }
        $this->deleteWhere($wpdb->prefix . 'fct_product_downloads', ['post_id' => $productId]);
        foreach (array_values(array_unique(array_merge([$productId], $variationIds))) as $objectId) {
            $this->deleteWhere($wpdb->prefix . 'fct_product_meta', ['object_id' => $objectId]);
        }
        $this->deleteWhere($wpdb->prefix . 'fct_product_variations', ['post_id' => $productId]);
        $this->deleteWhere($wpdb->prefix . 'fct_product_details', ['post_id' => $productId]);
        $this->deleteWhere($wpdb->term_relationships, ['object_id' => $productId]);
        foreach (array_values(array_unique($taxonomyIds)) as $termId) {
            $taxonomyId = $wpdb->get_var($wpdb->prepare(
                "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d LIMIT 1",
                $termId,
            ));
            if ($taxonomyId !== null) {
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                    (int) $taxonomyId,
                ));
                if ($wpdb->update($wpdb->term_taxonomy, ['count' => $count], ['term_taxonomy_id' => (int) $taxonomyId]) === false) {
                    throw new \RuntimeException('rollback_product_taxonomy_count_failed');
                }
            }
        }
        $this->deleteWhere($wpdb->postmeta, ['post_id' => $productId]);
        $this->deleteOne($wpdb->posts, ['ID' => $productId, 'post_type' => 'fluent-products'], 'rollback_product_root_delete_failed');

        foreach ($ownedMedia as $attachmentId) {
            $this->deleteAttachmentRows($attachmentId);
        }
        foreach ($ownedTaxonomies as $termId) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt
                 ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.term_id = %d",
                $termId,
            ));
            if ($remaining !== 0) {
                throw new \RuntimeException('rollback_product_taxonomy_still_referenced');
            }
            $this->deleteWhere($wpdb->term_taxonomy, ['term_id' => $termId]);
            $this->deleteOne($wpdb->terms, ['term_id' => $termId], 'rollback_product_taxonomy_delete_failed');
        }
    }

    private function deleteCustomer(TransferReceipt $receipt): void
    {
        global $wpdb;
        $id = $receipt->targetIds['primary'];
        $this->deleteWhere($wpdb->prefix . 'fct_customer_addresses', ['customer_id' => $id]);
        $this->deleteOne($wpdb->prefix . 'fct_customers', ['id' => $id], 'rollback_customer_root_delete_failed');
    }

    private function deleteOrder(TransferReceipt $receipt): void
    {
        global $wpdb;
        $id = $receipt->targetIds['primary'];
        foreach (['fct_order_transactions', 'fct_order_meta', 'fct_order_tax_rate', 'fct_applied_coupons', 'fct_order_addresses', 'fct_order_items'] as $suffix) {
            $this->deleteWhere($wpdb->prefix . $suffix, ['order_id' => $id]);
        }
        $this->deleteOne($wpdb->prefix . 'fct_orders', ['id' => $id], 'rollback_order_root_delete_failed');
    }

    private function deleteSubscription(TransferReceipt $receipt): void
    {
        global $wpdb;
        $id = $receipt->targetIds['primary'];
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}fct_order_transactions SET subscription_id = NULL WHERE subscription_id = %d",
            $id,
        ));
        if ($updated === false) {
            throw new \RuntimeException('rollback_subscription_history_unlink_failed');
        }
        $this->deleteWhere($wpdb->prefix . 'fct_subscription_meta', ['subscription_id' => $id]);
        $this->deleteOne($wpdb->prefix . 'fct_subscriptions', ['id' => $id], 'rollback_subscription_root_delete_failed');
    }

    private function createdByReceipt(TransferReceipt $receipt, SourceIdentity $identity, int $targetId): bool
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT created_by_migration, target_fingerprint FROM {$wpdb->prefix}cartshift_id_map
             WHERE source_key = %s AND entity_type = %s AND wc_id = %s AND fc_id = %d
               AND migration_id = %s AND is_simulated = 0 ORDER BY id ASC LIMIT 2",
            $identity->sourceKey,
            $identity->entityType,
            $identity->sourceId,
            $targetId,
            $receipt->runId,
        ));
        if (trim((string) ($wpdb->last_error ?? '')) !== '' || !is_array($rows) || count($rows) !== 1) {
            throw new \RuntimeException('rollback_owned_mapping_missing');
        }
        $fingerprint = (string) ($rows[0]->target_fingerprint ?? '');
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1) {
            throw new \RuntimeException('rollback_owned_mapping_invalid');
        }
        if (!hash_equals($receipt->afterFingerprint, $fingerprint)) {
            $owners = $wpdb->get_results($wpdb->prepare(
                "SELECT after_hash FROM {$wpdb->prefix}cartshift_transfer_records
                 WHERE run_id = %s AND generation = %d AND state = 'successful' AND after_hash = %s
                 ORDER BY id ASC LIMIT 2",
                $receipt->runId, $receipt->generation, $fingerprint,
            ));
            if (trim((string) ($wpdb->last_error ?? '')) !== '' || !is_array($owners) || count($owners) !== 1
                || !hash_equals($fingerprint, (string) ($owners[0]->after_hash ?? ''))) {
                throw new \RuntimeException('rollback_shared_mapping_owner_unproven');
            }
            return false;
        }
        return (int) ($rows[0]->created_by_migration ?? 0) === 1;
    }

    private function assertOwnedAttachment(TransferReceipt $receipt, int $attachmentId): void
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_type, run_meta.meta_value AS migration_run
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} run_meta ON run_meta.post_id = p.ID AND run_meta.meta_key = '_cartshift_migration_run'
             WHERE p.ID = %d LIMIT 2",
            $attachmentId,
        ));
        if (!is_array($rows) || count($rows) !== 1
            || (string) ($rows[0]->post_type ?? '') !== 'attachment'
            || !hash_equals($receipt->runId, (string) ($rows[0]->migration_run ?? ''))) {
            throw new \RuntimeException('rollback_product_media_ownership_changed');
        }
    }

    private function deleteAttachmentRows(int $attachmentId): void
    {
        global $wpdb;
        $commentIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d",
            $attachmentId,
        )));
        foreach ($commentIds as $commentId) {
            $this->deleteWhere($wpdb->commentmeta, ['comment_id' => $commentId]);
        }
        $this->deleteWhere($wpdb->comments, ['comment_post_ID' => $attachmentId]);
        $this->deleteWhere($wpdb->term_relationships, ['object_id' => $attachmentId]);
        $this->deleteWhere($wpdb->postmeta, ['post_id' => $attachmentId]);
        $this->deleteOne($wpdb->posts, ['ID' => $attachmentId, 'post_type' => 'attachment'], 'rollback_product_media_delete_failed');
    }

    /** @param array<string,int|string> $where */
    private function deleteWhere(string $table, array $where): void
    {
        global $wpdb;
        if ($wpdb->delete($table, $where) === false) {
            throw new \RuntimeException('rollback_target_child_delete_failed');
        }
    }

    /** @param array<string,int|string> $where */
    private function deleteOne(string $table, array $where, string $error): void
    {
        global $wpdb;
        if ($wpdb->delete($table, $where) !== 1) {
            throw new \RuntimeException($error);
        }
    }
}
