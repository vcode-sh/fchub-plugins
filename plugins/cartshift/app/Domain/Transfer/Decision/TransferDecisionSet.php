<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Decision;

use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

final readonly class TransferDecisionSet
{
    /**
     * @param array<string, array<string, mixed>> $decisions
     * @param array<string, array<string, mixed>> $auditFindingDecisions
     * @param array<string, array<string, mixed>> $targetFindingDecisions
     */
    private function __construct(
        public array $decisions,
        private array $auditFindingDecisions = [],
        private array $targetFindingDecisions = [],
    ) {}

    public static function empty(): self { return new self([]); }

    /** @param list<array<string, mixed>> $decisions */
    public static function fromArray(array $decisions): self
    {
        if (!array_is_list($decisions)) throw new \InvalidArgumentException('Transfer decisions must be a list.');
        $byIdentity = [];
        $auditFindings = [];
        $targetFindings = [];
        foreach ($decisions as $decision) {
            if (!is_array($decision) || !is_string($decision['identity'] ?? null)) throw new \InvalidArgumentException('Transfer decision is malformed.');
            $identity = SourceIdentity::fromCanonical($decision['identity']);
            $canonical = $identity->canonical();
            $scope = $decision['scope'] ?? 'record';
            if ($scope === 'audit_finding') {
                $findingCode = $decision['finding_code'] ?? null;
                $key = is_string($findingCode) ? $canonical . '|' . $findingCode : '';
                if (!is_string($findingCode) || preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $findingCode) !== 1
                    || !self::validAuditFindingDecision($decision, $identity, $findingCode)
                    || $key === '' || isset($auditFindings[$key])) {
                    throw new \InvalidArgumentException('Transfer audit finding decision is incomplete or unsupported.');
                }
            } elseif ($scope === 'target_finding') {
                $findingCode = $decision['finding_code'] ?? null;
                $key = is_string($findingCode) ? $canonical . '|' . $findingCode : '';
                if (!is_string($findingCode)
                    || preg_match('/\A[a-z][a-z0-9_]{2,63}\z/D', $findingCode) !== 1
                    || !self::validTargetFindingDecision($decision, $identity, $findingCode)
                    || $key === ''
                    || isset($targetFindings[$key])) {
                    throw new \InvalidArgumentException('Transfer target finding decision is incomplete or unsupported.');
                }
            } elseif ($scope !== 'record') {
                throw new \InvalidArgumentException('Transfer decision scope is unsupported.');
            }
            if (($scope === 'record' && isset($byIdentity[$canonical]))
                || !in_array($decision['action'] ?? null, ['approve_mapping', 'approve_subscription_manual', 'excluded_by_policy', 'reuse_explicit_target_customer', 'attach_exact_same_site_user', 'allow_unlinked_downloads', 'activate_catalogue', 'leave_catalogue_draft'], true)
                || ($scope === 'record' && !self::validRecordDecision($decision, $identity))
                || !is_string($decision['source_fingerprint'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/D', $decision['source_fingerprint']) !== 1
                || !is_string($decision['operator'] ?? null) || $decision['operator'] === ''
                || !is_string($decision['reason'] ?? null) || $decision['reason'] === ''
                || !is_string($decision['decided_at'] ?? null) || $decision['decided_at'] === '') {
                throw new \InvalidArgumentException('Transfer decision is incomplete, duplicated or unsupported.');
            }
            if (($decision['action'] ?? null) === 'activate_catalogue' && ($decision['target_status'] ?? null) !== 'publish') {
                throw new \InvalidArgumentException('Catalogue activation decision must approve publish exactly.');
            }
            if (($decision['action'] ?? null) === 'leave_catalogue_draft'
                && ($identity->entityType !== 'product' || ($decision['target_status'] ?? null) !== 'draft')) {
                throw new \InvalidArgumentException('Leave-draft decision must approve a product remaining draft exactly.');
            }
            if ($scope === 'audit_finding') {
                $auditFindings[$key] = CanonicalJson::canonicalise($decision);
            } elseif ($scope === 'target_finding') {
                $targetFindings[$key] = CanonicalJson::canonicalise($decision);
            } else {
                $byIdentity[$canonical] = CanonicalJson::canonicalise($decision);
            }
        }
        ksort($byIdentity, SORT_STRING);
        ksort($auditFindings, SORT_STRING);
        ksort($targetFindings, SORT_STRING);
        return new self($byIdentity, $auditFindings, $targetFindings);
    }

    /** @return array<string, mixed>|null */
    public function for(SourceIdentity $identity): ?array { return $this->decisions[$identity->canonical()] ?? null; }

    /** @return array<string, mixed>|null */
    public function forAuditFinding(string $identity, string $findingCode): ?array
    {
        return $this->auditFindingDecisions[$identity . '|' . $findingCode] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    public function auditFindings(): array
    {
        return $this->auditFindingDecisions;
    }

    /** @return array<string, array<string, mixed>> */
    public function targetFindings(): array
    {
        return $this->targetFindingDecisions;
    }

    public function fingerprint(): string { return CanonicalJson::fingerprint(['decisions' => $this->rows()]); }

    public function canonicalJson(): string
    {
        return CanonicalJson::encode(['decisions' => $this->rows()]) . "\n";
    }

    public function assertSourceKey(string $sourceKey): void
    {
        SourceIdentity::assertValidSourceKey($sourceKey);
        foreach (array_merge(array_keys($this->decisions), array_map(
            static fn (array $decision): string => (string) $decision['identity'],
            $this->auditFindingDecisions,
        ), array_map(
            static fn (array $decision): string => (string) $decision['identity'],
            $this->targetFindingDecisions,
        )) as $canonical) {
            if (SourceIdentity::fromCanonical($canonical)->sourceKey !== $sourceKey) {
                throw new \RuntimeException('transfer_decision_source_namespace_changed');
            }
        }
    }

    public static function fromFile(string $path): self
    {
        $canonical = realpath($path);
        $webRoot = defined('ABSPATH') ? realpath(ABSPATH) : false;
        if ($path === '' || $path[0] !== '/' || $canonical === false
            || is_link($path) || !is_file($path) || (fileperms($path) & 0077) !== 0
            || ($webRoot !== false && ($canonical === $webRoot || str_starts_with($canonical . '/', $webRoot . '/')))) {
            throw new \InvalidArgumentException('Transfer decision file must be an absolute private non-symlink file.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) throw new \RuntimeException('Transfer decision file cannot be read.');
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_keys($data) !== ['decisions'] || !is_array($data['decisions'])) {
            throw new \InvalidArgumentException('Transfer decision file shape is invalid.');
        }
        $decisions = self::fromArray($data['decisions']);
        if (!hash_equals($decisions->canonicalJson(), $bytes)) {
            throw new \RuntimeException('Transfer decision file is not canonically serialized.');
        }
        return $decisions;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return array_values(array_merge($this->decisions, $this->auditFindingDecisions, $this->targetFindingDecisions));
    }

    /** @param array<string, mixed> $decision */
    private static function validAuditFindingDecision(
        array $decision,
        SourceIdentity $identity,
        string $findingCode,
    ): bool {
        if ($findingCode === 'historical_product_missing') {
            try {
                $placeholder = SourceIdentity::fromCanonical((string) ($decision['placeholder_identity'] ?? ''));
            } catch (\Throwable) {
                return false;
            }

            return ($decision['action'] ?? null) === 'approve_mapping'
                && $placeholder->sourceKey === $identity->sourceKey
                && $placeholder->entityType === 'product'
                && is_string($decision['placeholder_fingerprint'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $decision['placeholder_fingerprint']) === 1;
        }

        if ($findingCode === 'subscription_schedule_absence') {
            return ($decision['action'] ?? null) === 'approve_mapping'
                && ($decision['schedule_policy'] ?? null) === 'preserve_absence';
        }

        if ($findingCode === 'subscription_payment_ownership_unassessed') {
            return ($decision['action'] ?? null) === 'approve_mapping'
                && ($decision['target_collection_method'] ?? null) === 'manual'
                && ($decision['next_action_owner'] ?? null) === 'target_manual'
                && ($decision['source_auto_renewal_release_required'] ?? null) === true
                && is_string($decision['source_gateway'] ?? null);
        }

        if ($findingCode === 'target_schema_unrepresentable') {
            $targetSku = $decision['target_sku'] ?? null;
            $length = is_string($targetSku)
                ? (function_exists('mb_strlen') ? mb_strlen($targetSku, 'UTF-8') : strlen($targetSku))
                : 0;

            return ($decision['action'] ?? null) === 'approve_mapping'
                && ($decision['field'] ?? null) === 'sku'
                && is_string($targetSku)
                && $length > 0
                && $length <= 30;
        }

        if ($findingCode === 'product_relation_loss_decision_required') {
            return ($decision['action'] ?? null) === 'approve_mapping'
                && ($decision['relation_policy'] ?? null) === 'preserve_provenance'
                && is_int($decision['upsell_count'] ?? null)
                && $decision['upsell_count'] >= 0
                && is_int($decision['cross_sell_count'] ?? null)
                && $decision['cross_sell_count'] >= 0;
        }
        if ($findingCode === 'product_password_protection_unsupported') {
            return ($decision['action'] ?? null) === 'approve_mapping'
                && ($decision['password_protection_policy'] ?? null) === 'excluded_by_policy';
        }
        if ($findingCode === 'order_note_visibility_decision_required') {
            return ($decision['action'] ?? null) === 'approve_mapping'
                && ($decision['note_policy'] ?? null) === 'preserve_history_select_canonical'
                && is_int($decision['note_count'] ?? null)
                && $decision['note_count'] > 0
                && is_int($decision['customer_visible_note_count'] ?? null)
                && $decision['customer_visible_note_count'] >= 0
                && $decision['customer_visible_note_count'] <= $decision['note_count'];
        }

        return ($decision['action'] ?? null) === 'excluded_by_policy';
    }

    /** @param array<string, mixed> $decision */
    private static function validTargetFindingDecision(
        array $decision,
        SourceIdentity $identity,
        string $findingCode,
    ): bool {
        if ($findingCode === 'source_identity_conflict') {
            return $identity->entityType === 'order'
                && ($decision['action'] ?? null) === 'approve_mapping'
                && ($decision['target_disposition'] ?? null) === 'create_distinct'
                && is_int($decision['candidate_target_id'] ?? null)
                && $decision['candidate_target_id'] > 0
                && is_string($decision['target_fingerprint'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $decision['target_fingerprint']) === 1;
        }

        return false;
    }

    /** @param array<string, mixed> $decision */
    private static function validRecordDecision(array $decision, SourceIdentity $identity): bool
    {
        $action = $decision['action'] ?? null;
        if ($identity->entityType === 'product') {
            $relationPolicy = $decision['relation_policy'] ?? null;
            if ($relationPolicy !== null
                && ($relationPolicy !== 'preserve_provenance'
                    || !is_string($decision['relation_fingerprint'] ?? null)
                    || preg_match('/\A[a-f0-9]{64}\z/D', $decision['relation_fingerprint']) !== 1)) {
                return false;
            }
            if (isset($decision['password_protection_policy'])
                && $decision['password_protection_policy'] !== 'excluded_by_policy') {
                return false;
            }
        }
        if ($action === 'approve_subscription_manual') {
            return $identity->entityType === 'subscription'
                && ($decision['target_collection_method'] ?? null) === 'manual'
                && ($decision['next_action_owner'] ?? null) === 'target_manual'
                && is_string($decision['payment_reference_digest'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $decision['payment_reference_digest']) === 1
                && is_string($decision['source_gateway'] ?? null)
                && is_bool($decision['source_auto_renewal_release_required'] ?? null);
        }
        if ($action === 'reuse_explicit_target_customer') {
            return $identity->entityType === 'customer'
                && is_int($decision['target_id'] ?? null)
                && $decision['target_id'] > 0
                && is_string($decision['target_fingerprint'] ?? null)
                && preg_match('/\A[a-f0-9]{64}\z/D', $decision['target_fingerprint']) === 1;
        }
        if ($action === 'attach_exact_same_site_user') {
            return $identity->entityType === 'customer'
                && preg_match('/\A[1-9][0-9]*\z/D', $identity->sourceId) === 1
                && is_int($decision['user_id'] ?? null)
                && $decision['user_id'] === (int) $identity->sourceId;
        }
        if ($action === 'allow_unlinked_downloads') {
            if ($identity->entityType !== 'customer'
                || !is_array($decision['affected_orders'] ?? null)
                || !array_is_list($decision['affected_orders'])
                || $decision['affected_orders'] === []
                || !is_array($decision['downloadable_orders'] ?? null)
                || !array_is_list($decision['downloadable_orders'])
                || !is_int($decision['downloadable_order_count'] ?? null)
                || $decision['downloadable_order_count'] !== count($decision['downloadable_orders'])) {
                return false;
            }
            $affected = self::canonicalOrderList($decision['affected_orders'], $identity->sourceKey);
            $downloadable = self::canonicalOrderList($decision['downloadable_orders'], $identity->sourceKey);
            return $affected !== null
                && $downloadable !== null
                && $affected === $decision['affected_orders']
                && $downloadable === $decision['downloadable_orders']
                && array_diff($downloadable, $affected) === [];
        }

        return !in_array($action, [
            'approve_subscription_manual',
            'reuse_explicit_target_customer',
            'attach_exact_same_site_user',
            'allow_unlinked_downloads',
        ], true);
    }

    /** @param list<mixed> $values @return list<string>|null */
    private static function canonicalOrderList(array $values, string $sourceKey): ?array
    {
        $orders = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                return null;
            }
            try {
                $identity = SourceIdentity::fromCanonical($value);
            } catch (\Throwable) {
                return null;
            }
            if ($identity->sourceKey !== $sourceKey || $identity->entityType !== 'order') {
                return null;
            }
            $orders[] = $identity->canonical();
        }
        $orders = array_values(array_unique($orders));
        sort($orders, SORT_NATURAL);
        return $orders;
    }
}
