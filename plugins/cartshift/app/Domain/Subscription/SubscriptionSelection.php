<?php

declare(strict_types=1);

namespace CartShift\Domain\Subscription;

defined('ABSPATH') || exit;

/**
 * Which subscriptions a run is about, and a fingerprint that says so.
 *
 * The fingerprint is the cutover's freeze marker: the package is exported, the
 * source renewal workers are quiesced, the package is exported again, and if
 * this value moved in between then the source changed under the migration and
 * the run aborts. It therefore has to be stable across two processes on two
 * machines, which is why the ids and statuses are normalised and sorted here
 * rather than trusted in whatever order the caller assembled them.
 */
final readonly class SubscriptionSelection
{
    /** @var list<int> Sorted, unique. Empty means every subscription. */
    public array $subscriptionIds;

    /** @var list<string> Sorted, unique, lower case. Empty means every status. */
    public array $statuses;

    /** @var list<int> Sorted, unique. These IDs never enter the cohort. */
    public array $excludedSubscriptionIds;

    /**
     * @param list<int>    $subscriptionIds
     * @param list<string> $statuses
     * @param list<int>    $excludedSubscriptionIds
     */
    public function __construct(
        public string $sourceKey,
        array $subscriptionIds = [],
        array $statuses = [],
        array $excludedSubscriptionIds = [],
    ) {
        $ids = self::positiveIds($subscriptionIds);
        sort($ids);

        $excludedIds = self::positiveIds($excludedSubscriptionIds);
        sort($excludedIds);

        $normalisedStatuses = array_values(array_unique(array_filter(array_map(
            static fn (mixed $status): string => strtolower(trim((string) $status)),
            $statuses,
        ))));
        sort($normalisedStatuses);

        $this->subscriptionIds = $ids;
        $this->statuses        = $normalisedStatuses;
        $this->excludedSubscriptionIds = $excludedIds;
    }

    public static function all(string $sourceKey): self
    {
        return new self($sourceKey);
    }

    public function includes(int $sourceSubscriptionId, string $status): bool
    {
        if (!$this->includesId($sourceSubscriptionId)) {
            return false;
        }

        return $this->statuses === [] || in_array(strtolower(trim($status)), $this->statuses, true);
    }

    public function includesId(int $sourceSubscriptionId): bool
    {
        if (in_array($sourceSubscriptionId, $this->excludedSubscriptionIds, true)) {
            return false;
        }

        return $this->subscriptionIds === [] || in_array($sourceSubscriptionId, $this->subscriptionIds, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $definition = [
            'source_key'       => $this->sourceKey,
            'statuses'         => $this->statuses,
            'subscription_ids' => $this->subscriptionIds,
        ];

        // Keep the original all/include-only shape byte-for-byte compatible.
        // Existing package and receipt fingerprints were calculated before
        // exclusions existed, and an empty new field must not invalidate them.
        if ($this->excludedSubscriptionIds !== []) {
            $definition['excluded_subscription_ids'] = $this->excludedSubscriptionIds;
        }

        return $definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function fromArray(array $definition, ?string $fallbackSourceKey = null): self
    {
        return new self(
            trim((string) ($definition['source_key'] ?? $fallbackSourceKey ?? '')),
            (array) ($definition['subscription_ids'] ?? []),
            (array) ($definition['statuses'] ?? []),
            (array) ($definition['excluded_subscription_ids'] ?? []),
        );
    }

    public function fingerprint(): string
    {
        return SubscriptionRecordFactory::digest($this->toArray());
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<int>
     */
    private static function positiveIds(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(intval(...), $values),
            static fn (int $id): bool => $id > 0,
        )));
    }
}
