<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\Execution;

use CartShift\Domain\Transfer\AssessmentOutcome;
use CartShift\Domain\Transfer\Customer\CustomerAssessment;
use CartShift\Domain\Transfer\Customer\CustomerAssessor;
use CartShift\Domain\Transfer\Customer\CustomerRecord;
use CartShift\Domain\Transfer\Decision\TransferDecisionSet;
use CartShift\Domain\Transfer\Identity\CheckedMappingStore;
use CartShift\Domain\Transfer\Order\OrderProjectionContext;
use CartShift\Domain\Transfer\Order\OrderRecord;
use CartShift\Domain\Transfer\Order\OrderStagePlan;
use CartShift\Domain\Transfer\Package\AssetManifestEntry;
use CartShift\Domain\Transfer\Product\ProductAssessmentContext;
use CartShift\Domain\Transfer\Product\ProductCapabilityAssessor;
use CartShift\Domain\Transfer\Product\ProductFieldDecisionSet;
use CartShift\Domain\Transfer\Product\ProductFieldDisposition;
use CartShift\Domain\Transfer\Product\LinkedProductPlan;
use CartShift\Domain\Transfer\Product\LoadedFluentCartProductGateway;
use CartShift\Domain\Transfer\Product\ProductRecord;
use CartShift\Domain\Transfer\Product\ProductStagePlan;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Domain\Transfer\Subscription\SubscriptionStagePlan;

defined('ABSPATH') || exit;

/** Rebuilds target plans from sealed package records and current checked mappings. */
final class TargetRecordPlanFactory
{
    /** @var array<string,ProductRecord> */
    private array $products = [];
    /** @var array<string,OrderRecord> */
    private array $orders = [];

    /** @var \Closure(CustomerRecord):list<array<string,mixed>> */
    private readonly \Closure $customerCandidates;
    /** @var \Closure(int):array<string,mixed> */
    private readonly \Closure $productTargetSnapshot;

    /**
     * @param list<RecordEnvelope> $records
     * @param list<array{taxonomy:string,slug:string,name:string,parent_source:?string,target_id:int}> $targetTerms
     * @param list<string> $targetTaxClasses
     * @param array<string,bool> $productCapabilities
     * @param array<string,int> $targetShippingClasses
     * @param (callable(CustomerRecord):list<array<string,mixed>>)|null $customerCandidates
     * @param (callable(int):array<string,mixed>)|null $productTargetSnapshot
     */
    public function __construct(
        private readonly TransferDecisionSet $decisions,
        private readonly CheckedMappingStore $maps,
        private readonly string $packageDirectory,
        array $records,
        private readonly array $targetTerms,
        private readonly array $targetTaxClasses,
        private readonly array $productCapabilities,
        private readonly array $targetShippingClasses,
        private readonly string $paymentMode,
        private readonly bool $taxRoundingAtSubtotal,
        ?callable $customerCandidates = null,
        private readonly TransferRecordHydrator $hydrator = new TransferRecordHydrator(),
        private readonly ?string $evaluationUtc = null,
        ?callable $productTargetSnapshot = null,
    ) {
        $package = realpath($packageDirectory);
        if ($package === false || !is_dir($package) || is_link($packageDirectory)) {
            throw new \InvalidArgumentException('target_projection_package_invalid');
        }
        if (!in_array($paymentMode, ['live', 'test'], true)) {
            throw new \InvalidArgumentException('target_projection_payment_mode_invalid');
        }
        foreach ($records as $record) {
            if (!$record instanceof RecordEnvelope) {
                throw new \InvalidArgumentException('target_projection_record_invalid');
            }
            if ($record->identity->entityType === 'product') {
                $product = $this->hydrator->product($record);
                $this->products[$product->identity->canonical()] = $product;
            } elseif ($record->identity->entityType === 'order') {
                $order = $this->hydrator->order($record);
                $this->orders[$order->identity->canonical()] = $order;
            }
        }
        $this->customerCandidates = $customerCandidates === null
            ? static fn (CustomerRecord $record): array => self::loadedCustomerCandidates($record)
            : $customerCandidates(...);
        $this->productTargetSnapshot = $productTargetSnapshot === null
            ? static fn (int $targetId): array => (new LoadedFluentCartProductGateway())->snapshot($targetId)
            : $productTargetSnapshot(...);
    }

    public function product(RecordEnvelope $envelope): ProductStagePlan|LinkedProductPlan
    {
        $record = $this->hydrator->product($envelope);
        $decision = $this->assertRecordDecision($envelope, [
            'activate_catalogue',
            'leave_catalogue_draft',
            'link_existing_product',
        ]);
        if (($decision['action'] ?? null) === 'link_existing_product') {
            return LinkedProductPlan::fromDecision(
                $record,
                $envelope,
                $decision,
                ($this->productTargetSnapshot)((int) $decision['target_product_id']),
            );
        }
        $context = new ProductAssessmentContext(
            $this->targetTaxClasses,
            $this->productCapabilities,
            $this->productFieldDecisions($record, $this->decisions->for($record->identity)),
            approvedDraftStatuses: [],
            targetShippingClasses: $this->targetShippingClasses,
        );
        $assessment = (new ProductCapabilityAssessor())->assess($record, $context);
        if ($assessment->outcome !== AssessmentOutcome::Ready) {
            throw new \RuntimeException('target_product_assessment_blocked:' . $assessment->reasonCode);
        }
        return ProductStagePlan::build(
            $record,
            $context,
            $this->targetTerms,
            $this->assetManifest($record),
            $this->skuOverrides($record),
        );
    }

    /** @param array<string,mixed>|null $decision */
    private function productFieldDecisions(ProductRecord $record, ?array $decision): ProductFieldDecisionSet
    {
        $fields = ProductFieldDecisionSet::all(ProductFieldDisposition::Migrate)->decisions;
        if ($record->upsellProducts !== [] || $record->crossSellProducts !== []) {
            $relations = [
                'upsell_products' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $record->upsellProducts),
                'cross_sell_products' => array_map(static fn (SourceIdentity $identity): string => $identity->canonical(), $record->crossSellProducts),
            ];
            if (($decision['relation_policy'] ?? null) !== 'preserve_provenance'
                || !hash_equals(\CartShift\Support\CanonicalJson::fingerprint($relations), (string) ($decision['relation_fingerprint'] ?? ''))) {
                throw new \RuntimeException('target_product_relation_decision_missing_or_stale:' . $record->identity->canonical());
            }
            $fields['upsell_ids'] = ProductFieldDisposition::PreserveProvenance;
            $fields['cross_sell_ids'] = ProductFieldDisposition::PreserveProvenance;
        }
        if ($record->passwordProtected) {
            if (($decision['password_protection_policy'] ?? null) !== 'excluded_by_policy') {
                throw new \RuntimeException('target_product_password_protection_decision_missing:' . $record->identity->canonical());
            }
            $fields['post_password'] = ProductFieldDisposition::ExcludeByPolicy;
        }
        return new ProductFieldDecisionSet($fields);
    }

    /** @return array{record:CustomerRecord,assessment:CustomerAssessment} */
    public function customer(RecordEnvelope $envelope): array
    {
        $record = $this->hydrator->customer($envelope);
        $decision = $this->assertRecordDecision($envelope, [
            'reuse_explicit_target_customer',
            'attach_exact_same_site_user',
            'allow_unlinked_downloads',
        ]);
        $assessor = new CustomerAssessor(
            $this->customerCandidates,
            function (CustomerRecord $candidate): ?array {
                $mapping = $this->maps->get($candidate->identity);
                return $mapping === null ? null : [
                    'target_id' => $mapping->targetId,
                    'target_fingerprint' => $mapping->targetFingerprint,
                ];
            },
            $this->decisions->decisions,
            static fn (CustomerRecord $candidate): ?int => ($decision['action'] ?? null) === 'attach_exact_same_site_user'
                ? (int) $decision['user_id']
                : null,
        );
        $downloadable = ($decision['action'] ?? null) === 'allow_unlinked_downloads'
            ? (int) ($decision['downloadable_order_count'] ?? 0)
            : 0;
        $assessment = $assessor->assess($record, $downloadable);
        if (!in_array($assessment->action, [
            'create_target_customer_unlinked',
            'attach_exact_same_site_user',
            'reuse_exact_customer_map',
            'reuse_explicit_target_customer',
        ], true)) {
            throw new \RuntimeException('target_customer_assessment_blocked:' . $assessment->action);
        }
        return ['record' => $record, 'assessment' => $assessment];
    }

    public function order(RecordEnvelope $envelope): OrderStagePlan
    {
        $record = $this->hydrator->order($envelope);
        $customerId = $record->customer === null ? null : $this->mapped($record->customer, 'customer');
        $parentId = $record->parentOrder === null ? null : $this->mapped($record->parentOrder, 'parent_order');
        $productTargets = [];
        foreach ($record->productLines as $line) {
            $product = $this->products[$line->product->canonical()] ?? null;
            if (!$product instanceof ProductRecord) {
                throw new \RuntimeException('order_dependency_package_product_missing');
            }
            $variation = null;
            foreach ($product->variations as $candidate) {
                if ($candidate->identity->canonical() === $line->variation->canonical()) {
                    $variation = $candidate;
                    break;
                }
            }
            $productTargets[$line->identity->canonical()] = $variation === null
                ? [
                    'post_id' => $this->mapped($line->product, 'product'),
                    'object_id' => 0,
                    'fulfillment_type' => (string) ($line->otherInfo['source_fulfilment_type'] ?? ''),
                    'historical_variation_unlinked' => true,
                ]
                : [
                    'post_id' => $this->mapped($line->product, 'product'),
                    'object_id' => $this->mapped($line->variation, 'variation'),
                    'fulfillment_type' => $variation->fulfilmentType,
                ];
        }
        $decision = $this->decisions->for($record->identity);
        if ($decision !== null && !hash_equals($envelope->sourceContentDigest, (string) ($decision['source_fingerprint'] ?? ''))) {
            throw new \RuntimeException('target_record_decision_stale:' . $record->identity->canonical());
        }
        $canonicalNote = null;
        $noteFingerprint = null;
        if ($record->notes !== []) {
            if (!is_array($decision)
                || ($decision['action'] ?? null) !== 'approve_mapping'
                || !array_key_exists('canonical_customer_note', $decision)
                || !is_string($decision['note_decision_fingerprint'] ?? null)) {
                throw new \RuntimeException('target_order_note_decision_missing:' . $record->identity->canonical());
            }
            $canonical = $decision['canonical_customer_note'];
            $canonicalNote = $canonical === null ? null : SourceIdentity::fromCanonical((string) $canonical);
            $noteFingerprint = (string) $decision['note_decision_fingerprint'];
        }
        return OrderStagePlan::build(
            $record,
            new OrderProjectionContext(
                $productTargets,
                [],
                [],
                $this->paymentMode,
                'Migrated WooCommerce payment',
                $this->taxRoundingAtSubtotal,
            ),
            $customerId,
            $parentId,
            canonicalCustomerNote: $canonicalNote,
            noteDecisionFingerprint: $noteFingerprint,
        );
    }

    public function subscription(RecordEnvelope $envelope): SubscriptionStagePlan
    {
        $record = $this->hydrator->subscription($envelope);
        $decision = $this->assertRecordDecision($envelope, ['approve_subscription_manual']);
        $scheduleDecision = $this->decisions->forAuditFinding(
            $record->identity->canonical(),
            'subscription_schedule_absence',
        );
        if ($scheduleDecision !== null) {
            $decision['schedule_absence_decision'] = $scheduleDecision;
        }
        if ($this->evaluationUtc === null) {
            throw new \RuntimeException('target_subscription_evaluation_time_missing');
        }
        return SubscriptionStagePlan::build(
            $envelope,
            $record,
            $decision,
            $this->maps,
            $this->orders,
            $this->evaluationUtc,
        );
    }

    /** @param list<string> $allowed @return array<string,mixed> */
    private function assertRecordDecision(RecordEnvelope $record, array $allowed): array
    {
        $decision = $this->decisions->for($record->identity);
        if (!is_array($decision)
            || !in_array($decision['action'] ?? null, $allowed, true)
            || !hash_equals($record->sourceContentDigest, (string) ($decision['source_fingerprint'] ?? ''))) {
            throw new \RuntimeException('target_record_decision_missing_or_stale:' . $record->identity->canonical());
        }
        return $decision;
    }

    private function mapped(SourceIdentity $identity, string $dependency): int
    {
        $mapping = $this->maps->get($identity);
        if ($mapping === null || !$mapping->isActive() || $mapping->targetId <= 0) {
            throw new \RuntimeException('target_dependency_mapping_missing:' . $dependency);
        }
        return $mapping->targetId;
    }

    /** @return array<string,AssetManifestEntry> */
    private function assetManifest(ProductRecord $record): array
    {
        $manifest = [];
        foreach ([$record->media, ...array_map(static fn ($variation): array => $variation->media, $record->variations)] as $media) {
            foreach ($media as $reference) {
                $entry = $this->assetEntry($reference->expectedSha256, $reference->mimeType, $reference->locator, 'media');
                $manifest[$reference->identity->canonical()] = $entry;
                $manifest[$entry->sha256] = $entry;
            }
        }
        foreach ([$record->downloads, ...array_map(static fn ($variation): array => $variation->downloads, $record->variations)] as $downloads) {
            foreach ($downloads as $reference) {
                $entry = $this->assetEntry($reference->contentSha256, 'application/octet-stream', $reference->locator, 'download');
                $manifest[$reference->identity->canonical()] = $entry;
                $manifest[$entry->sha256] = $entry;
            }
        }
        return $manifest;
    }

    private function assetEntry(?string $sha256, string $mime, string $locator, string $kind): AssetManifestEntry
    {
        if (!is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new \RuntimeException('target_asset_hash_missing');
        }
        $path = $this->packageDirectory . '/assets/' . $sha256;
        $bytes = is_file($path) && !is_link($path) ? filesize($path) : false;
        if (!is_int($bytes) || !hash_equals($sha256, (string) hash_file('sha256', $path))) {
            throw new \RuntimeException('target_asset_package_changed');
        }
        $urlPath = parse_url($locator, PHP_URL_PATH);
        $name = is_string($urlPath) ? rawurldecode(basename($urlPath)) : '';
        if ($name === '' || $name === '/' || $name === '.') {
            $name = $sha256;
        }
        return new AssetManifestEntry($sha256, $bytes, $mime, $name, $kind);
    }

    /** @return array<string,string> */
    private function skuOverrides(ProductRecord $record): array
    {
        $decision = $this->decisions->forAuditFinding($record->identity->canonical(), 'target_schema_unrepresentable');
        if (!is_array($decision) || ($decision['field'] ?? null) !== 'sku') {
            return [];
        }
        if (count($record->variations) !== 1) {
            throw new \RuntimeException('target_sku_mapping_variation_ambiguous');
        }
        return [$record->variations[0]->identity->canonical() => (string) $decision['target_sku']];
    }

    /** @return list<array<string,mixed>> */
    private static function loadedCustomerCandidates(CustomerRecord $record): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_customers WHERE email = %s ORDER BY id ASC LIMIT 2",
            $record->email,
        ), ARRAY_A);
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('target_customer_candidate_read_failed');
        }
        return is_array($rows) ? array_values(array_map(static fn ($row): array => ['id' => (int) $row['id']], $rows)) : [];
    }
}
