<?php

declare(strict_types=1);

namespace CartShift\Domain\Transfer\SameSite;

use CartShift\Domain\Mapping\ProductMatcher;
use CartShift\Domain\Mapping\VariantResolver;
use CartShift\Domain\Transfer\Product\ProductTargetFingerprint;
use CartShift\Domain\Transfer\RecordEnvelope;
use CartShift\Domain\Transfer\SourceIdentity;
use CartShift\Support\CanonicalJson;

defined('ABSPATH') || exit;

/** Builds one evidence-bound owner question for a likely product conflict. */
final readonly class GuidedProductQuestionBuilder
{
    /** @var \Closure(SourceIdentity):array{orders:int,subscriptions:int} */
    private \Closure $dependencyCounts;

    /** @param (callable(SourceIdentity):array{orders:int,subscriptions:int})|null $dependencyCounts */
    public function __construct(
        ?callable $dependencyCounts = null,
        private ProductMatcher $matcher = new ProductMatcher(),
        private VariantResolver $variants = new VariantResolver(),
        private ProductTargetFingerprint $targetFingerprint = new ProductTargetFingerprint(),
    ) {
        $this->dependencyCounts = $dependencyCounts === null
            ? self::loadedDependencyCounts(...)
            : $dependencyCounts(...);
    }

    /** @param array<string,mixed> $row @param list<array<string,mixed>> $targets @return array<string,mixed>|null */
    public function build(RecordEnvelope $record, array $row, array $targets): ?array
    {
        $payload = $record->payload;
        $sourceVariations = $this->sourceVariations($payload);
        $match = $this->matcher->match([
            'name' => (string) ($payload['name'] ?? ''),
            'sku' => (string) ($payload['sku'] ?? ($sourceVariations[0]['sku'] ?? '')),
            'price' => (float) ($sourceVariations[0]['price'] ?? 0),
            'variation_count' => count($sourceVariations),
        ], array_map(static fn (array $target): array => [
            'id' => (int) ($target['id'] ?? 0),
            'name' => (string) ($target['name'] ?? ''),
            'sku' => (string) ($target['sku'] ?? ''),
            'price' => (float) ($target['price'] ?? 0),
            'variation_count' => (int) ($target['variation_count'] ?? 0),
        ], $targets));

        $targetsById = [];
        foreach ($targets as $target) {
            $targetsById[(int) ($target['id'] ?? 0)] = $target;
        }
        $strongLinks = [];
        $otherLinks = [];
        $strongCandidateFound = false;
        $candidateFound = false;
        foreach ($match['ranked'] as $ranked) {
            if (($ranked['band'] ?? ProductMatcher::BAND_NONE) === ProductMatcher::BAND_NONE) {
                continue;
            }
            $candidateFound = true;
            $strongCandidateFound = $strongCandidateFound
                || $ranked['band'] === ProductMatcher::BAND_STRONG;
            $target = $targetsById[(int) $ranked['id']] ?? null;
            if (!is_array($target)) {
                continue;
            }
            $link = $this->linkChoice($record, $sourceVariations, $target, (string) $ranked['band']);
            if ($link !== null) {
                if ($ranked['band'] === ProductMatcher::BAND_STRONG) {
                    $strongLinks[] = $link;
                } else {
                    $otherLinks[] = $link;
                }
            }
        }
        if (!$candidateFound) {
            return null;
        }
        $choices = $strongCandidateFound
            ? $strongLinks
            : [...$otherLinks, $this->choice(['action' => 'create'])];
        $dependencies = ($this->dependencyCounts)($record->identity);
        $orders = max(0, (int) ($dependencies['orders'] ?? 0));
        $subscriptions = max(0, (int) ($dependencies['subscriptions'] ?? 0));
        if ($orders === 0 && $subscriptions === 0) {
            $choices[] = $this->choice(['action' => 'skip']);
        }
        if ($choices === []) {
            return [
                'blocked' => true,
                'identity' => $record->identity->canonical(),
                'source_fingerprint' => $record->sourceContentDigest,
                'product_name' => (string) ($payload['name'] ?? 'WooCommerce product'),
                'dependent_orders' => $orders,
                'dependent_subscriptions' => $subscriptions,
                'original_decision' => $row,
            ];
        }

        $facts = [
            'identity' => $record->identity->canonical(),
            'source_fingerprint' => $record->sourceContentDigest,
            'choices' => $choices,
            'dependent_orders' => $orders,
            'dependent_subscriptions' => $subscriptions,
        ];
        return $facts + [
            'review_id' => 'product-' . substr(CanonicalJson::fingerprint($facts), 0, 12),
            'product_name' => (string) ($payload['name'] ?? 'WooCommerce product'),
            'original_decision' => $row,
        ];
    }

    /** @param list<array<string,mixed>> $sourceVariations @param array<string,mixed> $target */
    private function linkChoice(
        RecordEnvelope $record,
        array $sourceVariations,
        array $target,
        string $band,
    ): ?array {
        $snapshot = $target['snapshot'] ?? null;
        $targetVariations = is_array($snapshot) && is_array($snapshot['variations'] ?? null)
            ? array_values(array_filter($snapshot['variations'], 'is_array'))
            : [];
        if (count($targetVariations) !== count($sourceVariations)) {
            return null;
        }
        $sourceForResolver = [];
        $sourceByIndex = [];
        foreach ($sourceVariations as $index => $variation) {
            $id = $index + 1;
            $sourceForResolver[] = ['id' => $id, 'sku' => $variation['sku'], 'name' => $variation['name']];
            $sourceByIndex[$id] = $variation;
        }
        $targetForResolver = array_map(static fn (array $variation): array => [
            'id' => (int) ($variation['id'] ?? 0),
            'sku' => (string) ($variation['sku'] ?? ''),
            'name' => (string) ($variation['variation_title'] ?? ''),
        ], $targetVariations);
        $resolved = $this->variants->resolve(
            $sourceForResolver,
            $targetForResolver,
            allowPositionalFallback: false,
        );
        if ($resolved['orphans'] !== [] || count($resolved['map']) !== count($sourceVariations)) {
            return null;
        }

        $targetById = [];
        foreach ($targetVariations as $variation) {
            $targetById[(int) $variation['id']] = $variation;
        }
        $sourceMap = [$record->identity->canonical() => (int) $target['id']];
        $links = [];
        foreach ($resolved['map'] as $sourceIndex => $targetId) {
            $source = $sourceByIndex[$sourceIndex];
            $targetVariation = $targetById[$targetId] ?? null;
            if (!is_array($targetVariation)) {
                return null;
            }
            $sourceMap[$source['identity']] = $targetId;
            $links[] = [
                'source_variation' => $source['identity'],
                'target_variation_id' => $targetId,
                'source_fingerprint' => CanonicalJson::fingerprint($source['payload']),
                'target_fingerprint' => CanonicalJson::fingerprint($targetVariation),
            ];
        }
        usort($links, static fn (array $left, array $right): int =>
            $left['source_variation'] <=> $right['source_variation']
        );

        return $this->choice([
            'action' => 'link',
            'band' => $band,
            'target_product_id' => (int) $target['id'],
            'target_name' => (string) $target['name'],
            'target_fingerprint' => $this->targetFingerprint->fingerprint($snapshot, $sourceMap),
            'variation_links' => $links,
        ]);
    }

    /** @param array<string,mixed> $choice @return array<string,mixed> */
    private function choice(array $choice): array
    {
        return ['choice_id' => 'choice-' . substr(CanonicalJson::fingerprint($choice), 0, 12)] + $choice;
    }

    /** @param array<string,mixed> $payload @return list<array{identity:string,sku:string,name:string,price:int,payload:array<string,mixed>}> */
    private function sourceVariations(array $payload): array
    {
        $variations = $payload['variations'] ?? null;
        if (!is_array($variations) || !array_is_list($variations) || $variations === []) {
            throw new \RuntimeException('guided_product_variations_invalid');
        }
        $result = [];
        foreach ($variations as $variation) {
            if (!is_array($variation) || !is_string($variation['identity'] ?? null)) {
                throw new \RuntimeException('guided_product_variations_invalid');
            }
            $assignments = is_array($variation['attribute_assignments'] ?? null)
                ? $variation['attribute_assignments']
                : [];
            $names = [];
            foreach ($assignments as $assignment) {
                if (is_array($assignment) && trim((string) ($assignment['value'] ?? '')) !== '') {
                    $names[] = trim((string) $assignment['value']);
                }
            }
            $result[] = [
                'identity' => $variation['identity'],
                'sku' => (string) ($variation['sku'] ?? ''),
                'name' => $names === [] ? 'Default' : implode(' / ', $names),
                'price' => (int) ($variation['price']['active_price'] ?? 0),
                'payload' => $variation,
            ];
        }
        return $result;
    }

    /** @return array{orders:int,subscriptions:int} */
    private static function loadedDependencyCounts(SourceIdentity $identity): array
    {
        global $wpdb;
        $orders = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT oi.order_id)
             FROM {$wpdb->prefix}woocommerce_order_itemmeta oim
             INNER JOIN {$wpdb->prefix}woocommerce_order_items oi ON oi.order_item_id = oim.order_item_id
             WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d",
            (int) $identity->sourceId,
        ));
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('guided_product_dependency_read_failed');
        }
        return ['orders' => $orders, 'subscriptions' => 0];
    }
}
