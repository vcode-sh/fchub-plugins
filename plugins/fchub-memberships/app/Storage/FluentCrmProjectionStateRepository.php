<?php

declare(strict_types=1);

namespace FChubMemberships\Storage;

defined('ABSPATH') || exit;

final class FluentCrmProjectionStateRepository
{
    private const META_KEY = '_fchub_memberships_fluentcrm_projection';

    /** @var \Closure(int, string): mixed */
    private \Closure $reader;

    /** @var \Closure(int, string, array<string, mixed>): bool */
    private \Closure $writer;

    public function __construct(?callable $reader = null, ?callable $writer = null)
    {
        $this->reader = \Closure::fromCallable($reader ?? static fn(int $userId, string $key): mixed => (
            get_user_meta($userId, $key, true)
        ));
        $this->writer = \Closure::fromCallable($writer ?? static function (
            int $userId,
            string $key,
            array $state
        ): bool {
            $existing = get_user_meta($userId, $key, true);
            if ($existing === $state) {
                return true;
            }

            return update_user_meta($userId, $key, $state) !== false;
        });
    }

    /** @return array{contact_id:int, tag_ids:list<int>, list_ids:list<int>} */
    public function get(int $userId): array
    {
        if ($userId <= 0) {
            return $this->normalise([]);
        }

        $state = ($this->reader)($userId, self::META_KEY);

        return $this->normalise(is_array($state) ? $state : []);
    }

    public function save(int $userId, array $state): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return ($this->writer)($userId, self::META_KEY, $this->normalise($state));
    }

    /** @return array{contact_id:int, tag_ids:list<int>, list_ids:list<int>} */
    private function normalise(array $state): array
    {
        return [
            'contact_id' => max(0, (int) ($state['contact_id'] ?? 0)),
            'tag_ids' => $this->normaliseIds($state['tag_ids'] ?? []),
            'list_ids' => $this->normaliseIds($state['list_ids'] ?? []),
        ];
    }

    /** @return list<int> */
    private function normaliseIds(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $normalised = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($normalised, SORT_NUMERIC);

        return $normalised;
    }
}
