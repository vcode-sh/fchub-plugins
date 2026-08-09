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

    /**
     * @param list<int>    $subscriptionIds
     * @param list<string> $statuses
     */
    public function __construct(
        public string $sourceKey,
        array $subscriptionIds = [],
        array $statuses = [],
    ) {
        $ids = array_values(array_unique(array_map(intval(...), $subscriptionIds)));
        sort($ids);

        $normalisedStatuses = array_values(array_unique(array_filter(array_map(
            static fn (mixed $status): string => strtolower(trim((string) $status)),
            $statuses,
        ))));
        sort($normalisedStatuses);

        $this->subscriptionIds = $ids;
        $this->statuses        = $normalisedStatuses;
    }

    public static function all(string $sourceKey): self
    {
        return new self($sourceKey);
    }

    public function includes(int $sourceSubscriptionId, string $status): bool
    {
        if ($this->subscriptionIds !== [] && !in_array($sourceSubscriptionId, $this->subscriptionIds, true)) {
            return false;
        }

        return $this->statuses === [] || in_array(strtolower(trim($status)), $this->statuses, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_key'       => $this->sourceKey,
            'statuses'         => $this->statuses,
            'subscription_ids' => $this->subscriptionIds,
        ];
    }

    public function fingerprint(): string
    {
        return SubscriptionRecordFactory::digest($this->toArray());
    }
}
